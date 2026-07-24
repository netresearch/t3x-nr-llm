<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional coverage for the dashboard aggregate read methods on
 * {@see AgentRunRepository}: countByStatus / countByTerminationReason /
 * countInStatus. Rows are inserted directly with controlled status,
 * termination_reason and crdate so the GROUP BY and windowing are exercised
 * against the real schema.
 */
#[CoversClass(AgentRunRepository::class)]
final class AgentRunRepositoryAggregatesTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_agentrun';

    private AgentRunRepository $repository;
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->repository = new AgentRunRepository($connectionPool, $this->get(AgentStateCodec::class));
    }

    #[Test]
    public function countByStatusGroupsAllTimeWhenSinceIsZero(): void
    {
        $now = time();
        $this->insertRun(AgentRunStatus::COMPLETED->value, 'completed', $now);
        $this->insertRun(AgentRunStatus::COMPLETED->value, 'completed', $now);
        $this->insertRun(AgentRunStatus::FAILED->value, 'provider_failed', $now);
        $this->insertRun(AgentRunStatus::RUNNING->value, '', $now);

        self::assertSame(
            ['completed' => 2, 'failed' => 1, 'running' => 1],
            $this->sorted($this->repository->countByStatus(0)),
        );
    }

    #[Test]
    public function countByStatusWindowsByCrdate(): void
    {
        $now        = time();
        $tenDaysAgo = $now - (10 * 86400);
        $this->insertRun(AgentRunStatus::COMPLETED->value, 'completed', $now);
        $this->insertRun(AgentRunStatus::COMPLETED->value, 'completed', $tenDaysAgo);

        $since = $now - (5 * 86400);

        self::assertSame(['completed' => 1], $this->repository->countByStatus($since));
    }

    #[Test]
    public function countByTerminationReasonSkipsRunsWithoutAReason(): void
    {
        $now = time();
        $this->insertRun(AgentRunStatus::COMPLETED->value, 'completed', $now);
        $this->insertRun(AgentRunStatus::FAILED->value, 'budget_exhausted', $now);
        $this->insertRun(AgentRunStatus::FAILED->value, 'budget_exhausted', $now);
        // A run still in flight carries no termination_reason: excluded.
        $this->insertRun(AgentRunStatus::RUNNING->value, '', $now);

        self::assertSame(
            ['budget_exhausted' => 2, 'completed' => 1],
            $this->sorted($this->repository->countByTerminationReason(0)),
        );
    }

    #[Test]
    public function countInStatusIsALiveGaugeIgnoringAge(): void
    {
        $now        = time();
        $longAgo    = $now - (30 * 86400);
        $this->insertRun(AgentRunStatus::WAITING_FOR_APPROVAL->value, '', $longAgo);
        $this->insertRun(AgentRunStatus::WAITING_FOR_APPROVAL->value, '', $now);
        $this->insertRun(AgentRunStatus::RUNNING->value, '', $now);

        self::assertSame(2, $this->repository->countInStatus(AgentRunStatus::WAITING_FOR_APPROVAL));
        self::assertSame(0, $this->repository->countInStatus(AgentRunStatus::CANCELLED));
    }

    private function insertRun(string $status, string $terminationReason, int $crdate): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'                      => 0,
            'uuid'                     => bin2hex(random_bytes(8)),
            'status'                   => $status,
            'configuration_uid'        => 0,
            'configuration_identifier' => 'primary',
            'be_user'                  => 0,
            'termination_reason'       => $terminationReason,
            'started_at'               => $crdate,
            'finished_at'              => 0,
            'tstamp'                   => $crdate,
            'crdate'                   => $crdate,
        ]);
    }

    /**
     * ksort a value => count map so assertions do not depend on GROUP BY order.
     *
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
