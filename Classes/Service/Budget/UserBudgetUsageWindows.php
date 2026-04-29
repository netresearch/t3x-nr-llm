<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Budget;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * SQL-backed implementation of `BudgetUsageWindowsInterface`.
 *
 * Reads `tx_nrllm_service_usage` (the per-user/per-day rollup written by
 * `UsageMiddleware`) and returns daily + monthly totals in one roundtrip
 * via conditional SUM() expressions.
 */
final readonly class UserBudgetUsageWindows implements BudgetUsageWindowsInterface
{
    private const USAGE_TABLE = 'tx_nrllm_service_usage';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function aggregate(
        int $beUserUid,
        ?int $dailyFromTimestamp,
        ?int $monthlyFromTimestamp,
        int $toTimestamp,
    ): array {
        $empty = ['requests' => 0, 'tokens' => 0, 'cost' => 0.0];
        if ($dailyFromTimestamp === null && $monthlyFromTimestamp === null) {
            return ['daily' => $empty, 'monthly' => $empty];
        }

        // The lower bound for the SQL WHERE: monthly is always the wider
        // window, so if it's set we use that; otherwise fall back to the
        // daily bound.
        $lowerBound = $monthlyFromTimestamp ?? $dailyFromTimestamp;

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::USAGE_TABLE);
        $dailyFromParam = $queryBuilder->createNamedParameter($dailyFromTimestamp ?? PHP_INT_MAX);
        $monthlyFromParam = $queryBuilder->createNamedParameter($monthlyFromTimestamp ?? PHP_INT_MAX);

        $row = $queryBuilder
            ->addSelectLiteral(sprintf('SUM(CASE WHEN request_date >= %s THEN request_count ELSE 0 END) AS daily_requests', $dailyFromParam))
            ->addSelectLiteral(sprintf('SUM(CASE WHEN request_date >= %s THEN tokens_used ELSE 0 END) AS daily_tokens', $dailyFromParam))
            ->addSelectLiteral(sprintf('SUM(CASE WHEN request_date >= %s THEN estimated_cost ELSE 0 END) AS daily_cost', $dailyFromParam))
            ->addSelectLiteral(sprintf('SUM(CASE WHEN request_date >= %s THEN request_count ELSE 0 END) AS monthly_requests', $monthlyFromParam))
            ->addSelectLiteral(sprintf('SUM(CASE WHEN request_date >= %s THEN tokens_used ELSE 0 END) AS monthly_tokens', $monthlyFromParam))
            ->addSelectLiteral(sprintf('SUM(CASE WHEN request_date >= %s THEN estimated_cost ELSE 0 END) AS monthly_cost', $monthlyFromParam))
            ->from(self::USAGE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUserUid)),
                $queryBuilder->expr()->gte('request_date', $queryBuilder->createNamedParameter($lowerBound)),
                $queryBuilder->expr()->lte('request_date', $queryBuilder->createNamedParameter($toTimestamp)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return ['daily' => $empty, 'monthly' => $empty];
        }

        return [
            'daily' => [
                'requests' => is_numeric($row['daily_requests'] ?? null) ? (int)$row['daily_requests'] : 0,
                'tokens' => is_numeric($row['daily_tokens'] ?? null) ? (int)$row['daily_tokens'] : 0,
                'cost' => is_numeric($row['daily_cost'] ?? null) ? (float)$row['daily_cost'] : 0.0,
            ],
            'monthly' => [
                'requests' => is_numeric($row['monthly_requests'] ?? null) ? (int)$row['monthly_requests'] : 0,
                'tokens' => is_numeric($row['monthly_tokens'] ?? null) ? (int)$row['monthly_tokens'] : 0,
                'cost' => is_numeric($row['monthly_cost'] ?? null) ? (float)$row['monthly_cost'] : 0.0,
            ],
        ];
    }
}
