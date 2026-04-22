<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Repository\UserBudgetRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Enforces per-backend-user daily and monthly AI spending ceilings.
 *
 * The budget record on tx_nrllm_user_budget is a ceiling, not a counter.
 * Actual usage is aggregated on demand from tx_nrllm_service_usage — the
 * same table the UsageTracker already writes to — so we never drift from
 * the source of truth and we don't pay a second-write cost per request.
 *
 * The service is intentionally a pure pre-flight check: call it BEFORE
 * dispatching to a provider. It does not increment anything.
 *
 * Concurrency: this is a best-effort gate, not a transactionally-safe
 * one. Two simultaneous requests for the same user can both pass the
 * check before either updates tx_nrllm_service_usage, temporarily
 * allowing a one-request overshoot. Adding a per-user lock would
 * serialise a hot path; callers needing strict enforcement should
 * layer their own lock / reservation on top.
 *
 * Like CapabilityPermissionService, this ships the primitive; wiring the
 * check into individual feature services is a deliberate follow-up.
 */
class BudgetService
{
    private const USAGE_TABLE = 'tx_nrllm_service_usage';

    public function __construct(
        private readonly UserBudgetRepository $repository,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Pre-flight check: is the user allowed to make a request whose
     * expected cost is $plannedCost?
     *
     * Resolution:
     *   1. No budget record -> allowed (admin hasn't capped this user)
     *   2. Budget is inactive -> allowed (admin muted the cap)
     *   3. Budget has no limits set -> allowed
     *   4. Otherwise check current-day and current-month buckets in order.
     *      The first bucket to exceed its limit wins and is reported back.
     */
    public function check(int $beUserUid, float $plannedCost = 0.0): BudgetCheckResult
    {
        if ($beUserUid <= 0) {
            return BudgetCheckResult::allowed();
        }

        // Negative planned cost would artificially reduce the projected
        // total and let callers bypass cost limits. Clamp defensively.
        $plannedCost = max(0.0, $plannedCost);

        $budget = $this->repository->findOneByBeUser($beUserUid);
        if ($budget === null || !$budget->isActive() || !$budget->hasAnyLimit()) {
            return BudgetCheckResult::allowed();
        }

        $now = new DateTimeImmutable();
        $hasDailyLimits = $budget->getMaxRequestsPerDay() > 0
            || $budget->getMaxTokensPerDay() > 0
            || $budget->getMaxCostPerDay() > 0.0;
        $hasMonthlyLimits = $budget->getMaxRequestsPerMonth() > 0
            || $budget->getMaxTokensPerMonth() > 0
            || $budget->getMaxCostPerMonth() > 0.0;

        $dayStart = $now->setTime(0, 0, 0)->getTimestamp();
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0)->getTimestamp();

        // ONE DB roundtrip covering both windows. When only one window is
        // configured the other aggregate is cheap (the row count is tiny
        // since tx_nrllm_service_usage is already a per-user/per-day
        // rollup, not a per-request log).
        $windows = $this->aggregateWindowUsage(
            $beUserUid,
            $hasDailyLimits ? $dayStart : null,
            $hasMonthlyLimits ? $monthStart : null,
            $now->getTimestamp(),
        );

        if ($hasDailyLimits) {
            $dailyResult = $this->compare(
                usage: $windows['daily'],
                plannedCost: $plannedCost,
                requestLimit: $budget->getMaxRequestsPerDay(),
                tokenLimit: $budget->getMaxTokensPerDay(),
                costLimit: $budget->getMaxCostPerDay(),
                requestLimitId: BudgetCheckResult::LIMIT_DAILY_REQUESTS,
                tokenLimitId: BudgetCheckResult::LIMIT_DAILY_TOKENS,
                costLimitId: BudgetCheckResult::LIMIT_DAILY_COST,
            );
            if (!$dailyResult->allowed) {
                return $dailyResult;
            }
        }

        if ($hasMonthlyLimits) {
            return $this->compare(
                usage: $windows['monthly'],
                plannedCost: $plannedCost,
                requestLimit: $budget->getMaxRequestsPerMonth(),
                tokenLimit: $budget->getMaxTokensPerMonth(),
                costLimit: $budget->getMaxCostPerMonth(),
                requestLimitId: BudgetCheckResult::LIMIT_MONTHLY_REQUESTS,
                tokenLimitId: BudgetCheckResult::LIMIT_MONTHLY_TOKENS,
                costLimitId: BudgetCheckResult::LIMIT_MONTHLY_COST,
            );
        }

        return BudgetCheckResult::allowed();
    }

    /**
     * @param array{requests: int, tokens: int, cost: float} $usage
     */
    private function compare(
        array $usage,
        float $plannedCost,
        int $requestLimit,
        int $tokenLimit,
        float $costLimit,
        string $requestLimitId,
        string $tokenLimitId,
        string $costLimitId,
    ): BudgetCheckResult {
        // Each incoming call is +1 request, +$plannedCost. Tokens are unknown
        // at pre-flight so we check the existing total only.
        if ($requestLimit > 0 && ($usage['requests'] + 1) > $requestLimit) {
            return BudgetCheckResult::denied(
                $requestLimitId,
                (float)$usage['requests'],
                (float)$requestLimit,
            );
        }
        if ($tokenLimit > 0 && $usage['tokens'] > $tokenLimit) {
            return BudgetCheckResult::denied(
                $tokenLimitId,
                (float)$usage['tokens'],
                (float)$tokenLimit,
            );
        }
        if ($costLimit > 0.0 && ($usage['cost'] + $plannedCost) > $costLimit) {
            return BudgetCheckResult::denied(
                $costLimitId,
                $usage['cost'],
                $costLimit,
            );
        }
        return BudgetCheckResult::allowed();
    }

    /**
     * Aggregate per-user usage for the daily AND monthly windows in a
     * single DB roundtrip, using conditional SUM() expressions. Protected
     * so tests can stub out the DB layer without mocking the QueryBuilder
     * chain. `null` for a window means "limit not configured" — the
     * matching aggregate is returned as a zeroed bucket.
     *
     * @return array{daily: array{requests: int, tokens: int, cost: float}, monthly: array{requests: int, tokens: int, cost: float}}
     */
    protected function aggregateWindowUsage(
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
