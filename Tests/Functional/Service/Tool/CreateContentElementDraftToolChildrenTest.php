<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedDestination;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Fixtures\BlankChildSubheaderHook;
use Netresearch\NrLlm\Tests\Functional\Fixtures\DetachChildHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * `create_content_element_draft` drafts a content element with the records of
 * its inline columns, writes its FlexForm's defaults and leaves folder and
 * record references empty (ADR-324, spec 064).
 *
 * The content types come from the fixture extension
 * `nrllm_content_children_fixture`, shaped like bootstrap_package's accordion,
 * card group and carousel, and every write goes through the real DataHandler.
 */
#[CoversClass(CreateContentElementDraftTool::class)]
final class CreateContentElementDraftToolChildrenTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_content_children_fixture',
    ];

    /** A page every backend user may edit content on. */
    private const PAGE = 2;

    private const ITEM_TABLE = 'tx_childrenfixture_item';

    /** A draft workspace the admin may write in. */
    private const DRAFT = 1;

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
            'uid'           => self::PAGE, 'pid' => 0, 'title' => 'Open', 'doktype' => 1, 'slug' => '/open',
            'sorting'       => 1,
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => (string) self::PAGE,
        ]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2',
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new CreateContentElementDraftTool($this->connectionPool, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theSpecOffersTheTypesWithChildrenAndTheirShape(): void
    {
        $spec       = $this->tool->getSpec();
        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        $typeProperty = $properties['type'] ?? null;
        self::assertIsArray($typeProperty);
        $types = $typeProperty['description'] ?? null;
        self::assertIsString($types);

        foreach (['nrllm_items', 'nrllm_slides', 'nrllm_folder'] as $offered) {
            self::assertMatchesRegularExpression('/\b' . $offered . '\b/', $types, $offered . ' must be offered');
        }

        foreach (['nrllm_mm', 'nrllm_reqflex', 'nrllm_reqgroup'] as $excluded) {
            self::assertDoesNotMatchRegularExpression('/\b' . $excluded . '\b/', $types, $excluded . ' must not be offered');
        }

        self::assertStringContainsString(
            'nrllm_items: children.tx_childrenfixture_items[] = {header*, subheader, bodytext, link, link_title}',
            $spec->description,
        );
        self::assertStringContainsString('nrllm_slides: children.tx_childrenfixture_slides[] = {header*}', $spec->description);
        self::assertIsArray($properties['children'] ?? null);
    }

    #[Test]
    public function anElementIsCreatedWithItsChildrenInOrderAndItsFlexFormDefaults(): void
    {
        $result = $this->tool->execute(
            [
                'page'     => self::PAGE,
                'type'     => 'nrllm_items',
                'header'   => 'FAQ',
                'children' => [
                    'tx_childrenfixture_items' => [
                        ['header' => 'First question', 'bodytext' => '<p>First answer.</p>'],
                        [
                            'header'     => 'Second question',
                            'subheader'  => 'Sub',
                            'link'       => 'https://example.org/more',
                            'link_title' => 'Read more',
                        ],
                    ],
                ],
            ],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('2 record(s) in tx_childrenfixture_items', $result->content);

        $element = $this->onlyRow('tt_content');
        self::assertSame(1, (int) $element['hidden']);
        self::assertSame('nrllm_items', $element['CType']);
        self::assertSame(2, (int) $element['tx_childrenfixture_items'], 'the inline column counts the children');
        $flexForm = $element['pi_flexform'];
        self::assertIsString($flexForm);
        self::assertStringContainsString('<field index="columns">', $flexForm);
        self::assertStringContainsString('<value index="vDEF">2</value>', $flexForm);
        self::assertStringNotContainsString('index="opened"', $flexForm, 'a field without default stays out');

        $items = $this->rows(self::ITEM_TABLE);
        self::assertCount(2, $items);
        self::assertSame(['First question', 'Second question'], array_column($items, 'header'));
        foreach ($items as $item) {
            self::assertSame((int) $element['uid'], (int) $item['parent_content'], 'each child points to the new element');
            self::assertSame(self::PAGE, (int) $item['pid']);
            self::assertSame(0, (int) $item['hidden'], 'a child is visible; its parent is the hidden draft');
            self::assertSame(0, (int) $item['sys_language_uid']);
        }

        self::assertLessThan((int) $items[1]['sorting'], (int) $items[0]['sorting'], 'the children keep the order given');
        self::assertSame('https://example.org/more', $items[1]['link']);
        self::assertSame('Read more', $items[1]['link_title']);
    }

    /**
     * In a draft workspace the element and its children are new there, and
     * live stays as it was (ADR-264, ADR-324).
     */
    #[Test]
    public function inADraftWorkspaceTheElementAndItsChildrenAreNewThere(): void
    {
        $admin = $this->setUpBackendUser(1);
        self::assertTrue($admin->setTemporaryWorkspace(self::DRAFT));

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => 'Drafted', 'children' => ['tx_childrenfixture_items' => [['header' => 'One'], ['header' => 'Two']]]],
            ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true, [], [], self::DRAFT), $admin),
        );

        self::assertFalse($result->isError, $result->content);
        $element = $this->onlyRow('tt_content');
        self::assertSame(self::DRAFT, (int) $element['t3ver_wsid']);
        self::assertSame(1, (int) $element['t3ver_state'], 'new in the workspace');

        $items = $this->rows(self::ITEM_TABLE);
        self::assertSame(['One', 'Two'], array_column($items, 'header'));
        foreach ($items as $item) {
            self::assertSame(self::DRAFT, (int) $item['t3ver_wsid'], 'a child is new in the workspace, not live');
            self::assertSame(1, (int) $item['t3ver_state']);
            self::assertSame((int) $element['uid'], (int) $item['parent_content']);
        }
    }

    /**
     * Each element saved with children leaves the lowest sort value of the
     * child table on the page (RelationHandler numbers a parent's children
     * from 1), so the next element's children find no room at the top. Their
     * order is the parent's, which the relation writes anyway: that is no
     * reason to refuse the draft.
     */
    #[Test]
    public function inADraftWorkspaceASecondElementWithChildrenOnThePageIsCreated(): void
    {
        $admin = $this->setUpBackendUser(1);
        self::assertTrue($admin->setTemporaryWorkspace(self::DRAFT));
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true, [], [], self::DRAFT), $admin);

        foreach (['First', 'Second'] as $header) {
            $result = $this->tool->execute(
                ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => $header, 'children' => ['tx_childrenfixture_items' => [['header' => $header . ' A'], ['header' => $header . ' B']]]],
                $context,
            );
            self::assertFalse($result->isError, $result->content);
        }

        $byParent = [];
        foreach ($this->rows('tt_content') as $element) {
            $children = array_values(array_filter($this->rows(self::ITEM_TABLE), static fn (array $row): bool => (int) $row['parent_content'] === (int) $element['uid']));
            usort($children, static fn (array $a, array $b): int => (int) $a['sorting'] <=> (int) $b['sorting']);
            $byParent[$this->toText($element['header'])] = array_map(static fn (array $row): array => [$row['header'], (int) $row['sorting']], $children);
        }

        // The relation numbers each parent's children from 1; the first
        // element's children keep theirs.
        self::assertSame([['First A', 1], ['First B', 2]], $byParent['First'] ?? null);
        self::assertSame([['Second A', 1], ['Second B', 2]], $byParent['Second'] ?? null);
    }

    #[Test]
    public function inADraftWorkspaceAChildThatDidNotTakeTakesEverythingBack(): void
    {
        $this->registerBlankingHook();
        $admin = $this->setUpBackendUser(1);
        self::assertTrue($admin->setTemporaryWorkspace(self::DRAFT));

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => 'Drafted', 'children' => ['tx_childrenfixture_items' => [['header' => 'One', 'subheader' => 'lost']]]],
            ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true, [], [], self::DRAFT), $admin),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('so it was deleted again', $result->content);
        self::assertSame([], array_filter($this->rows('tt_content'), static fn (array $row): bool => (int) $row['deleted'] === 0));
        self::assertSame([], array_filter($this->rows(self::ITEM_TABLE), static fn (array $row): bool => (int) $row['deleted'] === 0));
    }

    #[Test]
    public function childrenOfAnElementPlacedAfterAnotherLandOnThePage(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 50, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Anchor', 'sorting' => 256,
        ]);

        $result = $this->tool->execute(
            [
                'page'              => self::PAGE,
                'type'              => 'nrllm_items',
                'header'            => 'After',
                'after_content_uid' => 50,
                'children'          => ['tx_childrenfixture_items' => [['header' => 'Only']]],
            ],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        $item = $this->onlyRow(self::ITEM_TABLE);
        self::assertSame(self::PAGE, (int) $item['pid']);
    }

    #[Test]
    public function aTypeWithAFolderReferenceIsCreatedWithTheReferenceEmpty(): void
    {
        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_folder', 'header' => 'Downloads', 'bodytext' => '<p>Files.</p>'],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertEmpty($this->onlyRow('tt_content')['tx_childrenfixture_folder'] ?? null, 'the folder reference stays empty');
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function refusedChildren(): iterable
    {
        yield 'an unknown child-record column' => [
            ['tx_childrenfixture_slides' => [['header' => 'x']]],
            '"tx_childrenfixture_slides" is not a child-record column of content type "nrllm_items". Child-record columns: tx_childrenfixture_items.',
        ];
        yield 'an unknown column of a record' => [
            ['tx_childrenfixture_items' => [['header' => 'x', 'icon' => 'star']]],
            '"icon" is not a column of the records in "children.tx_childrenfixture_items". Columns: header, subheader, bodytext, link, link_title.',
        ];
        yield 'a missing required column' => [
            ['tx_childrenfixture_items' => [['subheader' => 'no header']]],
            '"children.tx_childrenfixture_items[0]": "header" is required.',
        ];
        yield 'more records than the column takes' => [
            ['tx_childrenfixture_items' => [['header' => 'a'], ['header' => 'b'], ['header' => 'c'], ['header' => 'd']]],
            '"children.tx_childrenfixture_items" takes at most 3 records.',
        ];
        yield 'a link scheme a draft may not set' => [
            ['tx_childrenfixture_items' => [['header' => 'x', 'link' => 'javascript:alert(1)']]],
            'children.tx_childrenfixture_items[0]: Refused: the value for "link" must be an http or https URL',
        ];
        yield 'not a list of records' => [
            ['tx_childrenfixture_items' => ['header' => 'x']],
            '"children.tx_childrenfixture_items" must be a list of records.',
        ];
        yield 'children as a list' => [
            [['header' => 'x']],
            '"children" must be an object of child-record columns to lists of records.',
        ];
    }

    /**
     * @param array<array-key, mixed> $children
     */
    #[Test]
    #[DataProvider('refusedChildren')]
    public function wrongChildrenAreRefusedAndNothingIsCreated(array $children, string $expected): void
    {
        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => 'x', 'children' => $children],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString($expected, $result->content);
        self::assertSame(0, $this->rowCount('tt_content'));
        self::assertSame(0, $this->rowCount(self::ITEM_TABLE));
    }

    #[Test]
    public function anEditorWithoutTheChildTableIsRefusedBeforeTheWrite(): void
    {
        $editor = $this->editorFor('nrllm_items', 'tx_childrenfixture_item:header');

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => 'x', 'children' => ['tx_childrenfixture_items' => [['header' => 'y']]]],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('may not create records in tx_childrenfixture_item (no "tables_modify" grant)', $result->content);
        self::assertSame(0, $this->rowCount('tt_content'));
    }

    #[Test]
    public function anEditorWithoutTheGrantOfAChildColumnIsRefusedBeforeTheWrite(): void
    {
        $editor                             = $this->editorFor('nrllm_items');
        $editor->groupData['tables_modify'] = 'tt_content,tx_childrenfixture_item';

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => 'x', 'children' => ['tx_childrenfixture_items' => [['header' => 'y', 'subheader' => 'z']]]],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        // `subheader` carries no `exclude`, so only `header` is named.
        self::assertStringContainsString('grant for tx_childrenfixture_item:header. Nothing was written.', $result->content);
        self::assertSame(0, $this->rowCount('tt_content'));
    }

    #[Test]
    public function anEditorWithTheGrantsCreatesTheElementWithItsChildren(): void
    {
        $editor                             = $this->editorFor('nrllm_items', 'tx_childrenfixture_item:header');
        $editor->groupData['tables_modify'] = 'tt_content,tx_childrenfixture_item';

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => 'x', 'children' => ['tx_childrenfixture_items' => [['header' => 'y', 'subheader' => 'z']]]],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('y', $this->onlyRow(self::ITEM_TABLE)['header']);
    }

    #[Test]
    public function aChildTypeTheEditorIsNotAllowedRefusesTheCall(): void
    {
        $editor                             = $this->editorFor('nrllm_slides');
        $editor->groupData['tables_modify'] = 'tt_content,tx_childrenfixture_slide';

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_slides', 'header' => 'x', 'children' => ['tx_childrenfixture_slides' => [['header' => 'y']]]],
            ToolExecutionContext::fromBackendUser($editor),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('not allowed the record type "text" of tx_childrenfixture_slide', $result->content);
        self::assertSame(0, $this->rowCount('tt_content'));
    }

    #[Test]
    public function aChildOfATypedTableGetsTheDefaultType(): void
    {
        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_slides', 'header' => 'x', 'children' => ['tx_childrenfixture_slides' => [['header' => 'y']]]],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('text', $this->onlyRow('tx_childrenfixture_slide')['kind']);
    }

    #[Test]
    public function pageTsConfigThatHidesAChildColumnRefusesTheCall(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'TCEFORM.tx_childrenfixture_item.subheader.disabled = 1'],
            ['uid' => self::PAGE],
        );

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => 'x', 'children' => ['tx_childrenfixture_items' => [['header' => 'y', 'subheader' => 'z']]]],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('"tx_childrenfixture_item:subheader" is not editable on page [2]', $result->content);
        self::assertSame(0, $this->rowCount('tt_content'));
    }

    /**
     * A child value a foreign hook changes in silence takes the element back
     * with its children: the approver agreed to the whole draft (ADR-324).
     */
    #[Test]
    public function aChildValueThatDidNotTakeTakesTheElementBack(): void
    {
        $this->registerBlankingHook();

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => 'x', 'children' => ['tx_childrenfixture_items' => [['header' => 'y', 'subheader' => 'z']]]],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('tx_childrenfixture_item:subheader did not carry the value asked for, so it was deleted again', $result->content);
        self::assertSame(1, (int) $this->onlyRow('tt_content')['deleted'], 'the element is taken back');
        self::assertSame([], array_filter($this->rows(self::ITEM_TABLE), static fn (array $row): bool => (int) $row['deleted'] === 0));
    }

    #[Test]
    public function aChildAHookDetachedIsTakenBackWithTheElement(): void
    {
        /** @var array{SC_OPTIONS: array<string, array<string, list<string>>>} $confVars */
        $confVars                                                                         = &$GLOBALS['TYPO3_CONF_VARS'];
        $confVars['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = DetachChildHook::class;
        unset($confVars);

        $result = $this->tool->execute(
            ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => 'FAQ', 'children' => ['tx_childrenfixture_items' => [['header' => 'One'], ['header' => 'Two']]]],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertTrue($result->isError, $result->content);
        self::assertStringContainsString('was deleted again', $result->content);
        self::assertSame([], array_filter($this->rows(self::ITEM_TABLE), static fn (array $row): bool => (int) $row['deleted'] === 0), 'no detached child is left');
    }

    #[Test]
    public function theApprovalCardListsEveryChildWithItsValues(): void
    {
        $lines = $this->tool->previewCall(
            [
                'page'     => self::PAGE,
                'type'     => 'nrllm_items',
                'header'   => 'FAQ',
                'children' => ['tx_childrenfixture_items' => [['header' => 'Q1', 'link' => 't3://page?uid=2'], ['header' => 'Q2']]],
            ],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        $card = implode("\n", $lines);
        self::assertStringContainsString('Items 1: Header: “Q1”; Link: “t3://page?uid=2”', $card);
        self::assertStringContainsString('Items 2: Header: “Q2”', $card);
    }

    #[Test]
    public function inADraftWorkspaceTheCardNamesTheChildTableThatKeepsNoDraft(): void
    {
        /** @var array{tx_childrenfixture_item: array{ctrl: array<string, mixed>}} $tca */
        $tca                                                    = &$GLOBALS['TCA'];
        $tca['tx_childrenfixture_item']['ctrl']['versioningWS'] = false;
        unset($tca);
        $admin = $this->setUpBackendUser(1);
        self::assertTrue($admin->setTemporaryWorkspace(self::DRAFT));

        $card = implode("\n", $this->tool->previewCall(
            ['page' => self::PAGE, 'type' => 'nrllm_items', 'header' => 'FAQ', 'children' => ['tx_childrenfixture_items' => [['header' => 'Q1']]]],
            ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true, [], [], self::DRAFT), $admin),
        ));

        self::assertStringContainsString('Fixture item', $card);
    }

    #[Test]
    public function theScopedWriteNamesTheChildTable(): void
    {
        $scoped = $this->tool->scopedWrite([
            'page'     => self::PAGE,
            'type'     => 'nrllm_items',
            'header'   => 'x',
            'children' => ['tx_childrenfixture_items' => [['header' => 'y']]],
        ]);

        self::assertNotNull($scoped);
        self::assertSame(['tt_content', self::ITEM_TABLE], array_map(static fn (ScopedDestination $destination): string => $destination->table, $scoped->destinations));
    }

    private function toText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function registerBlankingHook(): void
    {
        /** @var array{SC_OPTIONS: array<string, array<string, list<string>>>} $confVars */
        $confVars                                                                         = &$GLOBALS['TYPO3_CONF_VARS'];
        $confVars['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = BlankChildSubheaderHook::class;
        unset($confVars);
    }

    /**
     * A non-admin who may create the given type and hide it.
     *
     * @param non-empty-string ...$furtherGrants further `non_exclude_fields` entries
     */
    private function editorFor(string $type, string ...$furtherGrants): BackendUserAuthentication
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:' . $type;
        $editor->groupData['non_exclude_fields'] = implode(',', ['tt_content:hidden', ...$furtherGrants]);

        return $editor;
    }

    /**
     * Every row of a table, deleted ones included, in creation order.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return array_values($queryBuilder->select('*')->from($table)->orderBy('uid')->executeQuery()->fetchAllAssociative());
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyRow(string $table): array
    {
        $rows = $this->rows($table);
        self::assertCount(1, $rows, 'exactly one row must exist in ' . $table);

        return $rows[0];
    }

    private function rowCount(string $table): int
    {
        return count($this->rows($table));
    }
}
