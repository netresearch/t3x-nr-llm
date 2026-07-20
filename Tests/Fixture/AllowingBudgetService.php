<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixture;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\BudgetServiceInterface;

/**
 * A budget service that allows everything, for tests whose subject is not the
 * budget gate.
 *
 * The specialized services require a budget service since the fail-open
 * optional dependency was removed, and "allows everything" is the honest
 * stand-in for "this test is about something else" — an omitted dependency
 * would silently disable a control.
 */
final readonly class AllowingBudgetService implements BudgetServiceInterface
{
    public function check(int $beUserUid, float $plannedCost = 0.0, ?LlmConfiguration $configuration = null): BudgetCheckResult
    {
        return BudgetCheckResult::allowed();
    }
}
