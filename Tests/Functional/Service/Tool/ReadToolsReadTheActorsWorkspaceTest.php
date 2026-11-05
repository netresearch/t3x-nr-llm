<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Service\Tool\Builtin\GetFalReferencesTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetPageTreeTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetRecordHistoryTool;
use Netresearch\NrLlm\Service\Tool\Builtin\GetTsConfigTool;
use Netresearch\NrLlm\Service\Tool\Builtin\HighlightRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadFalAssetMetaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadRecordsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchFalFilesTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SearchRecordsTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The record readers read what the run's draft workspace shows, and nothing
 * of another workspace (ADR-263): `read_records`, `search_records`,
 * `get_pagetree`, the file tools, `get_record_history`, `highlight_record`
 * and `get_tsconfig`. Each record is reported under its live uid.
 */
#[CoversClass(ReadRecordsTool::class)]
#[CoversClass(SearchRecordsTool::class)]
#[CoversClass(GetPageTreeTool::class)]
#[CoversClass(ReadFalAssetMetaTool::class)]
#[CoversClass(SearchFalFilesTool::class)]
#[CoversClass(GetFalReferencesTool::class)]
#[CoversClass(GetRecordHistoryTool::class)]
#[CoversClass(HighlightRecordTool::class)]
#[CoversClass(GetTsConfigTool::class)]
final class ReadToolsReadTheActorsWorkspaceTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'seo', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private const ELEMENT = 10;

    private const ELEMENT_WITH_DRAFT = 11;

    private const DRAFT_OF_ELEMENT = 12;

    private const NEW_IN_DRAFT = 13;

    /** A version of {@see self::ELEMENT} in the other draft workspace. */
    private const OTHER_DRAFT_OF_ELEMENT = 14;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;

        $this->importFixture('BeUsers.csv');
        $workspaces = $this->connectionPool->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Other', 'members' => '']);

        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([[1, 0, 'Root'], [2, 1, 'Second page']] as [$uid, $pid, $title]) {
            $pages->insert('pages', [
                'uid'           => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/' . $uid, 'doktype' => 1, 'sorting' => $uid,
                'perms_userid'  => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
            ]);
        }

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::ELEMENT, 'Live header', 0, 0, 0],
            [self::ELEMENT_WITH_DRAFT, 'Second live', 0, 0, 0],
            [self::DRAFT_OF_ELEMENT, 'Second in the draft', self::DRAFT, self::ELEMENT_WITH_DRAFT, 0],
            [self::NEW_IN_DRAFT, 'New in the draft', self::DRAFT, 0, 1],
            [self::OTHER_DRAFT_OF_ELEMENT, 'Only in the other workspace', self::OTHER_DRAFT, self::ELEMENT, 0],
        ] as [$uid, $header, $workspace, $original, $state]) {
            $content->insert('tt_content', [
                'uid'        => $uid, 'pid' => 1, 'colPos' => 0, 'CType' => 'text', 'header' => $header, 'sorting' => $uid,
                't3ver_wsid' => $workspace, 't3ver_oid' => $original, 't3ver_state' => $state,
            ]);
        }

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function readRecordsListsThePageAsTheDraftWorkspaceShowsIt(): void
    {
        $draft = $this->read(['table' => 'tt_content', 'pid' => 1, 'fields' => ['header']], $this->contextIn(self::DRAFT));

        self::assertStringContainsString('Records in tt_content (3, offset 0):', $draft);
        self::assertStringContainsString("- tt_content:11\n  pid: 1\n  header: Second in the draft", $draft);
        self::assertStringContainsString("- tt_content:13\n  pid: 1\n  header: New in the draft", $draft);
        self::assertStringContainsString("- tt_content:10\n  pid: 1\n  header: Live header", $draft);
        self::assertStringNotContainsString('tt_content:' . self::DRAFT_OF_ELEMENT, $draft, 'a version is reported under its live uid');
        self::assertStringNotContainsString('Second live', $draft);
        self::assertStringNotContainsString('other workspace', $draft);

        foreach ([0, self::OTHER_DRAFT] as $workspace) {
            $other = $this->read(['table' => 'tt_content', 'pid' => 1, 'fields' => ['header']], $this->contextIn($workspace));
            self::assertStringContainsString('Second live', $other, (string) $workspace);
            self::assertStringNotContainsString('in the draft', $other, (string) $workspace);
            self::assertStringNotContainsString('tt_content:' . self::NEW_IN_DRAFT, $other, (string) $workspace);
        }

        self::assertStringNotContainsString('other workspace', $this->read(['table' => 'tt_content', 'pid' => 1, 'fields' => ['header']], $this->contextIn(0)));
        self::assertStringContainsString('Only in the other workspace', $this->read(['table' => 'tt_content', 'uid' => self::ELEMENT, 'fields' => ['header']], $this->contextIn(self::OTHER_DRAFT)));
    }

    #[Test]
    public function readRecordsAddressesARecordByItsLiveUidOnly(): void
    {
        $context = $this->contextIn(self::DRAFT);

        self::assertStringContainsString(
            "- tt_content:11\n  pid: 1\n  header: Second in the draft",
            $this->read(['table' => 'tt_content', 'uid' => self::ELEMENT_WITH_DRAFT, 'fields' => ['header']], $context),
        );
        self::assertStringContainsString(
            'Second in the draft',
            $this->read(['table' => 'tt_content', 'where_equals' => ['uid' => self::ELEMENT_WITH_DRAFT], 'fields' => ['header']], $context),
        );

        // A version row's own uid, and another workspace's version, are no record.
        foreach ([self::DRAFT_OF_ELEMENT, self::OTHER_DRAFT_OF_ELEMENT] as $uid) {
            self::assertStringStartsWith('No records', $this->read(['table' => 'tt_content', 'uid' => $uid], $context), (string) $uid);
            self::assertStringStartsWith('No records', $this->read(['table' => 'tt_content', 'uid' => $uid], $this->contextIn(0)), (string) $uid);
        }
    }

    /**
     * Limit, offset and the count are taken over the records the workspace
     * shows: records deleted there neither count nor take a page's places.
     */
    #[Test]
    public function readRecordsPagesOverWhatTheDraftWorkspaceShows(): void
    {
        $this->deleteInDraft(100, 'Gone');
        // A version whose own uid sorts after every other row: the page is
        // ordered by the live uid it is reported under.
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 3000, 'pid' => 1, 'CType' => 'text', 'header' => 'First in the draft', 'sorting' => 1, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::ELEMENT,
        ]);

        $context = $this->contextIn(self::DRAFT);
        $first   = $this->read(['table' => 'tt_content', 'pid' => 1, 'limit' => 2, 'fields' => ['header']], $context);
        $second  = $this->read(['table' => 'tt_content', 'pid' => 1, 'limit' => 2, 'offset' => 2, 'fields' => ['header']], $context);

        self::assertStringContainsString('Records in tt_content (2, offset 0):', $first);
        self::assertStringContainsString("- tt_content:10\n  pid: 1\n  header: First in the draft", $first);
        self::assertStringContainsString('tt_content:11', $first);
        self::assertStringContainsString('Records in tt_content (1, offset 2):', $second);
        self::assertStringContainsString('tt_content:13', $second);
        self::assertStringNotContainsString('Gone', $first . $second);

        // Live still has them.
        self::assertStringContainsString('Gone', $this->read(['table' => 'tt_content', 'where_equals' => ['header' => 'Gone'], 'limit' => 1], $this->contextIn(0)));
    }

    /**
     * A soft-deleted version row is no version: the live row shows, as core's
     * getWorkspaceVersionOfRecord() finds no version either.
     */
    #[Test]
    public function aDeletedVersionRowDoesNotHideTheLiveRecord(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 3001, 'pid' => 1, 'CType' => 'text', 'header' => 'Discarded', 'deleted' => 1, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::ELEMENT,
        ]);

        $draft = $this->contextIn(self::DRAFT);
        self::assertStringContainsString("- tt_content:10\n  pid: 1\n  header: Live header", $this->read(['table' => 'tt_content', 'uid' => self::ELEMENT, 'fields' => ['header']], $draft));
        self::assertStringContainsString('tt_content:10 · Live header', $this->search(['query' => 'Live header'], $draft));
    }

    #[Test]
    public function readRecordsOnATableWithoutWorkspacesReadsAsBefore(): void
    {
        $this->connectionPool->getConnectionForTable('sys_filemounts')->insert('sys_filemounts', ['uid' => 7, 'pid' => 0, 'title' => 'Mount', 'identifier' => '1:/']);

        self::assertStringContainsString('- sys_filemounts:7', $this->read(['table' => 'sys_filemounts', 'uid' => 7], $this->contextIn(self::DRAFT)));
    }

    #[Test]
    public function aRunThatDoesNotKnowItsWorkspaceReadsLive(): void
    {
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true), $this->adminIn(self::DRAFT));

        $records = $this->read(['table' => 'tt_content', 'pid' => 1, 'fields' => ['header']], $context);
        self::assertStringContainsString('Second live', $records);
        self::assertStringNotContainsString('in the draft', $records);

        self::assertStringStartsWith('No matches', $this->search(['query' => 'in the draft'], $context));
    }

    /**
     * The search runs over what the workspace shows. A term found only in a
     * version row of this workspace finds the record under its live uid; a
     * term the workspace replaced, deleted or hid is not found there; a term
     * of another workspace is found nowhere else.
     */
    #[Test]
    public function searchRecordsSearchesWhatTheDraftWorkspaceShows(): void
    {
        $draft = $this->contextIn(self::DRAFT);
        $live  = $this->contextIn(0);
        $other = $this->contextIn(self::OTHER_DRAFT);

        // Only in this workspace's version.
        $hit = $this->search(['query' => 'Second in the', 'table' => 'tt_content'], $draft);
        self::assertStringContainsString('tt_content:11 · Second in the draft · pid 1', $hit);
        self::assertStringNotContainsString('tt_content:12', $hit);
        self::assertStringStartsWith('No matches', $this->search(['query' => 'Second in the', 'table' => 'tt_content'], $live));
        self::assertStringStartsWith('No matches', $this->search(['query' => 'Second in the', 'table' => 'tt_content'], $other));

        // Only in the live text this workspace replaced.
        self::assertStringStartsWith('No matches', $this->search(['query' => 'Second live', 'table' => 'tt_content'], $draft));
        self::assertStringContainsString('tt_content:11 · Second live', $this->search(['query' => 'Second live', 'table' => 'tt_content'], $live));

        // Only in another workspace's version.
        self::assertStringStartsWith('No matches', $this->search(['query' => 'other workspace'], $draft));
        self::assertStringStartsWith('No matches', $this->search(['query' => 'other workspace'], $live));
        self::assertStringContainsString('tt_content:10 · Only in the other workspace', $this->search(['query' => 'other workspace'], $other));
    }

    #[Test]
    public function searchRecordsDoesNotFindWhatTheDraftWorkspaceDeletedOrHid(): void
    {
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 30, 'pid' => 1, 'CType' => 'text', 'header' => 'Deleted in the draft']);
        $content->insert('tt_content', ['uid' => 31, 'pid' => 1, 'CType' => 'text', 'header' => 'Deleted in the draft', 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 30, 't3ver_state' => 2]);
        $content->insert('tt_content', ['uid' => 32, 'pid' => 1, 'CType' => 'text', 'header' => 'Hidden in the draft']);
        $content->insert('tt_content', ['uid' => 33, 'pid' => 1, 'CType' => 'text', 'header' => 'Hidden in the draft', 'hidden' => 1, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 32]);

        $draft = $this->contextIn(self::DRAFT);
        self::assertStringStartsWith('No matches', $this->search(['query' => 'Deleted in the draft'], $draft));
        self::assertStringStartsWith('No matches', $this->search(['query' => 'Hidden in the draft'], $draft), 'the live text of an element hidden in the draft workspace was found');
        self::assertStringContainsString('tt_content:32 · Hidden in the draft · pid 1 · language 0 (hidden)', $this->search(['query' => 'Hidden in the draft', 'include_hidden' => true], $draft));

        $live = $this->contextIn(0);
        self::assertStringContainsString('tt_content:30', $this->search(['query' => 'Deleted in the draft'], $live));
        self::assertStringContainsString('tt_content:32', $this->search(['query' => 'Hidden in the draft'], $live));
    }

    #[Test]
    public function searchRecordsDoesNotLetRecordsDeletedInTheDraftWorkspaceUseUpTheLimit(): void
    {
        $this->deleteInDraft(60, 'Gone');
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 900, 'pid' => 1, 'CType' => 'text', 'header' => 'Gone too', 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1,
        ]);

        $hits = $this->search(['query' => 'Gone', 'table' => 'tt_content', 'limit' => 1], $this->contextIn(self::DRAFT));

        self::assertStringContainsString('Matches for "Gone" (1):', $hits);
        self::assertStringContainsString('tt_content:900 · Gone too', $hits);
    }

    #[Test]
    public function getPagetreeShowsThePageTreeOfTheDraftWorkspace(): void
    {
        $pages = $this->connectionPool->getConnectionForTable('pages');
        $base  = ['doktype' => 1, 'perms_userid' => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL];
        // Page 2 renamed in the draft workspace, page 3 new there, page 4 deleted
        // there, page 5 moved away from page 1 to page 2 there, page 6 renamed in
        // the other workspace.
        $pages->insert('pages', ['uid' => 20, 'pid' => 1, 'title' => 'Second page renamed', 'sorting' => 2, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 2] + $base);
        $pages->insert('pages', ['uid' => 3, 'pid' => 1, 'title' => 'New page', 'sorting' => 3, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1] + $base);
        $pages->insert('pages', ['uid' => 4, 'pid' => 1, 'title' => 'Deleted page', 'sorting' => 4] + $base);
        $pages->insert('pages', ['uid' => 40, 'pid' => 1, 'title' => 'Deleted page', 'sorting' => 4, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 4, 't3ver_state' => 2] + $base);
        $pages->insert('pages', ['uid' => 5, 'pid' => 1, 'title' => 'Moved page', 'sorting' => 5] + $base);
        $pages->insert('pages', ['uid' => 50, 'pid' => 2, 'title' => 'Moved page', 'sorting' => 5, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 5, 't3ver_state' => 4] + $base);
        $pages->insert('pages', ['uid' => 6, 'pid' => 1, 'title' => 'Sixth page', 'sorting' => 6] + $base);
        $pages->insert('pages', ['uid' => 60, 'pid' => 1, 'title' => 'Renamed elsewhere', 'sorting' => 6, 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => 6] + $base);

        $tree = $this->tool('get_pagetree');

        $draft = $tree->execute(['rootUid' => 1], $this->contextIn(self::DRAFT))->content;
        self::assertStringContainsString("[2] Second page renamed (doktype 1)\n  [5] Moved page (doktype 1)\n[3] New page (doktype 1)\n[6] Sixth page (doktype 1)", $draft);
        self::assertStringNotContainsString('[20]', $draft);
        self::assertStringNotContainsString('Deleted page', $draft);
        self::assertStringNotContainsString('elsewhere', $draft);

        $live = $tree->execute(['rootUid' => 1], $this->contextIn(0))->content;
        self::assertStringContainsString("[2] Second page (doktype 1)\n[4] Deleted page (doktype 1)\n[5] Moved page (doktype 1)\n[6] Sixth page (doktype 1)", $live);
        self::assertStringNotContainsString('New page', $live);
        self::assertStringNotContainsString('elsewhere', $live);
    }

    /**
     * For an editor, whether a page may be shown is asked of the live page,
     * as core's page tree asks it: a version keeps the permissions it was
     * created with. Page 30 is closed in live and open in its draft, page 31
     * the other way round.
     */
    #[Test]
    public function anEditorsPageAccessIsTheLivePagesInTheDraftWorkspace(): void
    {
        $pages = $this->connectionPool->getConnectionForTable('pages');
        $open  = ['doktype' => 1, 'perms_userid' => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL];
        $shut  = ['perms_everybody' => 0] + $open;
        $pages->insert('pages', ['uid' => 30, 'pid' => 1, 'title' => 'Closed live', 'sorting' => 30] + $shut);
        $pages->insert('pages', ['uid' => 300, 'pid' => 1, 'title' => 'Secret draft', 'sorting' => 30, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 30] + $open);
        $pages->insert('pages', ['uid' => 31, 'pid' => 1, 'title' => 'Open live', 'sorting' => 31] + $open);
        $pages->insert('pages', ['uid' => 310, 'pid' => 1, 'title' => 'Open draft', 'sorting' => 31, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 31] + $shut);

        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 1, 'pid' => 0, 'title' => 'Editors', 'tables_select' => 'pages,tt_content']);
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '1', 'db_mountpoints' => '1', 'options' => 3], ['uid' => 2]);
        $editor = $this->setUpBackendUser(2);
        self::assertFalse($editor->isAdmin());
        self::assertSame(self::DRAFT, $editor->workspace, 'positive control: the editor works in the draft workspace');
        $context = ToolExecutionContext::fromBackendUser($editor);

        $tree = $this->tool('get_pagetree')->execute(['rootUid' => 1], $context)->content;
        self::assertStringContainsString('[31] Open draft', $tree);
        self::assertStringNotContainsString('Secret', $tree);

        $records = $this->read(['table' => 'pages', 'pid' => 1], $context);
        self::assertStringContainsString('Open draft', $records);
        self::assertStringNotContainsString('Secret', $records);

        $hits = $this->search(['query' => 'draft', 'table' => 'pages'], $context);
        self::assertStringContainsString('pages:31 · Open draft', $hits);
        self::assertStringNotContainsString('Secret', $hits);
    }

    /**
     * The same reads against rows core's DataHandler made in the draft
     * workspace — a change, a hide, a move and a delete — rather than rows
     * written by hand.
     */
    #[Test]
    public function theReadsMatchWhatTheDataHandlerStoresInTheDraftWorkspace(): void
    {
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([[70, 'To be hidden'], [71, 'To be moved'], [72, 'To be deleted']] as [$uid, $header]) {
            $content->insert('tt_content', ['uid' => $uid, 'pid' => 1, 'colPos' => 0, 'CType' => 'text', 'header' => $header, 'sorting' => $uid]);
        }

        $admin       = $this->adminIn(self::DRAFT);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            ['tt_content' => [self::ELEMENT => ['header' => 'Changed in the draft'], 70 => ['hidden' => 1]]],
            ['tt_content' => [71 => ['move' => 2], 72 => ['delete' => 1]]],
            $admin,
        );
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog);
        foreach ([self::ELEMENT, 70, 71, 72] as $uid) {
            self::assertCount(1, $this->versionsOf($uid), 'positive control: no version of ' . $uid);
        }

        $draft = $this->contextIn(self::DRAFT);
        $page1 = $this->read(['table' => 'tt_content', 'pid' => 1, 'fields' => ['header']], $draft);
        $page2 = $this->read(['table' => 'tt_content', 'pid' => 2, 'fields' => ['header']], $draft);
        self::assertStringContainsString("- tt_content:10\n  pid: 1\n  header: Changed in the draft", $page1);
        self::assertStringNotContainsString('To be', $page1);
        self::assertStringContainsString("- tt_content:71\n  pid: 2\n  header: To be moved", $page2);
        self::assertStringContainsString('tt_content:10 · Changed in the draft', $this->search(['query' => 'Changed in the draft'], $draft));
        self::assertStringStartsWith('No matches', $this->search(['query' => 'To be hidden'], $draft));
        self::assertStringStartsWith('No matches', $this->search(['query' => 'To be deleted'], $draft));

        $live = $this->read(['table' => 'tt_content', 'pid' => 1, 'fields' => ['header']], $this->contextIn(0));
        foreach (['Live header', 'To be hidden', 'To be moved', 'To be deleted'] as $header) {
            self::assertStringContainsString($header, $live);
        }

        self::assertStringNotContainsString('Changed in the draft', $live);
    }

    #[Test]
    public function fileMetadataIsReadAsTheDraftWorkspaceShowsIt(): void
    {
        $this->addFileWithMetadataDrafts();

        $meta = $this->tool('read_fal_asset_meta');
        self::assertStringContainsString('title: Draft title', $meta->execute(['uid' => 20], $this->contextIn(self::DRAFT))->content);
        $live = $meta->execute(['uid' => 20], $this->contextIn(0))->content;
        self::assertStringContainsString('title: Live title', $live);
        self::assertStringNotContainsString('Draft', $live);
        self::assertStringNotContainsString('Other', $live);
        self::assertStringContainsString('title: Other title', $meta->execute(['uid' => 20], $this->contextIn(self::OTHER_DRAFT))->content);

        $search = $this->tool('search_fal_files');
        self::assertStringContainsString('- [20] 1:/logo.png', $search->execute(['query' => 'Draft title'], $this->contextIn(self::DRAFT))->content);
        foreach ([[self::DRAFT, 'Live title'], [self::DRAFT, 'Other title'], [0, 'Draft title'], [0, 'Other title']] as [$workspace, $term]) {
            self::assertStringStartsWith('No files match', $search->execute(['query' => $term], $this->contextIn($workspace))->content, $workspace . ' ' . $term);
        }
    }

    #[Test]
    public function fileReferencesAreListedAsTheDraftWorkspaceShowsThem(): void
    {
        $this->addFileWithMetadataDrafts();
        $references = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $base       = ['uid_local' => 20, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'pid' => 1];
        // Live on element 10 and deleted in the draft workspace; new there on
        // element 13; new in the other workspace on element 99.
        $references->insert('sys_file_reference', ['uid' => 200, 'uid_foreign' => self::ELEMENT] + $base);
        $references->insert('sys_file_reference', ['uid' => 201, 'uid_foreign' => self::ELEMENT, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 200, 't3ver_state' => 2] + $base);
        $references->insert('sys_file_reference', ['uid' => 202, 'uid_foreign' => self::NEW_IN_DRAFT, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1] + $base);
        $references->insert('sys_file_reference', ['uid' => 203, 'uid_foreign' => 99, 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_state' => 1] + $base);

        $tool = $this->tool('get_fal_references');

        $draft = $tool->execute(['uid' => 20], $this->contextIn(self::DRAFT))->content;
        self::assertStringContainsString('References to logo.png (1):', $draft);
        self::assertStringContainsString('- tt_content:13 (field image)', $draft);

        $live = $tool->execute(['uid' => 20], $this->contextIn(0))->content;
        self::assertStringContainsString('References to logo.png (1):', $live);
        self::assertStringContainsString('- tt_content:10 (field image)', $live);
        self::assertStringNotContainsString('tt_content:99', $draft . $live);
    }

    /**
     * The history of a record is the history its workspace has: a version's
     * changes in the draft workspace, never the history of another
     * workspace's version or of a record new there.
     */
    #[Test]
    public function theHistoryOfARecordIsReadInTheRunsWorkspaceOnly(): void
    {
        $history = $this->connectionPool->getConnectionForTable('sys_history');
        foreach ([
            [self::ELEMENT_WITH_DRAFT, 0, 'Live edit'],
            [self::DRAFT_OF_ELEMENT, self::DRAFT, 'Draft edit'],
            [self::NEW_IN_DRAFT, self::DRAFT, 'New edit'],
            [self::OTHER_DRAFT_OF_ELEMENT, self::OTHER_DRAFT, 'Other edit'],
            // An entry the other workspace logged on the live uid.
            [self::ELEMENT_WITH_DRAFT, self::OTHER_DRAFT, 'Stray edit'],
        ] as $i => [$uid, $workspace, $header]) {
            $history->insert('sys_history', [
                'tstamp'       => 1000 + $i, 'actiontype' => 2, 'usertype' => 'BE', 'userid' => 1, 'tablename' => 'tt_content',
                'recuid'       => $uid, 'workspace' => $workspace,
                'history_data' => (string) json_encode(['oldRecord' => ['header' => 'Before'], 'newRecord' => ['header' => $header]]),
            ]);
        }

        $tool  = $this->tool('get_record_history');
        $calls = static fn (int $uid): array => ['table' => 'tt_content', 'uid' => $uid];

        $draft = $tool->execute($calls(self::ELEMENT_WITH_DRAFT), $this->contextIn(self::DRAFT))->content;
        self::assertStringContainsString('Draft edit', $draft);
        self::assertStringContainsString('Live edit', $draft);
        self::assertStringContainsString('New edit', $tool->execute($calls(self::NEW_IN_DRAFT), $this->contextIn(self::DRAFT))->content);

        $live = $tool->execute($calls(self::ELEMENT_WITH_DRAFT), $this->contextIn(0))->content;
        self::assertStringContainsString('Live edit', $live);
        self::assertStringNotContainsString('Draft edit', $live);
        self::assertStringNotContainsString('Stray edit', $draft . $live);
        self::assertStringContainsString('Stray edit', $tool->execute($calls(self::ELEMENT_WITH_DRAFT), $this->contextIn(self::OTHER_DRAFT))->content);

        // A version row's own uid and a record of another workspace are no record.
        foreach ([
            [self::DRAFT, self::OTHER_DRAFT_OF_ELEMENT],
            [self::DRAFT, self::DRAFT_OF_ELEMENT],
            [0, self::DRAFT_OF_ELEMENT],
            [0, self::NEW_IN_DRAFT],
            [self::OTHER_DRAFT, self::NEW_IN_DRAFT],
        ] as [$workspace, $uid]) {
            self::assertSame('Table not found or not permitted.', $tool->execute($calls($uid), $this->contextIn($workspace))->content, $workspace . ':' . $uid);
        }

        self::assertStringContainsString('Other edit', $tool->execute($calls(self::ELEMENT), $this->contextIn(self::OTHER_DRAFT))->content);
        self::assertStringNotContainsString('Other edit', $tool->execute($calls(self::ELEMENT), $this->contextIn(self::DRAFT))->content);
    }

    #[Test]
    public function aRecordIsHighlightedWhereTheDraftWorkspaceShowsIt(): void
    {
        $tool    = $this->tool('highlight_record');
        $subject = new SubjectRecord(new RecordReference('pages', 1));
        $in      = fn (int $workspace): ToolExecutionContext => new ToolExecutionContext(
            AiActorContext::backendUser(1, true, [], [], $workspace),
            $this->adminIn($workspace),
            subjectRecord: $subject,
        );
        $call = static fn (int $uid): array => ['table' => 'tt_content', 'uid' => $uid];

        self::assertFalse($tool->execute($call(self::NEW_IN_DRAFT), $in(self::DRAFT))->isError);
        self::assertTrue($tool->execute($call(self::NEW_IN_DRAFT), $in(0))->isError);
        self::assertTrue($tool->execute($call(self::NEW_IN_DRAFT), $in(self::OTHER_DRAFT))->isError);
        self::assertTrue($tool->execute($call(self::DRAFT_OF_ELEMENT), $in(self::DRAFT))->isError, 'a version is highlighted by its live uid');
        self::assertFalse($tool->execute($call(self::ELEMENT_WITH_DRAFT), $in(self::DRAFT))->isError);
    }

    #[Test]
    public function tsconfigIsNotReadFromAnotherWorkspacesPage(): void
    {
        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 30, 'pid' => 1, 'title' => 'New page', 'doktype' => 1, 't3ver_wsid' => self::DRAFT, 't3ver_state' => 1, 'TSconfig' => 'draft.secret = 1']);
        $pages->insert('pages', ['uid' => 31, 'pid' => 1, 'title' => 'Root elsewhere', 'doktype' => 1, 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => 1, 'TSconfig' => 'other.secret = 1']);

        $tool = $this->tool('get_tsconfig');

        self::assertStringContainsString('draft', $tool->execute(['pageUid' => 30], $this->contextIn(self::DRAFT))->content);
        foreach ([[0, 30], [self::OTHER_DRAFT, 30], [0, 31], [self::DRAFT, 31]] as [$workspace, $uid]) {
            self::assertSame('Page not found.', $tool->execute(['pageUid' => $uid], $this->contextIn($workspace))->content, $workspace . ':' . $uid);
        }

        // The rootline is read in the run's workspace too: a draft of the root
        // page's TSconfig in the draft workspace shows there, and not for a
        // run that did not record its workspace although its user works there.
        $pages->insert('pages', ['uid' => 32, 'pid' => 0, 'title' => 'Root in the draft', 'doktype' => 1, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 1, 'TSconfig' => 'draftonly.flag = 1']);
        self::assertStringContainsString('draftonly', $tool->execute(['pageUid' => 1], $this->contextIn(self::DRAFT))->content);
        $unpinned = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true), $this->adminIn(self::DRAFT));
        self::assertStringNotContainsString('draftonly', $tool->execute(['pageUid' => 1], $unpinned)->content);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function read(array $arguments, ToolExecutionContext $context): string
    {
        return $this->tool('read_records')->execute($arguments, $context)->content;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function search(array $arguments, ToolExecutionContext $context): string
    {
        return $this->tool('search_records')->execute($arguments, $context)->content;
    }

    /**
     * Live elements on page 1, each deleted in the draft workspace.
     */
    private function deleteInDraft(int $count, string $header): void
    {
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        for ($i = 0; $i < $count; ++$i) {
            $content->insert('tt_content', ['uid' => 1000 + $i, 'pid' => 1, 'CType' => 'text', 'header' => $header, 'sorting' => 1]);
            $content->insert('tt_content', ['uid' => 2000 + $i, 'pid' => 1, 'CType' => 'text', 'header' => $header, 'sorting' => 1, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 1000 + $i, 't3ver_state' => 2]);
        }
    }

    /**
     * The draft workspace's rows of a record, hidden ones included.
     *
     * @return list<array<string, mixed>>
     */
    private function versionsOf(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->select('*')->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', self::DRAFT),
                $queryBuilder->expr()->eq('t3ver_oid', $uid),
            )
            ->executeQuery()->fetchAllAssociative();
    }

    /**
     * File 20 in storage 1 with live metadata and a version of it in each
     * draft workspace.
     */
    private function addFileWithMetadataDrafts(): void
    {
        $files = $this->connectionPool->getConnectionForTable('sys_file');
        $files->insert('sys_file', ['uid' => 20, 'storage' => 1, 'identifier' => '/logo.png', 'name' => 'logo.png', 'mime_type' => 'image/png', 'size' => 10, 'missing' => 0]);
        $files->insert('sys_file_metadata', ['uid' => 1, 'file' => 20, 'title' => 'Live title']);
        $files->insert('sys_file_metadata', ['uid' => 2, 'file' => 20, 'title' => 'Draft title', 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 1]);
        $files->insert('sys_file_metadata', ['uid' => 3, 'file' => 20, 'title' => 'Other title', 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_oid' => 1]);
    }

    private function contextIn(int $workspace): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($this->adminIn($workspace));
    }

    private function adminIn(int $workspace): BackendUserAuthentication
    {
        $admin = $this->setUpBackendUser(1);
        self::assertTrue($admin->setTemporaryWorkspace($workspace));
        self::assertSame($workspace, $admin->workspace, 'positive control: the user is in the workspace');

        return $admin;
    }

    private function tool(string $name): ToolInterface
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get($name);
        self::assertInstanceOf(ToolInterface::class, $tool);
        (new ToolStateRepository($this->connectionPool))->setEnabled($name, true);

        return $tool;
    }
}
