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
            // First-attempt reliability. Every run requested from this provider
            // counts as a sample; a run counts as a SUCCESS only when the
            // provider served it ITSELF (fallback_attempts = 0) and succeeded.
            // A telemetry row names the requested PRIMARY, but `success` is the
            // whole-pipeline outcome — so a fallback-rescued run
            // (fallback_attempts > 0, success = 1) means the primary FAILED its
            // first attempt: it is counted here as a primary failure (in
            // `samples`, not `successes`), never credited to the primary on the
            // back of the sibling that answered for it, and never dropped (which
            // would inflate a usually-rescued primary toward 100%).
            ->addSelectLiteral('COUNT(*) AS samples')
            ->addSelectLiteral('SUM(CASE WHEN fallback_attempts = 0 AND success = 1 THEN 1 ELSE 0 END) AS successes')
            // Average latency only over self-served runs. A fallback-rescued run's
            // latency_ms is the whole-pipeline time (the failed primary attempt
            // plus the sibling that answered), which would distort the primary's
            // own latency; AVG ignores the NULLs the ELSE branch yields.
            ->addSelectLiteral('AVG(CASE WHEN fallback_attempts = 0 THEN latency_ms END) AS avg_latency')
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
