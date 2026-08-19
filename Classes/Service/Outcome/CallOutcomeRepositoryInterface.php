<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\CallOutcome;

/**
 * Reads and writes how a call turned out (ADR-176).
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface CallOutcomeRepositoryInterface
{
    public function record(string $correlationId, CallOutcome $outcome): void;

    /**
     * Every outcome recorded for one call, at most one per source.
     *
     * @return list<CallOutcome>
     */
    public function findByCorrelation(string $correlationId): array;

    /**
     * Drop rows created before the cutoff, returning how many went.
     *
     * Runs under the telemetry retention window rather than one of its own
     * (ADR-064): an outcome is only readable joined to the telemetry row that
     * carries its model and cost, so outliving that row would leave a rating
     * nothing can interpret, and predeceasing it would silently shrink the
     * denominator of any comparison.
     */
    public function purgeOlderThan(int $timestamp): int;
}
