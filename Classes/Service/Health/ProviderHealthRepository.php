<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Health;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Aggregates provider health from the telemetry log (ADR-063).
 *
 * Reads {@sql tx_nrllm_telemetry} directly via the Doctrine ConnectionPool —
 * the same access pattern the telemetry writer and usage analytics use —
 * grouping recent rows by provider into a success rate and mean latency.
 * Read-only: it never writes the table.
 */
final readonly class ProviderHealthRepository implements ProviderHealthRepositoryInterface, SingletonInterface
{
    private const TABLE = 'tx_nrllm_telemetry';

    private const COL_PROVIDER = 'provider';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function scoresSince(int $sinceTimestamp): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $rows = $queryBuilder
            ->select(self::COL_PROVIDER)
            ->addSelectLiteral('COUNT(*) AS samples')
            ->addSelectLiteral('SUM(success) AS successes')
            ->addSelectLiteral('AVG(latency_ms) AS avg_latency')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($sinceTimestamp)),
                $queryBuilder->expr()->neq(self::COL_PROVIDER, $queryBuilder->createNamedParameter('')),
            )
            ->groupBy(self::COL_PROVIDER)
            ->executeQuery()
            ->fetchAllAssociative();

        $scores = [];
        foreach ($rows as $row) {
            $provider = is_string($row[self::COL_PROVIDER] ?? null) ? $row[self::COL_PROVIDER] : '';
            if ($provider === '') {
                continue;
            }

            $samples    = is_numeric($row['samples'] ?? null) ? (int)$row['samples'] : 0;
            $successes  = is_numeric($row['successes'] ?? null) ? (int)$row['successes'] : 0;
            $avgLatency = is_numeric($row['avg_latency'] ?? null) ? (float)$row['avg_latency'] : 0.0;

            $scores[$provider] = ProviderHealthScore::fromSamples($provider, $samples, $successes, $avgLatency);
        }

        return $scores;
    }
}
