<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
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
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationGate;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationTool;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationToolProvider;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDependencyCanceller;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDependencyPoller;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\DependencyResumeCoordinator;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Fixture\AgentRunRepositoryFactory;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A run that delegates a step to a remote A2A agent (spec 051 stage 2:
 * A2A-R2 to A2A-R12, ADR-281), end to end over a scripted transport and a
 * scripted model.
 */
#[CoversClass(A2aDelegationTool::class)]
#[CoversClass(A2aDelegationToolProvider::class)]
#[CoversClass(A2aDependencyPoller::class)]
#[CoversClass(A2aDependencyCanceller::class)]
#[CoversClass(DependencyResumeCoordinator::class)]
#[CoversClass(ToolLoopService::class)]
#[CoversClass(AgentRunExecutor::class)]
final class A2aDelegationTest extends AbstractFunctionalTestCase
{
    private const OWNER = 1;

    private const SKILL_TOOL = 'a2a_remote_summarise';

    private ScriptedA2aTransport $transport;

    private AgentRunPersister $persister;

    private A2aSendJournal $journal;

    private LlmConfiguration $configuration;

    /** @var list<AiActorContext> */
    private array $recordedActors = [];

    private int $recordedCalls = 0;

    /** @var list<list<mixed>> every transcript the model was sent */
    private array $transcripts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2);
        $pool            = $this->getService(ConnectionPool::class);
        $this->transport = new ScriptedA2aTransport();
        $this->journal   = new A2aSendJournal($pool);
        $this->persister = new AgentRunPersister(
            AgentRunRepositoryFactory::create($pool, $this->getService(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
        $this->configuration = new LlmConfiguration();
        $this->configuration->_setProperty('uid', 1);
        $this->configuration->setIsActive(true);
    }

    /**
     * A2A-R8: one tool per usable record and card skill, from the records
     * alone; no request leaves while the tools are listed.
     */
    #[Test]
    public function listingTheToolsMakesNoRequestAndSkipsUnusableRecords(): void
    {
        $this->agent(['identifier' => 'remote']);
        $this->agent(['identifier' => 'off', 'enabled' => 0]);
        $this->agent(['identifier' => 'unclassified', 'data_class' => '']);
        $this->agent(['identifier' => 'unimported', 'interface_url' => '', 'card_json' => '']);
        $this->agent(['identifier' => 'badcard', 'card_json' => '{"name":"x"}']);

        $names = array_map(static fn (ToolInterface $tool): string => $tool->getSpec()->name, iterator_to_array($this->provider()->tools(), false));

        self::assertSame([self::SKILL_TOOL, 'a2a_remote_translate'], $names);
        self::assertSame([], $this->transport->requests);
    }

    /**
     * A2A-R2: while nr_mcp_agent is loaded in a version that does not handle
     * the status, no run is offered a delegation tool.
     */
    #[Test]
    public function noToolIsOfferedWhileAnIncompatibleNrMcpAgentIsLoaded(): void
    {
        $this->agent(['identifier' => 'remote']);

        self::assertSame([], iterator_to_array($this->provider(new A2aDelegationGate(null, static fn (): string => '2.4.0'))->tools(), false));
        self::assertCount(2, iterator_to_array($this->provider(new A2aDelegationGate(null, static fn (): ?string => null))->tools(), false));
    }

    /**
     * A2A-R5: a turn that pairs the delegation with another tool executes
     * neither; the model is told to call the delegation alone.
     */
    #[Test]
    public function aTurnMixingADelegationWithAnotherToolExecutesNeither(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0]);
        $runtime = $this->runtime([
            $this->toolTurn([new ToolCall('d1', self::SKILL_TOOL, ['message' => 'summarise']), new ToolCall('r1', 'record_actor', [])]),
            $this->finalTurn('gave up'),
        ]);

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertSame([], $this->transport->requests);
        self::assertSame(0, $this->recordedCalls);
        $refusals = array_values(array_filter($result->steps, static fn ($step): bool => $step->toolName !== null));
        self::assertCount(2, $refusals);
        self::assertStringContainsString('must be called alone', (string) $refusals[0]->toolResult);
    }

    /**
     * A2A-R3, A2A-R4, A2A-R7, A2A-R9 to A2A-R11: the accepted task suspends
     * the run with its checkpoint; polls without news reschedule with a
     * doubled interval, a transport failure is no news, and the settled task
     * resumes the run as its owner with the remote's artifact as the result
     * of exactly the pending call. SendMessage leaves once.
     */
    #[Test]
    public function anAcceptedTaskSuspendsTheRunAndItsResultResumesItOnce(): void
    {
        $agent = $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10, 'max_poll_interval_seconds' => 15, 'max_wait_seconds' => 600]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_SUBMITTED')]]);
        $runtime = $this->runtime([
            $this->toolTurn([new ToolCall('d1', self::SKILL_TOOL, ['message' => 'summarise the report'])], 7, 3),
            $this->toolTurn([new ToolCall('r1', 'record_actor', [])], 11, 5),
            $this->finalTurn('the summary is in', 13, 2),
        ]);

        $suspended = $runtime->run($this->request(AiActorContext::backendUser(self::OWNER, workspaceId: 0)));

        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $suspended->outcome, (string) $suspended->error);
        $run = $this->runOf($suspended->runUuid);
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $run->statusEnum());
        $checkpoint = $this->checkpointOf($run);
        $intent     = $this->journal->findByMessageId(self::OWNER, $this->sentMessageId(0));
        self::assertNotNull($intent);
        self::assertSame(['d1', self::SKILL_TOOL, $intent->uid, (int) $agent->getUid(), 'task-1', self::OWNER, 0, 1], [$checkpoint->toolCallId, $checkpoint->toolName, $checkpoint->journalUid, $checkpoint->agentUid, $checkpoint->remoteTaskId, $checkpoint->ownerUid, $checkpoint->workspaceId, $checkpoint->delegations]);
        self::assertSame($checkpoint->suspendedAt + 600, $checkpoint->deadline);
        self::assertGreaterThan(0, $checkpoint->transferredBytes);
        $params = ScriptedA2aTransport::envelopeOf($this->transport->requests[0])['params'] ?? null;
        self::assertIsArray($params);
        self::assertSame(['returnImmediately' => true], $params['configuration'] ?? null);

        $poller = $this->poller();
        $start  = $checkpoint->suspendedAt;

        // Not due before the minimum interval: no request.
        self::assertSame(1, $poller->tick($start + 5)->notDue);
        self::assertCount(1, $this->transport->requests);

        // WORKING: rescheduled with the minimum, then doubled up to the maximum.
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        self::assertSame(1, $poller->tick($start + 10)->waiting);
        self::assertSame([10, $start + 20], $this->schedule($intent->uid));
        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::notSent('CONNECT_FAILED', 'no route'));
        self::assertSame(1, $poller->tick($start + 20)->waiting);
        self::assertSame([15, $start + 35], $this->schedule($intent->uid));
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($suspended->runUuid)->statusEnum());

        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_COMPLETED', 'The report says three things.')]);
        $report = $poller->tick($start + 35);

        self::assertSame(1, $report->resumed);
        $finished = $this->runOf($suspended->runUuid);
        self::assertSame(AgentRunStatus::COMPLETED, $finished->statusEnum());
        self::assertSame(['SendMessage', 'GetTask', 'GetTask', 'GetTask'], $this->methods());
        // As the run's owner, in its workspace (A2A-R11, ADR-260).
        self::assertCount(1, $this->recordedActors);
        self::assertSame([self::OWNER, 0], [$this->recordedActors[0]->backendUserUid, $this->recordedActors[0]->workspaceId]);
        // Token budget across both segments (A2A-R7).
        self::assertSame([31, 10], [$finished->totalPromptTokens, $finished->totalCompletionTokens]);
        $toolSteps = $this->toolResultsOf($finished, self::SKILL_TOOL);
        self::assertCount(2, $toolSteps);
        self::assertStringContainsString('task task-1', $toolSteps[0]);
        self::assertStringContainsString('The report says three things.', $toolSteps[1]);

        // The resume answered exactly the pending call d1 (A2A-R4).
        self::assertSame(['tool', 'd1'], $this->lastToolResultOf($this->transcripts[1]));

        // Single-shot (A2A-R4): another tick finds nothing to resume.
        self::assertSame(0, $poller->tick($start + 100)->resumed);
        self::assertCount(4, $this->transport->requests);
    }

    /**
     * The default path (A2A-R8, ADR-084): a record that requires approval
     * suspends for it first; the approved call sends and then waits.
     */
    #[Test]
    public function anApprovedDelegationSendsAndThenWaits(): void
    {
        $this->agent(['identifier' => 'remote']);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', self::SKILL_TOOL, ['message' => 'summarise'])])]);

        $paused = $runtime->run($this->request());
        self::assertSame(AgentRunOutcome::AWAITING_APPROVAL, $paused->outcome, (string) $paused->error);
        self::assertSame([], $this->transport->requests);

        $state = $paused->suspendedState;
        self::assertInstanceOf(SuspendedRunState::class, $state);
        $approved = $runtime->approve(AiActorContext::backendUser(self::OWNER, true), $paused->runUuid, new ApprovalDecision(true, self::OWNER, (new PendingTurnDigest())->forState($state)));

        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $approved->outcome, (string) $approved->error);
        self::assertSame(['SendMessage'], $this->methods());
        self::assertSame('d1', $this->checkpointOf($this->runOf($paused->runUuid))->toolCallId);
    }

    /**
     * A2A-R9: a message answer or a task already settled is an ordinary tool
     * result; the run does not suspend.
     */
    #[Test]
    public function aMessageAnswerOrASettledTaskDoesNotSuspend(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0]);
        $this->transport->thenJson(['result' => ['message' => ['messageId' => 'r', 'role' => 'ROLE_AGENT', 'parts' => [['text' => 'right away']]]]]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-2', 'TASK_STATE_COMPLETED', 'done at once')]]);
        $runtime = $this->runtime([
            $this->toolTurn([new ToolCall('d1', self::SKILL_TOOL, ['message' => 'one'])]),
            $this->toolTurn([new ToolCall('d2', self::SKILL_TOOL, ['message' => 'two'])]),
            $this->finalTurn('both answered'),
        ]);

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        $results = $this->toolResultsOf($this->runOf($result->runUuid), self::SKILL_TOOL);
        self::assertStringContainsString('right away', $results[0]);
        self::assertStringContainsString('done at once', $results[1]);
    }

    /**
     * A2A-R7, A2A-C6: the record's delegation limit counts this run's sends;
     * an unknown outcome is an error result and is never resent.
     */
    #[Test]
    public function theLimitHoldsAndAnUnknownOutcomeIsNotResent(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'max_delegations_per_run' => 1]);
        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', 'no answer'));
        $runtime = $this->runtime([
            $this->toolTurn([new ToolCall('d1', self::SKILL_TOOL, ['message' => 'one'])]),
            $this->toolTurn([new ToolCall('d2', self::SKILL_TOOL, ['message' => 'two'])]),
            $this->finalTurn('could not'),
        ]);

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertSame(['SendMessage'], $this->methods());
        $results = $this->toolResultsOf($this->runOf($result->runUuid), self::SKILL_TOOL);
        self::assertStringContainsString('outcome unknown', $results[0]);
        self::assertStringContainsString($this->sentMessageId(0), $results[0]);
        self::assertStringContainsString('limit of the record', $results[1]);
        self::assertSame(A2aSendState::OUTCOME_UNKNOWN, $this->journal->findByMessageId(self::OWNER, $this->sentMessageId(0))?->state);
    }

    /**
     * A2A-R10, A2A-R12: at the deadline the run resumes with a timeout result
     * and the remote task is cancelled once.
     */
    #[Test]
    public function theDeadlineResumesWithATimeoutAndCancelsTheRemoteTaskOnce(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'max_wait_seconds' => 60]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $runtime = $this->runtime([
            $this->toolTurn([new ToolCall('d1', self::SKILL_TOOL, ['message' => 'slow'])]),
            $this->finalTurn('it timed out'),
        ]);
        $suspended  = $runtime->run($this->request());
        $checkpoint = $this->checkpointOf($this->runOf($suspended->runUuid));
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_CANCELED')]);

        $report = $this->poller()->tick($checkpoint->deadline);

        self::assertSame(1, $report->timedOut);
        self::assertSame(['SendMessage', 'CancelTask'], $this->methods());
        $run = $this->runOf($suspended->runUuid);
        self::assertSame(AgentRunStatus::COMPLETED, $run->statusEnum());
        self::assertStringContainsString('did not finish within 60 seconds', $this->toolResultsOf($run, self::SKILL_TOOL)[1]);
        self::assertSame('TASK_STATE_CANCELED', $this->journal->findForRun($run->uid, $checkpoint->journalUid)?->cancelOutcome);
        self::assertSame(0, $this->poller()->tick($checkpoint->deadline + 1)->timedOut);
        self::assertCount(2, $this->transport->requests);
    }

    /**
     * A2A-R12: cancelling a waiting run sends CancelTask once and settles the
     * run CANCELLED whatever the remote answers; the answer is journalled.
     */
    #[Test]
    public function cancellingAWaitingRunCancelsItsRemoteTaskOnceWhateverTheAnswer(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $runtime   = $this->runtime([$this->toolTurn([new ToolCall('d1', self::SKILL_TOOL, ['message' => 'x'])])]);
        $suspended = $runtime->run($this->request());
        $intentUid = $this->checkpointOf($this->runOf($suspended->runUuid))->journalUid;
        $this->transport->thenJson(['error' => ['code' => -32002, 'message' => 'not cancelable', 'data' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'TASK_NOT_CANCELABLE', 'domain' => 'a2a-protocol.org']]]]);

        self::assertTrue($runtime->cancel(AiActorContext::backendUser(self::OWNER, true), $suspended->runUuid));
        self::assertFalse($runtime->cancel(AiActorContext::backendUser(self::OWNER, true), $suspended->runUuid));

        $run = $this->runOf($suspended->runUuid);
        self::assertSame(AgentRunStatus::CANCELLED, $run->statusEnum());
        self::assertSame(['SendMessage', 'CancelTask'], $this->methods());
        self::assertSame('FAILED: TASK_NOT_CANCELABLE', $this->journal->findForRun($run->uid, $intentUid)?->cancelOutcome);
        self::assertSame(0, $this->poller()->tick(time() + 100000)->resumed);
    }

    /**
     * A2A-R6: of two workers that resolve the same dependency one wins; and a
     * worker that lost its lease after the claim writes nothing further.
     */
    #[Test]
    public function exactlyOneWorkerResolvesTheDependencyAndALostLeaseWritesNothing(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $runtime    = $this->runtime([$this->toolTurn([new ToolCall('d1', self::SKILL_TOOL, ['message' => 'x'])]), $this->finalTurn('never reached')]);
        $suspended  = $runtime->run($this->request());
        $run        = $this->runOf($suspended->runUuid);
        $checkpoint = $this->checkpointOf($run);
        $events     = count($this->persister->findEvents($run->uid));
        $pool       = $this->getService(ConnectionPool::class);

        $first = $this->coordinator()->resume($run, $checkpoint, ToolResult::text('result'), static function () use ($pool, $run): void {
            // Another owner takes the run over between the claim and the first step.
            $pool->getConnectionForTable('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['claimed_by' => 'someone-else:9'], ['uid' => $run->uid]);
        });
        $second = $this->coordinator()->resume($run, $checkpoint, ToolResult::text('result'));

        self::assertSame(AgentRunOutcome::LEASE_LOST, $first?->outcome);
        self::assertNull($second);
        $after = $this->runOf($suspended->runUuid);
        self::assertSame([AgentRunStatus::RUNNING, 'someone-else:9'], [$after->statusEnum(), $after->claimedBy]);
        self::assertCount($events, $this->persister->findEvents($run->uid));
    }

    /**
     * A2A-R12: only the call that wins the guarded transition sends
     * CancelTask; a run a resume already claimed is left alone.
     */
    #[Test]
    public function aCancelThatLosesToAResumeClaimSendsNothing(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $runtime   = $this->runtime([$this->toolTurn([new ToolCall('d1', self::SKILL_TOOL, ['message' => 'x'])])]);
        $suspended = $runtime->run($this->request());
        $waiting   = $this->runOf($suspended->runUuid);
        self::assertTrue($this->persister->claimResumeFromDependency($waiting, 'poller:1', time() + 300));

        self::assertFalse($this->canceller()->cancelWaiting($waiting));
        self::assertSame(['SendMessage'], $this->methods());
    }

    // ------------------------------------------------------------------

    /**
     * Role and call id of the last message of a transcript.
     *
     * @param list<mixed> $messages
     *
     * @return array{string, string}
     */
    private function lastToolResultOf(array $messages): array
    {
        $last = end($messages);
        $data = is_object($last) && method_exists($last, 'toTranscriptArray') ? $last->toTranscriptArray() : $last;
        self::assertIsArray($data);

        return [self::text($data['role'] ?? null), self::text($data['tool_call_id'] ?? null)];
    }

    /**
     * @param list<CompletionResponse> $responses
     */
    private function runtime(array $responses): AgentRuntime
    {
        $registry = $this->registry();
        $policy   = $this->policy();
        $manager  = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(function (array $messages) use (&$responses): CompletionResponse {
            $this->transcripts[] = $messages;
            $next                = array_shift($responses);
            self::assertInstanceOf(CompletionResponse::class, $next, 'The model was asked more often than scripted.');

            return $next;
        });
        $manager->method('chatWithConfiguration')->willReturn($this->finalTurn('closing'));
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $this->loop = new ToolLoopService($manager, $registry, $policy);

        return new AgentRuntime(
            $this->loop,
            $this->persister,
            $this->configurations(),
            new NullLogger(),
            $bus,
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($registry),
            toolPolicy: $policy,
            dependencyCanceller: $this->canceller(),
        );
    }

    private ?ToolLoopService $loop = null;

    private function coordinator(): DependencyResumeCoordinator
    {
        self::assertInstanceOf(ToolLoopService::class, $this->loop);
        $registry = $this->registry();

        return new DependencyResumeCoordinator(
            $this->persister,
            $this->configurations(),
            $this->loop,
            new AgentRunExecutor($this->loop, $this->persister, new NullLogger(), new ActingBackendUserResolver(), new ToolEffectResolver($registry)),
        );
    }

    private function poller(): A2aDependencyPoller
    {
        return new A2aDependencyPoller(
            $this->coordinator(),
            $this->journal,
            $this->getService(A2aRemoteAgentRepository::class),
            $this->client(),
            $this->canceller(),
            $this->vaultScope(),
            jitter: static fn (int $interval): int => 0,
        );
    }

    private function canceller(): A2aDependencyCanceller
    {
        return new A2aDependencyCanceller($this->persister, $this->journal, $this->getService(A2aRemoteAgentRepository::class), $this->client(), $this->vaultScope());
    }

    private function provider(?A2aDelegationGate $gate = null): A2aDelegationToolProvider
    {
        return new A2aDelegationToolProvider(
            $this->getService(A2aRemoteAgentRepository::class),
            $this->client(),
            $this->journal,
            new A2aResponseParser(),
            $gate ?? new A2aDelegationGate(null, static fn (): ?string => null),
        );
    }

    private function registry(): ToolRegistry
    {
        $recorder = new class ($this) implements ToolInterface {
            public function __construct(private readonly A2aDelegationTest $test) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('record_actor', 'Records who runs it.', ['type' => 'object', 'properties' => new stdClass()]);
            }

            public function execute(array $arguments, ToolExecutionContext $context): ToolResult
            {
                $this->test->record($context->actor);

                return ToolResult::text('recorded');
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

        return new ToolRegistry([$recorder], [$this->provider()]);
    }

    public function record(AiActorContext $actor): void
    {
        $this->recordedActors[] = $actor;
        ++$this->recordedCalls;
    }

    private function policy(): ToolCallPolicyInterface
    {
        $policy = self::createStub(ToolCallPolicyInterface::class);
        $policy->method('skillAllowListForRun')->willReturn(new SkillToolAllowList(null));
        $policy->method('filterOfferable')->willReturnCallback(static fn (?array $names): array => array_values($names ?? ['record_actor', self::SKILL_TOOL, 'a2a_remote_translate']));
        $policy->method('decide')->willReturnCallback(static fn (string $name): ToolPolicyDecision => new ToolPolicyDecision($name, true, ToolDataClass::PUBLIC_CONTENT, TrustZone::LOCAL, ToolDataClass::PUBLIC_CONTENT));

        return $policy;
    }

    private function configurations(): LlmConfigurationRepository
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findByUid')->willReturn($this->configuration);

        return $repository;
    }

    private function client(): A2aClient
    {
        return new A2aClient($this->journal, $this->transport, new A2aResponseParser());
    }

    private function vaultScope(): WorkerVaultScope
    {
        return new WorkerVaultScope(self::createStub(TechnicalActorContextInterface::class), self::createStub(ExtensionConfiguration::class), false);
    }

    private function request(?AiActorContext $actor = null): AgentRunRequest
    {
        return new AgentRunRequest($this->configuration, [['role' => 'user', 'content' => 'please delegate']], $actor ?? AiActorContext::backendUser(self::OWNER));
    }

    /**
     * @param list<ToolCall> $calls
     */
    private function toolTurn(array $calls, int $prompt = 0, int $completion = 0): CompletionResponse
    {
        return new CompletionResponse('', 'fake', UsageStatistics::fromTokens($prompt, $completion), toolCalls: $calls);
    }

    private function finalTurn(string $text, int $prompt = 0, int $completion = 0): CompletionResponse
    {
        return new CompletionResponse($text, 'fake', UsageStatistics::fromTokens($prompt, $completion));
    }

    /**
     * @return array<string, mixed>
     */
    private function task(string $id, string $state, string $artifact = ''): array
    {
        $task = ['id' => $id, 'contextId' => 'ctx-1', 'status' => ['state' => $state]];
        if ($artifact !== '') {
            $task['artifacts'] = [['artifactId' => 'a1', 'parts' => [['text' => $artifact]]]];
        }

        return $task;
    }

    /**
     * @param array<string, int|string> $values
     */
    private function agent(array $values = []): A2aRemoteAgent
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent');
        $connection->insert('tx_nrllm_a2a_agent', $values + [
            'title'         => 'Remote',
            'card_url'      => 'https://agent.example/.well-known/agent-card.json',
            'interface_url' => 'https://agent.example/a2a/jsonrpc',
            'card_json'     => json_encode([
                'name'                => 'Remote',
                'description'         => 'A remote agent',
                'version'             => '1.0.0',
                'supportedInterfaces' => [['url' => 'https://agent.example/a2a/jsonrpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']],
                'capabilities'        => new stdClass(),
                'defaultInputModes'   => ['text/plain'],
                'defaultOutputModes'  => ['text/plain'],
                'skills'              => [
                    ['id' => 'summarise', 'name' => 'Summarise', 'description' => 'Summarises a text.', 'tags' => ['text']],
                    ['id' => 'translate', 'name' => 'Translate', 'description' => 'Translates a text.', 'tags' => ['text']],
                ],
            ], JSON_THROW_ON_ERROR),
            'credential' => 'vault-credential-id',
            'data_class' => ToolDataClass::PUBLIC_CONTENT->value,
            'enabled'    => 1,
        ]);

        $agent = $this->getService(A2aRemoteAgentRepository::class)->findByUid((int) $connection->lastInsertId());
        self::assertInstanceOf(A2aRemoteAgent::class, $agent);

        return $agent;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function runOf(string $uuid): AgentRun
    {
        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    private function checkpointOf(AgentRun $run): DependencyCheckpoint
    {
        $decoded = json_decode((string) $run->suspendedState, true);
        self::assertIsArray($decoded);
        $checkpoint = DependencyCheckpoint::fromStoredState($decoded);
        self::assertInstanceOf(DependencyCheckpoint::class, $checkpoint);

        return $checkpoint;
    }

    /**
     * @return list<string>
     */
    private function methods(): array
    {
        return array_map(static fn (A2aHttpRequest $request): string => self::text(ScriptedA2aTransport::envelopeOf($request)['method'] ?? null), $this->transport->requests);
    }

    private function sentMessageId(int $index): string
    {
        $params = ScriptedA2aTransport::envelopeOf($this->transport->requests[$index])['params'] ?? [];

        return is_array($params) && is_array($params['message'] ?? null) ? self::text($params['message']['messageId'] ?? null) : '';
    }

    /**
     * @return array{int, int|null}
     */
    private function schedule(int $intentUid): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE)
            ->select(['poll_interval', 'next_poll_at'], A2aSendJournal::TABLE, ['uid' => $intentUid])->fetchAssociative();
        self::assertIsArray($row);

        return [(int) $row['poll_interval'], $row['next_poll_at'] === null ? null : (int) $row['next_poll_at']];
    }

    /**
     * The tool results the run's timeline recorded for one tool, in order.
     *
     * @return list<string>
     */
    private function toolResultsOf(AgentRun $run, string $tool): array
    {
        $results = [];
        foreach ($this->persister->findEvents($run->uid) as $event) {
            $payload = $event->payload;
            if (($payload['toolName'] ?? null) === $tool && is_string($payload['toolResult'] ?? null)) {
                $results[] = $payload['toolResult'];
            }
        }

        return $results;
    }
}
