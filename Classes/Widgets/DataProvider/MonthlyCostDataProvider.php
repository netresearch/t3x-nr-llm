<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Widgets\DataProvider;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;

/**
 * Data provider for the "AI cost this month" NumberWithIcon widget.
 *
 * Returns the rounded integer dollar total for the current calendar month
 * aggregated from tx_nrllm_service_usage. Fractions of a dollar are
 * truncated deliberately — the dashboard tile is an at-a-glance indicator,
 * not an accounting source. Precise figures belong in the usage report.
 */
final readonly class MonthlyCostDataProvider implements NumberWithIconDataProviderInterface
{
    public function __construct(
        private UsageTrackerServiceInterface $usageTracker,
    ) {}

    public function getNumber(): int
    {
        return (int)floor($this->usageTracker->getCurrentMonthCost());
    }
}
