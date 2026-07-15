<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadRecordsTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Unit tests for {@see ReadRecordsTool}: spec shape, field resolution
 * (explicit list vs. TCA label/label_alt defaults), filter validation and
 * binding, and the row output format. The query builder chain is stubbed
 * with call-capturing closures so the exact select list, filter columns and
 * bound parameters can be asserted positionally.
 */
#[CoversClass(ReadRecordsTool::class)]
final class ReadRecordsToolTest extends TestCase
{
    private mixed $tcaBackup = null;

    private mixed $beUserBackup = null;

    /** @var list<string> Tables passed to ConnectionPool::getQueryBuilderForTable(). */
    private array $requestedTables = [];

    /** @var list<list<string>> Positional argument lists of every select() call. */
    private array $selectCalls = [];

    /** @var list<array{string, mixed}> [field, placeholder] of every expr()->eq() call. */
    private array $eqCalls = [];

    /** @var list<array{mixed, mixed}> [value, type] of every createNamedParameter() call. */
    private array $paramCalls = [];

    private int $andWhereCalls = 0;

    /** @var list<int|null> */
    private array $maxResults = [];

    /** @var list<int> */
    private array $firstResults = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tcaBackup    = $GLOBALS['TCA'] ?? null;
        $this->beUserBackup = $GLOBALS['BE_USER'] ?? null;
        unset($GLOBALS['BE_USER']);

        // label_alt: one valid column (needs trimming), one unknown column,
        // one credential-ish column that exists — each discriminates a
        // different guard in the default-field resolution.
        $GLOBALS['TCA'] = [
            'tt_content' => [
                'ctrl' => [
                    'label'     => 'header',
                    'label_alt' => ' bodytext , no_such_col, password_field ',
                ],
                'columns' => [
                    'header'         => [],
                    'bodytext'       => [],
                    'CType'          => [],
                    'colPos'         => [],
                    'sorting'        => [],
                    'layout'         => [],
                    'password_field' => [],
                ],
            ],
        ];

