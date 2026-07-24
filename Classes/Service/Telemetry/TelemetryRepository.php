<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Writes and prunes provider pipeline telemetry rows (ADR-058).
 *
 * Uses the Doctrine ConnectionPool directly — the table is a UI-less append-only
 * log with no Extbase persistence needs, mirroring how UsageTrackerService
 * writes the usage table.
 */
final readonly class TelemetryRepository implements TelemetryRepositoryInterface, SingletonInterface
{
    private const TABLE = 'tx_nrllm_telemetry';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function record(TelemetryRecord $record): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'                      => 0,
            'correlation_id'           => $record->correlationId,
            'operation'                => $record->operation,
            'provider'                 => $record->provider,
            'model'                    => $record->model,
            'configuration_identifier' => $record->configurationIdentifier,
            'be_user'                  => $record->beUser,
            'success'                  => $record->success ? 1 : 0,
            'error_class'              => $record->errorClass,
            'latency_ms'               => $record->latencyMs,
            'cache_hit'                => $record->cacheHit ? 1 : 0,
            'fallback_attempts'        => $record->fallbackAttempts,
            'time_to_first_token_ms'   => $record->timeToFirstTokenMs,
            'crdate'                   => time(),
        ]);
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();

        return (int)$queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp)))
            ->executeStatement();
    }

    public function successRatePercent(int $since): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->addSelectLiteral('COUNT(uid) AS total', 'SUM(success) AS ok')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return 0;
        }
        $total = is_numeric($row['total'] ?? null) ? (int)$row['total'] : 0;
        $ok    = is_numeric($row['ok'] ?? null) ? (int)$row['ok'] : 0;

        return $total === 0 ? 0 : (int)round($ok * 100 / $total);
    }

    public function averageLatencyMs(int $since): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $avg = $queryBuilder
            ->addSelectLiteral('AVG(latency_ms) AS avg_latency')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($avg) ? (int)round((float)$avg) : 0;
    }
}
