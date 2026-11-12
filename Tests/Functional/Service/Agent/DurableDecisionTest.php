<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingInputException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleInputTurnException;
use Netresearch\NrLlm\Service\Agent\Exception\StalePauseException;
use Netresearch\NrLlm\Service\Agent\Exception\SubmitterNotPermittedException;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
use Netresearch\NrLlm\Service\Agent\PendingPause;
use Netresearch\NrLlm\Service\Agent\Process\ProcessPinProbe;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrLlm\Service\Agent\ResumeCoordinator;
use Netresearch\NrLlm\Service\Agent\RunResumeGuardInterface;
use Netresearch\NrLlm\Service\Agent\StoredDecision;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\Builtin\AskChoiceTool;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\WorkerWriteEnvironment;
use Netresearch\NrLlm\Tests\Fixture\AgentRunRepositoryFactory;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Exception\TechnicalActorException;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * A decision stored durably and carried out by the worker (ADR-281, spec 051
 * A2A-MT2), end to end over a scripted model: the checks before storing, the
 * race with the in-request path, and the worker's second look.
 */
#[CoversClass(ResumeCoordinator::class)]
#[CoversClass(AgentRuntime::class)]
#[CoversClass(StoredDecision::class)]
final class DurableDecisionTest extends AbstractFunctionalTestCase
{
    private const OWNER = 2;

    private AgentRunPersister $persister;

    private LlmConfiguration $configuration;

    /** @var list<CompletionResponse> */
    private array $responses = [];

    private int $modelCalls = 0;

    private int $writes = 0;

    /** The run the resolver of the release test hands to another worker when it is asked next; 0 is none. */
    private int $reclaimRun = 0;

    /** @var array{mixed, mixed}|null the ambient backend user and language service while the fake writer ran */
    private ?array $writerSaw = null;

    /** @var list<string> tools the policy refuses */
    private array $refused = [];

    /** What the process pin probe answers: a process run, none, or a failed lookup (null). */
    private ?bool $processPin = false;

    /** What the resume guard answers: null lets every resume through. */
    private ?string $guardRefusal = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->persister = new AgentRunPersister(
            AgentRunRepositoryFactory::create($this->getService(ConnectionPool::class), $this->getService(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
        $this->configuration = new LlmConfiguration();
        $this->configuration->_setProperty('uid', 1);
        $this->configuration->setIsActive(true);
    }

    /**
     * The input is stored with a receipt and the run is QUEUED; nothing ran.
     * The worker then carries it out once: the pending ask_choice call gets
     * the answer, the model goes on, and a second worker finds nothing.
     */
    #[Test]
    public function aStoredInputIsCarriedOutOnceByTheWorker(): void
    {
        $runtime = $this->runtime();
        $run     = $this->pausedForInput($runtime);
        $pause   = $runtime->pendingPause($this->owner(), $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        self::assertTrue($pause->isInput());
        self::assertSame('ask_choice', $pause->inputToolName);
        self::assertSame(['answer'], $pause->inputSchema['required'] ?? null);
        self::assertArrayNotHasKey('state', get_object_vars($pause), 'a decider is not handed the transcript or the pending calls');

        $this->responses = [$this->finalTurn('You picked B.')];
        $receipt         = $runtime->storeInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'B'], self::OWNER, $pause->turnDigest), $pause->generation);

        $queued = $this->runRow($run->uuid);
        self::assertSame(AgentRunStatus::QUEUED, $queued->statusEnum());
        self::assertSame($receipt->decisionId, $this->decisionOf($queued)['id']);
        self::assertSame(1, $this->modelCalls, 'storing the decision ran nothing');

        $result = $runtime->runQueued($run->uuid);
        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertSame(AgentRunStatus::COMPLETED, $this->runRow($run->uuid)->statusEnum());
        self::assertSame(2, $this->modelCalls);
        self::assertNull($runtime->runQueued($run->uuid), 'a continuation is carried out once');
    }

