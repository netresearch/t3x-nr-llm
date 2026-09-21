<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The content types `create_content_element_draft` offers are read from the
 * live TCA under an exclusion rule, and `fields` carries the scalar columns of
 * the chosen type (ADR-196).
 *
 * Core's EXT:frontend declares the stock types; four test types are added to
 * the TCA at runtime — one scalar-only, one with a FlexForm column, one with
 * an inline column, and `list` with a scalar showitem — so both directions of
 * the rule are exercised against the real {@see \TYPO3\CMS\Core\DataHandling\DataHandler}.
 * {@see CreateContentElementDraftToolTest} holds the behaviour of the four
 * original types unchanged.
 */
#[CoversClass(CreateContentElementDraftTool::class)]
final class CreateContentElementDraftToolTcaTypesTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /** A page every backend user may edit content on. */
    private const PAGE = 2;

    private const SCALAR_TYPE = 'nrllm_scalar';

    private const FLEX_TYPE = 'nrllm_flex';

    private const INLINE_TYPE = 'nrllm_inline';

    /** Scalar as well, with a `number` column whose override the DataHandler cannot evaluate. */
    private const DROPPING_TYPE = 'nrllm_dropping';

    private CreateContentElementDraftTool $tool;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->importFixture('BeUsers.csv');

        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE, 'pid' => 0, 'title' => 'Open', 'doktype' => 1, 'slug' => '/open',
            'sorting' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => (string)self::PAGE,
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $this->declareTestContentTypes();

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new CreateContentElementDraftTool($this->connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theSpecOffersTheStockProseTypesAndTheScalarTestTypeAndNothingElse(): void
    {
        $spec = $this->tool->getSpec();

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        $typeProperty = $properties['type'] ?? null;
        self::assertIsArray($typeProperty);
        $description = $typeProperty['description'] ?? null;
        self::assertIsString($description);

        // `textmedia`, `textpic` and `image` carry a `file` column and stay
        // offered; `uploads` carries `file_collections` (type `group`) and does not.
        foreach (['header', 'text', 'textmedia', 'textpic', 'image', 'bullets', 'table', self::SCALAR_TYPE] as $offered) {
            self::assertMatchesRegularExpression('/\b' . $offered . '\b/', $description, $offered . ' must be offered');
            self::assertMatchesRegularExpression('/\b' . $offered . '\b/', $spec->description, $offered . ' must be offered');
        }

        foreach (['shortcut', 'div', 'html', 'list', 'uploads', 'menu_pages', self::FLEX_TYPE, self::INLINE_TYPE] as $excluded) {
            self::assertDoesNotMatchRegularExpression('/\b' . $excluded . '\b/', $description, $excluded . ' must not be offered');
        }

        $fields = $properties['fields'] ?? null;
        self::assertIsArray($fields);
        self::assertSame('object', $fields['type'] ?? null);
        self::assertSame(['type' => ['string', 'boolean', 'number']], $fields['additionalProperties'] ?? null);
        $fieldsDescription = $fields['description'] ?? null;
        self::assertIsString($fieldsDescription);
        self::assertStringContainsString('TCA', $fieldsDescription);
    }

    #[Test]
    public function aScalarOnlyTypeIsCreatedWithItsFields(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            [
                'page'   => self::PAGE,
                'type'   => self::SCALAR_TYPE,
                'header' => 'Scalar draft',
                'fields' => [
                    // select, validated against the static items (0, 1, 2)
                    'bullets_type'  => 2,
                    // check, as a boolean
                    'sectionIndex'  => true,
                    // input, bounded by the TCA `max`
                    'table_caption' => ' A caption ',
                    // number with a TCA range
                    'imagewidth'    => 320,
                    // datetime, format `date`
                    'date'          => '2026-09-21',
                ],
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Created hidden ' . self::SCALAR_TYPE . ' element', $result->content);
        self::assertStringContainsString('bullets_type, sectionIndex, table_caption, imagewidth, date', $result->content);

        $row = $this->createdElement();
        self::assertSame(1, (int)($row['hidden'] ?? 0), 'a drafted element must never be visible');
        self::assertSame(self::SCALAR_TYPE, $row['CType'] ?? null);
        self::assertSame(2, (int)($row['bullets_type'] ?? -1));
        self::assertSame(1, (int)($row['sectionIndex'] ?? -1));
        self::assertSame('A caption', $row['table_caption'] ?? null);
        self::assertSame(320, (int)($row['imagewidth'] ?? -1));
        self::assertGreaterThan(0, (int)($row['date'] ?? 0), 'the date must have landed');
    }

    #[Test]
    public function textmediaIsStillCreatedWithHeaderAndBodyOnly(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'textmedia', 'header' => 'Media later', 'bodytext' => '<p>Text first.</p>'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);

        $row = $this->createdElement();
        self::assertSame('textmedia', $row['CType'] ?? null);
        self::assertSame(1, (int)($row['hidden'] ?? 0));
        self::assertSame(0, (int)($row['assets'] ?? -1), 'no media is attached by this tool');
    }

    #[Test]
    public function aTypeWithAFlexFormColumnIsRefused(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::FLEX_TYPE, 'header' => 'x'],
            'is not a content type this tool creates',
        );
    }

    #[Test]
    public function aTypeWithAnInlineColumnIsRefused(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::INLINE_TYPE, 'header' => 'x'],
            'is not a content type this tool creates',
        );
    }

    /**
     * Both carry a scalar-only showitem in this instance — `html` from core,
     * `list` from this test — and both stay out of reach: the deny-list is
     * checked before the showitem is read.
     */
    #[Test]
    public function listAndHtmlAreRefusedAlthoughTheirShowitemIsScalar(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => 'list', 'header' => 'x'],
            'is not a content type this tool creates',
        );
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => 'html', 'header' => 'x', 'bodytext' => '<script></script>'],
            'is not a content type this tool creates',
        );
    }

    #[Test]
    public function aSelectValueOutsideTheItemsIsRefusedAndNothingIsWritten(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['bullets_type' => 7]],
            'the value for "bullets_type" must be one of: "0", "1", "2"',
        );
    }

    #[Test]
    public function aNumberOutsideTheTcaRangeIsRefused(): void
    {
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['imagewidth' => 0]],
            'the value for "imagewidth" must be at least 1',
        );
    }

    #[Test]
    public function aColumnNotInTheTypesShowitemIsRefused(): void
    {
        // `table_class` is a scalar tt_content column, but the scalar test type
        // does not show it; the refusal names the columns the type does offer.
        $this->assertRefusedAndNothingCreated(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['table_class' => 'striped']],
            '"table_class" is not a scalar column of content type "' . self::SCALAR_TYPE . '"',
        );
    }

    #[Test]
    public function aColumnOnTheFieldDenyListIsRefused(): void
    {
        foreach (['hidden' => 0, 'fe_group' => '-2', 'colPos' => 1, 'header' => 'x'] as $column => $value) {
            $this->assertRefusedAndNothingCreated(
                ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => [$column => $value]],
                sprintf('"%s" cannot be set through "fields"', $column),
            );
        }
    }

    /**
     * `bullets_type` is an `exclude` field. The DataHandler would drop it in
     * silence and create the element without it; the grant is asked before the
     * write and the whole call is refused instead.
     */
    #[Test]
    public function aMissingExcludeFieldGrantRefusesTheWholeCall(): void
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:' . self::SCALAR_TYPE;
        // `hidden` granted, `bullets_type` deliberately not.
        $editor->groupData['non_exclude_fields'] = 'tt_content:hidden';

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['bullets_type' => 1]],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('no field-level ("exclude field") grant for tt_content:bullets_type', $result->content);
        self::assertSame(0, $this->elementCount(), 'nothing may have been created');
    }

    #[Test]
    public function theEditorCreatesWithTheFieldsTheyAreGranted(): void
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:' . self::SCALAR_TYPE;
        $editor->groupData['non_exclude_fields'] = 'tt_content:hidden,tt_content:bullets_type';

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::SCALAR_TYPE, 'header' => 'x', 'fields' => ['bullets_type' => 1]],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(1, (int)($this->createdElement()['bullets_type'] ?? -1));
    }

    /**
     * The backstop behind the grant check. `checkValueForNumber()` returns no
     * value for a `format` it does not know, without an `errorLog` entry, so
     * the element comes into being without the column. The read-back reports
     * the column and the element is taken back — an approver agreed to the
     * whole draft.
     */
    #[Test]
    public function aColumnTheDataHandlerDroppedIsReportedAndTheElementIsDeletedAgain(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => self::DROPPING_TYPE, 'header' => 'x', 'fields' => ['imagewidth' => 320]],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('imagewidth did not carry the value asked for', $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertSame(0, $this->undeletedElementCount(), 'nothing undeleted may be left behind');
    }

    #[Test]
    public function thePreviewNamesEveryFieldTheCallWouldSet(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            [
                'page'   => self::PAGE,
                'type'   => self::SCALAR_TYPE,
                'header' => 'Proposed',
                'fields' => ['bullets_type' => 2, 'table_caption' => 'Caption', 'sectionIndex' => false],
            ],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(8, $lines);
        self::assertStringContainsString('New ' . self::SCALAR_TYPE . ' element on page [2]', $lines[0]);
        self::assertSame('bullets_type: "2"', $lines[3]);
        self::assertSame('table_caption: "Caption"', $lines[4]);
        self::assertSame('sectionIndex: "0"', $lines[5]);
        self::assertStringContainsString('first in the column', $lines[6]);
        self::assertStringContainsString('hidden', $lines[7]);

        self::assertSame(0, $this->elementCount(), 'a preview must not create anything');
    }

    /**
     * Four types beside core's, added the way an installation's TCA overrides
     * add theirs. The DataHandler reads the compiled schema, so it is rebuilt
     * from the changed array (see {@see SetFileAlternativeTextToolFileMountTest}).
     */
    private function declareTestContentTypes(): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        $table = $tca['tt_content'] ?? null;
        self::assertIsArray($table);
        $columns = $table['columns'] ?? null;
        self::assertIsArray($columns);
        $cType = $columns['CType'] ?? null;
        self::assertIsArray($cType);
        $config = $cType['config'] ?? null;
        self::assertIsArray($config);
        $items = $config['items'] ?? null;
        self::assertIsArray($items);
        $types = $table['types'] ?? null;
        self::assertIsArray($types);

        foreach ([self::SCALAR_TYPE, self::FLEX_TYPE, self::INLINE_TYPE, self::DROPPING_TYPE, 'list'] as $value) {
            $items[] = ['label' => $value, 'value' => $value];
        }

        // Nothing writes this column, so it needs no database column.
        $columns['nrllm_children'] = [
            'label'  => 'Children',
            'config' => [
                'type'          => 'inline',
                'foreign_table' => 'sys_file_reference',
                'foreign_field' => 'uid_foreign',
            ],
        ];

        $types[self::SCALAR_TYPE] = [
            'showitem' => '--palette--;;headers, bodytext, bullets_type, sectionIndex, table_caption, imagewidth,'
                . ' --div--;core.form.tabs:categories, categories',
        ];
        $types[self::FLEX_TYPE] = [
            'showitem' => '--palette--;;headers, pi_flexform',
        ];
        $types[self::INLINE_TYPE] = [
            'showitem' => '--palette--;;headers, nrllm_children',
        ];
        $types[self::DROPPING_TYPE] = [
            'showitem'         => '--palette--;;headers, imagewidth',
            'columnsOverrides' => ['imagewidth' => ['config' => ['format' => 'unknown']]],
        ];
        $types['list'] = [
            'showitem' => '--palette--;;headers, bodytext',
        ];

        $config['items']   = $items;
        $cType['config']   = $config;
        $columns['CType']  = $cType;
        $table['columns']  = $columns;
        $table['types']    = $types;
        $tca['tt_content'] = $table;
        $GLOBALS['TCA']    = $tca;

        $this->getService(TcaSchemaFactory::class)->rebuild($tca);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assertRefusedAndNothingCreated(array $arguments, string $expectedFragment): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute($arguments, ToolExecutionContext::fromBackendUser($admin));

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString($expectedFragment, $result->content);
        self::assertSame(0, $this->elementCount(), 'nothing may have been created');
    }

    /**
     * The one element this tool created, asserting there is exactly one.
     *
     * @return array<string, mixed>
     */
    private function createdElement(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(1, $rows, 'exactly one element must have been created');

        return $rows[0];
    }

    private function elementCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Elements that are not flagged deleted — what an editor would still see.
     */
    private function undeletedElementCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }
}
