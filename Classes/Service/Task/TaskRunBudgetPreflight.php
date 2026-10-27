<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\TaskBudgetVerdict;
use Netresearch\NrLlm\Domain\ValueObject\TaskCostEstimate;
use Netresearch\NrLlm\Service\BudgetServiceInterface;

/**
 * Asks the budget whether the next run of a task passes, before it starts
 * (NEXT-170).
 *
 * `BudgetService::check()` is asked with planned cost 0 first, because that is
 * what the execution plans with — the task path passes no planned cost to
 * `BudgetMiddleware`. Only when that passes and the run can be priced is it asked
 * again with the upper estimate. See {@see TaskBudgetVerdict} for the states.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TaskRunBudgetPreflight
{
    public function __construct(
        private BudgetServiceInterface $budgetService,
    ) {}

    public function verdict(int $beUserUid, ?LlmConfiguration $configuration, TaskCostEstimate $estimate): TaskBudgetVerdict
    {
        $enforced = $this->budgetService->check($beUserUid, 0.0, $configuration);
        if (!$enforced->allowed) {
            return TaskBudgetVerdict::denied($enforced->exceededLimit);
        }

        if (!$estimate->isPriced()) {
            return TaskBudgetVerdict::allowed();
        }

        $atUpper = $this->budgetService->check($beUserUid, $estimate->plannedCost(), $configuration);
        if (!$atUpper->allowed) {
            return TaskBudgetVerdict::exceedsAtUpperEstimate($atUpper->exceededLimit);
        }

        return TaskBudgetVerdict::allowed();
    }
}
