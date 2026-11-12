<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Tests\Fixture\AgentRunRepositoryFactory;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The run-row transitions of the dependency wait (spec 051 A2A-R1, A2A-R2,
 * A2A-R3, A2A-R6, A2A-R12; ADR-281).
 */
#[CoversClass(AgentRunPersister::class)]
#[CoversClass(AgentRunRepository::class)]
final class A2aDependencyStateTest extends AbstractFunctionalTestCase
{
    private AgentRunRepository $repository;

    private AgentRunPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = AgentRunRepositoryFactory::create(
            $this->get(ConnectionPool::class),
            $this->get(AgentStateCodec::class),
        );
        $this->persister = new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
    }

    /**
     * A2A-R1: the suspended run holds no lease and no write fence, and its
     * checkpoint is sealed at rest beside the transcript (A2A-R3). The queued
     * request is gone, so an abandoned resume is never re-executed from the
     * start (A2A-C6).
     */
    #[Test]
    public function aSuspendedRunWaitsWithoutLeaseOrFenceAndItsCheckpointIsSealed(): void
    {
        $handle = $this->leasedRun(4);
        self::assertTrue($this->persister->markPendingEffect($handle, 'worker-a:1', 'non_idempotent_write', time() + 999));

        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_agentrun')
            ->update('tx_nrllm_agentrun', ['queued_request' => '{"queued":true}'], ['uid' => $handle->runUid]);

        self::assertTrue($this->persister->suspendForDependency($handle, $this->state(), $this->checkpoint('remote-task-secret')));

        $run = $this->runOf($handle);
        self::assertSame(AgentRunStatus::WAITING_FOR_DEPENDENCY, $run->statusEnum());
        self::assertSame(['', 0, '', ''], [$this->raw($handle, 'claimed_by'), (int) $this->raw($handle, 'lease_expires'), $this->raw($handle, 'pending_effect'), $this->raw($handle, 'queued_request')]);
        self::assertStringNotContainsString('remote-task-secret', $this->raw($handle, 'suspended_state'));

        $stored = json_decode((string) $run->suspendedState, true);
        self::assertIsArray($stored);
        self::assertEquals($this->checkpoint('remote-task-secret'), DependencyCheckpoint::fromStoredState($stored));
        self::assertSame(['call_1'], array_column(SuspendedRunState::fromArray($stored)->pendingCalls, 'id'));
    }

    /**
     * A2A-R2: the inbox lists decisions only; the run list's own query and the
     * scoped view see the waiting run; the reaper never does.
     */
    #[Test]
    public function aWaitingRunIsListedAsWaitingButNeitherAsADecisionNorAsStale(): void
    {
        $mine = $this->leasedRun(4);
        self::assertTrue($this->persister->suspendForDependency($mine, $this->state(), $this->checkpoint('t-1')));
        $theirs = $this->leasedRun(5);
        self::assertTrue($this->persister->suspendForDependency($theirs, $this->state(), $this->checkpoint('t-2')));
        // A lease value left behind must not make the reaper look at it.
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_agentrun')
            ->update('tx_nrllm_agentrun', ['lease_expires' => 1], ['uid' => $mine->runUid]);

        self::assertSame([], $this->persister->findAwaitingRuns());
        self::assertSame([$mine->uuid, $theirs->uuid], $this->uuids($this->persister->findWaitingForDependencyRuns() ?? []));
        self::assertSame([$mine->uuid], $this->uuids($this->persister->findWaitingForDependencyRuns(beUser: 4) ?? []));
        self::assertSame([], $this->repository->findStaleRunning(time()));
    }

    /**
     * A2A-R6: of two resumes of the same dependency exactly one claims it, and
     * the winner holds the claim and a lease.
     */
    #[Test]
    public function theResumeClaimIsWonExactlyOnceAndCarriesALease(): void
    {
        $handle = $this->leasedRun(4);
        self::assertTrue($this->persister->suspendForDependency($handle, $this->state(), $this->checkpoint('t-1')));
        $run = $this->runOf($handle);

        self::assertTrue($this->persister->claimResumeFromDependency($run, 'poller-a:1', time() + 300));
        self::assertFalse($this->persister->claimResumeFromDependency($run, 'poller-b:2', time() + 300));

        self::assertSame(AgentRunStatus::RUNNING, $this->runOf($handle)->statusEnum());
        self::assertSame('poller-a:1', $this->raw($handle, 'claimed_by'));
        self::assertGreaterThan(time(), (int) $this->raw($handle, 'lease_expires'));
    }

    /**
     * A2A-R12: the cancel of a waiting run is a guarded transition, so a cancel
     * and a resume claim cannot both win; a suspension that arrives after the
     * cancel is discarded.
     */
    #[Test]
    public function aCancelAndAResumeClaimCannotBothWin(): void
    {
        $claimedFirst = $this->leasedRun(4);
        self::assertTrue($this->persister->suspendForDependency($claimedFirst, $this->state(), $this->checkpoint('t-1')));
        self::assertTrue($this->persister->claimResumeFromDependency($this->runOf($claimedFirst), 'poller-a:1', time() + 300));
        self::assertFalse($this->persister->cancelWaitingForDependency($this->runOf($claimedFirst)));
        self::assertSame(AgentRunStatus::RUNNING, $this->runOf($claimedFirst)->statusEnum());

        $cancelledFirst = $this->leasedRun(4);
        self::assertTrue($this->persister->suspendForDependency($cancelledFirst, $this->state(), $this->checkpoint('t-2')));
        self::assertTrue($this->persister->cancelWaitingForDependency($this->runOf($cancelledFirst)));
        self::assertFalse($this->persister->claimResumeFromDependency($this->runOf($cancelledFirst), 'poller-a:1', time() + 300));
        $cancelled = $this->runOf($cancelledFirst);
        self::assertSame(AgentRunStatus::CANCELLED, $cancelled->statusEnum());
        self::assertSame('cancelled', $cancelled->terminationReason);
        self::assertNull($cancelled->suspendedState);
        self::assertFalse($this->persister->suspendForDependency($cancelledFirst, $this->state(), $this->checkpoint('t-3')));
    }

    private function leasedRun(int $beUser): AgentRunHandle
    {
        $handle = $this->persister->begin(null, $beUser, 'worker-a:1', time() + 300);
        self::assertInstanceOf(AgentRunHandle::class, $handle);

        return $handle;
    }

    private function state(): SuspendedRunState
    {
        return new SuspendedRunState(
            [['role' => 'user', 'content' => 'ask the other agent']],
            [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'a2a_agent_skill', 'arguments' => '{}']]],
            1,
            5,
            2,
        );
    }

    private function checkpoint(string $remoteTaskId): DependencyCheckpoint
    {
        return new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, $remoteTaskId, 4, 0, 1000, 4600, 1, 512);
    }

    private function runOf(AgentRunHandle $handle): AgentRun
    {
        $run = $this->repository->findByUuid($handle->uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    private function raw(AgentRunHandle $handle, string $column): string
    {
        $value = $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_agentrun')
            ->select([$column], 'tx_nrllm_agentrun', ['uid' => $handle->runUid])
            ->fetchOne();

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param list<AgentRun> $runs
     *
     * @return list<string>
     */
    private function uuids(array $runs): array
    {
        return array_map(static fn (AgentRun $run): string => $run->uuid, $runs);
    }
}
