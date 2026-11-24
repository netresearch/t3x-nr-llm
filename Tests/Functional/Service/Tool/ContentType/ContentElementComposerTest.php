<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\ContentType;

use Netresearch\NrLlm\Service\Tool\ContentType\ContentElementComposer;
use Netresearch\NrLlm\Service\Tool\ContentType\ContentElementDataMap;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Fixtures\ContentChildren\IconItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The public face of the content-type rule (ADR-324, spec 064 CC11): what
 * nr_landingpage's Page Composer reads and writes through.
 */
#[CoversClass(ContentElementComposer::class)]
#[CoversClass(ContentElementDataMap::class)]
final class ContentElementComposerTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_content_children_fixture',
    ];

    private const PAGE = 2;

    private ContentElementComposer $composer;

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
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
            'TSconfig'      => 'TCEFORM.tt_content.CType.removeItems = nrllm_slides',
        ]);
        $groups = $this->connectionPool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => (string) self::PAGE]);
        $groups->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        // Private and injected into consumers; nothing in nr_llm injects it,
        // so the container drops it, and the test builds it as a consumer's
        // container would.
        $this->composer = new ContentElementComposer();
    }

    #[Test]
    public function aTypeIsDescribedWithItsFieldsAndChildren(): void
    {
        $described = $this->composer->describe('nrllm_items');

        self::assertNotNull($described);
        self::assertSame('nrllm_items', $described['type']);
        self::assertSame('Fixture type nrllm_items', $described['description']);
        $items = $described['children']['tx_childrenfixture_items'] ?? null;
        self::assertIsArray($items);
        self::assertSame('tx_childrenfixture_item', $items['table']);
        self::assertSame(3, $items['maxItems']);
        self::assertSame(['header', 'subheader', 'bodytext', 'link', 'link_title', 'icon_set', 'icon'], array_keys($items['fields']), 'a column whose processor fails is left out');
        self::assertSame(['Alpha', 'Beta'], $items['fields']['icon_set']['items'] ?? null);
        $icons = $items['fields']['icon']['itemsBy'] ?? null;
        self::assertIsArray($icons);
        self::assertSame('icon_set', $icons['column'] ?? null);
        self::assertIsArray($icons['items'] ?? null);
        self::assertCount(61, $icons['items']['Alpha'] ?? []);
        self::assertContains('home', $icons['items']['Beta'] ?? []);
        self::assertContains('EXT:fixture/Icons/Beta/clash.svg', $icons['items']['Beta'], 'a label that is another item\'s value names nothing');
        self::assertSame(['columns', 'opened', 'settings.intro'], array_keys($described['flexForms']['pi_flexform'] ?? []));
        self::assertSame(['1', '2'], $described['flexForms']['pi_flexform']['columns']['items'] ?? null);
        self::assertTrue($items['fields']['header']['required'] ?? null);
        self::assertTrue($items['fields']['bodytext']['richText'] ?? null);
        self::assertSame('link', $items['fields']['link']['type'] ?? null);
    }

    #[Test]
    public function aTypeTheRuleExcludesIsNotDescribed(): void
    {
        self::assertNull($this->composer->describe('nrllm_mm'));
        self::assertNull($this->composer->describe('html'));
        self::assertContains('nrllm_items', $this->composer->offeredTypes());
        self::assertNotContains('nrllm_reqgroup', $this->composer->offeredTypes());
    }

    #[Test]
    public function anInlineColumnThroughAnIntermediateTableIsNoChildRelation(): void
    {
        /** @var array{tt_content: array{columns: array<string, array{config: array<string, mixed>}>}} $tca */
        $tca                                                                                    = &$GLOBALS['TCA'];
        $tca['tt_content']['columns']['tx_childrenfixture_items']['config']['foreign_selector'] = 'header';
        unset($tca);

        self::assertNull((new ContentElementComposer())->describe('nrllm_items'), 'its selector cannot be filled, so the type stays excluded');
    }

    #[Test]
    public function inADraftWorkspaceAChildTableWithoutVersioningIsRefused(): void
    {
        $admin = $this->setUpBackendUser(1);
        self::assertInstanceOf(BackendUserAuthentication::class, $admin);
        $admin->workspace = 1;
        /** @var array{tx_childrenfixture_item: array{ctrl: array<string, mixed>}} $tca */
        $tca                                                    = &$GLOBALS['TCA'];
        $tca['tx_childrenfixture_item']['ctrl']['versioningWS'] = false;
        unset($tca);

        $map = $this->composer->dataMap('nrllm_items', 'NEW1', [], ['tx_childrenfixture_items' => [['header' => 'A']]], self::PAGE, self::PAGE, 0, $admin);

        self::assertTrue($map->isRefused());
        self::assertStringContainsString('tx_childrenfixture_item has no versioning', implode(' ', $map->refusals));
    }

    #[Test]
    public function aReadOnlyInlineColumnOrOneWhoseChildrenOutliveTheElementIsNoChildRelation(): void
    {
        /** @var array{tt_content: array{columns: array<string, array{config: array<string, mixed>}>}} $tca */
        $tca                                                                            = &$GLOBALS['TCA'];
        $tca['tt_content']['columns']['tx_childrenfixture_items']['config']['readOnly'] = true;
        self::assertNull((new ContentElementComposer())->describe('nrllm_items'), 'read-only');

        unset($tca['tt_content']['columns']['tx_childrenfixture_items']['config']['readOnly']);
        $tca['tt_content']['columns']['tx_childrenfixture_items']['config']['behaviour'] = ['enableCascadingDelete' => false];
        self::assertNull((new ContentElementComposer())->describe('nrllm_items'), 'a take-back would leave the children behind');

        unset($tca['tt_content']['columns']['tx_childrenfixture_items']['config']['behaviour'], $tca);
        self::assertNotNull((new ContentElementComposer())->describe('nrllm_items'));
    }

    #[Test]
    public function aRecordReferenceWithADefaultExcludesItsType(): void
    {
        /** @var array{tt_content: array{columns: array<string, array{config: array<string, mixed>}>, types: array<string, array{showitem: string}>}} $tca */
        $tca                                      = &$GLOBALS['TCA'];
        $reference                                = 'tx_childrenfixture_reference';
        $tca['tt_content']['columns'][$reference] = ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'foreign_table' => 'pages', 'default' => 12]];
        $tca['tt_content']['types']['nrllm_items']['showitem'] .= ', ' . $reference;
        unset($tca);

        self::assertNull((new ContentElementComposer())->describe('nrllm_items'), 'the draft would reference record 12');
    }

    #[Test]
    public function aChildRecordTypeTheEditorMayNotSetIsNotOffered(): void
    {
        /** @var array{tx_childrenfixture_slide: array{columns: array<string, array<string, mixed>>}} $tca */
        $tca                                                           = &$GLOBALS['TCA'];
        $tca['tx_childrenfixture_slide']['columns']['kind']['exclude'] = true;
        unset($tca);

        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content,tx_childrenfixture_slide';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:nrllm_slides,tx_childrenfixture_slide:kind:text';
        $editor->groupData['non_exclude_fields'] = 'tt_content:hidden,tx_childrenfixture_slide:header';

        $map = $this->composer->dataMap('nrllm_slides', 'NEW1', [], ['tx_childrenfixture_slides' => [['header' => 'A']]], self::PAGE, self::PAGE, 0, $editor);
        self::assertTrue($map->isRefused());
        self::assertStringContainsString('tx_childrenfixture_slide:kind', implode(' ', $map->refusals));
        self::assertSame([], $this->composer->describe('nrllm_slides', null, $editor)['children'] ?? null);
    }

    #[Test]
    public function aChildTableListedOnItsOwnIsNoChildRelation(): void
    {
        /** @var array{tx_childrenfixture_item: array{ctrl: array<string, mixed>}} $tca */
        $tca                                                 = &$GLOBALS['TCA'];
        $tca['tx_childrenfixture_item']['ctrl']['hideTable'] = false;
        unset($tca);

        // The element is created hidden, its children are not: a table the
        // list module and the frontend reach on its own would show them.
        self::assertNull((new ContentElementComposer())->describe('nrllm_items'));
    }

    #[Test]
    public function aRelationNoDraftCanSatisfyIsNoChildRelation(): void
    {
        /** @var array{tt_content: array{columns: array<string, array{config: array<string, mixed>}>}} $tca */
        $tca                                                                                   = &$GLOBALS['TCA'];
        $tca['tt_content']['columns']['tx_childrenfixture_items']['config']['symmetric_field'] = 'header';
        self::assertNull((new ContentElementComposer())->describe('nrllm_items'), 'symmetric');

        unset($tca['tt_content']['columns']['tx_childrenfixture_items']['config']['symmetric_field']);
        $tca['tt_content']['columns']['tx_childrenfixture_items']['config']['minitems'] = 51;
        $tca['tt_content']['columns']['tx_childrenfixture_items']['config']['maxitems'] = 100;
        self::assertNull((new ContentElementComposer())->describe('nrllm_items'), 'at least 51 records, at most 50 written');
        unset($tca);
    }

    #[Test]
    public function theRelationsSortColumnIsNoChildField(): void
    {
        /** @var array{tt_content: array{columns: array<string, array{config: array<string, mixed>}>}} $tca */
        $tca                                                                                  = &$GLOBALS['TCA'];
        $tca['tt_content']['columns']['tx_childrenfixture_items']['config']['foreign_sortby'] = 'subheader';
        unset($tca);

        $fields = (new ContentElementComposer())->describe('nrllm_items')['children']['tx_childrenfixture_items']['fields'] ?? null;
        self::assertIsArray($fields);
        self::assertArrayNotHasKey('subheader', $fields);
        self::assertArrayHasKey('header', $fields);
    }

    #[Test]
    public function inADraftWorkspaceAChildTableWithoutVersioningIsNotOffered(): void
    {
        $admin = $this->setUpBackendUser(1);
        self::assertInstanceOf(BackendUserAuthentication::class, $admin);
        $admin->workspace = 1;
        /** @var array{tx_childrenfixture_item: array{ctrl: array<string, mixed>}} $tca */
        $tca                                                    = &$GLOBALS['TCA'];
        $tca['tx_childrenfixture_item']['ctrl']['versioningWS'] = false;
        unset($tca);

        self::assertSame([], $this->composer->describe('nrllm_items', null, $admin)['children'] ?? null);
    }

    #[Test]
    public function aTelephoneLinkWithASpaceIsRefused(): void
    {
        $admin = $this->setUpBackendUser(1);
        self::assertInstanceOf(BackendUserAuthentication::class, $admin);

        $map = $this->composer->dataMap('nrllm_items', 'NEW1', [], ['tx_childrenfixture_items' => [['header' => 'A', 'link' => 'tel:+1 2025550123']]], self::PAGE, self::PAGE, 0, $admin);
        self::assertTrue($map->isRefused());
        self::assertStringContainsString('no spaces', implode(' ', $map->refusals));

        self::assertFalse($this->composer->dataMap('nrllm_items', 'NEW1', [], ['tx_childrenfixture_items' => [['header' => 'A', 'link' => 'tel:+12025550123']]], self::PAGE, self::PAGE, 0, $admin)->isRefused());
    }

    #[Test]
    public function anInlineColumnThePageDisablesTakesNoChildren(): void
    {
        $this->setPageTsConfig('TCEFORM.tt_content.tx_childrenfixture_items.disabled = 1');
        $admin = $this->setUpBackendUser(1);
        self::assertInstanceOf(BackendUserAuthentication::class, $admin);

        $map = $this->composer->dataMap('nrllm_items', 'NEW1', [], ['tx_childrenfixture_items' => [['header' => 'A']]], self::PAGE, self::PAGE, 0, $admin);

        self::assertTrue($map->isRefused());
        self::assertStringContainsString('tt_content:tx_childrenfixture_items', implode(' ', $map->refusals));
    }

    #[Test]
    public function anInlineColumnThePageMakesReadOnlyTakesNoChildren(): void
    {
        $this->setPageTsConfig('TCEFORM.tt_content.tx_childrenfixture_items.config.readOnly = 1');
        $admin = $this->setUpBackendUser(1);
        self::assertInstanceOf(BackendUserAuthentication::class, $admin);

        $map = $this->composer->dataMap('nrllm_items', 'NEW1', [], ['tx_childrenfixture_items' => [['header' => 'A']]], self::PAGE, self::PAGE, 0, $admin);

        self::assertTrue($map->isRefused());
        self::assertStringContainsString('tt_content:tx_childrenfixture_items', implode(' ', $map->refusals));
    }

    #[Test]
    public function aChildRecordTypeThePageRemovesIsRefused(): void
    {
        $this->setPageTsConfig('TCEFORM.tx_childrenfixture_slide.kind.removeItems = text');
        $admin = $this->setUpBackendUser(1);
        self::assertInstanceOf(BackendUserAuthentication::class, $admin);

        $map = $this->composer->dataMap('nrllm_slides', 'NEW1', [], ['tx_childrenfixture_slides' => [['header' => 'A']]], self::PAGE, self::PAGE, 0, $admin);

        self::assertTrue($map->isRefused());
        self::assertStringContainsString('record type "text" of tx_childrenfixture_slide', implode(' ', $map->refusals));
    }

    #[Test]
    public function aFlexFormTheEditorMayNotSetIsLeftToTheDataHandler(): void
    {
        /** @var array{tt_content: array{columns: array<string, array<string, mixed>>}} $tca */
        $tca                                                    = &$GLOBALS['TCA'];
        $tca['tt_content']['columns']['pi_flexform']['exclude'] = true;
        unset($tca);

        $children = ['tx_childrenfixture_items' => [['header' => 'A']]];
        $without  = $this->composer->dataMap('nrllm_items', 'NEW1', [], $children, self::PAGE, self::PAGE, 0, $this->editor('tx_childrenfixture_item:header'));
        $with     = $this->composer->dataMap('nrllm_items', 'NEW1', [], $children, self::PAGE, self::PAGE, 0, $this->editor('tx_childrenfixture_item:header', 'tt_content:pi_flexform'));

        self::assertFalse($without->isRefused(), implode(' ', $without->refusals));
        self::assertArrayNotHasKey('pi_flexform', $without->dataMap['tt_content']['NEW1'] ?? []);
        self::assertArrayHasKey('pi_flexform', $with->dataMap['tt_content']['NEW1'] ?? []);
    }

    #[Test]
    public function describedForAnEditorItLeavesOutWhatTheEditorMayNotSet(): void
    {
        $editor = $this->editor('tx_childrenfixture_item:header');

        $described = $this->composer->describe('nrllm_items', null, $editor);

        self::assertNotNull($described);
        self::assertSame(['header', 'subheader', 'icon_set'], array_keys($described['children']['tx_childrenfixture_items']['fields'] ?? []));

        $withoutTable                             = $this->editor('tx_childrenfixture_item:header');
        $withoutTable->groupData['tables_modify'] = 'tt_content';
        self::assertSame([], $this->composer->describe('nrllm_items', null, $withoutTable)['children'] ?? null);
    }

    #[Test]
    public function thePageAndTheUserNarrowTheTypes(): void
    {
        $admin = $this->setUpBackendUser(1);
        self::assertSame(['text', 'nrllm_items', 'html'], $this->composer->typesOfferedOnPage(self::PAGE, $admin, ['text', 'nrllm_items', 'nrllm_slides', 'html']));
        self::assertSame(['text', 'nrllm_items', 'nrllm_slides'], $this->composer->typesOfferedOnPage(0, $admin, ['text', 'nrllm_items', 'nrllm_slides']), 'no page, no TSconfig');

        $editor = $this->editor();
        self::assertSame(['text'], $this->composer->typesOfferedOnPage(0, $editor, ['text', 'nrllm_items']), 'an editor without the item table');
    }

    #[Test]
    public function theDataMapOfAnElementWithChildrenIsWrittenByTheCaller(): void
    {
        $admin = $this->setUpBackendUser(1);
        $map   = $this->composer->dataMap(
            'nrllm_items',
            'NEW_content_0',
            [],
            ['tx_childrenfixture_items' => [['header' => 'A'], ['header' => 'B', 'link' => 'mailto:info@example.org']]],
            'NEW_page',
            self::PAGE,
            0,
            $admin,
        );

        self::assertFalse($map->isRefused(), implode(' ', $map->refusals));
        $dataMap                                          = $map->dataMap;
        $dataMap['pages']['NEW_page']                     = ['pid' => self::PAGE, 'title' => 'Landing', 'doktype' => 1];
        $dataMap['tt_content']['NEW_content_0']['CType']  = 'nrllm_items';
        $dataMap['tt_content']['NEW_content_0']['header'] = 'FAQ';
        $dataMap['tt_content']['NEW_content_0']['pid']    = 'NEW_page';

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, [], $admin);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);

        $pageUid    = (int) $dataHandler->substNEWwithIDs['NEW_page'];
        $elementUid = (int) $dataHandler->substNEWwithIDs['NEW_content_0'];
        $items      = $this->connectionPool->getConnectionForTable('tx_childrenfixture_item')
            ->select(['header', 'pid', 'parent_content', 'link'], 'tx_childrenfixture_item', [], [], ['sorting' => 'ASC'])
            ->fetchAllAssociative();
        self::assertSame(['A', 'B'], array_column($items, 'header'));
        self::assertSame([$pageUid, $pageUid], array_map(intval(...), array_column($items, 'pid')));
        self::assertSame([$elementUid, $elementUid], array_map(intval(...), array_column($items, 'parent_content')));
        self::assertSame('mailto:info@example.org', $items[1]['link']);
    }

    #[Test]
    public function choicesThatDependOnAStaticSelectAreDescribedPerChoice(): void
    {
        /** @var array{tx_childrenfixture_item: array{columns: array<string, array{config: array<string, mixed>}>}} $tca */
        $tca                                                             = &$GLOBALS['TCA'];
        $tca['tx_childrenfixture_item']['columns']['icon_set']['config'] = [
            'type'       => 'select',
            'renderType' => 'selectSingle',
            'default'    => IconItems::SET,
            'items'      => [['label' => 'Alpha', 'value' => IconItems::SET], ['label' => 'Beta', 'value' => 'EXT:fixture/Icons/Beta/']],
        ];
        unset($tca);

        $icon = ($this->composer->describe('nrllm_items')['children']['tx_childrenfixture_items']['fields'] ?? [])['icon'] ?? [];

        self::assertArrayNotHasKey('items', $icon);
        self::assertArrayNotHasKey('itemsPartial', $icon);
        $by = $icon['itemsBy'] ?? null;
        self::assertIsArray($by);
        self::assertSame('icon_set', $by['column'] ?? null);
        self::assertSame(IconItems::SET, $by['default'] ?? null);
        self::assertIsArray($by['items'] ?? null);
        self::assertContains('home', $by['items']['EXT:fixture/Icons/Beta/'] ?? []);
    }

    #[Test]
    public function choicesThatTooManyOthersDecideAreMarkedPartial(): void
    {
        /** @var array{tx_childrenfixture_item: array{columns: array<string, array{config: array<string, mixed>}>}} $tca */
        $tca                                                             = &$GLOBALS['TCA'];
        $tca['tx_childrenfixture_item']['columns']['icon_set']['config'] = [
            'type'       => 'select',
            'renderType' => 'selectSingle',
            'items'      => array_map(static fn (int $number): array => ['label' => 'S' . $number, 'value' => 's' . $number], range(1, 51)),
        ];
        unset($tca);

        $icon = ($this->composer->describe('nrllm_items')['children']['tx_childrenfixture_items']['fields'] ?? [])['icon'] ?? [];

        self::assertTrue($icon['itemsPartial'] ?? null);
    }

    #[Test]
    public function choicesThatDependOnAColumnTheEditorMayNotSetAreThoseOfItsDefault(): void
    {
        /** @var array{tx_childrenfixture_item: array{columns: array<string, array<string, mixed>>}} $tca */
        $tca                                                              = &$GLOBALS['TCA'];
        $tca['tx_childrenfixture_item']['columns']['icon_set']['exclude'] = true;
        unset($tca);

        $described = $this->composer->describe('nrllm_items', null, $this->editor('tx_childrenfixture_item:header', 'tx_childrenfixture_item:icon'));
        $fields    = $described['children']['tx_childrenfixture_items']['fields'] ?? [];

        self::assertArrayHasKey('header', $fields);
        self::assertArrayNotHasKey('icon_set', $fields);
        self::assertArrayNotHasKey('icon', $fields, 'without a default set there is no icon to choose');
    }

    #[Test]
    public function theDataMapCarriesFlexFormValuesAndIcons(): void
    {
        $admin = $this->setUpBackendUser(1);
        self::assertInstanceOf(BackendUserAuthentication::class, $admin);
        unset($GLOBALS['BE_USER']);
        $map = $this->composer->dataMap(
            'nrllm_items',
            'NEW_content',
            ['pi_flexform' => ['columns' => '1']],
            ['tx_childrenfixture_items' => [['header' => 'A', 'icon_set' => 'Beta', 'icon' => 'home']]],
            self::PAGE,
            self::PAGE,
            0,
            $admin,
        );

        self::assertSame([], $map->refusals);
        self::assertSame($admin, IconItems::$user, 'a processor sees the acting user');
        self::assertArrayNotHasKey('BE_USER', $GLOBALS, 'and the ambient one is restored');
        self::assertSame(['data' => ['sDEF' => ['lDEF' => ['columns' => ['vDEF' => '1']]]]], $map->dataMap['tt_content']['NEW_content']['pi_flexform'] ?? null);
        self::assertContains('EXT:fixture/Icons/Beta/home.svg', array_column($map->dataMap['tx_childrenfixture_item'] ?? [], 'icon'));
    }

    #[Test]
    public function aRefusedDataMapNamesTheReason(): void
    {
        $editor                             = $this->editor('tx_childrenfixture_item:header');
        $editor->groupData['tables_modify'] = 'tt_content';
        $map                                = $this->composer->dataMap('nrllm_items', 'NEW1', [], ['tx_childrenfixture_items' => [['header' => 'A']]], self::PAGE, self::PAGE, 0, $editor);

        self::assertTrue($map->isRefused());
        self::assertSame([], $map->dataMap);
        self::assertStringContainsString('no "tables_modify" grant', $map->refusals[0]);

        $unknown = $this->composer->dataMap('nrllm_mm', 'NEW1', [], [], self::PAGE, self::PAGE, 0, $this->setUpBackendUser(1));
        self::assertTrue($unknown->isRefused());
    }

    private function setPageTsConfig(string $tsConfig): void
    {
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['TSconfig' => $tsConfig], ['uid' => self::PAGE]);
    }

    private function editor(string ...$grants): BackendUserAuthentication
    {
        $editor                                  = $this->setUpBackendUser(2);
        $editor->groupData['tables_modify']      = 'tt_content,tx_childrenfixture_item';
        $editor->groupData['explicit_allowdeny'] = 'tt_content:CType:text,tt_content:CType:nrllm_items';
        $editor->groupData['non_exclude_fields'] = implode(',', ['tt_content:hidden', ...$grants]);

        return $editor;
    }
}