    /**
     * A decision made for an earlier pause is refused, and nothing is stored.
     * The generation is also checked where the run is written: an event at
     * or past it makes the transition fail.
     */
    #[Test]
    public function aStalePauseGenerationIsRefused(): void
    {
        $runtime = $this->runtime();
        $run     = $this->pausedForInput($runtime);
        $pause   = $runtime->pendingPause($this->owner(), $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);

        try {
            $runtime->storeInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'B'], self::OWNER, $pause->turnDigest), $pause->generation - 1);
            self::fail('A stale generation was accepted.');
        } catch (StalePauseException) {
        }

        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runRow($run->uuid)->statusEnum());

        $this->getService(ConnectionPool::class)->getConnectionForTable('tx_nrllm_agentrun_event')
            ->insert('tx_nrllm_agentrun_event', ['run' => $run->uid, 'sequence' => $pause->generation, 'kind' => 'llm', 'payload' => '{}']);
        self::assertFalse($this->persister->queueContinuation($run, AgentRunStatus::WAITING_FOR_INPUT, $pause->generation, '{}'));
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runRow($run->uuid)->statusEnum());
    }

    /**
     * The in-request path and the durable one race for one pause: whichever
     * comes first wins, the other is refused and changes nothing.
     */
    #[Test]
    public function theInRequestAndTheDurablePathCannotBothWinOnePause(): void
    {
        $runtime = $this->runtime();
        $run     = $this->pausedForInput($runtime);
        $pause   = $runtime->pendingPause($this->owner(), $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);

        $runtime->storeInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'A'], self::OWNER, $pause->turnDigest), $pause->generation);
        try {
            $runtime->submitInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'B'], self::OWNER, $pause->turnDigest));
            self::fail('The in-request path won a pause the durable one had taken.');
        } catch (RunNotAwaitingInputException) {
        }

        $data = $this->decisionOf($this->runRow($run->uuid))['data'] ?? null;
        self::assertSame(['answer' => 'A'], $data);

        $other = $this->pausedForInput($runtime);
        $later = $runtime->pendingPause($this->owner(), $other->uuid);
        self::assertInstanceOf(PendingPause::class, $later);
        $this->responses = [$this->finalTurn('done')];
        $runtime->submitInput($this->owner(), $other->uuid, new InputSubmission(['answer' => 'B'], self::OWNER, $later->turnDigest));
        try {
            $runtime->storeInput($this->owner(), $other->uuid, new InputSubmission(['answer' => 'A'], self::OWNER, $later->turnDigest), $later->generation);
            self::fail('The durable path won a pause the in-request one had taken.');
        } catch (RunNotAwaitingInputException) {
        }

        self::assertSame(AgentRunStatus::COMPLETED, $this->runRow($other->uuid)->statusEnum());
    }

    /**
     * Every check of the in-request path refuses before anything is stored:
     * an input outside the schema, a digest of another turn, a submitter who
     * may not run the pending tool.
     */
    #[Test]
    public function theChecksOfTheInRequestPathRefuseBeforeStoring(): void
    {
        $runtime = $this->runtime();
        $run     = $this->pausedForInput($runtime);
        $pause   = $runtime->pendingPause($this->owner(), $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);

        $refusals = [
            InvalidInputSubmissionException::class => new InputSubmission(['answer' => 'Z'], self::OWNER, $pause->turnDigest),
            StaleInputTurnException::class         => new InputSubmission(['answer' => 'A'], self::OWNER, str_repeat('0', 64)),
        ];
        foreach ($refusals as $expected => $submission) {
            try {
                $runtime->storeInput($this->owner(), $run->uuid, $submission, $pause->generation);
                self::fail($expected . ' was not thrown.');
            } catch (InvalidInputSubmissionException|StaleInputTurnException $refusal) {
                self::assertInstanceOf($expected, $refusal);
            }
        }

        $this->refused = ['ask_choice'];
        try {
            $runtime->storeInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'A'], self::OWNER, $pause->turnDigest), $pause->generation);
            self::fail('A submitter who may not run the tool was accepted.');
        } catch (SubmitterNotPermittedException) {
        }

        $stored = $this->runRow($run->uuid);
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $stored->statusEnum());
        self::assertSame([], $this->decisionOf($stored));
    }

    /**
     * The worker looks again before the first effect: a submitter whose
     * backend user was disabled after the decision decides nothing. The run
     * waits again without the decision, and the model was not asked.
     */
    #[Test]
    public function theWorkerRefusesADecisionWhoseDeciderLostTheRight(): void
    {
        $runtime = $this->runtime();
        $run     = $this->pausedForInput($runtime);
        $pause   = $runtime->pendingPause($this->owner(), $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        $runtime->storeInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'B'], self::OWNER, $pause->turnDigest), $pause->generation);

        $this->getService(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['disable' => 1], ['uid' => self::OWNER]);
        $result = $runtime->runQueued($run->uuid);

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::AWAITING_INPUT, $result->outcome);
        $back = $this->runRow($run->uuid);
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $back->statusEnum());
        self::assertSame([], $this->decisionOf($back), 'the refused decision is dropped');
        self::assertSame(1, $this->modelCalls);
    }

    /**
     * ADR-214 item 6 at the worker: a process run whose configuration turned
     * four-eyes after the initiator's decision was stored is stopped as the
     * in-request path stops it, before the model is asked; a process verdict
     * that cannot be read is refused as retryable and the run waits again.
     */
    #[Test]
    public function theWorkerStopsAProcessRunThatTurnedFourEyes(): void
    {
        $this->processPin = true;
        $runtime          = $this->runtime();
        $run              = $this->pausedForInput($runtime);
        $pause            = $runtime->pendingPause($this->owner(), $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        $runtime->storeInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'B'], self::OWNER, $pause->turnDigest), $pause->generation);

        $this->configuration->setRequireSecondApprover(true);
        $this->processPin = null;
        $result           = $runtime->runQueued($run->uuid);
        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::AWAITING_INPUT, $result->outcome, 'an unreadable verdict is retryable');
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runRow($run->uuid)->statusEnum());

        $this->configuration->setRequireSecondApprover(false);
        $pause = $runtime->pendingPause($this->owner(), $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        $this->processPin = true;
        $runtime->storeInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'B'], self::OWNER, $pause->turnDigest), $pause->generation);
        $this->configuration->setRequireSecondApprover(true);

        $result = $runtime->runQueued($run->uuid);
        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        $stopped = $this->runRow($run->uuid);
        self::assertSame([AgentRunStatus::FAILED, 'approval_denied'], [$stopped->statusEnum(), $stopped->terminationReason]);
        self::assertSame(1, $this->modelCalls, 'the model was not asked');
    }

    /**
     * A2A-MT4's hook: the resume guards are asked before the first effect of
     * the worker's continuation; a refusal fails the run and the model is
     * not asked.
     */
    #[Test]
    public function aResumeGuardRefusalFailsTheContinuationBeforeTheModel(): void
    {
        $runtime = $this->runtime();
        $run     = $this->pausedForInput($runtime);
        $pause   = $runtime->pendingPause($this->owner(), $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        $runtime->storeInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'B'], self::OWNER, $pause->turnDigest), $pause->generation);

        $this->guardRefusal = 'the client was revoked';
        $this->responses    = [$this->finalTurn('must not be asked')];
        $result             = $runtime->runQueued($run->uuid);

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertSame(AgentRunStatus::FAILED, $this->runRow($run->uuid)->statusEnum());
        self::assertSame(1, $this->modelCalls);
    }

    /**
     * The worker reads the decider as they are now, not as stored: an
     * administrator who decided on another user's run and lost the
     * administrator flag since decides nothing.
     */
    #[Test]
    public function theWorkerReadsTheDecidersCurrentRights(): void
    {
        $runtime = $this->runtime();
        $run     = $this->pausedForInput($runtime);
        $admin   = AiActorContext::backendUser(1, true);
        $pause   = $runtime->pendingPause($admin, $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        $runtime->storeInput($admin, $run->uuid, new InputSubmission(['answer' => 'B'], 1, $pause->turnDigest), $pause->generation);

        $this->getService(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['admin' => 0], ['uid' => 1]);
        $this->responses = [$this->finalTurn('must not be asked')];
        $result          = $runtime->runQueued($run->uuid);

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::AWAITING_INPUT, $result->outcome);
        self::assertSame(1, $this->modelCalls);
    }

    /**
     * A durable approval: the write runs in the worker, exactly once.
     */
    #[Test]
    public function aStoredApprovalRunsTheWriteInTheWorker(): void
    {
        $runtime         = $this->runtime();
        $this->responses = [$this->toolTurn([new ToolCall('w1', 'write_thing', [])])];
        $result          = $runtime->run($this->request(['write_thing']));
        self::assertSame(AgentRunOutcome::AWAITING_APPROVAL, $result->outcome);

        $pause = $runtime->pendingPause($this->owner(), $result->runUuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        self::assertFalse($pause->isInput());

        $this->responses = [$this->finalTurn('written')];
        $runtime->storeApproval($this->owner(), $result->runUuid, new ApprovalDecision(true, self::OWNER, $pause->turnDigest), $pause->generation);
        self::assertSame(0, $this->writes, 'storing the approval wrote nothing');

        // The worker has no backend user and no language service.
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        $runtime->runQueued($result->runUuid);
        self::assertSame(1, $this->writes);
        self::assertSame(AgentRunStatus::COMPLETED, $this->runRow($result->runUuid)->statusEnum());

        // The write ran as the run's acting user, with a language service.
        self::assertIsArray($this->writerSaw);
        self::assertInstanceOf(BackendUserAuthentication::class, $this->writerSaw[0]);
        self::assertSame(self::OWNER, $this->writerSaw[0]->user['uid'] ?? null);
        self::assertInstanceOf(LanguageService::class, $this->writerSaw[1]);
        // And only for that call: nothing stays behind for a later hook or run.
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertArrayNotHasKey('LANG', $GLOBALS);
    }

    /**
     * The worker's identity cannot act (its technical backend user is
     * disabled): nothing was claimed or run. The run ends FAILED instead of
     * staying QUEUED and failing every sweep again, as an ordinary queued run
     * does.
     */
    #[Test]
    public function aWorkerThatCannotActEndsAStoredDecisionsRun(): void
    {
        $technical = self::createStub(TechnicalActorContextInterface::class);
        $technical->method('runAs')->willThrowException(TechnicalActorException::userDisabled(9));
        $settings = self::createStub(ExtensionConfiguration::class);
        $settings->method('get')->willReturn(['worker' => ['technicalBeUserUid' => 9]]);

        $runtime = $this->runtime(vaultScope: new WorkerVaultScope($technical, $settings, true));
        $run     = $this->pausedForInput($runtime);
        $pause   = $runtime->pendingPause($this->owner(), $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        $runtime->storeInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'B'], self::OWNER, $pause->turnDigest), $pause->generation);

        $result = $runtime->runQueued($run->uuid);

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertSame(AgentRunStatus::FAILED, $this->runRow($run->uuid)->statusEnum());
        self::assertNull($runtime->runQueued($run->uuid), 'a settled run is not picked up again');
        self::assertSame(1, $this->modelCalls);
    }

    /**
     * A decision built from an earlier pause cannot replace a later one: a
     * suspension writes no event, so the event sequence alone does not tell
     * the pauses apart. The write is bound to the stored state the decision
     * was read from.
     */
    #[Test]
    public function aDecisionOfAnEarlierPauseCannotReplaceALaterOne(): void
    {
        $runtime = $this->runtime();
        $run     = $this->pausedForInput($runtime);
        $stale   = $this->runRow($run->uuid);
        $stored  = json_decode($stale->suspendedState ?? '', true);
        self::assertIsArray($stored);

        // The run went on and paused again without an event in between.
        $connection = $this->getService(ConnectionPool::class)->getConnectionForTable('tx_nrllm_agentrun');
        $connection->update('tx_nrllm_agentrun', ['status' => 'running'], ['uid' => $run->uid]);

        $earlier = SuspendedRunState::fromArray($stored);
        $later   = new SuspendedRunState($earlier->messages, $earlier->pendingCalls, 7, 0, 0, $earlier->allowedToolNames, [], $earlier->inputToolName, $earlier->inputSchema, initiatingActor: $earlier->initiatingActor, initiatingRunUuid: $earlier->initiatingRunUuid);
        self::assertTrue($this->persister->suspendForInput($this->persister->resumeHandle($this->runRow($run->uuid)) ?? self::fail('no handle'), $later));

        $current = $this->runRow($run->uuid);
        $handle  = $this->persister->resumeHandle($current);
        self::assertInstanceOf(AgentRunHandle::class, $handle);
        $generation = $handle->sequence;
        self::assertFalse($this->persister->queueContinuation($stale, AgentRunStatus::WAITING_FOR_INPUT, $generation, '{"decision":"stale"}'), 'the stale decision was written');
        $after = $this->runRow($run->uuid);
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $after->statusEnum());
        self::assertSame($current->suspendedState, $after->suspendedState, 'the later pause is as it was');

        self::assertTrue($this->persister->queueContinuation($current, AgentRunStatus::WAITING_FOR_INPUT, $generation, $current->suspendedState ?? '{}'), 'a decision of the current pause is written');
    }

    /**
     * The pause is written only by the claim that took the run. A segment
     * that lost its lease (the run was reclaimed since) writes no pause, and a
     * handle without a generation, as an in-request run has, keeps its guard.
     */
    #[Test]
    public function aSuspensionFromALostClaimWritesNothing(): void
    {
        $runtime = $this->runtime();
        $run     = $this->pausedForInput($runtime);
        $state   = SuspendedRunState::fromArray(json_decode($run->suspendedState ?? '', true) ?: []);
        $handle  = $this->persister->resumeHandle($run) ?? self::fail('no handle');
        self::assertSame(0, $handle->claimGeneration);

        // Claimed, then reclaimed by the reaper and claimed again: generation 1.
        $connection = $this->getService(ConnectionPool::class)->getConnectionForTable('tx_nrllm_agentrun');
        $connection->update('tx_nrllm_agentrun', ['status' => 'running', 'requeue_count' => 1, 'claimed_by' => 'worker-b:2'], ['uid' => $run->uid]);

        self::assertFalse($this->persister->suspendForInput($handle, $state), 'the pause of a stale claim was written');
        self::assertFalse($this->persister->suspend($handle, $state), 'the approval pause of a stale claim was written');
        $row = $this->runRow($run->uuid);
        self::assertSame([AgentRunStatus::RUNNING, 'worker-b:2'], [$row->statusEnum(), $row->claimedBy]);

        self::assertTrue($this->persister->suspendForInput(new AgentRunHandle($run->uid, $run->uuid), $state), 'a handle without a generation keeps the guard it had');
    }

    /**
     * The worker refuses a stored decision and releases the run to its pause,
     * but the run was reclaimed meanwhile: the release writes nothing and does
     * not fail the new claimant's run.
     */
    #[Test]
    public function aReleaseAfterALostClaimLeavesTheNewClaimantsRunAlone(): void
    {
        $resolver = new class ($this) implements ActingBackendUserResolverInterface {
            public function __construct(private readonly DurableDecisionTest $test) {}

            public function resolve(AiActorContext $actor): ?BackendUserAuthentication
            {
                // Until armed the user is the real one; once armed, the run is
                // taken over by another worker and the user no longer resolves.
                return $this->test->reclaimWhenArmed() ? null : (new ActingBackendUserResolver())->resolve($actor);
            }
        };
        $runtime = $this->runtime(resolver: $resolver);
        $run     = $this->pausedForInput($runtime);
        $pause   = $runtime->pendingPause($this->owner(), $run->uuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        $runtime->storeInput($this->owner(), $run->uuid, new InputSubmission(['answer' => 'B'], self::OWNER, $pause->turnDigest), $pause->generation);

        $this->reclaimRun = $run->uid;
        $runtime->runQueued($run->uuid);

        $row = $this->runRow($run->uuid);
        self::assertSame([AgentRunStatus::RUNNING, 'worker-b:2'], [$row->statusEnum(), $row->claimedBy], 'the new claimant still holds its run');
    }

    public function reclaimWhenArmed(): bool
    {
        if ($this->reclaimRun === 0) {
            return false;
        }

        $this->getService(ConnectionPool::class)->getConnectionForTable('tx_nrllm_agentrun')
            ->executeStatement("UPDATE tx_nrllm_agentrun SET requeue_count = requeue_count + 1, claimed_by = 'worker-b:2' WHERE uid = ?", [$this->reclaimRun]);
        $this->reclaimRun = 0;

        return true;
    }

    // ------------------------------------------------------------------

    private function pausedForInput(AgentRuntime $runtime): AgentRun
    {
        $this->responses = [$this->toolTurn([new ToolCall('q1', 'ask_choice', ['question' => 'A or B?', 'options' => ['A', 'B']])])];
        $result          = $runtime->run($this->request(['ask_choice']));
        self::assertSame(AgentRunOutcome::AWAITING_INPUT, $result->outcome);

        return $this->runRow($result->runUuid);
    }

    private function runRow(string $uuid): AgentRun
    {
        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionOf(AgentRun $run): array
    {
        $state    = json_decode($run->suspendedState ?? '', true);
        $decision = is_array($state) ? ($state[StoredDecision::STORAGE_KEY] ?? []) : [];
        self::assertIsArray($decision);

        /** @var array<string, mixed> $decision */
        return $decision;
    }

    private function owner(): AiActorContext
    {
        return AiActorContext::backendUser(self::OWNER);
    }

    /**
     * @param list<string> $tools
     */
    private function request(array $tools): AgentRunRequest
    {
        return new AgentRunRequest($this->configuration, [['role' => 'user', 'content' => 'go']], $this->owner(), allowedToolNames: $tools);
    }

    private function runtime(?WorkerVaultScope $vaultScope = null, ?ActingBackendUserResolverInterface $resolver = null): AgentRuntime
    {
        $writer = new class ($this) implements ToolInterface, ToolEffectInterface {
            public function __construct(private readonly DurableDecisionTest $test) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('write_thing', 'Writes a thing.', ['type' => 'object', 'properties' => new stdClass()]);
            }

            public function execute(array $arguments, ToolExecutionContext $context): ToolResult
            {
                $this->test->wrote();

                return ToolResult::text('written');
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

            public function getEffect(): ToolEffect
            {
                return ToolEffect::NON_IDEMPOTENT_WRITE;
            }
        };
        $registry = new ToolRegistry([new AskChoiceTool(), $writer]);

        $policy = self::createStub(ToolCallPolicyInterface::class);
        $policy->method('skillAllowListForRun')->willReturn(new SkillToolAllowList(null));
        $policy->method('filterOfferable')->willReturnCallback(static fn (?array $names): array => array_values($names ?? []));
        $policy->method('decide')->willReturnCallback(fn (string $name): ToolPolicyDecision => new ToolPolicyDecision($name, !in_array($name, $this->refused, true), ToolDataClass::PUBLIC_CONTENT, TrustZone::LOCAL, ToolDataClass::PUBLIC_CONTENT));

        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(function (): CompletionResponse {
            ++$this->modelCalls;
            $next = array_shift($this->responses);
            self::assertInstanceOf(CompletionResponse::class, $next, 'The model was asked more often than scripted.');

            return $next;
        });
        $manager->method('chatWithConfiguration')->willReturn($this->finalTurn('closing'));

        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findByUid')->willReturn($this->configuration);

        $probe = self::createStub(ProcessPinProbe::class);
        $probe->method('processPinOf')->willReturnCallback(fn (): ?bool => $this->processPin);

        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $loop  = new ToolLoopService($manager, $registry, $policy, writeEnvironment: new WorkerWriteEnvironment($this->getService(LanguageServiceFactory::class)));
        $guard = new class ($this) implements RunResumeGuardInterface {
            public function __construct(private readonly DurableDecisionTest $test) {}

            public function refusal(AgentRunHandle $handle, AiActorContext $owner): ?string
            {
                return $this->test->guardRefusal();
            }
        };

        return new AgentRuntime(
            $loop,
            $this->persister,
            $configurations,
            new NullLogger(),
            $bus,
            actingBackendUserResolver: $resolver ?? new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($registry),
            runExecutor: new AgentRunExecutor($loop, $this->persister, new NullLogger(), new ActingBackendUserResolver(), new ToolEffectResolver($registry), resumeGuards: [$guard]),
            toolPolicy: $policy,
            processPinProbe: $probe,
            workerVaultScope: $vaultScope,
        );
    }

    public function guardRefusal(): ?string
    {
        return $this->guardRefusal;
    }

    public function wrote(): void
    {
        ++$this->writes;
        $this->writerSaw = [$GLOBALS['BE_USER'] ?? null, $GLOBALS['LANG'] ?? null];
    }

    /**
     * @param list<ToolCall> $calls
     */
    private function toolTurn(array $calls): CompletionResponse
    {
        return new CompletionResponse('', 'fake', UsageStatistics::fromTokens(0, 0), toolCalls: $calls);
    }

    private function finalTurn(string $text): CompletionResponse
    {
        return new CompletionResponse($text, 'fake', UsageStatistics::fromTokens(0, 0));
    }
}
