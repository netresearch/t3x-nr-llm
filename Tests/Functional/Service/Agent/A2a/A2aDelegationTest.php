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
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationGate;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationTool;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDelegationToolProvider;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDependencyCanceller;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDependencyPoller;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aRemoteAnswerer;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\DecisionReceipt;
use Netresearch\NrLlm\Service\Agent\DependencyResumeCoordinator;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAccessDeniedException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleInputTurnException;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
use Netresearch\NrLlm\Service\Agent\PendingPause;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrLlm\Service\Agent\ResumeCoordinator;
use Netresearch\NrLlm\Service\Agent\RunScopedStateInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
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
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\FenceRecordingRunRepository;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
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

        self::assertSame([$this->skillTool(), $this->skillTool('translate')], $names);
        self::assertSame([], $this->transport->requests);
    }

    /**
     * A tool name never stands for two skills: two agents whose identifier
     * and skill id read alike get different names, and a card that lists a
     * skill id twice offers it once.
     */
    #[Test]
    public function aToolNameNeverStandsForTwoSkills(): void
    {
        $card = static fn (string ...$ids): string => json_encode([
            'name'                => 'Remote',
            'description'         => 'A remote agent',
            'version'             => '1.0.0',
            'supportedInterfaces' => [['url' => 'https://agent.example/a2a/jsonrpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']],
            'capabilities'        => new stdClass(),
            'defaultInputModes'   => ['text/plain'],
            'defaultOutputModes'  => ['text/plain'],
            'skills'              => array_map(static fn (string $id): array => ['id' => $id, 'name' => $id, 'description' => 'd', 'tags' => ['t']], $ids),
        ], JSON_THROW_ON_ERROR);
        $this->agent(['identifier' => 'ab', 'card_json' => $card('c_d', 'c_d', 'C_D')]);
        $this->agent(['identifier' => 'ab_c', 'card_json' => $card('d')]);

        $names = array_map(static fn (ToolInterface $tool): string => $tool->getSpec()->name, iterator_to_array($this->provider()->tools(), false));

        self::assertCount(3, $names);
        self::assertSame($names, array_values(array_unique($names)));
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
     * A2A-R8: a tool instance lives as long as the registry, a whole worker
     * process; approval, data class and the switch are read from the record
     * as it is now, so an administrator's change reaches a tool built before.
     */
    #[Test]
    public function aToolBuiltBeforeAnAdministratorsChangeFollowsTheCurrentRecord(): void
    {
        $agent = $this->agent(['identifier' => 'remote', 'requires_approval' => 0]);
        $tool  = iterator_to_array($this->provider()->tools(), false)[0];
        self::assertInstanceOf(A2aDelegationTool::class, $tool);
        self::assertFalse($tool->requiresApproval());
        self::assertSame(ToolDataClass::PUBLIC_CONTENT, $tool->getDataClass());

        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent');
        $connection->update('tx_nrllm_a2a_agent', ['requires_approval' => 1, 'data_class' => ToolDataClass::INTERNAL_CONFIGURATION->value], ['uid' => $agent->getUid()]);
        self::assertTrue($tool->requiresApproval());
        self::assertSame(ToolDataClass::INTERNAL_CONFIGURATION, $tool->getDataClass());

        $connection->update('tx_nrllm_a2a_agent', ['enabled' => 0], ['uid' => $agent->getUid()]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('no')]);
        $connection->update('tx_nrllm_a2a_agent', ['requires_approval' => 0], ['uid' => $agent->getUid()]);
        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertSame([], $this->transport->requests);
        self::assertStringContainsString('disabled', $this->toolResultsOf($this->runOf($result->runUuid), $this->skillTool())[0]);

        $connection->delete('tx_nrllm_a2a_agent', ['uid' => $agent->getUid()]);
        self::assertTrue($tool->requiresApproval());
    }

    #[Test]
    public function aTemporaryRemoteErrorIsNoNewsAndATaskNotFoundResumes(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10, 'max_poll_interval_seconds' => 10, 'max_wait_seconds' => 600]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_SUBMITTED')]]);
        $runtime   = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('done')]);
        $suspended = $runtime->run($this->request());
        $start     = $this->checkpointOf($this->runOf($suspended->runUuid))->suspendedAt;
        $poller    = $this->poller();

        foreach ([-32000 => 10, -32603 => 20] as $code => $offset) {
            $this->transport->thenJson(['error' => ['code' => $code, 'message' => 'try later']]);
            self::assertSame(1, $poller->tick($start + $offset)->waiting, 'error ' . $code);
            self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($suspended->runUuid)->statusEnum());
        }

        $this->transport->thenJson(['error' => ['code' => -32001, 'message' => 'no such task']]);
        self::assertSame(1, $poller->tick($start + 30)->resumed);
        self::assertSame(['SendMessage', 'GetTask', 'GetTask', 'GetTask'], $this->methods());
    }

    #[Test]
    public function aReadThatFailsAfterTheClaimGivesTheRunBackInsteadOfFailingIt(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $suspended  = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('done')])->run($this->request());
        $run        = $this->runOf($suspended->runUuid);
        $checkpoint = $this->checkpointOf($run);

        $runs                   = new FenceRecordingRunRepository(AgentRunRepositoryFactory::create($this->getService(ConnectionPool::class), $this->getService(AgentStateCodec::class)));
        $runs->beforeFindByUuid = static fn (): never => throw new RuntimeException('database away', 3504187263);

        $real            = $this->persister;
        $this->persister = new AgentRunPersister($runs, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
        try {
            self::assertNull($this->coordinator()->resume($run, $checkpoint, ToolResult::text('result')));
        } finally {
            $this->persister = $real;
        }

        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($suspended->runUuid)->statusEnum());
        self::assertInstanceOf(AgentRunResult::class, $this->coordinator()->resume($this->runOf($suspended->runUuid), $checkpoint, ToolResult::text('result')));
        self::assertSame(AgentRunStatus::COMPLETED, $this->runOf($suspended->runUuid)->statusEnum());
    }

    #[Test]
    public function aSegmentThatLostTheRunCannotSuspendItForTheDependency(): void
    {
        $begun = $this->persister->begin(null, 1, 'worker-a:1', time() + 300);
        self::assertInstanceOf(AgentRunHandle::class, $begun);
        $segment    = new AgentRunHandle($begun->runUid, $begun->uuid, 0);
        $state      = new SuspendedRunState([['role' => 'user', 'content' => 'ask']], [['id' => 'd1', 'type' => 'function', 'function' => ['name' => 'a2a_x', 'arguments' => '{}']]], 1, 0, 0);
        $checkpoint = new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'd1', 'a2a_x', 1, 1, 'task-1', self::OWNER, 0, time(), time() + 600, 1, 0);
        $runs       = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_agentrun');

        // Another worker reclaimed the run: the generation moved on.
        $runs->update('tx_nrllm_agentrun', ['requeue_count' => 1, 'claimed_by' => 'worker-b:2'], ['uid' => $begun->runUid]);
        self::assertFalse($this->persister->suspendForDependency($segment, $state, $checkpoint));
        self::assertSame(AgentRunStatus::RUNNING, $this->runOf($begun->uuid)->statusEnum());
        self::assertSame('worker-b:2', $runs->select(['claimed_by'], 'tx_nrllm_agentrun', ['uid' => $begun->runUid])->fetchOne());

        // The segment of the current generation suspends it.
        self::assertTrue($this->persister->suspendForDependency(new AgentRunHandle($begun->runUid, $begun->uuid, 1), $state, $checkpoint));
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($begun->uuid)->statusEnum());
    }

    #[Test]
    public function aTickPagesPastOlderRunsThatAreNotDue(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'max_wait_seconds' => 600]);
        $runs = [];
        foreach (['task-old', 'task-new'] as $task) {
            $this->transport->thenJson(['result' => ['task' => $this->task($task, 'TASK_STATE_SUBMITTED')]]);
            $runs[$task] = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('done')])->run($this->request());
        }

        $now     = time();
        $journal = $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE);
        $journal->update(A2aSendJournal::TABLE, ['next_poll_at' => $now + 3000], ['uid' => $this->checkpointOf($this->runOf($runs['task-old']->runUuid))->journalUid]);
        $journal->update(A2aSendJournal::TABLE, ['next_poll_at' => $now], ['uid' => $this->checkpointOf($this->runOf($runs['task-new']->runUuid))->journalUid]);

        $this->transport->thenJson(['result' => $this->task('task-new', 'TASK_STATE_COMPLETED', 'new result')]);

        $report = $this->poller()->tick($now, 1);

        self::assertSame([1, 1], [$report->notDue, $report->resumed]);
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($runs['task-old']->runUuid)->statusEnum());
        self::assertSame(AgentRunStatus::COMPLETED, $this->runOf($runs['task-new']->runUuid)->statusEnum());
    }

    #[Test]
    public function aCardChangedByAnImportInAnotherProcessSendsNothing(): void
    {
        $agent = $this->agent(['identifier' => 'remote', 'requires_approval' => 0]);
        // The worker reads the record once; its identity map keeps that copy.
        iterator_to_array($this->provider()->tools(), false);
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent');

        foreach ([
            'confirm'       => ['card_changed' => 1],
            'changed since' => ['interface_url' => 'https://elsewhere.example/a2a'],
        ] as $expected => $import) {
            $connection->update('tx_nrllm_a2a_agent', $import, ['uid' => $agent->getUid()]);
            $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('no')]);
            $result  = $runtime->run($this->request());

            self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
            self::assertSame([], $this->transport->requests);
            self::assertStringContainsString($expected, $this->toolResultsOf($this->runOf($result->runUuid), $this->skillTool())[0]);
            $connection->update('tx_nrllm_a2a_agent', ['card_changed' => 0, 'interface_url' => $agent->getInterfaceUrl()], ['uid' => $agent->getUid()]);
        }
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
            $this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'summarise']), new ToolCall('r1', 'record_actor', [])]),
            $this->finalTurn('gave up'),
        ]);

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertSame([], $this->transport->requests);
        self::assertSame(0, $this->recordedCalls);
        $refusals = array_values(array_filter($result->steps, static fn (RunStep $step): bool => $step->toolName !== null));
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
            $this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'summarise the report'])], 7, 3),
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
        self::assertSame(['d1', $this->skillTool(), $intent->uid, (int) $agent->getUid(), 'task-1', self::OWNER, 0, 1], [$checkpoint->toolCallId, $checkpoint->toolName, $checkpoint->journalUid, $checkpoint->agentUid, $checkpoint->remoteTaskId, $checkpoint->ownerUid, $checkpoint->workspaceId, $checkpoint->delegations]);
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
        $toolSteps = $this->toolResultsOf($finished, $this->skillTool());
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
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'summarise'])])]);

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
            $this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'one'])]),
            $this->toolTurn([new ToolCall('d2', $this->skillTool(), ['message' => 'two'])]),
            $this->finalTurn('both answered'),
        ]);

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        $results = $this->toolResultsOf($this->runOf($result->runUuid), $this->skillTool());
        self::assertStringContainsString('right away', $results[0]);
        self::assertStringContainsString('done at once', $results[1]);
    }

    /**
     * The message leaves for a third party and the answer comes back from one:
     * both pass the shared secret redactor, so a preview token or a bare token
     * in either does not cross the boundary.
     */
    #[Test]
    public function secretShapesAreMaskedInTheSentMessageAndInTheRemoteAnswer(): void
    {
        $preview = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
        $pat     = 'ghp_abcdefghijklmnopqrstuvwxyz0123456789';
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0]);
        $this->transport->thenJson(['result' => ['message' => ['messageId' => 'r', 'role' => 'ROLE_AGENT', 'parts' => [['text' => "echoing https://site.example/?ADMCMD_prev={$preview} and {$pat}"]]]]]);

        $runtime = $this->runtime([
            $this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => "check https://site.example/?ADMCMD_prev={$preview} using {$pat}"])]),
            $this->finalTurn('answered'),
        ]);

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertCount(1, $this->transport->requests);
        $sent = $this->transport->requests[0]->body;
        self::assertStringContainsString('check https://site.example/', $sent);
        self::assertStringNotContainsString($preview, $sent);
        self::assertStringNotContainsString($pat, $sent);

        $results = $this->toolResultsOf($this->runOf($result->runUuid), $this->skillTool());
        self::assertStringContainsString('echoing https://site.example/', $results[0]);
        self::assertStringNotContainsString($preview, $results[0]);
        self::assertStringNotContainsString($pat, $results[0]);
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
            $this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'one'])]),
            $this->toolTurn([new ToolCall('d2', $this->skillTool(), ['message' => 'two'])]),
            $this->finalTurn('could not'),
        ]);

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertSame(['SendMessage'], $this->methods());
        $results = $this->toolResultsOf($this->runOf($result->runUuid), $this->skillTool());
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
            $this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'slow'])]),
            $this->finalTurn('it timed out'),
        ]);
        $suspended  = $runtime->run($this->request());
        $checkpoint = $this->checkpointOf($this->runOf($suspended->runUuid));
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_CANCELED')]);

        $state = new class implements RunScopedStateInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        $report = $this->poller([$state])->tick($checkpoint->deadline);

        // A tick resumes runs in one process: each resume starts and ends
        // with the run-scoped state reset.
        self::assertSame(2, $state->resets);
        self::assertSame(1, $report->timedOut);
        self::assertSame(['SendMessage', 'CancelTask'], $this->methods());
        $run = $this->runOf($suspended->runUuid);
        self::assertSame(AgentRunStatus::COMPLETED, $run->statusEnum());
        self::assertStringContainsString('did not finish within 60 seconds', $this->toolResultsOf($run, $this->skillTool())[1]);
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
        $runtime   = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
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

    #[Test]
    public function aCancelThatCouldNotBeSentIsJournalledWithoutTheSecretAndAFailedWriteDoesNotThrow(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $runtime   = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $suspended = $runtime->run($this->request());
        $intentUid = $this->checkpointOf($this->runOf($suspended->runUuid))->journalUid;
        $this->transport->then(static fn (): never => throw new RuntimeException('cURL error 7: Failed to connect to https://agent.example/rpc?token=s3cr3t', 3504187261));

        self::assertTrue($runtime->cancel(AiActorContext::backendUser(self::OWNER, true), $suspended->runUuid));
        $run    = $this->runOf($suspended->runUuid);
        $intent = $this->journal->findForRun($run->uid, $intentUid);
        self::assertInstanceOf(A2aSendIntent::class, $intent);
        self::assertStringStartsWith('NOT_SENT: ', $intent->cancelOutcome);
        self::assertStringNotContainsString('s3cr3t', $intent->cancelOutcome);

        // A journal that cannot be written: the cancel is logged, not thrown.
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willThrowException(new RuntimeException('database gone', 3504187262));
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_CANCELED')]);
        (new A2aDependencyCanceller($this->persister, new A2aSendJournal($pool), $this->getService(A2aRemoteAgentRepository::class), $this->client(), $this->vaultScope(), new NullLogger()))
            ->cancelRemote($intent);
        self::assertSame(['SendMessage', 'CancelTask', 'CancelTask'], $this->methods());
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
        $runtime    = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('never reached')]);
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
        $runtime   = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $suspended = $runtime->run($this->request());
        $waiting   = $this->runOf($suspended->runUuid);
        self::assertTrue($this->persister->claimResumeFromDependency($waiting, 'poller:1', time() + 300));

        self::assertFalse($this->canceller()->cancelWaiting($waiting));
        self::assertSame(['SendMessage'], $this->methods());
    }

    // ------------------------------------------------------------------

    /**
     * Spec 060 RQ1, RQ2, RQ4, RQ6, RQ7: a remote question becomes a pause of
     * the run without a model call; the initiator's answer goes out with the
     * bound task and context id, once; the run waits for the task again, and
     * its completion answers exactly the pending call.
     */
    #[Test]
    public function theInitiatorsAnswerGoesToTheSameRemoteTask(): void
    {
        $agent    = $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10, 'max_wait_seconds' => 600]);
        $runtime  = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'summarise the report'])]), $this->finalTurn('the summary is in')]);
        $runUuid  = $this->delegatedAndAsked($runtime, 'Which year should the summary cover?');
        $original = $this->journal->findByMessageId(self::OWNER, $this->sentMessageId(0));
        self::assertInstanceOf(A2aSendIntent::class, $original);
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($runUuid)->statusEnum());
        self::assertCount(1, $this->transcripts, 'the model is not asked');
        $pause = $runtime->pendingPause(AiActorContext::backendUser(self::OWNER), $runUuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        self::assertStringContainsString('Which year should the summary cover?', (string) json_encode($pause->inputSchema));

        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $answered = $this->answer($runtime, $runUuid, 'The year 2025.');

        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $answered->outcome, (string) $answered->error);
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage'], $this->methods());
        self::assertSame(['task-1', 'ctx-1', 'The year 2025.'], $this->continuation(2));
        self::assertSame(1, $this->messagesWithoutTask(), 'one new remote task, the first send');
        self::assertSame('5', $this->sentMessageId(2)[14] ?? '', 'the id is derived from the run and the pause, so a repetition sends nothing');
        $checkpoint = $this->checkpointOf($this->runOf($runUuid));
        self::assertSame([$original->uid, 'task-1', 'd1'], [$checkpoint->journalUid, $checkpoint->remoteTaskId, $checkpoint->toolCallId]);
        self::assertSame(1, $this->journal->runTotals($this->runOf($runUuid)->uid, (int) $agent->getUid())['delegations']);

        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_COMPLETED', 'Three things happened in 2025.')]);
        self::assertSame(1, $this->poller()->tick($checkpoint->suspendedAt + 10)->resumed);

        $finished = $this->runOf($runUuid);
        self::assertSame(AgentRunStatus::COMPLETED, $finished->statusEnum());
        self::assertStringContainsString('Three things happened in 2025.', $this->lastResultOf($finished));
        self::assertSame(['tool', 'd1'], $this->lastToolResultOf($this->transcript(1)));
    }

    /**
     * Spec 060 RQ3: an administrator who did not start the run cannot answer
     * its remote question, neither in the request nor stored for the worker;
     * nothing is sent. The initiator can.
     */
    #[Test]
    public function anotherUserCannotAnswerEvenAsAdministrator(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('done')]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?', AiActorContext::backendUser(2));
        $admin   = AiActorContext::backendUser(self::OWNER, true);
        $pause   = $runtime->pendingPause($admin, $runUuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        $submission = new InputSubmission(['answer' => 'from the admin'], self::OWNER, $pause->turnDigest);

        foreach (['submitInput' => static fn (): AgentRunResult => $runtime->submitInput($admin, $runUuid, $submission), 'storeInput' => static fn (): DecisionReceipt => $runtime->storeInput($admin, $runUuid, $submission, $pause->generation)] as $way => $try) {
            try {
                $try();
                self::fail($way . ' accepted an answer from another user');
            } catch (RunAccessDeniedException) {
            }
        }

        self::assertSame(['SendMessage', 'GetTask'], $this->methods());
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($runUuid)->statusEnum());

        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $this->answer($runtime, $runUuid, 'from the initiator', 2)->outcome);
        self::assertSame(['task-1', 'ctx-1', 'from the initiator'], $this->continuation(2));
    }

    /**
     * Spec 060 RQ2: an answer stored for the worker is sent by a worker that
     * starts later, with nothing in memory, to the bound task.
     */
    #[Test]
    public function aStoredAnswerIsSentByAFreshWorkerToTheBoundTask(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');
        $owner   = AiActorContext::backendUser(self::OWNER);
        $pause   = $runtime->pendingPause($owner, $runUuid);
        self::assertInstanceOf(PendingPause::class, $pause);

        $runtime->storeInput($owner, $runUuid, new InputSubmission(['answer' => 'stored 2025'], self::OWNER, $pause->turnDigest), $pause->generation);
        self::assertSame([AgentRunStatus::QUEUED, 2], [$this->runOf($runUuid)->statusEnum(), count($this->transport->requests)]);

        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $continued = $this->runtime([])->runQueued($runUuid);

        self::assertInstanceOf(AgentRunResult::class, $continued);
        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $continued->outcome, (string) $continued->error);
        self::assertSame(['task-1', 'ctx-1', 'stored 2025'], $this->continuation(2));
        self::assertSame('task-1', $this->checkpointOf($this->runOf($runUuid))->remoteTaskId);
    }

    /**
     * Spec 060 RQ4: the same answer under the same message id is sent once;
     * a repetition reads the task instead. An answer whose outcome is unknown
     * is never sent again.
     */
    #[Test]
    public function aRepeatedExecutionSendsTheAnswerOnce(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime    = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid    = $this->delegatedAndAsked($runtime, 'Which year?');
        $run        = $this->runOf($runUuid);
        $checkpoint = $this->checkpointOf($run);
        $answerer   = new A2aRemoteAnswerer($this->journal, $this->getService(A2aRemoteAgentRepository::class), $this->client());

        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        self::assertInstanceOf(DependencyCheckpoint::class, $answerer->answer($checkpoint, '2025', '01890000-0000-7000-8000-00000000a001', $run->uid));
        self::assertInstanceOf(DependencyCheckpoint::class, $answerer->answer($checkpoint, '2025', '01890000-0000-7000-8000-00000000a001', $run->uid));
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage', 'GetTask'], $this->methods());

        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', 'no answer'));
        $unknown = $answerer->answer($checkpoint, '2026', '01890000-0000-7000-8000-00000000a002', $run->uid);
        $again   = $answerer->answer($checkpoint, '2026', '01890000-0000-7000-8000-00000000a002', $run->uid);
        self::assertInstanceOf(ToolResult::class, $again);
        self::assertStringContainsString('outcome unknown', $again->content);
        self::assertInstanceOf(ToolResult::class, $unknown);
        self::assertCount(5, $this->transport->requests, 'the unknown answer left once');

        // Another text for a question already answered under that id: not
        // sent, not reported as "nothing was sent", no internal id.
        $other = $answerer->answer($checkpoint, '1999', '01890000-0000-7000-8000-00000000a001', $run->uid);
        self::assertInstanceOf(ToolResult::class, $other);
        self::assertStringContainsString('outcome unknown', $other->content);
        self::assertStringNotContainsString('01890000-0000-7000-8000-00000000a001', $other->content);
        self::assertCount(5, $this->transport->requests);
    }

    /**
     * Spec 060 RQ5: a remote that answers about another task is not followed;
     * the run goes on with an error result and nothing is read of that task.
     */
    #[Test]
    public function anAnswerAboutAnotherTaskIsNotFollowed(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('could not')]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');

        $this->transport->thenJson(['result' => ['task' => $this->task('task-9', 'TASK_STATE_COMPLETED', 'not ours')]]);
        $result = $this->answer($runtime, $runUuid, '2025');

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage'], $this->methods());
        $results = $this->toolResultsOf($this->runOf($runUuid), $this->skillTool());
        self::assertStringContainsString('outcome unknown', $results[count($results) - 1]);
        self::assertStringNotContainsString('not ours', implode("\n", $results));
        self::assertSame(A2aSendState::OUTCOME_UNKNOWN, $this->journal->findByMessageId(self::OWNER, $this->sentMessageId(2))?->state);
    }

    /**
     * Spec 060 RQ7: an answer is not a delegation. With a limit of two, a
     * delegation, its answer and a second delegation all go out.
     */
    #[Test]
    public function anAnswerIsNotADelegation(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10, 'max_delegations_per_run' => 2]);
        $runtime = $this->runtime([
            $this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'one'])]),
            $this->toolTurn([new ToolCall('d2', $this->skillTool(), ['message' => 'two'])]),
            $this->finalTurn('both done'),
        ]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_COMPLETED', 'first done')]]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-2', 'TASK_STATE_COMPLETED', 'second done')]]);

        $result = $this->answer($runtime, $runUuid, '2025');

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage', 'SendMessage'], $this->methods());
        $results = $this->toolResultsOf($this->runOf($runUuid), $this->skillTool());
        self::assertStringContainsString('second done', $results[count($results) - 1]);
    }

    /**
     * Spec 060 RQ8: AUTH_REQUIRED is waited out: nothing but GetTask, and at
     * the deadline the timeout says that authentication was asked for.
     */
    #[Test]
    public function authRequiredSendsNothingButGetTask(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10, 'max_wait_seconds' => 60]);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_SUBMITTED')]]);
        $runtime    = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('no luck')]);
        $runUuid    = $runtime->run($this->request())->runUuid;
        $checkpoint = $this->checkpointOf($this->runOf($runUuid));

        $this->transport->thenJson(['result' => $this->asking('task-1', 'Log in at https://agent.example/login first.', 'TASK_STATE_AUTH_REQUIRED')]);
        $report = $this->poller()->tick($checkpoint->suspendedAt + 10);
        self::assertSame([0, 1], [$report->asked, $report->waiting]);
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($runUuid)->statusEnum());

        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_CANCELED')]);
        self::assertSame(1, $this->poller()->tick($checkpoint->deadline)->timedOut);

        self::assertSame(['SendMessage', 'GetTask', 'CancelTask'], $this->methods());
        self::assertStringContainsString('asked for authentication', $this->lastResultOf($this->runOf($runUuid)));
    }

    /**
     * Spec 060 RQ9: a run cancelled while it waits for the answer leaves no
     * open question on the remote side: the next tick cancels the task once.
     */
    #[Test]
    public function cancellingTheQuestionCancelsTheRemoteTask(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');

        self::assertTrue($runtime->cancel(AiActorContext::backendUser(self::OWNER), $runUuid));
        self::assertSame(AgentRunStatus::CANCELLED, $this->runOf($runUuid)->statusEnum());
        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_CANCELED')]);
        $this->poller()->tick(time());
        $this->poller()->tick(time() + 1000);

        self::assertSame(['SendMessage', 'GetTask', 'CancelTask'], $this->methods());
    }

    /**
     * Spec 060 RQ6: the delegation's deadline holds while nobody answers: the
     * run goes on with the timeout result and the remote task is cancelled
     * once.
     */
    #[Test]
    public function anUnansweredQuestionTimesOutAtTheDeadline(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10, 'max_wait_seconds' => 60]);
        $runtime    = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('nobody answered')]);
        $runUuid    = $this->delegatedAndAsked($runtime, 'Which year?');
        $checkpoint = $this->checkpointOf($this->runOf($runUuid));

        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_CANCELED')]);
        self::assertSame(1, $this->poller()->tick($checkpoint->deadline)->timedOut);
        $this->poller()->tick($checkpoint->deadline + 1000);

        self::assertSame(AgentRunStatus::COMPLETED, $this->runOf($runUuid)->statusEnum());
        self::assertSame(['SendMessage', 'GetTask', 'CancelTask'], $this->methods());
        self::assertStringContainsString('did not finish within 60 seconds', $this->lastResultOf($this->runOf($runUuid)));
    }

    /**
     * Spec 060 RQ2, RQ4: a refused answer (another turn's digest) puts the run
     * back into its pause with the binding; two questions in a row get two
     * answers under two message ids, both to the bound task.
     */
    #[Test]
    public function aRefusedAnswerKeepsTheBindingAndTwoQuestionsGetTwoAnswers(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('both answered')]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');

        try {
            $runtime->submitInput(AiActorContext::backendUser(self::OWNER), $runUuid, new InputSubmission(['answer' => '2025'], self::OWNER, 'another-turn'));
            self::fail('a stale digest was accepted');
        } catch (StaleInputTurnException) {
        }

        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($runUuid)->statusEnum());
        self::assertSame('task-1', $this->checkpointOf($this->runOf($runUuid))->remoteTaskId, 'the release kept the binding');

        $this->transport->thenJson(['result' => ['task' => $this->asking('task-1', 'And which month?')]]);
        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $this->answer($runtime, $runUuid, '2025')->outcome);
        $this->transport->thenJson(['result' => $this->asking('task-1', 'And which month?')]);
        self::assertSame(1, $this->poller()->tick($this->checkpointOf($this->runOf($runUuid))->suspendedAt + 10)->asked);
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_COMPLETED', 'March 2025.')]]);
        $result = $this->answer($runtime, $runUuid, 'March');

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage', 'GetTask', 'SendMessage'], $this->methods());
        self::assertSame(['task-1', 'ctx-1', '2025'], $this->continuation(2));
        self::assertSame(['task-1', 'ctx-1', 'March'], $this->continuation(4));
        self::assertNotSame($this->sentMessageId(2), $this->sentMessageId(4));
        self::assertStringContainsString('March 2025.', $this->lastResultOf($this->runOf($runUuid)));
    }

    /**
     * @return list<mixed>
     */
    private function transcript(int $index): array
    {
        return $this->transcripts[$index] ?? [];
    }

    private function lastResultOf(AgentRun $run): string
    {
        $results = $this->toolResultsOf($run, $this->skillTool());

        return $results === [] ? '' : $results[count($results) - 1];
    }

    /**
     * Spec 060 RQ2, RQ3: an answer is bound to the question it was given
     * for. When the run was answered and asked again between the checks and
     * the claim, even with the same question, the late answer is refused and
     * the run waits for the new question unchanged.
     */
    #[Test]
    public function anAnswerToAQuestionThatWasReplacedIsRefused(): void
    {
        $repository      = new FenceRecordingRunRepository(AgentRunRepositoryFactory::create($this->getService(ConnectionPool::class), $this->getService(AgentStateCodec::class)));
        $this->persister = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');
        $owner   = AiActorContext::backendUser(self::OWNER);
        $pause   = $runtime->pendingPause($owner, $runUuid);
        self::assertInstanceOf(PendingPause::class, $pause);

        // Between the checks of the late answer and its claim, the first
        // answer goes out and the remote asks the same question again.
        $repository->beforeClaimFromInput = function () use ($runtime, $runUuid): void {
            $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
            self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $this->answer($runtime, $runUuid, 'the first answer')->outcome);
            $this->transport->thenJson(['result' => $this->asking('task-1', 'Which year?')]);
            self::assertSame(1, $this->poller()->tick($this->checkpointOf($this->runOf($runUuid))->suspendedAt + 10)->asked);
        };

        try {
            $runtime->submitInput($owner, $runUuid, new InputSubmission(['answer' => 'the late answer'], self::OWNER, $pause->turnDigest));
            self::fail('an answer to a replaced question was accepted');
        } catch (StaleInputTurnException) {
        }

        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($runUuid)->statusEnum());
        self::assertSame('task-1', $this->checkpointOf($this->runOf($runUuid))->remoteTaskId);
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage', 'GetTask'], $this->methods());
        self::assertSame(['task-1', 'ctx-1', 'the first answer'], $this->continuation(2));
    }

    /**
     * Spec 060 RQ2: the answer goes only to the endpoint that asked. An agent
     * record repointed to another interface after the question gets no
     * answer: nothing is sent, and the run goes on with an error result.
     */
    #[Test]
    public function anAnswerIsNotSentToAnEndpointOtherThanTheOneThatAsked(): void
    {
        $agent   = $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('could not answer')]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent')->update(
            'tx_nrllm_a2a_agent',
            ['interface_url' => 'https://other.example/a2a/jsonrpc', 'confirmed_interface' => 'https://other.example/a2a/jsonrpc'],
            ['uid' => (int) $agent->getUid()],
        );

        $result = $this->answer($runtime, $runUuid, 'my private answer');

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertSame(['SendMessage', 'GetTask'], $this->methods());
        self::assertStringNotContainsString('my private answer', implode("\n", array_map(static fn (A2aHttpRequest $request): string => $request->body, $this->transport->requests)));
        self::assertStringContainsString('the remote agent record points elsewhere', $this->lastResultOf($this->runOf($runUuid)));
    }

    /**
     * Spec 060 RQ6: the deadline of a question holds however many older
     * pauses the installation has; a tick that pages one run at a time still
     * reaches it.
     */
    #[Test]
    public function aQuestionBehindOlderPausesStillTimesOut(): void
    {
        $this->agent(['identifier' => 'remote', 'min_poll_interval_seconds' => 10, 'max_wait_seconds' => 60]);
        $older = $this->runtime([$this->toolTurn([new ToolCall('d0', $this->skillTool(), ['message' => 'waits for approval'])])])->run($this->request());
        self::assertSame(AgentRunOutcome::AWAITING_APPROVAL, $older->outcome);
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent')->update('tx_nrllm_a2a_agent', ['requires_approval' => 0], ['identifier' => 'remote']);
        $runtime    = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('nobody answered')]);
        $runUuid    = $this->delegatedAndAsked($runtime, 'Which year?');
        $checkpoint = $this->checkpointOf($this->runOf($runUuid));

        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_CANCELED')]);
        self::assertSame(1, $this->poller()->tick($checkpoint->deadline, 1)->timedOut);
        self::assertSame(AgentRunStatus::COMPLETED, $this->runOf($runUuid)->statusEnum());
    }

    /**
     * Spec 060 RQ2: a refused answer whose run cannot be read again is not
     * put back as an ordinary input pause, whose next answer would run the
     * delegation again: the run fails, and nothing more is sent.
     */
    #[Test]
    public function aReleaseThatCannotReadTheRunFailsClosed(): void
    {
        $repository      = new FenceRecordingRunRepository(AgentRunRepositoryFactory::create($this->getService(ConnectionPool::class), $this->getService(AgentStateCodec::class)));
        $this->persister = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');

        // The third read of the submission is the release's own: it fails.
        $reads                        = 0;
        $repository->beforeFindByUuid = static function () use (&$reads): void {
            if (++$reads === 3) {
                throw new RuntimeException('database gone for a moment', 1799431201);
            }
        };

        try {
            $runtime->submitInput(AiActorContext::backendUser(self::OWNER), $runUuid, new InputSubmission(['answer' => '2025'], self::OWNER, 'another-turn'));
            self::fail('a stale digest was accepted');
        } catch (StaleInputTurnException) {
        }

        $repository->beforeFindByUuid = null;
        self::assertSame(AgentRunStatus::FAILED, $this->runOf($runUuid)->statusEnum());
        self::assertSame(['SendMessage', 'GetTask'], $this->methods());
    }

    /**
     * Spec 060 RQ1, RQ2: a form left open for a question that was answered
     * does not answer the next one, even when the remote asks the same thing
     * again; an answer of whitespace only is refused before anything runs.
     */
    #[Test]
    public function anOldFormDoesNotAnswerARepeatedQuestionAndBlankAnswersAreRefused(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');
        $owner   = AiActorContext::backendUser(self::OWNER);
        $first   = $runtime->pendingPause($owner, $runUuid);
        self::assertInstanceOf(PendingPause::class, $first);

        try {
            $runtime->submitInput($owner, $runUuid, new InputSubmission(['answer' => " \n\t "], self::OWNER, $first->turnDigest));
            self::fail('a blank answer was accepted');
        } catch (InvalidInputSubmissionException) {
        }

        try {
            $runtime->submitInput($owner, $runUuid, new InputSubmission(['answer' => "\u{00A0}\u{3000}"], self::OWNER, $first->turnDigest));
            self::fail('an answer of non-breaking and ideographic spaces was accepted');
        } catch (InvalidInputSubmissionException) {
        }

        $this->transport->thenJson(['result' => ['task' => $this->asking('task-1', 'Which year?')]]);
        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $this->answer($runtime, $runUuid, 'the first answer')->outcome);
        $this->transport->thenJson(['result' => $this->asking('task-1', 'Which year?')]);
        self::assertSame(1, $this->poller()->tick($this->checkpointOf($this->runOf($runUuid))->suspendedAt + 10)->asked);

        try {
            $runtime->submitInput($owner, $runUuid, new InputSubmission(['answer' => 'from the old form'], self::OWNER, $first->turnDigest));
            self::fail('an answer from the old form was accepted');
        } catch (StaleInputTurnException) {
        }

        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($runUuid)->statusEnum());
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage', 'GetTask'], $this->methods());
    }

    /**
     * Spec 060 RQ4: a stored answer executed again after it went out (the run
     * row set back to its queued state) sends nothing twice. The replay is
     * refused as stale and the run is back at the question; the same answer
     * given again gets the existing intent back, under the same id. In
     * production the reaper fails such a run instead; this pins the id.
     */
    #[Test]
    public function aReplayOfAStoredAnswerDoesNotSendItTwice(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');
        $owner   = AiActorContext::backendUser(self::OWNER);
        $pause   = $runtime->pendingPause($owner, $runUuid);
        self::assertInstanceOf(PendingPause::class, $pause);
        $runtime->storeInput($owner, $runUuid, new InputSubmission(['answer' => '2025'], self::OWNER, $pause->turnDigest), $pause->generation);
        $runs   = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_agentrun');
        $queued = $runs->select(['*'], 'tx_nrllm_agentrun', ['uuid' => $runUuid])->fetchAssociative();
        self::assertIsArray($queued);

        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        $this->runtime([])->runQueued($runUuid);
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage'], $this->methods());

        // The answer left; the run's next state is taken back.
        $runs->update('tx_nrllm_agentrun', $queued, ['uuid' => $runUuid]);
        $this->runtime([])->runQueued($runUuid);
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($runUuid)->statusEnum());

        $this->transport->thenJson(['result' => $this->task('task-1', 'TASK_STATE_WORKING')]);
        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $this->answer($runtime, $runUuid, '2025')->outcome);
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage', 'GetTask'], $this->methods(), 'the existing intent was read, not sent again');
    }

    /**
     * Spec 060 RQ5: a message reply that names another task than the one the
     * answer continues is not taken as the answer.
     */
    #[Test]
    public function aMessageReplyAboutAnotherTaskIsNotFollowed(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])]), $this->finalTurn('could not')]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');

        $this->transport->thenJson(['result' => ['message' => ['messageId' => 'm-9', 'role' => 'ROLE_AGENT', 'taskId' => 'task-9', 'contextId' => 'ctx-1', 'parts' => [['text' => 'not ours']]]]]);
        $result = $this->answer($runtime, $runUuid, '2025');

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, (string) $result->error);
        self::assertStringNotContainsString('not ours', $this->lastResultOf($this->runOf($runUuid)));
        self::assertStringContainsString('outcome unknown', $this->lastResultOf($this->runOf($runUuid)));
    }

    /**
     * Spec 060 RQ4: when the answer was accepted but the task cannot be read
     * right after it, the run keeps waiting on that task instead of ending
     * the delegation with an error.
     */
    #[Test]
    public function anAcceptedAnswerWhoseTaskCannotBeReadKeepsTheWait(): void
    {
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime    = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid    = $this->delegatedAndAsked($runtime, 'Which year?');
        $run        = $this->runOf($runUuid);
        $checkpoint = $this->checkpointOf($run);
        $answerer   = new A2aRemoteAnswerer($this->journal, $this->getService(A2aRemoteAgentRepository::class), $this->client());

        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        self::assertInstanceOf(DependencyCheckpoint::class, $answerer->answer($checkpoint, '2025', '01890000-0000-7000-8000-00000000b001', $run->uid));
        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', 'no answer'));

        $again = $answerer->answer($checkpoint, '2025', '01890000-0000-7000-8000-00000000b001', $run->uid);

        self::assertInstanceOf(DependencyCheckpoint::class, $again);
        self::assertSame('task-1', $again->remoteTaskId);
    }

    /**
     * Spec 060 RQ6: a deadline read for one question does not expire the
     * next one. When the question was answered and asked again between the
     * poller's read and its claim, the new question keeps waiting and its
     * remote task is not cancelled.
     */
    #[Test]
    public function aDeadlineDoesNotExpireTheQuestionThatReplacedIt(): void
    {
        $repository      = new FenceRecordingRunRepository(AgentRunRepositoryFactory::create($this->getService(ConnectionPool::class), $this->getService(AgentStateCodec::class)));
        $this->persister = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10, 'max_wait_seconds' => 60]);
        $runtime  = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid  = $this->delegatedAndAsked($runtime, 'Which year?');
        $deadline = $this->checkpointOf($this->runOf($runUuid))->deadline;

        $repository->beforeClaimFromInput = function () use ($runtime, $runUuid): void {
            $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
            self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $this->answer($runtime, $runUuid, 'the first answer')->outcome);
            $this->transport->thenJson(['result' => $this->asking('task-1', 'Which month?')]);
            self::assertSame(1, $this->poller()->tick($this->checkpointOf($this->runOf($runUuid))->suspendedAt + 10)->asked);
        };

        self::assertSame(0, $this->poller()->tick($deadline)->timedOut);

        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $this->runOf($runUuid)->statusEnum());
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage', 'GetTask'], $this->methods(), 'no CancelTask');
    }

    /**
     * Spec 060 RQ6: a deadline claim whose own read of the run fails does not
     * fall through to a later read that would accept the question that
     * replaced the expired one: nothing is cancelled and no timeout is given.
     */
    #[Test]
    public function aFailedReadAfterTheDeadlineClaimDoesNotExpireTheReplacement(): void
    {
        $repository      = new FenceRecordingRunRepository(AgentRunRepositoryFactory::create($this->getService(ConnectionPool::class), $this->getService(AgentStateCodec::class)));
        $this->persister = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10, 'max_wait_seconds' => 60]);
        $runtime  = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid  = $this->delegatedAndAsked($runtime, 'Which year?');
        $deadline = $this->checkpointOf($this->runOf($runUuid))->deadline;

        $repository->beforeClaimFromInput = function () use ($runtime, $runUuid, $repository): void {
            $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
            self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $this->answer($runtime, $runUuid, 'the first answer')->outcome);
            $this->transport->thenJson(['result' => $this->asking('task-1', 'Which month?')]);
            self::assertSame(1, $this->poller()->tick($this->checkpointOf($this->runOf($runUuid))->suspendedAt + 10)->asked);

            // The deadline claim's own read of the run fails, once.
            $repository->beforeFindByUuid = static function () use ($repository): never {
                $repository->beforeFindByUuid = null;

                throw new RuntimeException('database gone for a moment', 1799431202);
            };
        };

        self::assertSame(0, $this->poller()->tick($deadline)->timedOut);

        self::assertNotSame(AgentRunStatus::COMPLETED, $this->runOf($runUuid)->statusEnum());
        self::assertSame(['SendMessage', 'GetTask', 'SendMessage', 'GetTask'], $this->methods(), 'no CancelTask');
    }

    /**
     * Spec 060 RQ1: an INPUT_REQUIRED read before the question was answered
     * does not ask the initiator again once the run waits on the remote
     * after the answer.
     */
    #[Test]
    public function aStaleQuestionIsNotAskedAgainAfterItWasAnswered(): void
    {
        $repository      = new FenceRecordingRunRepository(AgentRunRepositoryFactory::create($this->getService(ConnectionPool::class), $this->getService(AgentStateCodec::class)));
        $this->persister = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
        $this->agent(['identifier' => 'remote', 'requires_approval' => 0, 'min_poll_interval_seconds' => 10]);
        $runtime = $this->runtime([$this->toolTurn([new ToolCall('d1', $this->skillTool(), ['message' => 'x'])])]);
        $runUuid = $this->delegatedAndAsked($runtime, 'Which year?');
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $this->answer($runtime, $runUuid, '2025')->outcome);
        $at = $this->checkpointOf($this->runOf($runUuid))->suspendedAt + 10;

        // The outer poll reads "Which month?"; before it claims, another poll
        // asked the same, and the initiator answered it.
        $this->transport->thenJson(['result' => $this->asking('task-1', 'Which month?')]);
        $repository->beforeClaimFromDependency = function () use ($runtime, $runUuid, $at): void {
            $this->transport->thenJson(['result' => $this->asking('task-1', 'Which month?')]);
            self::assertSame(1, $this->poller()->tick($at)->asked);
            $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_WORKING')]]);
            self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $this->answer($runtime, $runUuid, 'May')->outcome);
        };

        self::assertSame(0, $this->poller()->tick($at)->asked);
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $this->runOf($runUuid)->statusEnum());
    }

    /**
     * Sends the delegation (the remote accepts task-1), then lets the poller
     * see the remote's question.
     */
    private function delegatedAndAsked(AgentRuntime $runtime, string $question, ?AiActorContext $actor = null): string
    {
        $this->transport->thenJson(['result' => ['task' => $this->task('task-1', 'TASK_STATE_SUBMITTED')]]);
        $suspended = $runtime->run($this->request($actor));
        self::assertSame(AgentRunOutcome::AWAITING_DEPENDENCY, $suspended->outcome, (string) $suspended->error);
        $this->transport->thenJson(['result' => $this->asking('task-1', $question)]);
        self::assertSame(1, $this->poller()->tick($this->checkpointOf($this->runOf($suspended->runUuid))->suspendedAt + 10)->asked);

        return $suspended->runUuid;
    }

    private function answer(AgentRuntime $runtime, string $runUuid, string $text, int $by = self::OWNER): AgentRunResult
    {
        $actor = AiActorContext::backendUser($by);
        $pause = $runtime->pendingPause($actor, $runUuid);
        self::assertInstanceOf(PendingPause::class, $pause);

        return $runtime->submitInput($actor, $runUuid, new InputSubmission(['answer' => $text], $by, $pause->turnDigest));
    }

    /**
     * @return array<string, mixed>
     */
    private function asking(string $id, string $question, string $state = 'TASK_STATE_INPUT_REQUIRED'): array
    {
        return ['id' => $id, 'contextId' => 'ctx-1', 'status' => ['state' => $state, 'message' => ['messageId' => 'q-' . hash('sha256', $question), 'role' => 'ROLE_AGENT', 'parts' => [['text' => $question]]]]];
    }

    /**
     * Task id, context id and text of the message request $index sent.
     *
     * @return array{string, string, string}
     */
    private function continuation(int $index): array
    {
        $params  = ScriptedA2aTransport::envelopeOf($this->transport->requests[$index])['params'] ?? null;
        $message = is_array($params) ? ($params['message'] ?? null) : null;
        self::assertIsArray($message);
        $parts = is_array($message['parts'] ?? null) ? $message['parts'] : [];
        $first = is_array($parts[0] ?? null) ? $parts[0] : [];

        return [self::text($message['taskId'] ?? null), self::text($message['contextId'] ?? null), self::text($first['text'] ?? null)];
    }

    /**
     * How many SendMessage requests named no task: each one may create a task.
     */
    private function messagesWithoutTask(): int
    {
        $count = 0;
        foreach ($this->transport->requests as $request) {
            $envelope = ScriptedA2aTransport::envelopeOf($request);
            $message  = is_array($envelope['params'] ?? null) ? ($envelope['params']['message'] ?? null) : null;
            if (($envelope['method'] ?? null) === 'SendMessage' && is_array($message) && ($message['taskId'] ?? '') === '') {
                ++$count;
            }
        }

        return $count;
    }

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
        $executor   = new AgentRunExecutor($this->loop, $this->persister, new NullLogger(), new ActingBackendUserResolver(), new ToolEffectResolver($registry));

        return new AgentRuntime(
            $this->loop,
            $this->persister,
            $this->configurations(),
            new NullLogger(),
            $bus,
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($registry),
            resumeCoordinator: new ResumeCoordinator(
                $this->persister,
                $this->configurations(),
                $this->loop,
                $executor,
                toolEffectResolver: new ToolEffectResolver($registry),
                toolPolicy: $policy,
                actingBackendUserResolver: new ActingBackendUserResolver(),
                remoteQuestions: new A2aRemoteAnswerer($this->journal, $this->getService(A2aRemoteAgentRepository::class), $this->client()),
            ),
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

    /**
     * @param list<RunScopedStateInterface> $runScopedStates
     */
    private function poller(array $runScopedStates = []): A2aDependencyPoller
    {
        return new A2aDependencyPoller(
            $this->coordinator(),
            $this->journal,
            $this->getService(A2aRemoteAgentRepository::class),
            $this->client(),
            $this->canceller(),
            $this->vaultScope(),
            jitter: static fn (int $interval): int => 0,
            runScopedStates: $runScopedStates,
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
        $policy->method('filterOfferable')->willReturnCallback(fn (?array $names): array => array_values($names ?? ['record_actor', $this->skillTool(), $this->skillTool('translate')]));
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
     * The tool name of a skill of the agent `remote`.
     */
    private function skillTool(string $skill = 'summarise'): string
    {
        $uid = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent')->select(['uid'], 'tx_nrllm_a2a_agent', ['identifier' => 'remote'])->fetchOne();

        return A2aDelegationTool::nameFor(is_numeric($uid) ? (int) $uid : 0, 'remote', $skill);
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