        $this->requestedTables = [];
        $this->selectCalls     = [];
        $this->eqCalls         = [];
        $this->paramCalls      = [];
        $this->andWhereCalls   = 0;
        $this->maxResults      = [];
        $this->firstResults    = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
        if ($this->beUserBackup !== null) {
            $GLOBALS['BE_USER'] = $this->beUserBackup;
        } else {
            unset($GLOBALS['BE_USER']);
        }
        parent::tearDown();
    }

    #[Test]
    public function specPinsDescriptionAndParameterSchema(): void
    {
        $spec = $this->tool([])->getSpec();

        self::assertSame('read_records', $spec->name);
        self::assertSame(
            'Read records of one TYPO3 table with equality filters (no SQL). Returns uid, pid and the '
            . 'label field by default; pass "fields" for specific columns. Deleted and hidden records '
            . 'are excluded; credential-like columns are never returned.',
            $spec->description,
        );
        // Pin the whole JSON-Schema parameter block so any dropped item/type is caught.
        self::assertSame(
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'description' => 'The TCA table to read (e.g. "pages", "tt_content").',
                    ],
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'Optional: a single record uid.',
                    ],
                    'pid' => [
                        'type'        => 'integer',
                        'description' => 'Optional: only records on this page uid.',
                    ],
                    'where_equals' => [
                        'type'        => 'object',
                        'description' => 'Optional: field => value equality filters (max 5, TCA columns only).',
                        'additionalProperties' => true,
                    ],
                    'fields' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Optional: columns to return (validated against the TCA).',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Maximum rows (default 20, hard cap 50).',
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Rows to skip for pagination (default 0).',
                    ],
                ],
                'required' => ['table'],
            ],
            $spec->parameters,
        );
    }

    #[Test]
    public function trimsTableNameAndReturnsFormattedRowsForExplicitFields(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();
        $tool               = $this->tool([
            ['uid' => 1, 'pid' => 2, 'header' => 'Hello'],
            ['uid' => 3, 'pid' => 2, 'header' => null],
        ]);

        // "uid" is requested explicitly: the merged uid/pid prefix must be
        // deduplicated, keeping the ['uid', 'pid', 'header'] order.
        $output = $tool->execute([
            'table'  => '  tt_content  ',
            'fields' => ['uid', 'header'],
        ]);

        self::assertSame(['tt_content'], $this->requestedTables);
        self::assertSame([['uid', 'pid', 'header']], $this->selectCalls);
        // No uid/pid/where_equals argument -> not a single filter is bound.
        self::assertSame(0, $this->andWhereCalls);
        self::assertSame([], $this->paramCalls);
        self::assertSame(
            "Records in tt_content (2, offset 0):\n"
            . "- tt_content:1\n"
            . "  pid: 2\n"
            . "  header: Hello\n"
            . "- tt_content:3\n"
            . "  pid: 2\n"
            . '  header: null',
            $output,
        );
    }

    #[Test]
    public function defaultFieldsComeFromTrimmedNonSensitiveTcaLabels(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();
        $tool               = $this->tool([
            ['uid' => 7, 'pid' => 1, 'header' => 'A', 'bodytext' => 'B'],
        ]);

        $output = $tool->execute(['table' => 'tt_content']);

        // label "header" plus label_alt "bodytext" (trimmed); "no_such_col"
        // (not a TCA column) and "password_field" (credential-ish) are dropped.
        self::assertSame([['uid', 'pid', 'header', 'bodytext']], $this->selectCalls);
        self::assertSame(0, $this->andWhereCalls);
        self::assertSame(
            "Records in tt_content (1, offset 0):\n"
            . "- tt_content:7\n"
            . "  pid: 1\n"
            . "  header: A\n"
            . '  bodytext: B',
            $output,
        );
    }

    #[Test]
    public function skipsCtrlLabelThatIsNotATcaColumn(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();
        $GLOBALS['TCA']     = [
            'tt_content' => [
                'ctrl'    => ['label' => 'ghost_col', 'label_alt' => 'header'],
                'columns' => ['header' => []],
            ],
        ];
        $tool = $this->tool([['uid' => 9, 'pid' => 4, 'header' => 'H']]);

        $output = $tool->execute(['table' => 'tt_content']);

        // "ghost_col" is a ctrl label without a column definition: it must
        // not be selected; only the label_alt column survives.
        self::assertSame([['uid', 'pid', 'header']], $this->selectCalls);
        self::assertStringContainsString('- tt_content:9', $output);
    }

    #[Test]
    public function bindsUidPidAndWhereEqualsFiltersPositionally(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();
        $tool               = $this->tool([['uid' => 5, 'pid' => 0, 'header' => 'News']]);

        $output = $tool->execute([
            'table'        => 'tt_content',
            'fields'       => ['uid', 'header'],
            'uid'          => 42,
            'pid'          => 0,
            'offset'       => 7,
            'where_equals' => [
                ' header ' => 'News',
                'colPos'   => 7,
                'CType'    => 'text',
                'sorting'  => 3,
                'layout'   => 1,
            ],
        ]);

        // uid first, then pid (0 is valid: the root page), then the five
        // where_equals columns in argument order with the key trimmed.
        self::assertSame(7, $this->andWhereCalls);
        self::assertSame(
            [
                ['uid', ':p1'],
                ['pid', ':p2'],
                ['header', ':p3'],
                ['colPos', ':p4'],
                ['CType', ':p5'],
                ['sorting', ':p6'],
                ['layout', ':p7'],
            ],
            $this->eqCalls,
        );
        self::assertSame(
            [
                [42, Connection::PARAM_INT],
                [0, Connection::PARAM_INT],
                ['News', ParameterType::STRING],
                [7, Connection::PARAM_INT],
                ['text', ParameterType::STRING],
                [3, Connection::PARAM_INT],
                [1, Connection::PARAM_INT],
            ],
            $this->paramCalls,
        );
        self::assertSame([20], $this->maxResults);
        self::assertSame([7], $this->firstResults);
        self::assertSame(
            "Records in tt_content (1, offset 7):\n"
            . "- tt_content:5\n"
            . "  pid: 0\n"
            . '  header: News',
            $output,
        );
    }

    #[Test]
    public function rejectsMoreThanFiveWhereEqualsFiltersBeforeTouchingTheDatabase(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();
        $tool               = $this->tool([]);

        $output = $tool->execute([
            'table'        => 'tt_content',
            'where_equals' => [
                'header'   => 'a',
                'bodytext' => 'b',
                'CType'    => 'c',
                'colPos'   => 1,
                'sorting'  => 2,
                'layout'   => 3,
            ],
        ]);

        self::assertSame(
            'Invalid filter: only existing, non-credential TCA columns with scalar values are allowed.',
            $output,
        );
        self::assertSame([], $this->requestedTables);
    }

    #[Test]
    public function ignoresNegativePidAndZeroUidArguments(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();
        $tool               = $this->tool([['uid' => 11, 'pid' => 3, 'header' => 'X']]);

        $output = $tool->execute([
            'table'  => 'tt_content',
            'fields' => ['header'],
            'uid'    => 0,
            'pid'    => -3,
        ]);

        // A present-but-negative pid and a zero uid are no filters at all.
        self::assertSame(0, $this->andWhereCalls);
        self::assertSame([], $this->paramCalls);
        self::assertStringContainsString('- tt_content:11', $output);
    }

    private function adminUser(): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);

        return $user;
    }

    /**
     * A ReadRecordsTool whose query-builder chain records every select(),
     * expr()->eq(), createNamedParameter(), andWhere(), setMaxResults() and
     * setFirstResult() call into the test-case capture properties and yields
     * the given rows.
     *
     * @param list<array<string, int|string|null>> $rows
     */
    private function tool(array $rows): ReadRecordsTool
    {
        $result = self::createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $expressionBuilder = self::createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturnCallback(
            function (string $fieldName, mixed $value): string {
                $this->eqCalls[] = [$fieldName, $value];

                return sprintf('eq(%s)', $fieldName);
            },
        );

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnCallback(
            function (string ...$selects) use ($queryBuilder): QueryBuilder {
                $this->selectCalls[] = array_values($selects);

                return $queryBuilder;
            },
        );
        $queryBuilder->method('from')->willReturn($queryBuilder);
        $queryBuilder->method('orderBy')->willReturn($queryBuilder);
        $queryBuilder->method('setMaxResults')->willReturnCallback(
            function (?int $maxResults = null) use ($queryBuilder): QueryBuilder {
                $this->maxResults[] = $maxResults;

                return $queryBuilder;
            },
        );
        $queryBuilder->method('setFirstResult')->willReturnCallback(
            function (int $firstResult) use ($queryBuilder): QueryBuilder {
                $this->firstResults[] = $firstResult;

                return $queryBuilder;
            },
        );
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            function (mixed $value, mixed $type = ParameterType::STRING): string {
                $this->paramCalls[] = [$value, $type];

                return ':p' . count($this->paramCalls);
            },
        );
        $queryBuilder->method('andWhere')->willReturnCallback(
            function (mixed ...$predicates) use ($queryBuilder): QueryBuilder {
                ++$this->andWhereCalls;

                return $queryBuilder;
            },
        );
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturnCallback(
            function (string $table) use ($queryBuilder): QueryBuilder {
                $this->requestedTables[] = $table;

                return $queryBuilder;
            },
        );

        return new ReadRecordsTool($connectionPool, new TableReadAccessService());
    }
}
