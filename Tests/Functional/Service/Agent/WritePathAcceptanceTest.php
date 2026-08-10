<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\ApprovalNotAuditableException;
use Netresearch\NrLlm\Service\Agent\Exception\AuditPersistenceFailedException;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Agent\ResumeCoordinator;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Service\Tool\TrustZoneResolver;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\ApprovalEventFailingRunRepository;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\FenceRecordingRunRepository;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\ScriptedToolAdapter;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The write path as ONE process: a model asks for a write, the loop suspends,
 * an operator decides, the resume executes, and a `pages` row changes.
 *
 * Each ticket in the write programme proved its own piece — ADR-132's approval
 * audit, ADR-133's approver gate, ADR-134's effect-implies-approval coupling,
 * ADR-135's tool, ADR-136's preview. This test asserts that the pieces are one
 * transaction of trust, against the real stack: the real
 * {@see UpdatePageMetadataTool} over the real `DataHandler`, the real composite
 * gate, the real persister on the functional database, the real approvals-inbox
 * card. Only the PROVIDER is scripted — a model's answer is the one input this
 * suite cannot produce — and every verdict is read back from the database
 * (`pages`, `sys_log`, the run row, the event stream), never from a return value
 * alone.
 *
 * Identities are deliberately three different things, because a confused deputy
 * hides where they collapse into one:
 *
 * - The run OWNER is the admin (uid 1). The resume executes as the owner
 *   (ADR-083), so `sys_log` must name uid 1.
 * - The APPROVER is a non-admin (uid 2) holding only the `agent_approve` grant.
 *   They pass ADR-133's gate because `update_page_metadata` is not admin-only —
 *   and the same gate is what would refuse them an admin-only write.
 * - The AMBIENT `$GLOBALS['BE_USER']` is uid 2 as well, and never writes
 *   anything: the acting identity comes from the run, not from the request.
 *
 * THE WRITE FENCE, AND WHY THIS TEST NOW COVERS IT
 * ================================================
 *
 * Until ADR-141 this section said the opposite: the ADR-112 fence armed only
 * under a worker lease, `executeResume()` took no lease owner at all, and the
 * only producer of one — `QueuedRunCoordinator::runQueued()` — was reachable
 * solely through `enqueue()`, which had no in-repo caller outside `Tests/`.
 * Every shipped write therefore ran unfenced.
 *
 * What changed is which segment holds a lease, not what a lease does:
 *
 * 1. A resume CLAIMS the run under its own identity
 *    ({@see \Netresearch\NrLlm\Service\Tool\AgentRunRepository::claimForResume()}
 *    writes `claimed_by`/`lease_expires` instead of clearing them), and
 *    {@see \Netresearch\NrLlm\Service\Agent\ResumeCoordinator} threads that
 *    identity into `executeResume()`.
 * 2. A synchronous run claims at birth, so the first pass is fenced too — which
 *    matters for a remote tool that declares a write without its operator
 *    setting the approval flag, since ADR-134's effect-implies-approval rule
 *    exempts remote tools and such a call executes on the FIRST pass.
 * 3. The fencing hook is installed unconditionally now, and REFUSES a
 *    side-effecting tool it cannot fence
 *    ({@see \Netresearch\NrLlm\Service\Agent\Exception\WriteWithoutDurableExecutionException}).
 *    A future entry point that forgets to claim its run cannot execute a write
 *    at all — it fails closed instead of silently skipping the fence.
 *
 * The test below asserts (1) directly: the write is stamped BEFORE it runs and
 * cleared after, observed in order rather than read back from a column that is
 * empty again by the time the run returns.
 *
 * The fail-closed AUDIT is a separate guarantee and always held on every path,
 * because it sits outside the lease branch — the two tests at the bottom of this
 * class pin that.
 */
#[CoversClass(AgentRuntime::class)]
#[CoversClass(ResumeCoordinator::class)]
final class WritePathAcceptanceTest extends AbstractFunctionalTestCase
{
    use LlmServiceManagerTestFactory;

    /** The page the scripted model asks to edit. */
    private const PAGE = 1;

    private const OLD_DESCRIPTION = 'Old description';

    private const NEW_DESCRIPTION = 'A description a human approved';

    /** The run owner: the admin whose identity the resumed write executes under. */
    private const OWNER = 1;

    /** The approver: a non-admin who only holds the ADR-130 approve grant. */
    private const APPROVER = 2;

    private ConnectionPool $connectionPool;

    private LlmConfiguration $configuration;

