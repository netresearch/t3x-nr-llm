<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\GetPageContentTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTsConfigTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTypoScriptTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadRecordsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchRecordsTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\SearchableSchemaFieldsCollector;

/**
 * Unit tests for the five content/introspection tools (ADR-042): spec
 * shape, admin tiering, and the fail-closed / input-validation paths that
 * return before any database access (the injected ConnectionPool would
 * throw on use — the mocks are intentionally unprimed).
 */
#[CoversClass(SearchRecordsTool::class)]
#[CoversClass(GetPageContentTool::class)]
#[CoversClass(ReadRecordsTool::class)]
#[CoversClass(GetTypoScriptTool::class)]
#[CoversClass(GetTsConfigTool::class)]
final class ContentToolsSpecTest extends TestCase
{
    private mixed $tcaBackup = null;

    private mixed $beUserBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tcaBackup    = $GLOBALS['TCA'] ?? null;
        $this->beUserBackup = $GLOBALS['BE_USER'] ?? null;
        unset($GLOBALS['BE_USER']);

        $GLOBALS['TCA'] = [
            'tt_content' => [
                'ctrl'    => ['label' => 'header', 'searchFields' => 'header,bodytext'],
                'columns' => ['header' => [], 'bodytext' => [], 'CType' => []],
            ],
        ];
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
    public function specsDeclareTheExpectedNamesAndRequirements(): void
    {
        $expected = [
            'search_records'   => [$this->searchTool(), false, ['query']],
            'get_page_content' => [$this->pageContentTool(), false, ['uid']],
            'read_records'     => [$this->readRecordsTool(), false, ['table']],
            'get_typoscript'   => [$this->typoScriptTool(), true, ['pageUid']],
            'get_tsconfig'     => [$this->tsConfigTool(), true, ['pageUid']],
        ];

        foreach ($expected as $name => [$tool, $adminOnly, $required]) {
            $spec = $tool->getSpec();
            self::assertSame($name, $spec->name);
            self::assertSame($adminOnly, $tool->requiresAdmin(), $name);
            self::assertTrue($tool->isEnabledByDefault(), $name);
            self::assertSame($required, $spec->parameters['required'] ?? [], $name);
        }
    }

    #[Test]
    public function searchFailsClosedWithoutBackendUser(): void
    {
        self::assertSame('Not permitted.', $this->searchTool()->execute(['query' => 'foo'])->content);
    }

    #[Test]
    public function searchRejectsTooShortQuery(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();

        self::assertSame(
            'Query too short (minimum 2 characters).',
            $this->searchTool()->execute(['query' => 'x'])->content,
        );
    }

    #[Test]
    public function pageContentFailsClosedWithoutBackendUser(): void
    {
        self::assertSame(
            'Page not found or not permitted.',
            $this->pageContentTool()->execute(['uid' => 1])->content,
        );
    }

    #[Test]
    public function pageContentRejectsInvalidUid(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();

        self::assertSame(
            'Page not found or not permitted.',
            $this->pageContentTool()->execute(['uid' => 0])->content,
        );
    }

    #[Test]
    public function readRecordsFailsClosedWithoutBackendUser(): void
    {
        self::assertSame(
            'Table not found or not permitted.',
            $this->readRecordsTool()->execute(['table' => 'tt_content'])->content,
        );
    }

    #[Test]
    public function readRecordsDeniesSensitiveTableEvenForAdmins(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();
        $GLOBALS['TCA']['be_users'] = ['ctrl' => ['label' => 'username'], 'columns' => ['username' => []]];

        self::assertSame(
            'Table not found or not permitted.',
            $this->readRecordsTool()->execute(['table' => 'be_users'])->content,
        );
    }

    #[Test]
    public function readRecordsRejectsUnknownFilterColumn(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();

        self::assertSame(
            'Invalid filter: only existing, non-credential TCA columns with scalar values are allowed.',
            $this->readRecordsTool()->execute([
                'table'        => 'tt_content',
                'where_equals' => ['no_such_column' => 'x'],
            ])->content,
        );
    }

    #[Test]
    public function readRecordsRejectsCredentialFilterColumn(): void
    {
        $GLOBALS['BE_USER'] = $this->adminUser();
        $GLOBALS['TCA']['tt_content']['columns']['api_key'] = [];

        self::assertSame(
            'Invalid filter: only existing, non-credential TCA columns with scalar values are allowed.',
            $this->readRecordsTool()->execute([
                'table'        => 'tt_content',
                'where_equals' => ['api_key' => 'x'],
            ])->content,
        );
    }

    #[Test]
    public function typoScriptRejectsInvalidPageUid(): void
    {
        self::assertSame(
            'Page not found or no TypoScript template.',
            $this->typoScriptTool()->execute(['pageUid' => 0])->content,
        );
    }

    #[Test]
    public function tsConfigRejectsInvalidPageUid(): void
    {
        self::assertSame('Page not found.', $this->tsConfigTool()->execute(['pageUid' => 0])->content);
    }

    private function adminUser(): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);

        return $user;
    }

    private function searchTool(): SearchRecordsTool
    {
        return new SearchRecordsTool(
            $this->createMock(ConnectionPool::class),
            new TableReadAccessService(),
            $this->createMock(SearchableSchemaFieldsCollector::class),
        );
    }

    private function pageContentTool(): GetPageContentTool
    {
        return new GetPageContentTool($this->createMock(ConnectionPool::class));
    }

    private function readRecordsTool(): ReadRecordsTool
    {
        return new ReadRecordsTool($this->createMock(ConnectionPool::class), new TableReadAccessService());
    }

    private function typoScriptTool(): GetTypoScriptTool
    {
        // FrontendTypoScriptFactory and SysTemplateRepository are final — not
        // mockable. The paths under test (spec, invalid pageUid) never touch
        // the constructor-injected services, so instantiate without them.
        $reflection = new ReflectionClass(GetTypoScriptTool::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    private function tsConfigTool(): GetTsConfigTool
    {
        return new GetTsConfigTool($this->createMock(ConnectionPool::class));
    }
}
