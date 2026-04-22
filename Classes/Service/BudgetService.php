<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\UserBudget;
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

        $budget = $this->repository->findOneByBeUser($beUserUid);
        if ($budget === null || !$budget->isActive() || !$budget->hasAnyLimit()) {
            return BudgetCheckResult::allowed();
        }

        $now = new DateTimeImmutable();

        $dailyResult = $this->evaluateDaily($budget, $beUserUid, $plannedCost, $now);
        if (!$dailyResult->allowed) {
            return $dailyResult;
        }

        return $this->evaluateMonthly($budget, $beUserUid, $plannedCost, $now);
    }

    private function evaluateDaily(
        UserBudget $budget,
        int $beUserUid,
        float $plannedCost,
        DateTimeImmutable $now,
    ): BudgetCheckResult {
        if ($budget->getMaxRequestsPerDay() === 0
            && $budget->getMaxTokensPerDay() === 0
            && $budget->getMaxCostPerDay() === 0.0
        ) {
            return BudgetCheckResult::allowed();
        }

        $from = $now->setTime(0, 0, 0);
        $usage = $this->aggregateUsage($beUserUid, $from->getTimestamp(), $now->getTimestamp());

        return $this->compare(
            usage: $usage,
            plannedCost: $plannedCost,
            requestLimit: $budget->getMaxRequestsPerDay(),
            tokenLimit: $budget->getMaxTokensPerDay(),
            costLimit: $budget->getMaxCostPerDay(),
            requestLimitId: BudgetCheckResult::LIMIT_DAILY_REQUESTS,
            tokenLimitId: BudgetCheckResult::LIMIT_DAILY_TOKENS,
            costLimitId: BudgetCheckResult::LIMIT_DAILY_COST,
        );
    }

    private function evaluateMonthly(
        UserBudget $budget,
        int $beUserUid,
        float $plannedCost,
        DateTimeImmutable $now,
    ): BudgetCheckResult {
        if ($budget->getMaxRequestsPerMonth() === 0
            && $budget->getMaxTokensPerMonth() === 0
            && $budget->getMaxCostPerMonth() === 0.0
        ) {
            return BudgetCheckResult::allowed();
        }

        $from = $now->modify('first day of this month')->setTime(0, 0, 0);
        $usage = $this->aggregateUsage($beUserUid, $from->getTimestamp(), $now->getTimestamp());

        return $this->compare(
            usage: $usage,
            plannedCost: $plannedCost,
            requestLimit: $budget->getMaxRequestsPerMonth(),
            tokenLimit: $budget->getMaxTokensPerMonth(),
            costLimit: $budget->getMaxCostPerMonth(),
            requestLimitId: BudgetCheckResult::LIMIT_MONTHLY_REQUESTS,
            tokenLimitId: BudgetCheckResult::LIMIT_MONTHLY_TOKENS,
            costLimitId: BudgetCheckResult::LIMIT_MONTHLY_COST,
        );
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
     * Aggregate usage for the given user and time window. Protected so tests
     * can stub out the DB layer without mocking the full QueryBuilder chain.
     *
     * @return array{requests: int, tokens: int, cost: float}
     */
    protected function aggregateUsage(int $beUserUid, int $fromTimestamp, int $toTimestamp): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::USAGE_TABLE);
        $row = $queryBuilder
            ->addSelectLiteral('SUM(request_count) AS total_requests')
            ->addSelectLiteral('SUM(tokens_used) AS total_tokens')
            ->addSelectLiteral('SUM(estimated_cost) AS total_cost')
            ->from(self::USAGE_TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'be_user',
                    $queryBuilder->createNamedParameter($beUserUid),
                ),
                $queryBuilder->expr()->gte(
                    'request_date',
                    $queryBuilder->createNamedParameter($fromTimestamp),
                ),
                $queryBuilder->expr()->lte(
                    'request_date',
                    $queryBuilder->createNamedParameter($toTimestamp),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return ['requests' => 0, 'tokens' => 0, 'cost' => 0.0];
        }

        return [
            'requests' => is_numeric($row['total_requests'] ?? null) ? (int)$row['total_requests'] : 0,
            'tokens' => is_numeric($row['total_tokens'] ?? null) ? (int)$row['total_tokens'] : 0,
            'cost' => is_numeric($row['total_cost'] ?? null) ? (float)$row['total_cost'] : 0.0,
        ];
    }
}
