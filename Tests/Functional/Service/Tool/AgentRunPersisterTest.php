<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * End-to-end round-trip of the agent-run persistence layer against the real
 * schema: the persister writes through the raw-SQL repository, and the same
 * repository reads the run and its ordered event stream back.
 *
 * The repository is instantiated directly with the real ConnectionPool (as the
 * telemetry repository's tests do) — both are private DI services.
 */
#[CoversClass(AgentRunPersister::class)]
#[CoversClass(AgentRunRepository::class)]
final class AgentRunPersisterTest extends AbstractFunctionalTestCase
{
    private AgentRunRepository $repository;

    private AgentRunPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $this->repository = new AgentRunRepository($connectionPool);
        $this->persister  = new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
    }

    #[Test]
    public function suspendPersistsWaitingStateAndResumeContinuesThenSettleClearsIt(): void
    {
        $handle = $this->persister->begin(null, 0);
        self::assertNotNull($handle);
        $this->persister->recordStep($handle, new RunStep(kind: RunStep::KIND_REQUEST, round: 1, durationMs: 0.0));
        $this->persister->recordStep($handle, new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 1.0, content: 'x'));

        $state = new SuspendedRunState(
            [['role' => 'user', 'content' => 'delete it']],
            [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{}']]],
            1,
            5,
            2,
        );
        $this->persister->suspend($handle, $state);

        // The run is now WAITING_FOR_APPROVAL with its state persisted.
        $run = $this->repository->findByUuid($handle->uuid);
        self::assertNotNull($run);
        self::assertSame('waiting_for_approval', $run->status);
        self::assertNotNull($run->suspendedState);
        $decoded = json_decode($run->suspendedState, true);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['iterations']);

        // resumeHandle continues the event stream after the two recorded events.
        $resumed = $this->persister->resumeHandle($run);
        self::assertSame($handle->runUid, $resumed->runUid);
        self::assertSame(2, $resumed->sequence);

        // Settling a resumed run clears the suspended state.
        $this->persister->settleCompleted($resumed, new ToolLoopResult('done', [], 2, false, UsageStatistics::fromTokens(8, 6)));
        $settled = $this->repository->findByUuid($handle->uuid);
        self::assertNotNull($settled);
        self::assertSame('completed', $settled->status);
        self::assertNull($settled->suspendedState);
    }

    #[Test]
    public function claimForResumeIsAtomicSoOnlyTheFirstConcurrentApprovalWins(): void
    {
        $handle = $this->persister->begin(null, 0);
        self::assertNotNull($handle);
        $state = new SuspendedRunState(
            [['role' => 'user', 'content' => 'delete it']],
            [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{}']]],
            1,
            5,
            2,
        );
        $this->persister->suspend($handle, $state);

        $run = $this->repository->findByUuid($handle->uuid);
        self::assertNotNull($run);

        // First claim wins (WAITING_FOR_APPROVAL -> RUNNING).
        self::assertTrue($this->persister->claimResume($run));
        $afterFirst = $this->repository->findByUuid($handle->uuid);
        self::assertNotNull($afterFirst);
        self::assertSame('running', $afterFirst->status);

        // A second, concurrent/duplicate approval on the same run loses — the
        // gated (destructive) tool cannot be double-executed.
        self::assertFalse($this->persister->claimResume($run));
    }

    #[Test]
    public function aCompletedRunPersistsItsSummaryAndOrderedEventStream(): void
    {
        $handle = $this->persister->begin(null, 5);
        self::assertNotNull($handle);

        // While running, the row exists and is in the RUNNING state.
        $running = $this->repository->findByUuid($handle->uuid);
        self::assertNotNull($running);
        self::assertSame('running', $running->status);
        self::assertSame(5, $running->beUser);
        self::assertSame(0, $running->finishedAt);

        $this->persister->recordStep($handle, new RunStep(kind: RunStep::KIND_REQUEST, round: 1, durationMs: 0.0, messagesSent: [['role' => 'user', 'content' => 'hi']], toolSpecs: ['fetch_logs']));
        $this->persister->recordStep($handle, new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 12.5, content: 'answer', promptTokens: 7, completionTokens: 3, totalTokens: 10));
        $this->persister->recordStep($handle, new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 5.0, toolName: 'fetch_logs', toolArguments: ['limit' => 5], toolResult: 'ok', toolIsError: false));

        $this->persister->settleCompleted($handle, new ToolLoopResult('answer', [], 2, false, UsageStatistics::fromTokens(10, 20, 0.05)));

        $run = $this->repository->findByUuid($handle->uuid);
        self::assertNotNull($run);
        self::assertSame('completed', $run->status);
        self::assertSame(2, $run->iterations);
        self::assertFalse($run->truncated);
        self::assertSame(10, $run->totalPromptTokens);
        self::assertSame(20, $run->totalCompletionTokens);
        self::assertSame(30, $run->totalTokens);
        self::assertEqualsWithDelta(0.05, $run->estimatedCost, 0.0001);
        self::assertGreaterThan(0, $run->finishedAt);

        $events = $this->repository->findEvents($handle->runUid);
        self::assertCount(3, $events);
        self::assertSame([0, 1, 2], array_map(static fn($e): int => $e->sequence, $events));
        self::assertSame(['request', 'llm', 'tool'], array_map(static fn($e): string => $e->kind, $events));
        // The full RunStep snapshot survives the round-trip.
        self::assertSame('answer', $events[1]->payload['content'] ?? null);
        self::assertSame('fetch_logs', $events[2]->payload['toolName'] ?? null);
        self::assertSame(['limit' => 5], $events[2]->payload['toolArguments'] ?? null);
    }

    #[Test]
    public function aFailedRunRecordsTheFailedStatusWithItsExceptionClassAndPartialEvents(): void
    {
        $handle = $this->persister->begin(null, 0);
        self::assertNotNull($handle);

        $this->persister->recordStep($handle, new RunStep(kind: RunStep::KIND_REQUEST, round: 1, durationMs: 0.0, messagesSent: [['role' => 'user', 'content' => 'hi']]));
        $this->persister->settleFailed($handle, new RuntimeException('provider exhausted'));

        $run = $this->repository->findByUuid($handle->uuid);
        self::assertNotNull($run);
        self::assertSame('failed', $run->status);
        self::assertSame(RuntimeException::class, $run->errorClass);
        // The partial event recorded before the failure is retained.
        self::assertCount(1, $this->repository->findEvents($handle->runUid));
    }

    #[Test]
    public function purgeOlderThanRemovesRunsAndTheirEvents(): void
    {
        $handle = $this->persister->begin(null, 0);
        self::assertNotNull($handle);
        $this->persister->recordStep($handle, new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 1.0, content: 'x'));
        $this->persister->settleCompleted($handle, new ToolLoopResult('x', [], 1, false, UsageStatistics::fromTokens(0, 0)));

        // Purge everything created up to now.
        $deleted = $this->repository->purgeOlderThan(time() + 1);

        self::assertGreaterThanOrEqual(1, $deleted);
        self::assertNull($this->repository->findByUuid($handle->uuid));
        self::assertSame([], $this->repository->findEvents($handle->runUid));
    }
}
