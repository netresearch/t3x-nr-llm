<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\Builtin\GetPageContentTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateContentElementTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Service\Tool\WorkerWriteEnvironment;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * A chat run started in a draft workspace changes a content element there and
 * reads the page as that workspace shows it; live stays as it is (ADR-260).
 */
#[CoversClass(UpdateContentElementTool::class)]
#[CoversClass(GetPageContentTool::class)]
#[CoversClass(ActingBackendUserResolver::class)]
final class WritesIntoTheActorsWorkspaceTest extends AbstractFunctionalTestCase
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

        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'           => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1,
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([
            [self::ELEMENT, 'Live header', 0, 0, 0],
            [self::ELEMENT_WITH_DRAFT, 'Second live', 0, 0, 0],
            [self::DRAFT_OF_ELEMENT, 'Second in the draft', self::DRAFT, self::ELEMENT_WITH_DRAFT, 0],
            [self::NEW_IN_DRAFT, 'New in the draft', self::DRAFT, 0, 1],
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
    public function aChangeInADraftWorkspaceIsStoredAsAVersionAndLiveStaysAsItIs(): void
    {
        $tool    = $this->tool('update_content_element');
        $context = $this->contextIn(self::DRAFT);
        $call    = ['uid' => self::ELEMENT, 'fields' => ['header' => 'Changed by AI']];

        self::assertInstanceOf(ToolPreviewInterface::class, $tool);
        $card = implode("\n", $tool->previewCall($call, $context));
        self::assertStringContainsString('Workspace: “Entwurf”', $card, 'the card names the workspace written to');

        $result = $tool->execute($call, $context);

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Live header', $this->header(self::ELEMENT), 'live changed');
        $version = $this->versionOf(self::ELEMENT, self::DRAFT);
        self::assertNotNull($version, 'no workspace version was stored');
        self::assertSame('Changed by AI', $version['header']);

        // A second change goes to the same version.
        $again = $tool->execute(['uid' => self::ELEMENT, 'fields' => ['header' => 'Changed again']], $context);
        self::assertFalse($again->isError, $again->content);
        self::assertSame('Changed again', $this->versionOf(self::ELEMENT, self::DRAFT)['header'] ?? null);
        self::assertSame(1, $this->versionCount(self::ELEMENT));
    }

    #[Test]
    public function theCardShowsTheWorkspacesValueAsTheCurrentOne(): void
    {
        $tool = $this->tool('update_content_element');
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);

        $card = implode("\n", $tool->previewCall(['uid' => self::ELEMENT_WITH_DRAFT, 'fields' => ['header' => 'x']], $this->contextIn(self::DRAFT)));

        self::assertStringContainsString('“Second in the draft”', $card);
        self::assertStringNotContainsString('Second live', $card);
    }

    #[Test]
    public function anotherWorkspacesDraftIsNeitherShownNorChanged(): void
    {
        $tool    = $this->tool('update_content_element');
        $context = $this->contextIn(self::OTHER_DRAFT);
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);

        $card = implode("\n", $tool->previewCall(['uid' => self::ELEMENT_WITH_DRAFT, 'fields' => ['header' => 'x']], $context));
        self::assertStringContainsString('“Second live”', $card);
        self::assertStringNotContainsString('Second in the draft', $card);

        // Neither the version nor the new record of the first draft workspace
        // is there for a run in the second one.
        foreach ([self::DRAFT_OF_ELEMENT, self::NEW_IN_DRAFT] as $uid) {
            $result = $tool->execute(['uid' => $uid, 'fields' => ['header' => 'x']], $context);
            self::assertTrue($result->isError, (string) $uid);
            self::assertStringContainsString('not found or not permitted', $result->content);
        }

        self::assertSame('Second in the draft', $this->header(self::DRAFT_OF_ELEMENT));
        self::assertSame('New in the draft', $this->header(self::NEW_IN_DRAFT));
    }

    #[Test]
    public function aRecordNewInTheDraftWorkspaceIsChangedInPlace(): void
    {
        $result = $this->tool('update_content_element')->execute(
            ['uid' => self::NEW_IN_DRAFT, 'fields' => ['header' => 'New, changed']],
            $this->contextIn(self::DRAFT),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('New, changed', $this->header(self::NEW_IN_DRAFT));
        self::assertSame(0, $this->versionCount(self::NEW_IN_DRAFT));
    }

    #[Test]
    public function aRunThatDoesNotKnowItsWorkspaceWritesNothingInADraftWorkspace(): void
    {
        $user = $this->adminIn(self::DRAFT);
        // An actor queued before the workspace was recorded: no workspace.
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true), $user);

        $result = $this->tool('update_content_element')->execute(['uid' => self::ELEMENT, 'fields' => ['header' => 'x']], $context);

        self::assertTrue($result->isError);
        self::assertStringContainsString('does not record which workspace', $result->content);
        self::assertSame('Live header', $this->header(self::ELEMENT));
        self::assertNull($this->versionOf(self::ELEMENT, self::DRAFT));
    }

    /**
     * A write the worker carries out after a stored approval (ADR-281): the
     * process has no backend user and no language service, and the writer
     * refuses there (ADR-135). With the worker's environment around the call
     * it stores the draft as the run's user, and the environment is gone again.
     */
    #[Test]
    public function aWriteInABareWorkerNeedsTheWorkerEnvironment(): void
    {
        $tool    = $this->tool('update_content_element');
        $user    = $this->adminIn(self::DRAFT);
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true, [], [], self::DRAFT), $user);
        $call    = ['uid' => self::ELEMENT, 'fields' => ['header' => 'Changed by the worker']];

        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        $refused = $tool->execute($call, $context);
        self::assertTrue($refused->isError);
        self::assertStringContainsString('writing needs a full backend environment', $refused->content);
        self::assertNull($this->versionOf(self::ELEMENT, self::DRAFT), 'the refused write stored nothing');

        $environment = new WorkerWriteEnvironment($this->getService(LanguageServiceFactory::class), true);
        $result      = $environment->around($user, static fn (): ToolResult => $tool->execute($call, $context));

        self::assertFalse($result->isError, $result->content);
        self::assertSame('Changed by the worker', $this->versionOf(self::ELEMENT, self::DRAFT)['header'] ?? null);
        self::assertSame('Live header', $this->header(self::ELEMENT), 'live is untouched');
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertArrayNotHasKey('LANG', $GLOBALS);
    }

    #[Test]
    public function inLiveTheCardHasNoWorkspaceLineAndLiveIsWritten(): void
    {
        $tool = $this->tool('update_content_element');
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);
        $context = $this->contextIn(0);
        $call    = ['uid' => self::ELEMENT, 'fields' => ['header' => 'Live change']];

        self::assertStringNotContainsString('Workspace', implode("\n", $tool->previewCall($call, $context)));
        self::assertFalse($tool->execute($call, $context)->isError);
        self::assertSame('Live change', $this->header(self::ELEMENT));
    }

    #[Test]
    public function thePageIsReadAsTheDraftWorkspaceShowsIt(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'        => 40, 'pid' => 0, 'title' => 'Root in the draft', 'slug' => '/', 'doktype' => 1,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => 1, 't3ver_state' => 0,
        ]);
        $tool = $this->tool('get_page_content');

        $draft = $tool->execute(['uid' => 1], $this->contextIn(self::DRAFT))->content;
        self::assertStringStartsWith('Page [1] Root in the draft', $draft);
        self::assertStringContainsString('[' . self::ELEMENT_WITH_DRAFT . '] colPos=0 text · Second in the draft', $draft);
        self::assertStringContainsString('[' . self::NEW_IN_DRAFT . '] colPos=0 text · New in the draft', $draft);
        self::assertStringNotContainsString('Second live', $draft);
        self::assertStringNotContainsString('[' . self::DRAFT_OF_ELEMENT . ']', $draft, 'a version is shown under its live uid');

        // Live, and another workspace, never see the first workspace's drafts.
        foreach ([0, self::OTHER_DRAFT] as $workspace) {
            $other = $tool->execute(['uid' => 1], $this->contextIn($workspace))->content;
            self::assertStringStartsWith('Page [1] Root (', $other, (string) $workspace);
            self::assertStringContainsString('Second live', $other, (string) $workspace);
            self::assertStringNotContainsString('in the draft', $other, (string) $workspace);
        }
    }

    /**
     * For an editor, visibility is the version's: hidden in live but shown in
     * the draft workspace is listed, ended in the draft workspace is not.
     */
    #[Test]
    public function anEditorSeesWhatTheDraftWorkspaceMakesVisible(): void
    {
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 50, 'pid' => 1, 'CType' => 'text', 'header' => 'Hidden live', 'hidden' => 1, 'sorting' => 50]);
        $content->insert('tt_content', ['uid' => 51, 'pid' => 1, 'CType' => 'text', 'header' => 'Shown in the draft', 'hidden' => 0, 'sorting' => 50, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 50]);
        $content->insert('tt_content', ['uid' => 52, 'pid' => 1, 'CType' => 'text', 'header' => 'Ends in the draft', 'sorting' => 52]);
        $content->insert('tt_content', ['uid' => 53, 'pid' => 1, 'CType' => 'text', 'header' => 'Ended in the draft', 'endtime' => 1000, 'sorting' => 52, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 52]);

        // The page tree from the root, as the editor's own mount.
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', ['db_mountpoints' => '1', 'options' => 3], ['uid' => 2]);
        $editor = $this->setUpBackendUser(2);
        self::assertFalse($editor->isAdmin());
        self::assertSame(self::DRAFT, $editor->workspace, 'positive control: the editor works in the draft workspace');

        $draft = $this->tool('get_page_content')->execute(['uid' => 1], ToolExecutionContext::fromBackendUser($editor))->content;

        self::assertStringContainsString('[50] colPos=0 text · Shown in the draft', $draft);
        self::assertStringNotContainsString('[52]', $draft);
        self::assertStringNotContainsString('Ended in the draft', $draft);
    }

    /**
     * A run that did not record its workspace, while its user works in a draft
     * one: its card neither promises a draft nor shows the draft's values,
     * and the page is read live, as before (ADR-260).
     */
    #[Test]
    public function aRunThatDoesNotKnowItsWorkspaceNeitherShowsNorReadsTheDraft(): void
    {
        $tool = $this->tool('update_content_element');
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);
        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(1, true), $this->adminIn(self::DRAFT));

        $card = implode("\n", $tool->previewCall(['uid' => self::ELEMENT_WITH_DRAFT, 'fields' => ['header' => 'x']], $context));

        self::assertStringNotContainsString('Workspace', $card, 'the card promised a draft the write refuses');
        self::assertStringNotContainsString('in the draft', $card, "the card showed the draft's value");
        self::assertStringContainsString('does not record which workspace', $card);

        $page = $this->tool('get_page_content')->execute(['uid' => 1], $context)->content;
        self::assertStringContainsString('Second live', $page);
        self::assertStringNotContainsString('in the draft', $page);
    }

    #[Test]
    public function anotherLanguageNewInTheDraftWorkspaceIsNamedThere(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid'        => 30, 'pid' => 1, 'CType' => 'text', 'header' => 'Neu', 'sys_language_uid' => 1,
            't3ver_wsid' => self::DRAFT, 't3ver_oid' => 0, 't3ver_state' => 1,
        ]);
        $tool = $this->tool('get_page_content');

        self::assertStringContainsString('language 1 (1)', $tool->execute(['uid' => 1], $this->contextIn(self::DRAFT))->content);
        self::assertStringNotContainsString('language 1', $tool->execute(['uid' => 1], $this->contextIn(0))->content);
    }

    #[Test]
    public function aLanguageWhoseElementsAreDeletedInTheDraftWorkspaceIsNotNamedThere(): void
    {
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 60, 'pid' => 1, 'CType' => 'text', 'header' => 'Fr', 'sys_language_uid' => 1]);
        $content->insert('tt_content', ['uid' => 61, 'pid' => 1, 'CType' => 'text', 'header' => 'Fr', 'sys_language_uid' => 1, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 60, 't3ver_state' => 2]);

        $tool = $this->tool('get_page_content');

        self::assertStringContainsString('language 1 (1)', $tool->execute(['uid' => 1], $this->contextIn(0))->content);
        self::assertStringNotContainsString('language 1', $tool->execute(['uid' => 1], $this->contextIn(self::DRAFT))->content);
    }

    /**
     * The 100 elements a list shows are the ones the workspace shows: rows
     * deleted there do not take their places.
     */
    #[Test]
    public function rowsDeletedInTheDraftWorkspaceDoNotTakeTheListsPlaces(): void
    {
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        for ($i = 0; $i < 100; ++$i) {
            $content->insert('tt_content', ['uid' => 1000 + $i, 'pid' => 1, 'CType' => 'text', 'header' => 'Gone', 'sorting' => 1]);
            $content->insert('tt_content', ['uid' => 2000 + $i, 'pid' => 1, 'CType' => 'text', 'header' => 'Gone', 'sorting' => 1, 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 1000 + $i, 't3ver_state' => 2]);
        }

        $draft = $this->tool('get_page_content')->execute(['uid' => 1], $this->contextIn(self::DRAFT))->content;

        self::assertStringContainsString('[' . self::ELEMENT . '] colPos=0 text · Live header', $draft);
        self::assertStringNotContainsString('Gone', $draft);
    }

    #[Test]
    public function anElementDeletedOrMovedAwayInTheDraftWorkspaceIsNotOnThePageThere(): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'          => 2, 'pid' => 1, 'title' => 'Other page', 'slug' => '/other', 'doktype' => 1,
            'perms_userid' => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL,
        ]);
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        // Element 10 deleted in the draft workspace, element 11's draft moved to page 2.
        $content->insert('tt_content', ['uid' => 20, 'pid' => 1, 'CType' => 'text', 'header' => 'Live header', 't3ver_wsid' => self::DRAFT, 't3ver_oid' => self::ELEMENT, 't3ver_state' => 2]);
        $content->update('tt_content', ['pid' => 2, 't3ver_state' => 4], ['uid' => self::DRAFT_OF_ELEMENT]);

        $tool  = $this->tool('get_page_content');
        $page1 = $tool->execute(['uid' => 1], $this->contextIn(self::DRAFT))->content;
        $page2 = $tool->execute(['uid' => 2], $this->contextIn(self::DRAFT))->content;

        self::assertStringNotContainsString('[' . self::ELEMENT . ']', $page1);
        self::assertStringNotContainsString('[' . self::ELEMENT_WITH_DRAFT . ']', $page1);
        self::assertStringContainsString('[' . self::ELEMENT_WITH_DRAFT . '] colPos=0 text · Second in the draft', $page2);
    }

    #[Test]
    public function theResolverPinsTheWorkspaceTheRunWasStartedIn(): void
    {
        $users = $this->connectionPool->getConnectionForTable('be_users');
        $users->update('be_users', ['workspace_id' => 0], ['uid' => 1]);

        $user = (new ActingBackendUserResolver())->resolve(AiActorContext::backendUser(1, true, [], [], self::DRAFT));

        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        self::assertSame(self::DRAFT, $user->workspace);
        self::assertSame(0, (int) $users->select(['workspace_id'], 'be_users', ['uid' => 1])->fetchOne(), "the editor's own workspace was changed");

        // Not known: the workspace the user's record names, as before.
        $users->update('be_users', ['workspace_id' => self::OTHER_DRAFT], ['uid' => 1]);
        self::assertSame(self::OTHER_DRAFT, (new ActingBackendUserResolver())->resolve(AiActorContext::backendUser(1, true))?->workspace);
    }

    #[Test]
    public function theResolverGivesNoUserWhereTheUserMayNoLongerWork(): void
    {
        // A third workspace the editor works in now, so a fallback of core's
        // to the first one they may use (the draft) shows on their record.
        $this->connectionPool->getConnectionForTable('sys_workspace')
            ->insert('sys_workspace', ['uid' => 3, 'pid' => 0, 'title' => 'Third', 'members' => 'be_users_2']);
        $users = $this->connectionPool->getConnectionForTable('be_users');
        $users->update('be_users', ['workspace_id' => 3], ['uid' => 2]);
        // Core logs a fallback through the ambient user.
        $this->setUpBackendUser(1);

        // The editor is a member of the first draft workspace only, and has
        // no access to live: a run pinned elsewhere, or to a workspace that
        // is gone, has no user.
        $resolver = new ActingBackendUserResolver();
        self::assertSame(self::DRAFT, $resolver->resolve(AiActorContext::backendUser(2, false, [], [], self::DRAFT))?->workspace);
        self::assertNull($resolver->resolve(AiActorContext::backendUser(2, false, [], [], self::OTHER_DRAFT)));
        self::assertNull($resolver->resolve(AiActorContext::backendUser(2, false, [], [], 0)));
        self::assertNull($resolver->resolve(AiActorContext::backendUser(1, true, [], [], 99)));
        self::assertSame(3, (int) $users->select(['workspace_id'], 'be_users', ['uid' => 2])->fetchOne(), "the editor's own workspace was changed");
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

    private function header(int $uid): string
    {
        $value = $this->connectionPool->getConnectionForTable('tt_content')->select(['header'], 'tt_content', ['uid' => $uid])->fetchOne();

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function versionOf(int $uid, int $workspace): ?array
    {
        $row = $this->connectionPool->getConnectionForTable('tt_content')
            ->select(['*'], 'tt_content', ['t3ver_oid' => $uid, 't3ver_wsid' => $workspace])->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function versionCount(int $uid): int
    {
        return $this->connectionPool->getConnectionForTable('tt_content')->count('uid', 'tt_content', ['t3ver_oid' => $uid]);
    }
}
