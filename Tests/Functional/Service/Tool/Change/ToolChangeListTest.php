<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Change;

use ArrayObject;
use Closure;
use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Domain\Enum\ChangeCoverage;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChange;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChangeSet;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\CopyRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use Netresearch\NrLlm\Service\Tool\Change\ObservedChangeResolver;
use Netresearch\NrLlm\Service\Tool\Change\ToolChangeCollector;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixtures\Database\FailsOneReadConnectionPool;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What the tool loop records of the records a tool call wrote (ADR-292),
 * against a real database, the real DataHandler with its hooks and the real
 * tool loop. Only the provider is scripted.
 *
 * Every tool here is a thin wrapper that declares no effect, so the loop runs
 * it without an approval; the writes inside are the real ones, through
 * {@see ToolDataHandler} or the real {@see CopyRecordTool}.
 */
#[CoversClass(ToolChangeCollector::class)]
#[CoversClass(ToolLoopService::class)]
#[CoversClass(RunTrace::class)]
final class ToolChangeListTest extends AbstractFunctionalTestCase
{
    use RegistersTheFailingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    private const ROOT = 1;

    private const PAGE = 3;

    private const PAGE_TO_COPY = 5;

    private const SUBPAGE = 7;

    private const LOCKED_PAGE = 8;

    private const LOCKED_ELEMENT = 33;

    private const ELEMENT = 31;

    private const ELEMENT_ON_PAGE_TO_COPY = 32;

    private const DRAFT = 1;

    private const GERMAN = 1;

    private ConnectionPool $connectionPool;

    /** Keeps what the loop logged about a failed tool, which the tool's answer leaves out on purpose. */
    private AbstractLogger $logger;

