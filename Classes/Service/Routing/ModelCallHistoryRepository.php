<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Routing;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

use function is_numeric;
use function is_string;

/**
 * Aggregates per-model call history from the telemetry log (ADR-256).
 *
 * One GROUP BY over the window, keyed by the SERVING model — the columns that
 * name what answered, not what was requested: a fallback can hand a call to a
 * sibling, and the sibling's latency is not the primary's.
 *
 * Read-only, like {@see \Netresearch\NrLlm\Service\Health\ProviderHealthRepository}.
 *
 * @internal
 */
final readonly class ModelCallHistoryRepository implements SingletonInterface
{
    private const TABLE = 'tx_nrllm_telemetry';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<string, ModelCallStats> keyed by {@see ModelCallStats::key()}
     */
    public function statsSince(int $sinceTimestamp): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $expr         = $queryBuilder->expr();

        $rows = $queryBuilder
            ->select('served_provider', 'served_model', 'operation')
            ->addSelectLiteral('COUNT(*) AS samples')
            ->addSelectLiteral('AVG(latency_ms) AS avg_latency')
            ->addSelectLiteral('COUNT(actual_cost) AS costed')
            ->addSelectLiteral('AVG(actual_cost) AS avg_cost')
            ->from(self::TABLE)
            ->where(
                $expr->gte('crdate', $queryBuilder->createNamedParameter($sinceTimestamp)),
                // What the model did ITSELF: a success on the first attempt.
                // A fallback-rescued run's latency is the failed attempt plus
                // the sibling's answer, the same rule provider health applies.
                $expr->eq('success', $queryBuilder->createNamedParameter(1)),
                $expr->eq('fallback_attempts', $queryBuilder->createNamedParameter(0)),
                // A cache hit answers in a few milliseconds for nothing, and
                // measures the cache rather than the model (ADR-249).
                $expr->eq('cache_hit', $queryBuilder->createNamedParameter(0)),
                $expr->neq('served_provider', $queryBuilder->createNamedParameter('')),
                $expr->neq('served_model', $queryBuilder->createNamedParameter('')),
            )
            ->groupBy('served_provider', 'served_model', 'operation')
            ->executeQuery()
            ->fetchAllAssociative();

        $stats = [];
        foreach ($rows as $row) {
            $provider  = is_string($row['served_provider'] ?? null) ? $row['served_provider'] : '';
            $model     = is_string($row['served_model'] ?? null) ? $row['served_model'] : '';
            $operation = is_string($row['operation'] ?? null) ? $row['operation'] : '';

            $stats[ModelCallStats::key($provider, $model, $operation)] = new ModelCallStats(
                is_numeric($row['samples'] ?? null) ? (int) $row['samples'] : 0,
                is_numeric($row['avg_latency'] ?? null) ? (float) $row['avg_latency'] : null,
                is_numeric($row['costed'] ?? null) ? (int) $row['costed'] : 0,
                // NULL where no call was priced: AVG over NULLs only.
                is_numeric($row['avg_cost'] ?? null) ? (float) $row['avg_cost'] : null,
            );
        }

        return $stats;
    }
}
