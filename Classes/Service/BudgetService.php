<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\UserBudgetRepository;
use Netresearch\NrLlm\Service\Budget\BudgetUsageWindowsInterface;

/**
 * Enforces per-backend-user daily/monthly AI spending ceilings AND the
 * active configuration's per-day usage caps.
 *
 * Two scopes, checked in order — the most restrictive wins:
 *  1. Per-user: the budget record on tx_nrllm_user_budget is a ceiling,
 *     not a counter. Skipped for beUserUid <= 0 (ADR-025 rule 1).
 *  2. Per-configuration: the dispatched LlmConfiguration's own
 *     maxRequestsPerDay / maxTokensPerDay / maxCostPerDay caps, compared
 *     against the configuration's current-day usage across ALL users.
 *     Applies even when beUserUid is 0 (CLI/scheduler) because the cap
 *     is configuration-scoped, not user-scoped. Transient configurations
 *     (no persisted uid) and configurations without limits are skipped —
 *     the common no-limits case costs zero extra DB queries.
 *
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

    public function check(int $beUserUid, float $plannedCost = 0.0, ?LlmConfiguration $configuration = null): BudgetCheckResult
    {
        // Negative planned cost would artificially reduce the projected
        // total and let callers bypass cost limits. Clamp defensively.
        $plannedCost = max(0.0, $plannedCost);

        $result = $this->checkUserBudget($beUserUid, $plannedCost);

        if ($result->allowed && $configuration !== null) {
            $result = $this->checkConfigurationLimits($configuration, $plannedCost);
        }

        return $result;
    }

    private function checkUserBudget(int $beUserUid, float $plannedCost): BudgetCheckResult
    {
        $budget = $beUserUid > 0 ? $this->repository->findOneByBeUser($beUserUid) : null;
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

        $result = BudgetCheckResult::allowed();

        if ($hasDailyLimits) {
            $result = $this->compare(
                usage: $windows['daily'],
                plannedCost: $plannedCost,
                requestLimit: $budget->getMaxRequestsPerDay(),
                tokenLimit: $budget->getMaxTokensPerDay(),
                costLimit: $budget->getMaxCostPerDay(),
                limitIds: [
                    'request' => BudgetCheckResult::LIMIT_DAILY_REQUESTS,
                    'token' => BudgetCheckResult::LIMIT_DAILY_TOKENS,
                    'cost' => BudgetCheckResult::LIMIT_DAILY_COST,
                ],
            );
        }

        if ($result->allowed && $hasMonthlyLimits) {
            $result = $this->compare(
                usage: $windows['monthly'],
                plannedCost: $plannedCost,
                requestLimit: $budget->getMaxRequestsPerMonth(),
                tokenLimit: $budget->getMaxTokensPerMonth(),
                costLimit: $budget->getMaxCostPerMonth(),
                limitIds: [
                    'request' => BudgetCheckResult::LIMIT_MONTHLY_REQUESTS,
                    'token' => BudgetCheckResult::LIMIT_MONTHLY_TOKENS,
                    'cost' => BudgetCheckResult::LIMIT_MONTHLY_COST,
                ],
            );
        }

        return $result;
    }

    /**
     * Compare the configuration's current-day usage (all users) against
     * its own per-day caps. Transient configurations (no persisted uid)
     * and configurations without limits are allowed without touching the
     * database.
     */
    private function checkConfigurationLimits(LlmConfiguration $configuration, float $plannedCost): BudgetCheckResult
    {
        $configurationUid = $configuration->getUid() ?? 0;
        if ($configurationUid <= 0 || !$configuration->hasUsageLimits()) {
            return BudgetCheckResult::allowed();
        }

        $now = new DateTimeImmutable();
        $usage = $this->usageWindows->aggregateForConfiguration(
            $configurationUid,
            $now->setTime(0, 0, 0)->getTimestamp(),
            $now->getTimestamp(),
        );

        return $this->compare(
            usage: $usage,
            plannedCost: $plannedCost,
            requestLimit: $configuration->getMaxRequestsPerDay(),
            tokenLimit: $configuration->getMaxTokensPerDay(),
            costLimit: $configuration->getMaxCostPerDay(),
            limitIds: [
                'request' => BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_REQUESTS,
                'token' => BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_TOKENS,
                'cost' => BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_COST,
            ],
        );
    }

    /**
     * @param array{requests: int, tokens: int, cost: float}      $usage
     * @param array{request: string, token: string, cost: string} $limitIds
     */
    private function compare(
        array $usage,
        float $plannedCost,
        int $requestLimit,
        int $tokenLimit,
        float $costLimit,
        array $limitIds,
    ): BudgetCheckResult {
        // Each incoming call is +1 request, +$plannedCost. Tokens are unknown
        // at pre-flight so we check the existing total only.
        $result = BudgetCheckResult::allowed();
        if ($requestLimit > 0 && ($usage['requests'] + 1) > $requestLimit) {
            $result = BudgetCheckResult::denied(
                $limitIds['request'],
                (float)$usage['requests'],
                (float)$requestLimit,
            );
        } elseif ($tokenLimit > 0 && $usage['tokens'] > $tokenLimit) {
            $result = BudgetCheckResult::denied(
                $limitIds['token'],
                (float)$usage['tokens'],
                (float)$tokenLimit,
            );
        } elseif ($costLimit > 0.0 && ($usage['cost'] + $plannedCost) > $costLimit) {
            $result = BudgetCheckResult::denied(
                $limitIds['cost'],
                $usage['cost'],
                $costLimit,
            );
        }
        return $result;
    }
}
