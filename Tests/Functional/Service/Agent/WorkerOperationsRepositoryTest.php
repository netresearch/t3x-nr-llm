<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsRepository;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(WorkerOperationsRepository::class)]
#[CoversClass(AgentRunRepository::class)]
final class WorkerOperationsRepositoryTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function aggregatesDistinguishUnknownFutureAndMeasuredZero(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $connection = $pool->getConnectionForTable('tx_nrllm_agentrun');
        foreach ([
            ['status' => 'queued', 'queued_at' => 1000],
            ['status' => 'queued', 'queued_at' => 900],
            ['status' => 'queued', 'queued_at' => 0],
            ['status' => 'queued', 'queued_at' => 1001],
            ['status' => 'running', 'lease_expires' => 999],
            ['status' => 'running', 'lease_expires' => 1000],
            ['status' => 'running', 'lease_expires' => 0],
            ['status' => 'failed', 'termination_reason' => 'retries_exhausted'],
            ['status' => 'failed', 'termination_reason' => 'not_retryable'],
            ['status' => 'failed', 'termination_reason' => 'provider_failed'],
        ] as $row) {
            $connection->insert('tx_nrllm_agentrun', $row);
        }

        $repository = new WorkerOperationsRepository($pool);
        $repository->touch('worker-a', 'doctrine', 1000);
        $repository->touch('worker-a', 'doctrine', 1000);
        $repository->touch('worker-b', 'doctrine', 881);
        $repository->touch('worker-c', 'doctrine', 879);
        $repository->touch('other', 'another', 1000);

        $snapshot = $repository->snapshot(1000, 120, 'doctrine');
        self::assertSame(
            [
                'queued' => 4,
                'running' => 3,
                'dead_lettered' => 2,
                'expired_leases' => 1,
                'oldest_queue_wait_seconds' => 100,
                'unknown_queue_wait' => 2,
                'live_workers' => 2,
            ],
            $snapshot->toArray(),
        );
        $connection->delete('tx_nrllm_agentrun', ['status' => 'queued']);
        self::assertNull($repository->snapshot(1000)->oldestQueueWaitSeconds);
        $connection->insert(
            'tx_nrllm_agentrun',
            ['status' => 'queued', 'queued_at' => 1000],
        );
        self::assertSame(0, $repository->snapshot(1000)->oldestQueueWaitSeconds);
        $repository->remove('worker-a');
        self::assertSame(1, $repository->snapshot(1000)->liveWorkers);
        $repository->prune(882);
        self::assertSame(0, $repository->snapshot(1000)->liveWorkers);
    }

    #[Test]
    public function enqueueAndBothRequeuesStampOnlySuccessfulTransitions(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $repository = new AgentRunRepository($pool, $this->get(AgentStateCodec::class));
        $connection = $pool->getConnectionForTable('tx_nrllm_agentrun');
        $uid = $repository->enqueueRun('ops-run', 0, 'ops', 0, '{}');
        self::assertGreaterThanOrEqual(
            time() - 2,
            $this->queuedAt($connection, $uid),
        );
        self::assertTrue($repository->claimQueued($uid, 'owner', time() + 60));
        $connection->update(
            'tx_nrllm_agentrun',
            ['queued_at' => 123],
            ['uid' => $uid],
        );
        self::assertFalse($repository->requeue($uid, 'wrong-owner'));
        self::assertSame(123, $this->queuedAt($connection, $uid));
        self::assertTrue($repository->requeue($uid, 'owner'));
        self::assertGreaterThanOrEqual(
            time() - 2,
            $this->queuedAt($connection, $uid),
        );
        self::assertTrue($repository->claimQueued($uid, 'owner', time() - 1));
        $connection->update(
            'tx_nrllm_agentrun',
            ['queued_at' => 456],
            ['uid' => $uid],
        );
        self::assertTrue($repository->requeueStale($uid, time()));
        self::assertGreaterThanOrEqual(
            time() - 2,
            $this->queuedAt($connection, $uid),
        );
        $connection->update(
            'tx_nrllm_agentrun',
            ['queued_at' => 789],
            ['uid' => $uid],
        );
        self::assertFalse($repository->requeueStale($uid, time()));
        self::assertSame(789, $this->queuedAt($connection, $uid));
    }

    private function queuedAt(Connection $connection, int $uid): int
    {
        $value = $connection
            ->select(['queued_at'], 'tx_nrllm_agentrun', ['uid' => $uid])
            ->fetchOne();
        self::assertIsNumeric($value);
        return (int)$value;
    }
}
