<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;

/**
 * Public surface of the AI budget gate (per-backend-user and
 * per-configuration scopes).
 *
 * Consumers (middleware, feature services, tests) should depend on this
 * interface rather than the concrete `BudgetService` so the
 * implementation can be substituted without inheritance.
 */
interface BudgetServiceInterface
{
    /**
     * Pre-flight check: is this request, whose expected cost is
     * `$plannedCost`, allowed?
     *
     * Per-user scope: returns `BudgetCheckResult::allowed()` when there
     * is no budget record, the budget is inactive, no limits are set, or
     * every configured limit (current-day and current-month) is still
     * under its cap.
     *
     * Per-configuration scope: when a persisted `$configuration` with
     * usage limits is supplied, its per-day request/token/cost caps are
     * checked AFTER the per-user budget passes. The most restrictive
     * scope wins — the first scope that denies short-circuits.
     * Configuration caps apply regardless of `$beUserUid` (they gate
     * CLI/scheduler traffic too).
     *
     * Otherwise returns `BudgetCheckResult::denied(...)` naming the
     * first bucket that would overflow.
     */
    public function check(int $beUserUid, float $plannedCost = 0.0, ?LlmConfiguration $configuration = null): BudgetCheckResult;
}
