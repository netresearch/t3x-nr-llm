<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

/**
 * Persistence boundary for provider pipeline telemetry rows (ADR-058).
 *
 * Append one row, purge old rows, or read back what the dashboards need.
 * Telemetry stays append-only — there is no update path. A read either
 * aggregates or, for {@see self::recentFallbackHops()}, hands out whole rows;
 * either way it can only expose what the table holds, which is metadata (no
 * prompt, no response, no exception message).
 */
interface TelemetryRepositoryInterface
{
    /**
     * Append one telemetry row. Never throws for a caller-visible reason:
     * telemetry must not break the call it observes (see TelemetryMiddleware).
     */
    public function record(TelemetryRecord $record): void;

    /**
     * Delete rows created strictly before the given UNIX timestamp.
     *
     * @return int number of rows deleted
     */
    public function purgeOlderThan(int $timestamp): int;

    /**
     * Success rate as an integer percent (0-100) over rows created on/after
     * $since. Zero matching rows ⇒ 0.
     */
    public function successRatePercent(int $since): int;

    /**
     * Average latency_ms (rounded to an int) over rows created on/after $since.
     * Zero matching rows ⇒ 0.
     */
    public function averageLatencyMs(int $since): int;

    /**
     * The most recent runs that dispatched at least one fallback configuration
     * (`fallback_attempts > 0`), newest first, capped at $limit.
     *
     * This is the cheap narrowing only: a run that hopped may still have ended
     * with nobody serving it. Deciding which of these is a rescue is the
     * report's job, not the query's.
     *
     * @return list<FallbackHop>
     */
    public function recentFallbackHops(int $since, int $limit): array;
}