    private ToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->getService(ConnectionPool::class);
        $this->importFixture('BeUsers.csv');

        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE, 'pid' => 0, 'title' => 'Home', 'doktype' => 1, 'slug' => '/',
            'sorting' => 1, 'description' => self::OLD_DESCRIPTION,
            'perms_userid' => self::OWNER, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);

        // The DataHandler's declared prerequisites (ADR-135's E2 refusal); a
        // backend request has them, and the tool refuses rather than faking them.
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
        // The AMBIENT user is the approver, and writes nothing: everything the
        // write does is authorised against the run's own actor (ADR-083).
        $this->setUpBackendUser(self::APPROVER);

        $this->registry = new ToolRegistry([new UpdatePageMetadataTool($this->connectionPool)]);
        // A writing tool ships disabled (ADR-135); an admin turns it on in the
        // Tools module. Without this step the run never reaches the tool at all,
        // which is the gate doing its job rather than the test being clever.
        (new ToolStateRepository($this->connectionPool))->setEnabled('update_page_metadata', true);

        $this->configuration = $this->localConfiguration();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    /**
     * The whole path in one run: call → suspend → card → decision → execution →
     * changed row, with the audit trail to match.
     */
    #[Test]
    public function anApprovedWriteRunsFromTheModelCallToTheChangedRecord(): void
    {
        $adapter = $this->scriptedWriteCall();
        $runtime = $this->runtime($adapter);

        // 1. The model asks for the write. The loop suspends BEFORE executing it
        //    — ADR-134: the declared effect alone binds the call to a human, and
        //    the tool carries no approval marker.
        $started = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::AWAITING_APPROVAL, $started->outcome);
        $uuid = $started->runUuid;
        self::assertNotSame('', $uuid);

        // 2. The suspension is a stored fact, not a return value.
        $suspended = $this->storedRun($uuid);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $suspended->statusEnum());
        self::assertNotNull($suspended->suspendedState);

        // 3. Nothing has happened to the record yet. This is the assertion the
        //    whole suspension exists for.
        self::assertSame(self::OLD_DESCRIPTION, $this->pageRow()['description'] ?? null);
        self::assertSame([], $this->sysLogUserIds());

        // 4. The operator's card — the real inbox view factory — shows the call
        //    and what it would REPLACE (ADR-136), and carries the turn digest
        //    the decision has to name (ADR-132).
        $card = $this->cardFor($suspended, $this->viewer(self::OWNER));
        self::assertSame(WaitingRunView::MODE_APPROVAL, $card->mode);
        self::assertCount(1, $card->pendingCalls);
        self::assertSame('update_page_metadata', $card->pendingCalls[0]->name);
        self::assertFalse($card->pendingCalls[0]->previewFailed);
        self::assertContains(
            sprintf('description: "%s" → "%s"', self::OLD_DESCRIPTION, self::NEW_DESCRIPTION),
            $card->pendingCalls[0]->previewLines,
        );
        self::assertIsString($card->turnDigest);

        // 4b. The SAME card rendered for the approver shows no diff: ADR-136
        //     gates the preview on the viewer's permission on the record, and
        //     `agent_approve` says nothing about this page. So the approver here
        //     releases the write without seeing what it replaces -- deliberate,
        //     and the reason the withheld state is a first-class one rather than
        //     an empty line list.
        $blind = $this->cardFor($suspended, $this->viewer(self::APPROVER));
        self::assertTrue($blind->pendingCalls[0]->previewFailed);
        self::assertCount(1, $blind->pendingCalls[0]->previewLines);
        self::assertStringContainsString('no permission', $blind->pendingCalls[0]->previewLines[0]);
        // The digest is the run's, not the viewer's: both operators name the
        // same turn when they decide.
        self::assertSame($card->turnDigest, $blind->turnDigest);

        // 5. The non-admin approver passes ADR-133's gate — the same composite
        //    gate the execution passes — and the resume executes the turn.
        $approved = $runtime->approve($this->approver(), $uuid, new ApprovalDecision(true, self::APPROVER, $card->turnDigest));

        self::assertSame(AgentRunOutcome::COMPLETED, $approved->outcome, (string)$approved->error?->getMessage());
        self::assertSame(AgentRunStatus::COMPLETED, $this->storedRun($uuid)->statusEnum());

        // 6. The record actually changed — read back from `pages`, not from the
        //    tool's own success string.
        $row = $this->pageRow();
        self::assertSame(self::NEW_DESCRIPTION, $row['description'] ?? null);
        self::assertSame('Home', $row['title'] ?? null, 'only the requested field was written');
        self::assertSame('/', $row['slug'] ?? null, 'no field outside the allow-list was touched');

        // 7. TYPO3's own audit names the OWNER, not the approver and not the
        //    ambient user — both of which are uid 2 here (ADR-083).
        self::assertSame([self::OWNER], $this->sysLogUserIds());

        // 8. The run's own audit stream carries the decision and the executed
        //    write, in that order.
        $events = $this->events($suspended);
        $approval = $this->firstEventOfKind($events, AgentEventKind::APPROVAL);
        self::assertTrue($approval->payload['approved'] ?? null);
        self::assertSame(self::APPROVER, $approval->payload['decidedBy'] ?? null);

        $tool = $this->firstEventOfKind($events, AgentEventKind::TOOL);
        self::assertSame('update_page_metadata', $tool->payload['toolName'] ?? null);
        self::assertNotTrue($tool->payload['toolIsError'] ?? null, 'the write itself must not be an error step');
        self::assertGreaterThan($approval->sequence, $tool->sequence, 'the decision precedes the execution it authorised');
    }

    /**
     * The fence, on the segment that actually executes the write (ADR-141).
     *
     * A write suspends before it runs (ADR-134), so the tool executes on the
     * RESUME. That segment now claims the run under its own lease, which is what
     * lets `markPendingEffect`'s ownership guard match — and the stamp is what a
     * reaper reads to refuse retrying a side effect that may already have
     * landed.
     */
    #[Test]
    public function theWriteIsFencedOnTheResumeThatExecutesIt(): void
    {
        $recording = new FenceRecordingRunRepository($this->realRepository());
        $runtime   = $this->runtime($this->scriptedWriteCall(), $recording);

        $uuid      = $runtime->run($this->request())->runUuid;
        $suspended = $this->storedRun($uuid);
        $card      = $this->cardFor($suspended, $this->viewer(self::OWNER));

        $approved = $runtime->approve($this->approver(), $uuid, new ApprovalDecision(true, self::APPROVER, $card->turnDigest));
        self::assertSame(AgentRunOutcome::COMPLETED, $approved->outcome, (string)$approved->error?->getMessage());

        // The write happened — the fence must not have prevented it.
        self::assertSame(self::NEW_DESCRIPTION, $this->pageRow()['description'] ?? null);

        // Stamped with the declared effect before the tool ran, cleared after.
        self::assertSame(
            [ToolEffect::IDEMPOTENT_WRITE->value, ''],
            array_column($recording->fenceWrites, 'effect'),
        );

        // Both writes were made under the lease the resume claimed — an
        // ownership-guarded UPDATE under any other identity would affect no row.
        self::assertCount(1, $recording->resumeClaims);
        $leaseOwner = $recording->resumeClaims[0]['claimedBy'];
        self::assertStringStartsWith('resume:', $leaseOwner);
        self::assertSame([$leaseOwner, $leaseOwner], array_column($recording->fenceWrites, 'claimedBy'));
    }

    /**
     * The refusal run. A denial is a decision like any other — it is audited, it
     * resumes the loop with a refusal the model can read — and it changes
     * nothing at all.
     */
    #[Test]
    public function aDeniedWriteChangesNothing(): void
    {
        $runtime = $this->runtime($this->scriptedWriteCall());

        $uuid      = $runtime->run($this->request())->runUuid;
        $suspended = $this->storedRun($uuid);
        $card      = $this->cardFor($suspended);

        $denied = $runtime->approve($this->approver(), $uuid, new ApprovalDecision(false, self::APPROVER, $card->turnDigest));

        // The run itself completes: the model is told its call was refused and
        // answers. "Denied" is not "failed".
        self::assertSame(AgentRunOutcome::COMPLETED, $denied->outcome, (string)$denied->error?->getMessage());

        // The point of the run: the database is exactly as it was.
        self::assertSame(self::OLD_DESCRIPTION, $this->pageRow()['description'] ?? null);
        self::assertSame('Home', $this->pageRow()['title'] ?? null);
        self::assertSame([], $this->sysLogUserIds(), 'a denied write leaves no TYPO3 audit row, because no write happened');

        $events   = $this->events($suspended);
        $approval = $this->firstEventOfKind($events, AgentEventKind::APPROVAL);
        self::assertFalse($approval->payload['approved'] ?? null);
        self::assertSame(self::APPROVER, $approval->payload['decidedBy'] ?? null);

        // The turn's call is still recorded — as the refusal that was fed back
        // to the model, never as an execution.
        $tool = $this->firstEventOfKind($events, AgentEventKind::TOOL);
        self::assertSame('update_page_metadata', $tool->payload['toolName'] ?? null);
        self::assertTrue($tool->payload['toolIsError'] ?? null);
        $toolResult = $tool->payload['toolResult'] ?? null;
        self::assertIsString($toolResult);
        self::assertStringContainsString('denied by the operator', $toolResult);
    }

    /**
     * ADR-132 gate 3, with a real write behind it: an approval that authorises a
     * write and could not be recorded does not execute. Unlike the fence, this
     * guard holds on the resume path — it is the reason the previous test's
     * `sys_log` row is not the only evidence that a write happened.
     */
    #[Test]
    public function anApprovalThatCannotBeAuditedDoesNotWrite(): void
    {
        $runtime = $this->runtime(
            $this->scriptedWriteCall(),
            $this->repositoryFailingOn(AgentEventKind::APPROVAL),
        );

        $uuid      = $runtime->run($this->request())->runUuid;
        $suspended = $this->storedRun($uuid);
        $card      = $this->cardFor($suspended);

        try {
            $runtime->approve($this->approver(), $uuid, new ApprovalDecision(true, self::APPROVER, $card->turnDigest));
            self::fail('Expected ApprovalNotAuditableException');
        } catch (ApprovalNotAuditableException) {
            // The run is released, not settled: somebody can decide it again
            // once the audit store is back.
            self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $this->storedRun($uuid)->statusEnum());
        }

        self::assertSame(self::OLD_DESCRIPTION, $this->pageRow()['description'] ?? null);
        self::assertSame([], $this->sysLogUserIds());
    }

    /**
     * ADR-111's fail-closed STEP audit, on the very path the write fence does
     * not reach. The write has already run when its audit fails, so the run
     * fails — the row is NOT rolled back, and pretending otherwise would be the
     * more dangerous claim.
     */
    #[Test]
    public function aWriteWhoseStepAuditFailsFailsTheRun(): void
    {
        $runtime = $this->runtime(
            $this->scriptedWriteCall(),
            $this->repositoryFailingOn(AgentEventKind::TOOL),
        );

        $uuid = $runtime->run($this->request())->runUuid;
        $card = $this->cardFor($this->storedRun($uuid));

        $result = $runtime->approve($this->approver(), $uuid, new ApprovalDecision(true, self::APPROVER, $card->turnDigest));

        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertInstanceOf(AuditPersistenceFailedException::class, $result->error);
        self::assertSame(AgentRunStatus::FAILED, $this->storedRun($uuid)->statusEnum());

        // Stated rather than wished away: the mutation landed, and only the run
        // is marked failed. `sys_log` is what an operator has left.
        self::assertSame(self::NEW_DESCRIPTION, $this->pageRow()['description'] ?? null);
        self::assertSame([self::OWNER], $this->sysLogUserIds());
    }

    // --- wiring ------------------------------------------------------------

    /**
     * The runtime with the parts under test real: the real tool, the real gate,
     * the real persister on the functional database.
     *
     * Doubled are the provider adapter registry, `LlmConfigurationRepository`
     * (the run's configuration lives in memory rather than in a row), and two
     * constructor arguments the tested path never reaches —
     * `ExtensionConfiguration` (only `KeyedProviderRegistry`, i.e. the
     * provider-key path) and `CacheManagerInterface` (only
     * `EmbedCacheKeyBuilder`, i.e. `embed*()`).
     *
     * The middleware pipeline is real but empty. It *is* on the path —
     * `chatWithToolsForConfiguration()` runs through it — so fallback, budget,
     * usage and cache middleware are absent here, unlike in production.
     */
    private function runtime(ScriptedToolAdapter $adapter, ?AgentRunRepositoryInterface $repository = null): AgentRuntime
    {
        $adapterRegistry = self::createStub(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($adapter);

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        $policy = new ToolCallPolicy(
            $this->registry,
            new ToolAvailabilityService(
                $this->registry,
                new ToolStateRepository($this->connectionPool),
                new ToolGroupStateRepository($this->connectionPool),
            ),
            new AllowedToolsResolver(new SkillComposer(), $this->registry),
            new ToolDataClassResolver($this->registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        $loop = new ToolLoopService(
            $this->createLlmServiceManager(
                $extensionConfiguration,
                new NullLogger(),
                $adapterRegistry,
                new MiddlewarePipeline([]),
                self::createStub(CacheManagerInterface::class),
            ),
            $this->registry,
            $policy,
            new NullLogger(),
        );

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($this->configuration);

        return new AgentRuntime(
            toolLoop: $loop,
            persister: $this->persister($repository),
            configurationRepository: $configurationRepository,
            logger: new NullLogger(),
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($this->registry),
            toolPolicy: $policy,
        );
    }

    private function persister(?AgentRunRepositoryInterface $repository = null): AgentRunPersister
    {
        return new AgentRunPersister(
            $repository ?? $this->realRepository(),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
    }

    private function realRepository(): AgentRunRepository
    {
        return new AgentRunRepository($this->connectionPool, $this->getService(AgentStateCodec::class));
    }

    /**
     * The real repository with ONE event kind failing to store — the store
     * hiccup both fail-closed guards are written for.
     */
    private function repositoryFailingOn(AgentEventKind $kind): AgentRunRepositoryInterface
    {
        return new ApprovalEventFailingRunRepository($this->realRepository(), $kind->value);
    }

    /**
     * A provider that asks for the write once and answers plainly afterwards.
     * The second round happens on the RESUME, so the same instance must serve
     * both segments of the run.
     */
    private function scriptedWriteCall(): ScriptedToolAdapter
    {
        return new ScriptedToolAdapter(
            'The description is updated.',
            'update_page_metadata',
            ['uid' => self::PAGE, 'description' => self::NEW_DESCRIPTION],
        );
    }

    private function request(): AgentRunRequest
    {
        return new AgentRunRequest(
            $this->configuration,
            [ChatMessage::user('Give page 1 a better description.')],
            AiActorContext::backendUser(self::OWNER, isAdmin: true),
        );
    }

    /**
     * The approver: a non-admin who may decide another user's run ONLY because
     * of the ADR-130 grant — the principal ADR-133's gate is about.
     */
    private function approver(): AiActorContext
    {
        return AiActorContext::backendUser(self::APPROVER, grants: [BackendUserGrant::AGENT_APPROVE]);
    }

    /**
     * A configuration whose provider sits in the LOCAL trust zone, so the
     * trust-zone axis of the real gate permits the editing tool and the run is
     * about the write path rather than about data classes.
     */
    private function localConfiguration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setIdentifier('fake-provider');
        $provider->setAdapterType('openai');
        $provider->setTrustZoneEnum(TrustZone::LOCAL);
        $provider->setApiKey('nr_write_path_vault_key');

        $model = new Model();
        $model->setModelId('scripted-model');
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-write-path');
        $configuration->setLlmModel($model);

        return $configuration;
    }

    // --- reading the real state -------------------------------------------

    private function storedRun(string $uuid): AgentRun
    {
        $run = $this->persister()->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    /**
     * The approvals-inbox card an operator would decide on.
     */
    private function cardFor(AgentRun $run, ?BackendUserAuthentication $viewer = null): WaitingRunView
    {
        $views = (new WaitingRunViewFactory($this->registry, new SchemaPropertyClassifier(), new PendingTurnDigest()))
            ->buildWaiting([$run], $viewer);
        self::assertCount(1, $views);

        return $views[0];
    }

    /**
     * A backend user to render a card FOR, without disturbing the ambient one:
     * the approver stays `$GLOBALS['BE_USER']` for the whole run, because the
     * write ignoring it is what step 7 proves.
     */
    private function viewer(int $uid): BackendUserAuthentication
    {
        $ambient = $GLOBALS['BE_USER'];
        $viewer  = $this->setUpBackendUser($uid);

        $GLOBALS['BE_USER'] = $ambient;

        return $viewer;
    }

    /**
     * @return list<AgentRunEvent>
     */
    private function events(AgentRun $run): array
    {
        return $this->persister()->findEvents($run->uid);
    }

    /**
     * @param list<AgentRunEvent> $events
     */
    private function firstEventOfKind(array $events, AgentEventKind $kind): AgentRunEvent
    {
        foreach ($events as $event) {
            if ($event->kindEnum() === $kind) {
                return $event;
            }
        }

        self::fail(sprintf('The run recorded no %s event', $kind->value));
    }

    /**
     * @return array<string, mixed>
     */
    private function pageRow(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(self::PAGE, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row, 'the target page must exist');

        return $row;
    }

    /**
     * The `sys_log` user ids TYPO3 itself recorded for the target page.
     *
     * @return list<int>
     */
    private function sysLogUserIds(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('userid')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter(self::PAGE, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_unique(array_map(static fn(array $row): int => (int)($row['userid'] ?? 0), $rows)));
    }
}
