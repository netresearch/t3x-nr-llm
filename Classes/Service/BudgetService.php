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
use Netresearch\NrLlm\Service\Budget\BudgetUsageWindowsInterface;

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
final readonly class BudgetService implements BudgetServiceInterface
{
    public function __construct(
        private UserBudgetRepository $repository,
        private BudgetUsageWindowsInterface $usageWindows,
    ) {}

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

        $windows = $this->usageWindows->aggregate(
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
}
