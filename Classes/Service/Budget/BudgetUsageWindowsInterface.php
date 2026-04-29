<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Budget;

/**
 * Aggregates per-backend-user AI usage across daily and monthly windows.
 *
 * `BudgetService::check()` reads two roll-ups (current day, current month)
 * from `tx_nrllm_service_usage` to decide whether the next call would
 * push the user past their cap. Extracting the aggregation behind this
 * interface lets `BudgetService` be `final readonly` while tests still
 * substitute the DB read with a canned response.
 */
interface BudgetUsageWindowsInterface
{
    /**
     * Aggregate per-user usage for the daily AND monthly windows in a
     * single DB roundtrip.
     *
     * Pass `null` for a window when its limit is not configured — the
     * implementation MUST return a zeroed bucket for that window so the
     * caller does not have to special-case missing data.
     *
     * @return array{
     *   daily: array{requests: int, tokens: int, cost: float},
     *   monthly: array{requests: int, tokens: int, cost: float}
     * }
     */
    public function aggregate(
        int $beUserUid,
        ?int $dailyFromTimestamp,
        ?int $monthlyFromTimestamp,
        int $toTimestamp,
    ): array;
}
