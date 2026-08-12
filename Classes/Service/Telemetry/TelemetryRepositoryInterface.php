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
     * The most recent runs another configuration answered for, newest first,
     * capped at $limit.
     *
     * "Another configuration" is the narrowing: `fallback_attempts > 0` alone
     * also matches a chain that was exhausted with nobody serving, and a row
     * written before the `served_*` columns existed. Both name no serving
     * sibling and must not reach $limit — an outage produces them in bulk and
     * would otherwise crowd a period's real rescues out of the window.
     * Classifying the rows that do arrive stays with the reader
     * ({@see \Netresearch\NrLlm\Service\Analytics\FallbackRescueReport}), which
     * is where the rule is stated in domain terms.
     *
     * @return list<FallbackHop>
     */
    public function recentFallbackHops(int $since, int $limit): array;

    /**
     * The most recent runs whose model was chosen automatically, newest first,
     * capped at $limit (ADR-156).
     *
     * "Chosen automatically" is the narrowing: a row with an empty
     * `routing_policy_mode` recorded no decision — fixed mode names its own
     * model, a service path resolves no configuration, and a row written before
     * the columns existed says nothing either way. None of them belong in a
     * readout of decisions, and none of them may consume $limit.
     *
     * @return list<RoutedCall>
     */
    public function recentRoutedCalls(int $since, int $limit): array;

    /**
     * Every provider call that carried this correlation id, oldest first — the
     * calls of one agent run (ADR-153), whose uuid IS that id.
     *
     * Empty for an unknown or empty id: '' is the "no trace" marker, never a
     * bucket to read back. Like every read here the rows are metadata only.
     *
     * @return list<TelemetryCall>
     */
    public function findByCorrelation(string $correlationId): array;
}
