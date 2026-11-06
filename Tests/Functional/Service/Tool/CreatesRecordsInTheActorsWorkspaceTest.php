<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreatePageDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreatesRecordsInTheRunsWorkspaceTrait;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\InterferesWithAnUpdateHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheInterferingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * A chat run started in a draft workspace creates its content element or page
 * as a record new in that workspace; live stays as it is, and another
 * workspace's records are neither used nor shown (ADR-264).
 */
#[CoversClass(CreateContentElementDraftTool::class)]
#[CoversClass(CreatePageDraftTool::class)]
#[CoversTrait(CreatesRecordsInTheRunsWorkspaceTrait::class)]
final class CreatesRecordsInTheActorsWorkspaceTest extends AbstractFunctionalTestCase
{
    use RegistersTheInterferingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'seo', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private const ROOT = 1;

    private const PAGE_NEW_IN_DRAFT = 2;

    private const PAGE_NEW_IN_OTHER_DRAFT = 3;

    private const PAGE_WITH_DRAFT = 4;

    private const ELEMENT = 10;

    private const NEXT_ELEMENT = 11;

    private const ELEMENT_NEW_IN_DRAFT = 13;

    private const ELEMENT_DELETED_IN_DRAFT = 14;

    /** What another workspace's records carry and no card or answer may show. */
    private const SECRET = 'OTHER-DRAFT';

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
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Other', 'members' => 'be_users_2']);

        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([
            [self::ROOT, 0, 'Root', 0, 0, 0, Permission::ALL],
            [self::PAGE_NEW_IN_DRAFT, self::ROOT, 'New in the draft', self::DRAFT, 0, 1, Permission::ALL],
            [self::PAGE_NEW_IN_OTHER_DRAFT, self::ROOT, self::SECRET . ' page', self::OTHER_DRAFT, 0, 1, Permission::ALL],
            [self::PAGE_WITH_DRAFT, self::ROOT, 'Live title', 0, 0, 0, Permission::ALL],
            // The draft of page 4 is open to nobody; the live row stays open.
            // Core asks the live row on a create, and so does the tool.
            [20, self::ROOT, 'Draft title', self::DRAFT, self::PAGE_WITH_DRAFT, 0, 0],
        ] as [$uid, $pid, $title, $workspace, $original, $state, $everybody]) {
            $pages->insert('pages', [
                'uid'           => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/' . $uid, 'doktype' => 1, 'sorting' => $uid * 256,
                't3ver_wsid'    => $workspace, 't3ver_oid' => $original, 't3ver_state' => $state,
                'perms_userid'  => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => $everybody,
            ]);
        }

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::ELEMENT, self::ROOT, 'First', 0, 0, 0, 256],
            [self::NEXT_ELEMENT, self::ROOT, 'Second', 0, 0, 0, 512],
            [self::ELEMENT_NEW_IN_DRAFT, self::PAGE_NEW_IN_DRAFT, 'Drafted element', self::DRAFT, 0, 1, 256],
            [self::ELEMENT_DELETED_IN_DRAFT, self::ROOT, 'Gone in the draft', 0, 0, 0, 768],
            [15, self::ROOT, 'Gone in the draft', self::DRAFT, self::ELEMENT_DELETED_IN_DRAFT, 2, 768],
            [16, self::ROOT, self::SECRET . ' element', self::OTHER_DRAFT, 0, 1, 1024],
        ] as [$uid, $pid, $header, $workspace, $original, $state, $sorting]) {
            $content->insert('tt_content', [
                'uid'        => $uid, 'pid' => $pid, 'colPos' => 0, 'CType' => 'text', 'header' => $header, 'sorting' => $sorting,
                't3ver_wsid' => $workspace, 't3ver_oid' => $original, 't3ver_state' => $state,
            ]);
        }

        $this->connectionPool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid'                => 7, 'pid' => 0, 'title' => 'Editors', 'db_mountpoints' => '1',
            'tables_modify'      => 'pages,tt_content', 'tables_select' => 'pages,tt_content',
            'non_exclude_fields' => 'tt_content:hidden,pages:hidden', 'explicit_allowdeny' => 'tt_content:CType:text',
            'pagetypes_select'   => '1', 'workspace_perms' => 1,
        ]);
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '7', 'options' => 3], ['uid' => 2]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        $this->unregisterInterferingHook();
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function anElementCreatedInADraftWorkspaceIsNewThereAndLiveStaysAsItIs(): void
    {
        $tool    = $this->tool('create_content_element_draft');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['page' => self::ROOT, 'type' => 'text', 'header' => 'Made by AI', 'after_content_uid' => self::ELEMENT];
        $live    = $this->liveRows();

        $card = $this->card($tool, $call, $context);
        self::assertStringContainsString('Workspace: “Entwurf”', $card, 'the card names the workspace written to');
        self::assertStringContainsString('“First”', $card);

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        $row = $this->createdRow('tt_content', $result);
        self::assertSame(self::DRAFT, (int) $row['t3ver_wsid']);
        self::assertSame(1, (int) $row['t3ver_state'], 'new in the workspace');
        self::assertSame(0, (int) $row['t3ver_oid']);
        self::assertSame(self::ROOT, (int) $row['pid']);
        self::assertSame(1, (int) $row['hidden']);
        self::assertGreaterThan(256, (int) $row['sorting'], 'placed after its anchor');
        self::assertLessThan(512, (int) $row['sorting'], 'placed before the next element');
        self::assertSame($live, $this->liveRows(), 'a live row changed');

        // The provenance mark goes on the row new in the workspace.
        $this->dispatchWriteEvent($result, self::DRAFT);
        self::assertSame(1, (int) $this->createdRow('tt_content', $result)['tx_nrllm_ai_written']);
        self::assertSame($live, $this->liveRows(), 'the provenance mark reached a live row');
    }

    #[Test]
    public function anElementIsCreatedOnAPageAndAfterAnElementThatExistOnlyInTheDraftWorkspace(): void
    {
        $tool    = $this->tool('create_content_element_draft');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['page' => self::PAGE_NEW_IN_DRAFT, 'type' => 'text', 'header' => 'On the new page', 'after_content_uid' => self::ELEMENT_NEW_IN_DRAFT];

        self::assertStringContainsString('“New in the draft”', $this->card($tool, $call, $context));
        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        $row = $this->createdRow('tt_content', $result);
        self::assertSame(self::PAGE_NEW_IN_DRAFT, (int) $row['pid']);
        self::assertSame(self::DRAFT, (int) $row['t3ver_wsid']);
        self::assertGreaterThan(256, (int) $row['sorting']);

        // The same header again is the copy the card warns about.
        $again = $this->card($tool, ['page' => self::PAGE_NEW_IN_DRAFT, 'type' => 'text', 'header' => 'On the new page'], $context);
        self::assertStringContainsString('already exists on this page', $again);
    }

    #[Test]
    public function anElementDeletedInTheDraftWorkspaceAnchorsNothing(): void
    {
        $result = $this->tool('create_content_element_draft')->execute(
            ['page' => self::ROOT, 'type' => 'text', 'header' => 'x', 'after_content_uid' => self::ELEMENT_DELETED_IN_DRAFT],
            $this->contextIn(self::DRAFT),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('cannot anchor', $result->content);
        self::assertSame(0, $this->countNewRows('tt_content'));
    }

    #[Test]
    public function anotherWorkspacesPageAndElementAreNotThere(): void
    {
        $tool    = $this->tool('create_content_element_draft');
        $context = $this->contextIn(self::DRAFT);

        foreach ([
            ['page' => self::PAGE_NEW_IN_OTHER_DRAFT, 'type' => 'text', 'header' => 'x'],
            ['page' => self::ROOT, 'type' => 'text', 'header' => 'x', 'after_content_uid' => 16],
        ] as $call) {
            $result = $tool->execute($call, $context);
            self::assertTrue($result->isError, json_encode($call, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString(self::SECRET, $result->content . $this->card($tool, $call, $context));
        }

        $page = $this->tool('create_page_draft')->execute(['parent' => self::PAGE_NEW_IN_OTHER_DRAFT, 'title' => 'x'], $context);
        self::assertSame('Page not found or not permitted.', $page->content);
        self::assertSame(0, $this->countNewRows('tt_content') + $this->countNewRows('pages'));
    }

    /**
     * A viewer in live, or in another workspace, gets no card for a call on a
     * page that only the actor's workspace holds: its title is a draft.
     */
    #[Test]
    public function aViewerOutsideTheDraftWorkspaceGetsNoCardForItsPage(): void
    {
        foreach (['create_content_element_draft' => ['page' => self::PAGE_NEW_IN_DRAFT, 'type' => 'text', 'header' => 'x'], 'create_page_draft' => ['parent' => self::PAGE_NEW_IN_DRAFT, 'title' => 'x']] as $name => $call) {
            $tool = $this->tool($name);
            self::assertInstanceOf(ToolPreviewInterface::class, $tool);
            self::assertTrue($tool->mayViewerReadPreview($call, $this->adminIn(self::DRAFT)), $name);
            self::assertFalse($tool->mayViewerReadPreview($call, $this->adminIn(0)), $name);
            self::assertFalse($tool->mayViewerReadPreview($call, $this->adminIn(self::OTHER_DRAFT)), $name);
        }
    }

    /**
     * A version keeps the permissions it was made with. Core asks the stored
     * row — the live one — when it creates a record on a page, and so does
     * the tool: the draft of page 4 is closed, its live row open.
     */
    #[Test]
    public function anEditorIsAskedForThePermissionCoreAsks(): void
    {
        $editor = $this->setUpBackendUser(2);
        self::assertTrue($editor->setTemporaryWorkspace(self::DRAFT));
        self::assertSame(self::DRAFT, $editor->workspace, 'positive control: the editor works in the draft workspace');
        self::assertFalse($editor->isAdmin());
        $context = ToolExecutionContext::fromBackendUser($editor);

        $result = $this->tool('create_content_element_draft')->execute(['page' => self::PAGE_WITH_DRAFT, 'type' => 'text', 'header' => 'By an editor'], $context);

        self::assertFalse($result->isError, $result->content);
        $row = $this->createdRow('tt_content', $result);
        self::assertSame(self::PAGE_WITH_DRAFT, (int) $row['pid']);
        self::assertSame(self::DRAFT, (int) $row['t3ver_wsid']);
        self::assertStringContainsString('“Draft title”', $this->card($this->tool('create_content_element_draft'), ['page' => self::PAGE_WITH_DRAFT, 'type' => 'text', 'header' => 'y'], $context), 'the card shows the page as the workspace does');

        // And the other way round: a live row closed to the editor stays closed
        // whatever its draft says.
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => self::PAGE_WITH_DRAFT]);
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => Permission::ALL], ['uid' => 20]);
        $closed = $this->tool('create_content_element_draft')->execute(['page' => self::PAGE_WITH_DRAFT, 'type' => 'text', 'header' => 'z'], $context);
        self::assertSame('Page not found or not permitted.', $closed->content);
    }

    #[Test]
    public function aRunThatDoesNotKnowItsWorkspaceCreatesNothingInADraftWorkspace(): void
    {
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true), $this->adminIn(self::DRAFT));

        foreach (['create_content_element_draft' => ['page' => self::ROOT, 'type' => 'text', 'header' => 'x'], 'create_page_draft' => ['parent' => self::ROOT, 'title' => 'x']] as $name => $call) {
            $tool = $this->tool($name);
            $card = $this->card($tool, $call, $context);
            self::assertStringContainsString('does not record which workspace', $card, $name);
            self::assertStringNotContainsString('Workspace', $card, $name);

            $result = $tool->execute($call, $context);
            self::assertTrue($result->isError, $name);
            self::assertStringContainsString('does not record which workspace', $result->content);
        }

        self::assertSame(0, $this->countNewRows('tt_content') + $this->countNewRows('pages'));
    }

    #[Test]
    public function inLiveTheElementIsLiveAndTheCardNamesNoWorkspace(): void
    {
        $tool    = $this->tool('create_content_element_draft');
        $context = $this->contextIn(0);
        $call    = ['page' => self::ROOT, 'type' => 'text', 'header' => 'Live one'];

        self::assertStringNotContainsString('Workspace', $this->card($tool, $call, $context));
        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringNotContainsString('draft in the workspace', $result->content);
        self::assertSame(0, (int) $this->createdRow('tt_content', $result)['t3ver_wsid']);
    }

    #[Test]
    public function aPageCreatedInADraftWorkspaceIsNewThereAndLiveStaysAsItIs(): void
    {
        $tool    = $this->tool('create_page_draft');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['parent' => self::ROOT, 'title' => 'AI page', 'after_page_uid' => self::PAGE_WITH_DRAFT];
        $live    = $this->liveRows();

        $card = $this->card($tool, $call, $context);
        self::assertStringContainsString('Workspace: “Entwurf”', $card);
        self::assertStringContainsString('“Draft title”', $card, 'the anchor is shown as the workspace shows it');

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('Stored as a draft in the workspace "Entwurf"', $result->content);
        $row = $this->createdRow('pages', $result);
        self::assertSame(self::DRAFT, (int) $row['t3ver_wsid']);
        self::assertSame(1, (int) $row['t3ver_state']);
        self::assertSame(self::ROOT, (int) $row['pid']);
        self::assertSame(1, (int) $row['hidden']);
        self::assertSame($live, $this->liveRows(), 'a live row changed');

        $this->dispatchWriteEvent($result, self::DRAFT);
        self::assertSame(1, (int) $this->createdRow('pages', $result)['tx_nrllm_ai_written']);
        self::assertSame($live, $this->liveRows());
    }

    #[Test]
    public function aPageIsCreatedUnderAPageThatExistsOnlyInTheDraftWorkspace(): void
    {
        $tool    = $this->tool('create_page_draft');
        $context = $this->contextIn(self::DRAFT);

        $result = $tool->execute(['parent' => self::PAGE_NEW_IN_DRAFT, 'title' => 'Child'], $context);

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::PAGE_NEW_IN_DRAFT, (int) $this->createdRow('pages', $result)['pid']);

        // The page just drafted is the copy the next card warns about.
        self::assertStringContainsString('already exists under this page', $this->card($tool, ['parent' => self::PAGE_NEW_IN_DRAFT, 'title' => 'Child'], $context));
    }

    /**
     * A record that did not come out hidden is taken back; in a draft
     * workspace core discards it, and where the discard does not happen the
     * answer says so — read in the workspace, not in live, where the row was
     * never there.
     */
    #[Test]
    public function aDraftThatCameOutVisibleIsDiscardedAndAFailedDiscardIsNamed(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$keepVisible = true;
        $context                                 = $this->contextIn(self::DRAFT);
        $calls                                   = [
            'create_page_draft'            => ['pages', ['parent' => self::ROOT, 'title' => 'Visible page']],
            'create_content_element_draft' => ['tt_content', ['page' => self::ROOT, 'type' => 'text', 'header' => 'Visible element']],
        ];

        foreach ($calls as $name => [$table, $call]) {
            $result = $this->tool($name)->execute($call, $context);
            self::assertTrue($result->isError, $name);
            self::assertStringContainsString('was deleted again', $result->content, $name);
            self::assertSame(0, $this->countNewRows($table), $name . ' left a row');
        }

        foreach ($calls as $name => [$table, $call]) {
            InterferesWithAnUpdateHook::$keepDraftsOf = $table;
            $result                                   = $this->tool($name)->execute($call, $context);
            self::assertStringContainsString('COULD NOT BE DELETED', $result->content, $name);
            self::assertSame(1, $this->countNewRows($table), $name);
        }
    }

    /**
     * Where the sort order of a page has no room at the place asked for, core
     * makes room by adding to `sorting` of every row of the page with
     * `t3ver_oid` 0 — live rows and rows new in any workspace — and leaves
     * every version where it was (ADR-264): live would change, and this
     * workspace's versions would fall out of order. In a draft workspace the
     * tools refuse instead, and no row of the page changes.
     */
    #[Test]
    public function aDraftThatNeedsCoreToRenumberThePageIsRefusedAndNoRowChanges(): void
    {
        $content = $this->connectionPool->getConnectionForTable('tt_content');

        // First on the page, where the top record sorts at 0.
        $content->update('tt_content', ['sorting' => 0], ['uid' => self::ELEMENT]);

        $before = $this->everyRow();
        $first  = $this->tool('create_content_element_draft')->execute(['page' => self::ROOT, 'type' => 'text', 'header' => 'First now'], $this->contextIn(self::DRAFT));
        $this->assertRefusedForWantOfRoom($first, $before, 'first on the page');

        // After an anchor the next element follows directly, and the next
        // element's version in this workspace would stay behind.
        $content->update('tt_content', ['sorting' => 256], ['uid' => self::ELEMENT]);
        $content->update('tt_content', ['sorting' => 257], ['uid' => self::NEXT_ELEMENT]);
        $content->insert('tt_content', [
            'uid'        => 40, 'pid' => self::ROOT, 'colPos' => 0, 'CType' => 'text', 'header' => 'Second, drafted', 'sorting' => 257,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::NEXT_ELEMENT, 't3ver_state' => 0,
        ]);
        $before = $this->everyRow();
        $after  = $this->tool('create_content_element_draft')->execute(['page' => self::ROOT, 'type' => 'text', 'header' => 'After the first', 'after_content_uid' => self::ELEMENT], $this->contextIn(self::DRAFT));
        $this->assertRefusedForWantOfRoom($after, $before, 'after an anchor');

        // A page goes through the same creation.
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['sorting' => 0], ['uid' => self::PAGE_NEW_IN_DRAFT]);
        $before = $this->everyRow();
        $page   = $this->tool('create_page_draft')->execute(['parent' => self::ROOT, 'title' => 'First page now'], $this->contextIn(self::DRAFT));
        $this->assertRefusedForWantOfRoom($page, $before, 'page');
    }

    /**
     * The take-back is a discard, which core asks of the user's rights on the
     * new row: an editor's refused page and element are taken back as well.
     */
    #[Test]
    public function anEditorsDraftThatFindsNoRoomIsTakenBack(): void
    {
        $editor = $this->setUpBackendUser(2);
        self::assertTrue($editor->setTemporaryWorkspace(self::DRAFT));
        self::assertSame(self::DRAFT, $editor->workspace, 'positive control: the editor works in the draft workspace');
        self::assertFalse($editor->isAdmin());
        $context = ToolExecutionContext::fromBackendUser($editor);

        $this->connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['sorting' => 0], ['uid' => self::ELEMENT]);
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['sorting' => 0], ['uid' => self::PAGE_NEW_IN_DRAFT]);

        $before  = $this->everyRow();
        $element = $this->tool('create_content_element_draft')->execute(['page' => self::ROOT, 'type' => 'text', 'header' => 'First now'], $context);
        $this->assertRefusedForWantOfRoom($element, $before, 'element, editor');
        self::assertSame(1, $this->discardsLoggedBy(2, 'tt_content'), "positive control: the editor's element was inserted and discarded");

        $page = $this->tool('create_page_draft')->execute(['parent' => self::ROOT, 'title' => 'First page now'], $context);
        $this->assertRefusedForWantOfRoom($page, $before, 'page, editor');
        self::assertSame(1, $this->discardsLoggedBy(2, 'pages'), "positive control: the editor's page was inserted and discarded");
    }

    /**
     * In live the tools create where core makes room, as before.
     */
    #[Test]
    public function inLiveCoreStillMakesRoomForTheNewRecord(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['sorting' => 0], ['uid' => self::ELEMENT]);

        $result = $this->tool('create_content_element_draft')->execute(['page' => self::ROOT, 'type' => 'text', 'header' => 'First now'], $this->contextIn(0));

        self::assertFalse($result->isError, $result->content);
        $row = $this->createdRow('tt_content', $result);
        self::assertSame(0, (int) $row['t3ver_wsid']);
        self::assertGreaterThan(0, (int) $this->connectionPool->getConnectionForTable('tt_content')->select(['sorting'], 'tt_content', ['uid' => self::ELEMENT])->fetchOne(), 'core renumbered');
        self::assertSame((int) $row['uid'], $this->liveOrder($this->liveRows())[0], 'the new element comes first');
    }

    /**
     * A connected translation another workspace drafted is no reason to
     * refuse in this one; in live, where any workspace may be published, it
     * is.
     */
    #[Test]
    public function anotherWorkspacesTranslationDoesNotRefuseTheElementHere(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid'        => 19, 'pid' => self::ROOT, 'CType' => 'text', 'header' => 'Übersetzt', 'sys_language_uid' => 1, 'l18n_parent' => self::ELEMENT,
            't3ver_wsid' => self::OTHER_DRAFT, 't3ver_state' => 1,
        ]);
        $tool = $this->tool('create_content_element_draft');
        $call = ['page' => self::ROOT, 'type' => 'text', 'header' => 'Frei', 'language' => 1];

        self::assertStringNotContainsString('already holds connected translations', $this->card($tool, $call, $this->contextIn(self::DRAFT)));
        self::assertStringContainsString('already holds connected translations', $this->card($tool, $call, $this->contextIn(0)));
    }

    /**
     * The duplicate warnings read the workspace: an element or a page
     * deleted there is no copy in the draft workspace, and is one in live.
     */
    #[Test]
    public function aRecordDeletedInTheDraftWorkspaceIsNoDuplicateThere(): void
    {
        $tool = $this->tool('create_content_element_draft');
        $call = ['page' => self::ROOT, 'type' => 'text', 'header' => 'Gone in the draft'];

        self::assertStringNotContainsString('already exists on this page', $this->card($tool, $call, $this->contextIn(self::DRAFT)));
        self::assertStringContainsString('already exists on this page', $this->card($tool, $call, $this->contextIn(0)));

        // An element whose type the workspace changed is no copy of this type.
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid'        => 22, 'pid' => self::ROOT, 'colPos' => 0, 'CType' => 'header', 'header' => 'Second', 'sorting' => 512,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::NEXT_ELEMENT, 't3ver_state' => 0,
        ]);
        $retyped = ['page' => self::ROOT, 'type' => 'text', 'header' => 'Second'];
        self::assertStringNotContainsString('already exists on this page', $this->card($tool, $retyped, $this->contextIn(self::DRAFT)));
        self::assertStringContainsString('already exists on this page', $this->card($tool, $retyped, $this->contextIn(0)));

        // The same for a page.
        $pages = $this->connectionPool->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 6, 'pid' => self::ROOT, 'title' => 'Gone page', 'slug' => '/6', 'doktype' => 1, 'perms_everybody' => Permission::ALL]);
        $pages->insert('pages', ['uid' => 21, 'pid' => self::ROOT, 'title' => 'Gone page', 'slug' => '/6', 'doktype' => 1, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 6, 't3ver_state' => 2]);

        $pageTool = $this->tool('create_page_draft');
        $pageCall = ['parent' => self::ROOT, 'title' => 'Gone page'];
        self::assertStringNotContainsString('already exists under this page', $this->card($pageTool, $pageCall, $this->contextIn(self::DRAFT)));
        self::assertStringContainsString('already exists under this page', $this->card($pageTool, $pageCall, $this->contextIn(0)));
    }

    /**
     * Where the table keeps no draft in a workspace, the two tools are
     * refused before the card, and the card says so.
     */
    #[Test]
    public function aTableTheInstallationEditsLiveIsRefusedBeforeTheCard(): void
    {
        $context = $this->contextIn(self::DRAFT);
        foreach (['create_content_element_draft' => ['tt_content', ['page' => self::ROOT, 'type' => 'text', 'header' => 'x']], 'create_page_draft' => ['pages', ['parent' => self::ROOT, 'title' => 'x']]] as $name => [$table, $call]) {
            $tool = $this->tool($name);
            self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $tool);
            self::assertNull($tool->refusalBeforeApproval($call, $context), $name . ' with versioning');

            $original = $GLOBALS['TCA'];
            /** @var array<string, array<string, array<string, mixed>>> $tca */
            $tca                                                     = $original;
            $tca[$table]['ctrl']['versioningWS_alwaysAllowLiveEdit'] = true;
            $GLOBALS['TCA']                                          = $tca;
            try {
                // A copy of the call: the same call was asked above, under the
                // other TCA, and the answer is read again.
                self::assertInstanceOf(ToolResult::class, $tool->refusalBeforeApproval([...$call], $context), $name);
                self::assertStringContainsString('Not possible in the workspace “Entwurf”', $this->card($tool, $call, $context), $name);
                self::assertStringContainsString('has no versioning or allows editing live', $tool->execute($call, $context)->content, $name);
            } finally {
                $GLOBALS['TCA'] = $original;
            }
        }

        self::assertSame(0, $this->countNewRows('tt_content') + $this->countNewRows('pages'));
    }

    /**
     * @param array<string, mixed> $call
     */
    private function card(ToolInterface $tool, array $call, ToolExecutionContext $context): string
    {
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);

        return implode("\n", $tool->previewCall($call, $context));
    }

    private function dispatchWriteEvent(ToolResult $result, int $workspace): void
    {
        self::assertNotNull($result->writeTarget);
        self::assertNotNull($result->writeKind);
        $dispatcher = $this->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new AfterAiRecordWrittenEvent('run-1', $result->writeTarget, $result->writeKind, $workspace));
    }

    private function contextIn(int $workspace): ToolExecutionContext
    {
        $admin = $this->adminIn($workspace);

        return ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true, [], [], $workspace), $admin);
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

    /**
     * @return array<string, mixed>
     */
    private function createdRow(string $table, ToolResult $result): array
    {
        self::assertNotNull($result->writeTarget);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from($table)
            ->where($queryBuilder->expr()->eq('uid', $result->writeTarget->uid))->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * The uids of the live elements of the root page, by their sorting.
     *
     * @param array<string, list<array<string, mixed>>> $rows
     *
     * @return list<int>
     */
    private function liveOrder(array $rows): array
    {
        $elements = array_values(array_filter($rows['tt_content'], static fn (array $row): bool => (int) $row['pid'] === self::ROOT));
        usort($elements, static fn (array $a, array $b): int => [(int) $a['sorting'], (int) $a['uid']] <=> [(int) $b['sorting'], (int) $b['uid']]);

        return array_map(static fn (array $row): int => (int) $row['uid'], $elements);
    }

    private function countNewRows(string $table): int
    {
        return $this->connectionPool->getConnectionForTable($table)->count('uid', $table, []) - ($table === 'pages' ? 5 : 6);
    }

    /**
     * The call was refused for want of room, the record it inserted was
     * taken back, and no row of `pages` or `tt_content` differs from before.
     *
     * @param array<string, list<array<string, mixed>>> $before
     */
    private function assertRefusedForWantOfRoom(ToolResult $result, array $before, string $case): void
    {
        self::assertTrue($result->isError, $case . ': ' . $result->content);
        self::assertStringContainsString('no room in the sort order', $result->content, $case);
        self::assertStringNotContainsString('COULD NOT BE DELETED', $result->content, $case);
        self::assertSame($before, $this->everyRow(), 'a row changed (' . $case . ')');
    }

    /**
     * How many records of `$table` core logged as deleted by `$user` without
     * an error: a discard logs one, a refused insert none (`DataHandler::
     * discard()`, 14.3.10 and 13.4.37).
     */
    private function discardsLoggedBy(int $user, string $table): int
    {
        return $this->connectionPool->getConnectionForTable('sys_log')->count('uid', 'sys_log', [
            'userid' => $user, 'tablename' => $table, 'action' => SystemLogDatabaseAction::DELETE, 'error' => 0,
        ]);
    }

    /**
     * Every row of the tables the tools write, live and of every workspace,
     * whole.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function everyRow(): array
    {
        $rows = [];
        foreach (['pages', 'tt_content'] as $table) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            /** @var list<array<string, mixed>> $all */
            $all          = $queryBuilder->select('*')->from($table)->orderBy('uid')->executeQuery()->fetchAllAssociative();
            $rows[$table] = $all;
        }

        return $rows;
    }

    /**
     * Every live row of the tables the tools write, whole.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function liveRows(): array
    {
        $rows = [];
        foreach (['pages', 'tt_content'] as $table) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            /** @var list<array<string, mixed>> $all */
            $all          = $queryBuilder->select('*')->from($table)->where($queryBuilder->expr()->eq('t3ver_wsid', 0))->orderBy('uid')->executeQuery()->fetchAllAssociative();
            $rows[$table] = $all;
        }

        return $rows;
    }
}
