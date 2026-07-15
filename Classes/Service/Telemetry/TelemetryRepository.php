<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

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
}
