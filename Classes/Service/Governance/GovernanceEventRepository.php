<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Append-only store for the governance-decision audit trail
 * (tx_nrllm_governance_event).
 *
 * By construction the application can only INSERT: {@see record()} plus read
 * aggregates for the dashboard, and — the one retention exception — a purge
 * driven by the nrllm:governance:purge command. Uses the Doctrine ConnectionPool
 * directly, mirroring {@see \Netresearch\NrLlm\Service\Telemetry\TelemetryRepository}:
 * the table is a UI-less log with no Extbase / TCA. No update or delete path
 * beyond the age-based purge.
 */
final readonly class GovernanceEventRepository implements GovernanceEventRepositoryInterface, SingletonInterface
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_governance_event';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function record(GovernanceEvent $event): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'                      => 0,
            'correlation_id'           => $event->correlationId,
            'decision'                 => $event->decision,
            'reason'                   => $event->reason,
            'provider'                 => $event->provider,
            'model'                    => $event->model,
            'configuration_identifier' => $event->configurationIdentifier,
            'be_user'                  => $event->beUser,
            'tool_name'                => $event->toolName,
            'agentrun_uid'             => $event->agentrunUid,
            'guardrail'                => $event->guardrail,
            'detail'                   => $event->detail,
            'crdate'                   => time(),
        ]);
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $connection   = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();

        return (int)$queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp)))
            ->executeStatement();
    }

    public function countByDecision(int $since = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('decision')
            ->addSelectLiteral('COUNT(uid) AS event_count')
            ->from(self::TABLE);
        if ($since > 0) {
            $queryBuilder->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
            );
        }
        $rows = $queryBuilder
            ->groupBy('decision')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->tallyBy($rows, 'decision', 'event_count');
    }

    public function countToolDenialsByReason(int $since = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('reason')
            ->addSelectLiteral('COUNT(uid) AS event_count')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('decision', $queryBuilder->createNamedParameter('tool_denied')),
            );
        if ($since > 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
            );
        }
        $rows = $queryBuilder
            ->groupBy('reason')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->tallyBy($rows, 'reason', 'event_count');
    }

    public function countToolDecisionsByName(int $since = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('tool_name')
            ->addSelectLiteral('COUNT(uid) AS event_count')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->neq('tool_name', $queryBuilder->createNamedParameter('')),
            );
        if ($since > 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
            );
        }
        $rows = $queryBuilder
            ->groupBy('tool_name')
            ->orderBy('event_count', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->tallyBy($rows, 'tool_name', 'event_count');
    }

    /**
     * Fold GROUP BY rows into a value => count map, dropping empty keys.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private function tallyBy(array $rows, string $keyColumn, string $countColumn): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = self::toStr($row[$keyColumn] ?? '');
            if ($key === '') {
                continue;
            }
            $out[$key] = self::toInt($row[$countColumn] ?? 0);
        }

        return $out;
    }
}
