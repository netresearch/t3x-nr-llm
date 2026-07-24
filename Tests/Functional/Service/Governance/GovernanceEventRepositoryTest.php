<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Governance;

use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(GovernanceEventRepository::class)]
#[CoversClass(GovernanceEvent::class)]
final class GovernanceEventRepositoryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_governance_event';

    private GovernanceEventRepository $repository;
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->repository = new GovernanceEventRepository($this->connectionPool);
    }

    #[Test]
    public function recordInsertsOneRowWithAllFields(): void
    {
        $this->repository->record(new GovernanceEvent(
            correlationId: 'corr-1',
            decision: 'response_blocked',
            reason: 'deny',
            provider: 'openai',
            model: 'gpt-4o',
            configurationIdentifier: 'primary',
            beUser: 7,
            toolName: '',
            agentrunUid: 0,
            guardrail: 'Acme\\SecretGuardrail',
            detail: 'contained a secret pattern',
        ));

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row        = $connection->select(['*'], self::TABLE, ['correlation_id' => 'corr-1'])->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame('response_blocked', $row['decision']);
        self::assertSame('deny', $row['reason']);
        self::assertSame('openai', $row['provider']);
        self::assertSame('gpt-4o', $row['model']);
        self::assertSame('primary', $row['configuration_identifier']);
        self::assertSame(7, (int)$row['be_user']);
        self::assertSame('Acme\\SecretGuardrail', $row['guardrail']);
        self::assertSame('contained a secret pattern', $row['detail']);
        self::assertGreaterThan(0, (int)$row['crdate']);
    }

    #[Test]
    public function countByDecisionGroupsWithinTheWindow(): void
    {
        $now = time();
        $this->insert('tool_denied', 'trustZone', '', $now);
        $this->insert('tool_denied', 'requiresAdmin', '', $now);
        $this->insert('response_blocked', 'deny', '', $now);
        // Out of window: excluded.
        $this->insert('content_filter', 'content_filter', '', $now - (10 * 86400));

        $counts = $this->repository->countByDecision($now - (5 * 86400));
        ksort($counts);

        self::assertSame(['response_blocked' => 1, 'tool_denied' => 2], $counts);
    }

    #[Test]
    public function countToolDenialsByReasonCountsOnlyToolDeniedRows(): void
    {
        $now = time();
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now);
        $this->insert('tool_denied', 'trustZone', 'get_env', $now);
        $this->insert('tool_denied', 'requiresAdmin', 'list_be_users', $now);
        // A guardrail block also carries a reason, but must not count as a tool denial.
        $this->insert('response_blocked', 'deny', '', $now);

        $counts = $this->repository->countToolDenialsByReason(0);
        ksort($counts);

        self::assertSame(['requiresAdmin' => 1, 'trustZone' => 2], $counts);
    }

    #[Test]
    public function countToolDecisionsByNameGroupsByToolAndSkipsEmptyNames(): void
    {
        $now = time();
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now);
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now);
        $this->insert('tool_denied', 'requiresAdmin', 'get_env', $now);
        // Guardrail row has no tool name: excluded.
        $this->insert('response_blocked', 'deny', '', $now);

        $counts = $this->repository->countToolDecisionsByName(0);

        self::assertSame(2, $counts['fetch_logs']);
        self::assertSame(1, $counts['get_env']);
        self::assertArrayNotHasKey('', $counts);
    }

    #[Test]
    public function purgeOlderThanDeletesOnlyOlderRows(): void
    {
        $now = time();
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now - (10 * 86400));
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now);

        $deleted = $this->repository->purgeOlderThan($now - (5 * 86400));

        self::assertSame(1, $deleted);
        self::assertSame(1, $this->connectionPool->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
    }

    private function insert(string $decision, string $reason, string $toolName, int $crdate): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'                      => 0,
            'crdate'                   => $crdate,
            'correlation_id'           => '',
            'decision'                 => $decision,
            'reason'                   => $reason,
            'provider'                 => 'openai',
            'model'                    => 'gpt',
            'configuration_identifier' => 'primary',
            'be_user'                  => 0,
            'tool_name'                => $toolName,
            'agentrun_uid'             => 0,
            'guardrail'                => '',
            'detail'                   => '',
        ]);
    }
}
