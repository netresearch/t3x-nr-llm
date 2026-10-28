<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Budget;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Model\UserBudget;
use Netresearch\NrLlm\Domain\Repository\UserBudgetRepository;
use Netresearch\NrLlm\Domain\ValueObject\BudgetWindowStatus;
use Netresearch\NrLlm\Domain\ValueObject\UserBudgetStatus;
use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;

/**
 * The read model behind "my budget" in the AI Tasks module (NEXT-170).
 *
 * Reads what {@see \Netresearch\NrLlm\Service\BudgetService::check()} compares,
 * from the same places: the record `UserBudgetRepository::findOneByBeUser()`
 * returns, and usage from {@see BudgetUsageWindowsInterface::aggregate()} with
 * the same window starts — today from 00:00, this month from the first at 00:00,
 * both up to now. The only difference is that both windows are always read,
 * because the panel shows usage on an axis without a limit too, where `check()`
 * skips the window.
 *
 * Callers pass the uid of the logged-in backend user. A uid of 0 reads nothing:
 * `be_user = 0` is where the usage of every caller without a backend user
 * (CLI, scheduler) is recorded, and that is nobody's personal usage. The
 * formatter is the reader's: the logged-in user's locale.
 *
 * Other extensions read an editor's budget status through this service
 * (ADR-232). Obtain it from the DI container; the constructor is not part of
 * the promise.
 *
 * @api
 */
final readonly class UserBudgetStatusService
{
    private const NO_USAGE = ['requests' => 0, 'tokens' => 0, 'cost' => 0.0];

    public function __construct(
        private UserBudgetRepository $repository,
        private BudgetUsageWindowsInterface $usageWindows,
    ) {}

    public function forUser(int $beUserUid, LocalizedNumberFormatter $formatter): UserBudgetStatus
    {
        if ($beUserUid <= 0) {
            return new UserBudgetStatus(
                false,
                BudgetWindowStatus::fromUsage(self::NO_USAGE, 0, 0, 0.0, $formatter),
                BudgetWindowStatus::fromUsage(self::NO_USAGE, 0, 0, 0.0, $formatter),
            );
        }

        $now        = new DateTimeImmutable();
        $dayStart   = $now->setTime(0, 0, 0)->getTimestamp();
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0)->getTimestamp();

        $windows = $this->usageWindows->aggregate($beUserUid, $dayStart, $monthStart, $now->getTimestamp());

        $budget = $this->repository->findOneByBeUser($beUserUid);
        // The same three conditions under which check() allows every call.
        if (!$budget instanceof UserBudget || !$budget->isActive() || !$budget->hasAnyLimit()) {
            return new UserBudgetStatus(
                false,
                BudgetWindowStatus::fromUsage($windows['daily'], 0, 0, 0.0, $formatter),
                BudgetWindowStatus::fromUsage($windows['monthly'], 0, 0, 0.0, $formatter),
            );
        }

        return new UserBudgetStatus(
            true,
            BudgetWindowStatus::fromUsage(
                $windows['daily'],
                $budget->getMaxRequestsPerDay(),
                $budget->getMaxTokensPerDay(),
                $budget->getMaxCostPerDay(),
                $formatter,
            ),
            BudgetWindowStatus::fromUsage(
                $windows['monthly'],
                $budget->getMaxRequestsPerMonth(),
                $budget->getMaxTokensPerMonth(),
                $budget->getMaxCostPerMonth(),
                $formatter,
            ),
        );
    }
}
