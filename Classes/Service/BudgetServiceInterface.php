<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;

/**
 * Public surface of the per-backend-user AI budget gate.
 *
 * Consumers (middleware, feature services, tests) should depend on this
 * interface rather than the concrete `BudgetService` so the
 * implementation can be substituted without inheritance.
 */
interface BudgetServiceInterface
{
    /**
     * Pre-flight check: is the user allowed to make a request whose
     * expected cost is `$plannedCost`?
     *
     * Returns `BudgetCheckResult::allowed()` when there is no budget
     * record, the budget is inactive, no limits are set, or every
     * configured limit (current-day and current-month) is still under
     * its cap. Otherwise returns `BudgetCheckResult::denied(...)`
     * naming the first bucket that would overflow.
     */
    public function check(int $beUserUid, float $plannedCost = 0.0): BudgetCheckResult;
}