    /** @var ArrayObject<int, string> */
    private ArrayObject $logged;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logged = new ArrayObject();
        $this->logger = new class ($this->logged) extends AbstractLogger {
            /**
             * @param ArrayObject<int, string> $logged
             */
            public function __construct(private readonly ArrayObject $logged) {}

            /**
             * @param array<string, mixed> $context
             */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $failure = $context['exception'] ?? null;
                $this->logged->append($message . ($failure instanceof Throwable ? ' ' . $failure::class . ': ' . $failure->getMessage() : ''));
            }
        };

        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;

        $this->importFixture('BeUsers.csv');
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);

        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([
            [self::ROOT, 0, 'Root', 1],
            [self::PAGE, self::ROOT, 'Page', 0],
            [self::PAGE_TO_COPY, self::ROOT, 'Template page', 0],
            [self::SUBPAGE, self::PAGE_TO_COPY, 'Template child', 0],
        ] as [$uid, $pid, $title, $siteRoot]) {
            $pages->insert('pages', [
                'uid'           => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'slug' => '/' . $uid,
                'is_siteroot'   => $siteRoot, 'sorting' => $uid * 256,
                'perms_userid'  => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
            ]);
        }

        $pages->insert('pages', [
            'uid'           => self::LOCKED_PAGE, 'pid' => self::ROOT, 'title' => 'Locked', 'doktype' => 1, 'slug' => '/locked', 'sorting' => 2048,
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([[self::ELEMENT, self::PAGE, 'Before'], [self::ELEMENT_ON_PAGE_TO_COPY, self::PAGE_TO_COPY, 'On the template'], [self::LOCKED_ELEMENT, self::LOCKED_PAGE, 'Locked']] as [$uid, $pid, $header]) {
            $content->insert('tt_content', ['uid' => $uid, 'pid' => $pid, 'colPos' => 0, 'CType' => 'text', 'header' => $header, 'sorting' => $uid * 256]);
        }

        $siteWriter = $this->get(SiteWriter::class);
        self::assertInstanceOf(SiteWriter::class, $siteWriter);
        $siteWriter->write('testing', [
            'rootPageId' => self::ROOT,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'English', 'base' => '/', 'locale' => 'en_US.UTF-8', 'flag' => 'us'],
                ['languageId' => self::GERMAN, 'title' => 'German', 'base' => '/de/', 'locale' => 'de_DE.UTF-8', 'flag' => 'de', 'fallbackType' => 'strict'],
            ],
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
        ToolChangeCollector::reset();
    }

    protected function tearDown(): void
    {
        ToolChangeCollector::reset();
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aToolWritingOneRecordLeavesOneChangeAfterItsToolStep(): void
    {
        $trace = $this->runLoopWith($this->tool(function (ToolExecutionContext $context): ToolResult {
            $this->updateHeader(self::ELEMENT, 'Written once', $context);

            return ToolResult::text('WROTE')->withWriteTarget(new RecordReference('tt_content', self::ELEMENT), WriteKind::UPDATED, WriteCompleteness::COMPLETE);
        }));

        $kinds = array_column(array_map(static fn (RunStep $step): array => ['kind' => $step->kind], array_values(array_filter($trace->getSteps(), static fn (RunStep $step): bool => !in_array($step->kind, [RunStep::KIND_REQUEST, RunStep::KIND_LLM, RunStep::KIND_CONTEXT], true)))), 'kind');
        self::assertSame([RunStep::KIND_TOOL, RunStep::KIND_WRITE, RunStep::KIND_CHANGES], $kinds);

        $step = $this->changesStep($trace);
        $set  = $step->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        self::assertSame(ChangeCoverage::DATAHANDLER, $set->coverage);
        self::assertCount(1, $set->changes);

        $change = $set->changes[0];
        self::assertSame('tt_content:' . self::ELEMENT, (string) $change->record);
        self::assertSame(ChangeAction::UPDATED, $change->action);
        self::assertTrue($change->isWriteTarget);
        self::assertSame(0, $change->workspace);
        self::assertFalse($change->isDraft());
        self::assertFalse($change->hidden);
        self::assertSame([], $change->restrictions);

        // The step carries identities and stored flags, never what the row holds.
        self::assertStringNotContainsString('Written once', json_encode($step->toArray(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aCopyWithDependentRecordsListsEveryRecordItWroteAndNamesOneAsTheTarget(): void
    {
        $copy  = new CopyRecordTool($this->connectionPool, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)));
        $trace = $this->runLoopWith($this->tool(
            static fn (ToolExecutionContext $context): ToolResult => $copy->execute(['table' => 'pages', 'uid' => self::PAGE_TO_COPY, 'target_page' => self::PAGE], $context),
        ));

        $write = $this->stepOfKind($trace, RunStep::KIND_WRITE);
        self::assertInstanceOf(RecordReference::class, $write->writeTarget);
        self::assertSame('pages', $write->writeTarget->table);

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        $byRecord = [];
        foreach ($set->changes as $change) {
            $byRecord[(string) $change->record] = $change;
        }

        $page = $byRecord[(string) $write->writeTarget] ?? null;
        self::assertInstanceOf(ObservedChange::class, $page, 'the copied page is listed: ' . implode(', ', array_keys($byRecord)));
        self::assertTrue($page->isWriteTarget);
        self::assertSame(ChangeAction::CREATED, $page->action);
        self::assertTrue($page->hidden, 'a copy comes hidden');

        $elements = array_filter($set->changes, static fn (ObservedChange $change): bool => $change->record->table === 'tt_content');
        self::assertCount(1, $elements, 'the content of the page is listed as well: ' . implode(', ', array_keys($byRecord)));
        $element = array_values($elements)[0];
        self::assertSame(ChangeAction::CREATED, $element->action);
        self::assertFalse($element->isWriteTarget, "a dependent record is not the tool's target");
        self::assertCount(2, $set->changes, 'the subpage is not copied: ' . implode(', ', array_keys($byRecord)));
    }

    /**
     * Core deletes a page's content and subpages without a command of its own,
     * so only the DataHandler's history names them.
     */
    #[Test]
    public function aDeleteListsTheRecordsCoreTookAlongThatNoHookSaw(): void
    {
        $trace = $this->runLoopWith($this->tool(function (ToolExecutionContext $context): ToolResult {
            $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
            $dataHandler->start([], ['pages' => [self::PAGE_TO_COPY => ['delete' => 1]]], $context->actingBackendUser());
            $dataHandler->process_cmdmap();

            return ToolResult::text('DELETED');
        }));

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        $actions = [];
        foreach ($set->changes as $change) {
            $actions[(string) $change->record] = $change->action;
        }

        self::assertSame(ChangeAction::DELETED, $actions['pages:' . self::PAGE_TO_COPY] ?? null);
        self::assertSame(ChangeAction::DELETED, $actions['tt_content:' . self::ELEMENT_ON_PAGE_TO_COPY] ?? null, 'the content of the page: ' . implode(', ', array_keys($actions)));
        self::assertSame(ChangeAction::DELETED, $actions['pages:' . self::SUBPAGE] ?? null, 'the subpage: ' . implode(', ', array_keys($actions)));
    }

    #[Test]
    public function aToolThatWritesAndThenThrowsKeepsItsChangeListAndItsError(): void
    {
        $result = null;
        $trace  = $this->runLoopWith($this->tool(function (ToolExecutionContext $context): ToolResult {
            $this->updateHeader(self::ELEMENT, 'Written before the failure', $context);

            throw new RuntimeException('boom', 1799990293);
        }), $result);

        $tool = $this->stepOfKind($trace, RunStep::KIND_TOOL);
        self::assertTrue($tool->toolIsError);
        self::assertStringStartsWith('Error: tool "change_list_probe" failed.', (string) $tool->toolResult);
        self::assertStringContainsString('Before it failed this call changed: tt_content:' . self::ELEMENT . ' (updated)', (string) $tool->toolResult);
        self::assertStringNotContainsString('boom', (string) $tool->toolResult);

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        self::assertCount(1, $set->changes);
        self::assertSame('tt_content:' . self::ELEMENT, (string) $set->changes[0]->record);
        self::assertFalse($set->changes[0]->isWriteTarget, 'an error result names no target (ADR-182)');
        self::assertFalse(ToolChangeCollector::isArmed());
    }

    #[Test]
    public function aWriteInADraftWorkspaceNamesTheWorkspaceAndTheLiveRecord(): void
    {
        $admin = $this->useASessionlessAmbientUser(1);
        self::assertTrue($admin->setTemporaryWorkspace(self::DRAFT));

        $trace = $this->runLoopWith($this->tool(function (ToolExecutionContext $context): ToolResult {
            $this->updateHeader(self::ELEMENT, 'Draft header', $context);

            return ToolResult::text('WROTE')->withWriteTarget(new RecordReference('tt_content', self::ELEMENT), WriteKind::UPDATED, WriteCompleteness::COMPLETE);
        }), user: $admin);

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        self::assertSame(ChangeCoverage::DATAHANDLER, $set->coverage, "the version is the tool's target through its live uid");
        $listed = implode(', ', array_map(static fn (ObservedChange $change): string => (string) $change->record, $set->changes));
        self::assertCount(1, $set->changes, 'exactly the version, no live row beside it: ' . $listed);
        $versions = array_values(array_filter($set->changes, static fn (ObservedChange $change): bool => $change->liveUid === self::ELEMENT));
        self::assertCount(1, $versions, 'one version of the live element: ' . $listed);

        $version = $versions[0];
        self::assertNotSame(self::ELEMENT, $version->record->uid, 'the row written is the version, not the live row');
        self::assertSame(self::DRAFT, $version->workspace);
        self::assertTrue($version->isDraft());
        self::assertTrue($version->isWriteTarget);
        self::assertSame('Before', $this->headerOf(self::ELEMENT), 'live is unchanged');
    }

    /**
     * The row says where it is stored, not the workspace of the user who wrote
     * it: a table without versioning is written live by a user in a workspace
     * that allows live editing.
     */
    #[Test]
    public function aLiveWriteByAUserInADraftWorkspaceIsNotLabelledAsADraft(): void
    {
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => 3, 'pid' => 0, 'title' => 'Live editing', 'members' => '', 'live_edit' => 1]);
        $this->connectionPool->getConnectionForTable('fe_users')->insert('fe_users', ['uid' => 1, 'pid' => self::PAGE, 'username' => 'visitor', 'name' => 'Before']);
        $admin = $this->useASessionlessAmbientUser(1);
        self::assertTrue($admin->setTemporaryWorkspace(3));

        $trace = $this->runLoopWith($this->tool(static function (ToolExecutionContext $context): ToolResult {
            $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
            $dataHandler->start(['fe_users' => [1 => ['name' => 'After']]], [], $context->actingBackendUser());
            $dataHandler->process_datamap();

            return ToolResult::text('WROTE');
        }), user: $admin);

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        self::assertCount(1, $set->changes);
        self::assertSame('fe_users:1', (string) $set->changes[0]->record);
        self::assertSame(0, $set->changes[0]->workspace, 'the row is live');
        self::assertFalse($set->changes[0]->isDraft());
        self::assertSame('After', $this->connectionPool->getConnectionForTable('fe_users')->select(['name'], 'fe_users', ['uid' => 1])->fetchOne(), 'positive control: the write was made live');
    }

    /**
     * A move core makes live from a draft workspace (a table without
     * versioning, a workspace that allows live editing) has no version: the
     * live row is named as moved, in workspace 0, so that neither the list
     * nor the effect check of the scope loses it.
     */
    #[Test]
    public function aLiveMoveByAUserInADraftWorkspaceIsListedAsLive(): void
    {
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => 3, 'pid' => 0, 'title' => 'Live editing', 'members' => '', 'live_edit' => 1]);
        $this->connectionPool->getConnectionForTable('fe_users')->insert('fe_users', ['uid' => 1, 'pid' => self::PAGE, 'username' => 'visitor', 'name' => 'Before']);
        // Website users are allowed in a folder.
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', ['uid' => 90, 'pid' => self::ROOT, 'title' => 'Users', 'doktype' => 254]);
        $admin = $this->useASessionlessAmbientUser(1);
        self::assertTrue($admin->setTemporaryWorkspace(3));

        $trace = $this->runLoopWith($this->tool(static function (ToolExecutionContext $context): ToolResult {
            $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
            $dataHandler->start([], ['fe_users' => [1 => ['move' => 90]]], $context->actingBackendUser());
            $dataHandler->process_cmdmap();

            return ToolResult::text('MOVED');
        }), user: $admin);

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        $listed = implode(', ', array_map(static fn (ObservedChange $change): string => $change->record . '@' . $change->workspace . '(' . $change->action->value . ')', $set->changes));
        self::assertSame('fe_users:1@0(moved)', $listed);
        self::assertSame(90, (int) $this->connectionPool->getConnectionForTable('fe_users')->select(['pid'], 'fe_users', ['uid' => 1])->fetchOne(), 'positive control: the move was made live');
    }

    /**
     * The DataHandler ran an update for the record although no column changed,
     * and that is what the list states; the tool named it and is not told it
     * was missed.
     */
    #[Test]
    public function anUpdateThatChangedNoColumnIsStillListed(): void
    {
        $trace = $this->runLoopWith($this->tool(function (ToolExecutionContext $context): ToolResult {
            $this->updateHeader(self::ELEMENT, 'Before', $context);

            return ToolResult::text('WROTE')->withWriteTarget(new RecordReference('tt_content', self::ELEMENT), WriteKind::TEXT_UNCHANGED, WriteCompleteness::COMPLETE);
        }));

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        self::assertSame(ChangeCoverage::DATAHANDLER, $set->coverage);
        self::assertSame(['tt_content:' . self::ELEMENT], array_map(static fn (ObservedChange $change): string => (string) $change->record, $set->changes));
    }

    /**
     * `sys_history` has no index on its correlation id, so only the rows after
     * the uid the table had when the call began are read.
     */
    #[Test]
    public function theHistoryIsReadFromTheWatermarkOnly(): void
    {
        $history = $this->connectionPool->getConnectionForTable('sys_history');
        foreach ([[10, 'before'], [11, 'after']] as [$uid, $label]) {
            $history->insert('sys_history', [
                'uid'          => $uid, 'tstamp' => 1, 'actiontype' => 4, 'usertype' => 'BE', 'userid' => 1, 'recuid' => $uid, 'tablename' => 'tt_content',
                'history_data' => '[]', 'workspace' => 0, 'correlation_id' => '0000$scope1:subject' . $label,
            ]);
        }

        $resolver = new ObservedChangeResolver($this->connectionPool);

        $after = $resolver->resolve([], ['scope1'], 10);
        self::assertSame(['tt_content:11'], array_map(static fn (ObservedChange $change): string => (string) $change->record, $after->changes));

        self::assertSame([], $resolver->resolve([], ['scope1'])->changes, 'without a watermark no history is read');
        self::assertSame([], $resolver->resolve([], ['otherscope'], 0)->changes, "another chain's rows are not this call's");
    }

    /**
     * A row that cannot be read is not a row that is gone. A move is listed
     * as the hooks heard of it, and the list stays complete; a delete or
     * undelete, which would be listed
     * only if its row or the history bore it out, is counted as a record the
     * list could not settle, so the list does not read as complete. A delete of
     * a uid that no row bears, read without a failure, is still no change.
     */
    #[Test]
    public function aCommandWhoseRowCannotBeReadIsNotDroppedSilently(): void
    {
        $records = static fn (ChangeAction $action, int $uid = self::ELEMENT): array => ['tt_content:' . $uid => ['table' => 'tt_content', 'uid' => $uid, 'action' => $action, 'workspace' => 0]];

        // Only the read that settles the entry fails; the row is read again
        // for the list and found.
        $moved = (new ObservedChangeResolver(FailsOneReadConnectionPool::failing('tt_content', 1, 1)))->resolve($records(ChangeAction::MOVED), []);
        self::assertSame(['tt_content:' . self::ELEMENT], array_map(static fn (ObservedChange $change): string => (string) $change->record, $moved->changes), 'the move is listed');
        self::assertSame(0, $moved->omitted, 'the listed move is not also counted as left out');

        foreach ([ChangeAction::DELETED, ChangeAction::RESTORED] as $action) {
            $set = (new ObservedChangeResolver(FailsOneReadConnectionPool::failing('tt_content')))->resolve($records($action), []);
            self::assertSame(1, $set->omitted, $action->value . ': the list says it is not complete');
        }

        $gone = (new ObservedChangeResolver($this->connectionPool))->resolve($records(ChangeAction::DELETED, 9999), []);
        self::assertSame([], $gone->changes);
        self::assertSame(0, $gone->omitted, 'a row that is confirmed missing is no change and leaves the list complete');

        // In a draft workspace the version of a deleted or moved live row is
        // looked up after the row (the second read of the table); a lookup
        // that fails leaves the list incomplete.
        $inDraft = static fn (ChangeAction $action): array => ['tt_content:' . self::ELEMENT => ['table' => 'tt_content', 'uid' => self::ELEMENT, 'action' => $action, 'workspace' => self::DRAFT]];
        foreach ([ChangeAction::DELETED, ChangeAction::MOVED] as $action) {
            $pool = FailsOneReadConnectionPool::failing('tt_content', 2, 2);
            $set  = (new ObservedChangeResolver($pool))->resolve($inDraft($action), []);
            self::assertSame(1, $pool->failed, $action->value . ': the version lookup failed');
            self::assertSame(1, $set->omitted, $action->value . ': a version that cannot be looked up leaves the list incomplete');
        }
    }

    /**
     * The list reads each row it names for the facts it states (workspace,
     * live uid, language, hidden state, restrictions). A row whose read fails
     * there is listed with the writer's workspace only, as a row that is gone
     * is; the list then says it is not complete, so that a reader does not take
     * the missing facts for a missing row. One marker, however many reads fail.
     */
    #[Test]
    public function aListedChangeWhoseRowCannotBeReadLeavesTheListIncomplete(): void
    {
        $records = static fn (ChangeAction $action, int $workspace = 0): array => ['tt_content:' . self::ELEMENT => ['table' => 'tt_content', 'uid' => self::ELEMENT, 'action' => $action, 'workspace' => $workspace]];

        // An update and a creation are not read before the list: the list's
        // read is the first and only one.
        foreach ([ChangeAction::UPDATED, ChangeAction::CREATED] as $action) {
            $pool = FailsOneReadConnectionPool::failing('tt_content');
            $set  = (new ObservedChangeResolver($pool))->resolve($records($action), []);
            self::assertSame(1, $pool->failed, $action->value . ': the row read failed');
            self::assertSame(['tt_content:' . self::ELEMENT], array_map(static fn (ObservedChange $change): string => (string) $change->record, $set->changes), $action->value . ': the change is listed');
            self::assertSame(1, $set->omitted, $action->value . ': the list says it is not complete');
        }

        // A move, delete or undelete is read once to settle it and once more
        // for the list; only the second read fails.
        foreach ([ChangeAction::MOVED, ChangeAction::DELETED, ChangeAction::RESTORED] as $action) {
            $pool = FailsOneReadConnectionPool::failing('tt_content', 2, 2);
            $set  = (new ObservedChangeResolver($pool))->resolve($records($action), []);
            self::assertSame(1, $pool->failed, $action->value . ': the second read failed');
            self::assertCount(1, $set->changes, $action->value . ': the change is listed');
            self::assertSame(1, $set->omitted, $action->value . ': the list says it is not complete');
        }

        // A move whose every read fails is listed and marked once.
        $moved = (new ObservedChangeResolver(FailsOneReadConnectionPool::failing('tt_content')))->resolve($records(ChangeAction::MOVED), []);
        self::assertCount(1, $moved->changes, 'the move is listed');
        self::assertSame(1, $moved->omitted, 'the move with no readable row leaves the list incomplete');

        // Two changes whose reads fail mark the list once.
        $two = [
            'tt_content:' . self::ELEMENT                 => ['table' => 'tt_content', 'uid' => self::ELEMENT, 'action' => ChangeAction::UPDATED, 'workspace' => 0],
            'tt_content:' . self::ELEMENT_ON_PAGE_TO_COPY => ['table' => 'tt_content', 'uid' => self::ELEMENT_ON_PAGE_TO_COPY, 'action' => ChangeAction::UPDATED, 'workspace' => 0],
        ];
        $pool = FailsOneReadConnectionPool::failing('tt_content');
        $both = (new ObservedChangeResolver($pool))->resolve($two, []);
        self::assertSame(2, $pool->failed, 'both reads failed');
        self::assertCount(2, $both->changes, 'both changes are listed');
        self::assertSame(1, $both->omitted, 'two failed reads, one marker');

        // Read without a failure, the list is complete.
        self::assertSame(0, (new ObservedChangeResolver($this->connectionPool))->resolve($records(ChangeAction::UPDATED), [])->omitted, 'a row that was read leaves the list complete');
    }

    /**
     * The call's history is read from the uid `sys_history` had before its
     * first write. Where that uid cannot be read, the history cannot be read
     * either, and the list says it is not complete; a call with the uid read
     * reads its history as before.
     */
    #[Test]
    public function aHistoryWhoseStartCannotBeReadLeavesTheListIncomplete(): void
    {
        // What the effect check reads (the armed frame) and the set the run
        // records (what take() hands over).
        $listed = static function (?ConnectionPool $pool): array {
            ToolChangeCollector::arm();
            $set = null;
            try {
                if ($pool instanceof ConnectionPool) {
                    GeneralUtility::addInstance(ConnectionPool::class, $pool);
                }

                ToolChangeCollector::beforeWrite();
                $frame = ToolChangeCollector::peek();
                self::assertIsArray($frame);
                $truncated = false;
                (new ObservedChangeResolver(GeneralUtility::makeInstance(ConnectionPool::class)))->entries($frame['records'], $frame['scopes'], $frame['watermark'], $truncated);
            } finally {
                $set = ToolChangeCollector::take();
            }

            return [$truncated, $set->omitted];
        };

        $pool = FailsOneReadConnectionPool::failing('sys_history');
        self::assertSame([true, 1], $listed($pool), 'the effect check and the list are told the history is incomplete');
        self::assertSame(1, $pool->failed, 'the read of the start failed');
        self::assertSame([false, 0], $listed(null), 'a start that was read leaves the list complete');
    }

    /**
     * A delete the DataHandler refuses for lack of permission is not listed:
     * core does not call the command hooks for it, which is what this pins.
     */
    #[Test]
    public function aDeleteRefusedForPermissionIsNotListed(): void
    {
        $editor = $this->useASessionlessAmbientUser(2);
        self::assertFalse($editor->isAdmin());

        $trace = $this->runLoopWith($this->tool(static function (ToolExecutionContext $context): ToolResult {
            $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
            $dataHandler->start([], ['tt_content' => [self::LOCKED_ELEMENT => ['delete' => 1]]], $context->actingBackendUser());
            $dataHandler->process_cmdmap();

            return ToolResult::text('TRIED');
        }), user: $editor);

        self::assertSame(0, $this->connectionPool->getConnectionForTable('tt_content')->count('uid', 'tt_content', ['uid' => self::LOCKED_ELEMENT, 'deleted' => 1]), 'positive control: the element was not deleted');
        self::assertNotContains(RunStep::KIND_CHANGES, array_map(static fn (RunStep $step): string => $step->kind, $trace->getSteps()));
    }

    /**
     * The hook of a command hears of the command, not of its outcome: a delete
     * of a uid no row bears reaches it, and is not a change.
     */
    #[Test]
    public function aDeleteOfAUidNoRowBearsIsNotListed(): void
    {
        $trace = $this->runLoopWith($this->tool(static function (ToolExecutionContext $context): ToolResult {
            $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
            $dataHandler->start([], ['tt_content' => [9999 => ['delete' => 1]]], $context->actingBackendUser());
            $dataHandler->process_cmdmap();

            return ToolResult::text('TRIED');
        }));

        self::assertNotContains(RunStep::KIND_CHANGES, array_map(static fn (RunStep $step): string => $step->kind, $trace->getSteps()));
    }

    /**
     * A delete in a draft workspace writes a version of the live row, and that
     * version is what the list names, in the workspace it is stored in.
     */
    #[Test]
    public function aDeleteInADraftWorkspaceNamesTheVersionAndTheWorkspace(): void
    {
        $admin = $this->useASessionlessAmbientUser(1);
        self::assertTrue($admin->setTemporaryWorkspace(self::DRAFT));

        $trace = $this->runLoopWith($this->tool(static function (ToolExecutionContext $context): ToolResult {
            $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
            $dataHandler->start([], ['tt_content' => [self::ELEMENT => ['delete' => 1]]], $context->actingBackendUser());
            $dataHandler->process_cmdmap();

            return ToolResult::text('DELETED');
        }), user: $admin);

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        $listed = implode(', ', array_map(static fn (ObservedChange $change): string => (string) $change->record, $set->changes));
        self::assertCount(1, $set->changes, $listed);
        self::assertSame(ChangeAction::DELETED, $set->changes[0]->action);
        self::assertSame(self::ELEMENT, $set->changes[0]->liveUid, 'the live element it is the version of: ' . $listed);
        self::assertNotSame(self::ELEMENT, $set->changes[0]->record->uid);
        self::assertSame(self::DRAFT, $set->changes[0]->workspace);
        self::assertSame(0, $this->connectionPool->getConnectionForTable('tt_content')->count('uid', 'tt_content', ['uid' => self::ELEMENT, 'deleted' => 1]), 'positive control: live is untouched');
    }

    /**
     * A move in a draft workspace writes a version of the live row too; the
     * list names that version in its workspace, not the live row as moved
     * live, so a reader does not take a draft for a live change.
     */
    #[Test]
    public function aMoveInADraftWorkspaceNamesTheVersionAndTheWorkspace(): void
    {
        $admin = $this->useASessionlessAmbientUser(1);
        self::assertTrue($admin->setTemporaryWorkspace(self::DRAFT));
        $before = $this->connectionPool->getConnectionForTable('tt_content')->select(['pid', 'sorting'], 'tt_content', ['uid' => self::ELEMENT])->fetchAssociative();

        $trace = $this->runLoopWith($this->tool(static function (ToolExecutionContext $context): ToolResult {
            $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
            $dataHandler->start([], ['tt_content' => [self::ELEMENT => ['move' => self::PAGE_TO_COPY]]], $context->actingBackendUser());
            $dataHandler->process_cmdmap();

            return ToolResult::text('MOVED');
        }), user: $admin);

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        $listed = implode(', ', array_map(static fn (ObservedChange $change): string => $change->record . '@' . $change->workspace . '(' . $change->action->value . ')', $set->changes));
        self::assertNotSame([], $set->changes, $listed);
        foreach ($set->changes as $change) {
            self::assertSame(self::DRAFT, $change->workspace, 'nothing is named as live: ' . $listed);
        }

        self::assertContains(self::ELEMENT, array_map(static fn (ObservedChange $change): ?int => $change->liveUid, $set->changes), 'the version of the moved element is named: ' . $listed);

        self::assertSame($before, $this->connectionPool->getConnectionForTable('tt_content')->select(['pid', 'sorting'], 'tt_content', ['uid' => self::ELEMENT])->fetchAssociative(), 'positive control: live is untouched');
    }

    /**
     * A tool that returns an error after it wrote tells the model what it
     * wrote, as one that throws does.
     */
    #[Test]
    public function anErrorResultAfterAWriteNamesTheRecordsWritten(): void
    {
        $trace = $this->runLoopWith($this->tool(function (ToolExecutionContext $context): ToolResult {
            $this->updateHeader(self::ELEMENT, 'Written before the refusal', $context);

            return ToolResult::error('Refused: the second step was not allowed.');
        }));

        $tool = $this->stepOfKind($trace, RunStep::KIND_TOOL);
        self::assertTrue($tool->toolIsError);
        self::assertStringStartsWith('Before it failed this call changed: tt_content:' . self::ELEMENT . ' (updated). Check these records before retrying. Refused: the second step was not allowed.', (string) $tool->toolResult);
        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        self::assertCount(1, $set->changes);
    }

    /**
     * A call that succeeded gets no failure note.
     */
    #[Test]
    public function aSuccessfulWriteGetsNoFailureNote(): void
    {
        $trace = $this->runLoopWith($this->tool(function (ToolExecutionContext $context): ToolResult {
            $this->updateHeader(self::ELEMENT, 'Written once more', $context);

            return ToolResult::text('WROTE');
        }));

        self::assertSame('WROTE', $this->stepOfKind($trace, RunStep::KIND_TOOL)->toolResult);
    }

    /**
     * The tool's target is kept among the entries however many there are, and
     * so decides the coverage on the full list.
     */
    #[Test]
    public function theTargetSurvivesTheLimitOfTheList(): void
    {
        $records = [];
        for ($uid = 1000; $uid < 1060; ++$uid) {
            $records['tt_content:' . $uid] = ['table' => 'tt_content', 'uid' => $uid, 'action' => ChangeAction::UPDATED, 'workspace' => 0];
        }

        $set = (new ObservedChangeResolver($this->connectionPool))->resolve($records, [], null, new RecordReference('tt_content', 1059));

        self::assertCount(ObservedChangeSet::LIMIT, $set->changes);
        self::assertSame(10, $set->omitted);
        self::assertContains('tt_content:1059', array_map(static fn (ObservedChange $change): string => (string) $change->record, $set->changes));

        // The fifty listed rows are read first, then the rest in order until
        // the target: read 1 is a change that stays listed, read 50 the one
        // the target replaces, read 51 a change that stays left out, read 60
        // the target that is kept. Only a failed read of a change that ends up
        // listed marks the list incomplete.
        $retained = (new ObservedChangeResolver(FailsOneReadConnectionPool::failing('tt_content', 1, 1)))->resolve($records, [], null, new RecordReference('tt_content', 1059));
        self::assertSame(11, $retained->omitted, 'a failed read of a change that stays listed marks the list');

        $replaced = (new ObservedChangeResolver(FailsOneReadConnectionPool::failing('tt_content', 50, 50)))->resolve($records, [], null, new RecordReference('tt_content', 1059));
        self::assertNotContains('tt_content:1049', array_map(static fn (ObservedChange $change): string => (string) $change->record, $replaced->changes), 'the target took its place');
        self::assertSame(10, $replaced->omitted, 'a failed read of the change the target replaced adds nothing');

        $leftOut = (new ObservedChangeResolver(FailsOneReadConnectionPool::failing('tt_content', 51, 51)))->resolve($records, [], null, new RecordReference('tt_content', 1059));
        self::assertSame(10, $leftOut->omitted, 'a failed read of a change that is not listed adds nothing');

        $pool   = FailsOneReadConnectionPool::failing('tt_content', 60, 60);
        $target = (new ObservedChangeResolver($pool))->resolve($records, [], null, new RecordReference('tt_content', 1059));
        self::assertSame(1, $pool->failed, 'the read of the target failed');
        self::assertContains('tt_content:1059', array_map(static fn (ObservedChange $change): string => (string) $change->record, $target->changes));
        self::assertSame(11, $target->omitted, 'the kept target whose row could not be read marks the list incomplete');
    }

    /**
     * A history read that reaches its cap says it lost rows; one below it does not.
     */
    #[Test]
    public function aHistoryReadThatHitItsCapIsCountedAsTruncated(): void
    {
        $history = $this->connectionPool->getConnectionForTable('sys_history');
        $history->bulkInsert('sys_history', array_map(
            static fn (int $uid): array => [$uid, 1, 1, 'BE', 1, $uid, 'tt_content', '[]', 0, '0000$scopeA:subject' . $uid],
            range(100, 599),
        ), ['uid', 'tstamp', 'actiontype', 'usertype', 'userid', 'recuid', 'tablename', 'history_data', 'workspace', 'correlation_id']);

        $resolver = new ObservedChangeResolver($this->connectionPool);
        $few      = $resolver->resolve([], ['scopeA'], 0);
        self::assertSame(500 - ObservedChangeSet::LIMIT, $few->omitted, 'every row was read and counted');

        $history->bulkInsert('sys_history', array_map(
            static fn (int $uid): array => [$uid, 1, 1, 'BE', 1, $uid, 'tt_content', '[]', 0, '0000$scopeB:subject' . $uid],
            range(1000, 6100),
        ), ['uid', 'tstamp', 'actiontype', 'usertype', 'userid', 'recuid', 'tablename', 'history_data', 'workspace', 'correlation_id']);

        $many = $resolver->resolve([], ['scopeB'], 0);
        // Ten pages of 500 rows are read, 50 are kept, and the loss past the cap is marked by one more.
        self::assertSame(5000 - ObservedChangeSet::LIMIT + 1, $many->omitted);
    }

    #[Test]
    public function aTranslationNamesItsLanguage(): void
    {
        $trace = $this->runLoopWith($this->tool(function (ToolExecutionContext $context): ToolResult {
            $this->localize(self::ELEMENT, $context);

            return ToolResult::text('TRANSLATED');
        }));

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        $translations = array_values(array_filter($set->changes, static fn (ObservedChange $change): bool => $change->action === ChangeAction::CREATED));
        self::assertCount(1, $translations, implode(', ', array_map(static fn (ObservedChange $change): string => (string) $change->record, $set->changes)));
        self::assertSame(self::GERMAN, $translations[0]->language);
        self::assertSame(0, $translations[0]->workspace);
        self::assertNull($translations[0]->liveUid);
    }

    #[Test]
    public function aTranslationInADraftWorkspaceNamesBothTheLanguageAndTheWorkspace(): void
    {
        $admin = $this->useASessionlessAmbientUser(1);
        self::assertTrue($admin->setTemporaryWorkspace(self::DRAFT));

        $trace = $this->runLoopWith($this->tool(function (ToolExecutionContext $context): ToolResult {
            $this->localize(self::ELEMENT, $context);

            return ToolResult::text('TRANSLATED');
        }), user: $admin);

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        $translations = array_values(array_filter($set->changes, static fn (ObservedChange $change): bool => $change->language === self::GERMAN));
        self::assertCount(1, $translations, implode(', ', array_map(static fn (ObservedChange $change): string => (string) $change->record, $set->changes)));
        self::assertSame(self::DRAFT, $translations[0]->workspace);
        self::assertTrue($translations[0]->isDraft());
    }

    /**
     * A tool that states a write the DataHandler never reported — it wrote with
     * a query — leaves a set that says so. The list is never "complete".
     */
    #[Test]
    public function aWriteThatBypassesTheDataHandlerIsNotCaptured(): void
    {
        $trace = $this->runLoopWith($this->tool(function (): ToolResult {
            $this->connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['header' => 'By query'], ['uid' => self::ELEMENT]);

            return ToolResult::text('WROTE')->withWriteTarget(new RecordReference('tt_content', self::ELEMENT), WriteKind::UPDATED, WriteCompleteness::COMPLETE);
        }));

        $set = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $set);
        self::assertSame(ChangeCoverage::NOT_CAPTURED, $set->coverage);
        self::assertSame([], $set->changes);
    }

    #[Test]
    public function aWriteOutsideAToolCallLeaksIntoNoLaterCall(): void
    {
        // Nothing is armed here: the hooks must record nothing.
        $user = $this->useASessionlessAmbientUser(1);
        $this->updateHeader(self::ELEMENT, 'Written by an editor', ToolExecutionContext::fromBackendUser($user));
        self::assertFalse(ToolChangeCollector::isArmed());

        $trace = $this->runLoopWith($this->tool(static fn (): ToolResult => ToolResult::text('READ ONLY')), user: $user);

        $kinds = array_map(static fn (RunStep $step): string => $step->kind, $trace->getSteps());
        self::assertNotContains(RunStep::KIND_CHANGES, $kinds, 'a call that wrote nothing records no change step');
        self::assertFalse(ToolChangeCollector::isArmed());
    }

    #[Test]
    public function aToolThatRunsAnotherLoopPassesItsChangesToTheCallAroundIt(): void
    {
        $inner = null;
        $trace = $this->runLoopWith($this->tool(function (ToolExecutionContext $context) use (&$inner): ToolResult {
            ToolChangeCollector::arm();
            $this->updateHeader(self::ELEMENT, 'Written in the inner call', $context);
            $inner = ToolChangeCollector::take();

            return ToolResult::text('WROTE');
        }));

        self::assertInstanceOf(ObservedChangeSet::class, $inner);
        self::assertCount(1, $inner->changes);
        $outer = $this->changesStep($trace)->observedChanges;
        self::assertInstanceOf(ObservedChangeSet::class, $outer);
        self::assertSame('tt_content:' . self::ELEMENT, (string) ($outer->changes[0]->record ?? ''), 'the call around the inner one saw the same write');
    }

    // --- scaffold -----------------------------------------------------------

    /**
     * A tool without an effect, so the loop runs it at once.
     *
     * @param Closure(ToolExecutionContext): ToolResult $run
     */
    private function tool(Closure $run): ToolInterface
    {
        return new class ($run) implements ToolInterface {
            /**
             * @param Closure(ToolExecutionContext): ToolResult $run
             */
            public function __construct(private readonly Closure $run) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('change_list_probe', 'writes records', ['type' => 'object', 'properties' => []]);
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function execute(array $arguments, ToolExecutionContext $context): ToolResult
            {
                return ($this->run)($context);
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'test';
            }
        };
    }

    private function runLoopWith(ToolInterface $tool, mixed &$result = null, ?BackendUserAuthentication $user = null): RunTrace
    {
        $user ??= $this->useASessionlessAmbientUser(1);

        $queue = [
            new CompletionResponse('', 'test-model', UsageStatistics::fromTokens(0, 0), toolCalls: [new ToolCall('call_1', 'change_list_probe', [])]),
            new CompletionResponse('Done.', 'test-model', UsageStatistics::fromTokens(0, 0)),
        ];
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(static function () use (&$queue): CompletionResponse {
            $next = array_shift($queue);
            if (!$next instanceof CompletionResponse) {
                throw new RuntimeException('Scripted response queue underflow.', 1799990292);
            }

            return $next;
        });

        $registry = new ToolRegistry([$tool]);
        $policy   = new ToolCallPolicy(
            $registry,
            new ToolAvailabilityService($registry, new ToolStateRepository($this->connectionPool), new ToolGroupStateRepository($this->connectionPool)),
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        $trace  = new RunTrace();
        $result = (new ToolLoopService($manager, $registry, $policy, $this->logger))->runLoop(
            [['role' => 'user', 'content' => 'go']],
            $configuration,
            ToolExecutionContext::fromBackendUser($user),
            null,
            runTrace: $trace,
        );

        return $trace;
    }

    private function updateHeader(int $uid, string $header, ToolExecutionContext $context): void
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['tt_content' => [$uid => ['header' => $header]]], [], $context->actingBackendUser());
        $dataHandler->process_datamap();
    }

    private function localize(int $uid, ToolExecutionContext $context): void
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], ['tt_content' => [$uid => ['localize' => self::GERMAN]]], $context->actingBackendUser());
        $dataHandler->process_cmdmap();
    }

    private function headerOf(int $uid): string
    {
        $value = $this->connectionPool->getConnectionForTable('tt_content')->select(['header'], 'tt_content', ['uid' => $uid])->fetchOne();

        return is_string($value) ? $value : '';
    }

    private function stepOfKind(RunTrace $trace, string $kind): RunStep
    {
        foreach ($trace->getSteps() as $step) {
            if ($step->kind === $kind) {
                return $step;
            }
        }

        $answers = array_map(static fn (RunStep $step): string => (string) $step->toolResult, array_filter($trace->getSteps(), static fn (RunStep $step): bool => $step->kind === RunStep::KIND_TOOL));

        self::fail(sprintf(
            'The trace holds no %s step; it holds: %s. The tool answered: %s. Logged: %s',
            $kind,
            implode(', ', array_map(static fn (RunStep $step): string => $step->kind, $trace->getSteps())),
            implode(' | ', $answers),
            implode(' | ', $this->logged->getArrayCopy()),
        ));
    }

    private function changesStep(RunTrace $trace): RunStep
    {
        return $this->stepOfKind($trace, RunStep::KIND_CHANGES);
    }
}
