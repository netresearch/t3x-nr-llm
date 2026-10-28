<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use DateTimeInterface;

/**
 * Read-only reporting over tx_nrllm_service_usage for the Analytics module.
 *
 * Separate from the write-path UsageTrackerService: this never mutates the
 * table. All windows are inclusive of both bounds (midnight-aligned days).
 */
interface UsageAnalyticsServiceInterface
{
    /**
     * @return array{cost: float, requests: int, tokens: int, providers: int, models: int}
     */
    public function getKpiTotals(DateTimeInterface $from, DateTimeInterface $to): array;

    /**
     * @return list<array{date: string, cost: float, requests: int, tokens: int}>
     */
    public function getDailyTrend(DateTimeInterface $from, DateTimeInterface $to): array;

    /**
     * @return list<array{label: string, cost: float, requests: int, tokens: int}>
     */
    public function getBreakdownByProvider(DateTimeInterface $from, DateTimeInterface $to): array;

    /**
     * @return list<array{label: string, cost: float, requests: int, tokens: int}>
     */
    public function getBreakdownByModel(DateTimeInterface $from, DateTimeInterface $to): array;

    /**
     * @return list<array{label: string, cost: float, requests: int, tokens: int}>
     */
    public function getBreakdownByService(DateTimeInterface $from, DateTimeInterface $to): array;

    /**
     * Cost, requests and tokens per calling extension — the `source_extension`
     * a consumer named via `AbstractOptions::withCallerSource()` (ADR-178).
     * Calls that named nobody are grouped under `unattributed`.
     *
     * @return list<array{label: string, cost: float, requests: int, tokens: int}>
     */
    public function getBreakdownBySourceExtension(DateTimeInterface $from, DateTimeInterface $to): array;

    /**
     * Sum cost/requests/tokens grouped by an internal column, keyed by that
     * column's value. $column MUST be a hardcoded internal column name
     * (never user input): 'service_provider', 'model_uid', 'configuration_uid',
     * or 'task_uid'.
     *
     * @return array<int|string, array{cost: float, requests: int, tokens: int}>
     */
    public function getTotalsGroupedBy(string $column, DateTimeInterface $from, DateTimeInterface $to): array;

    /**
     * Sum cost/requests/tokens grouped by several internal columns at once,
     * one row per combination that occurs, ordered by cost. Each row carries
     * the grouped columns under their column names, as stored ('' and 0 kept,
     * not relabelled). $columns MUST be hardcoded internal column names from
     * the same whitelist as {@see getTotalsGroupedBy()}.
     *
     * @param non-empty-list<string> $columns
     *
     * @return list<array<string, int|float|string>>
     */
    public function getTotalsGroupedByColumns(array $columns, DateTimeInterface $from, DateTimeInterface $to): array;

    /**
     * @return list<array{
     *     beUserUid: int,
     *     label: string,
     *     cost: float,
     *     requests: int,
     *     tokens: int,
     *     budget: array{usedCost: float, limitCost: float, percent: float}|null
     * }>
     */
    public function getPerUserUsage(DateTimeInterface $from, DateTimeInterface $to): array;
}
