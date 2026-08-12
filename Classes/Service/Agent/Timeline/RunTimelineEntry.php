<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Timeline;

/**
 * One line of a run's timeline (ADR-153): a persisted step, a provider call it
 * caused, or a governance decision taken during it.
 *
 * Metadata only. `detail` is assembled from an allow-list of non-content keys
 * by {@see RunTimelineFactory}, so the view cannot render a transcript even if
 * the installation raised the privacy level and the stored payload does carry
 * one.
 */
final readonly class RunTimelineEntry
{
    /** A persisted `tx_nrllm_agentrun_event` row. */
    public const SOURCE_STEP = 'step';

    /** A `tx_nrllm_telemetry` row carrying the run's correlation id. */
    public const SOURCE_CALL = 'call';

    /** A `tx_nrllm_governance_event` row belonging to the run. */
    public const SOURCE_GOVERNANCE = 'governance';

    /** The row states no outcome (a request step, an assembled prompt). */
    public const OUTCOME_NONE = '';

    public const OUTCOME_OK = 'ok';

    public const OUTCOME_FAILED = 'failed';

    /**
     * @param string $source     one of the SOURCE_* constants; the view translates it
     * @param string $kind       the row's own kind (step kind, operation, or decision)
     * @param int    $occurredAt UNIX timestamp the row was written
     * @param int    $sequence   the step sequence within the run; -1 for rows that have none,
     *                           which also orders them after a step of the same second
     * @param int    $round      the loop round, 0 when the row has none
     * @param float  $durationMs 0.0 when the row has none
     * @param string $detail     `key=value` metadata pairs, already joined for display
     * @param string $outcome    one of the OUTCOME_* constants — a string rather than a
     *                           nullable bool because the view has to tell "no outcome"
     *                           apart from "failed", which a Fluid truth test cannot
     */
    public function __construct(
        public string $source,
        public string $kind,
        public int $occurredAt,
        public int $sequence,
        public int $round,
        public float $durationMs,
        public string $detail,
        public string $outcome = self::OUTCOME_NONE,
    ) {}
}
