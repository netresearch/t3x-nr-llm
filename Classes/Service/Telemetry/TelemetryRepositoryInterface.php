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
 * Append one row, purge old rows, or read a small set of dashboard aggregates.
 * Telemetry stays append-only — there is no update path; the read methods only
 * ever aggregate, never expose a single row's payload.
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
}
