<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Routing;

/**
 * One candidate's call-history signals, each 0–1 and higher is better, null
 * where the candidate has no trustworthy figure (ADR-256).
 *
 * @internal
 */
final readonly class CallHistorySignals
{
    public function __construct(
        public ?float $latency,
        // Whether any candidate of this decision has a latency figure — the
        // same answer for every candidate. Without one the signal is not
        // collected at all.
        public bool $latencyIsMeasured,
        // Whether this decision ranks cost by the measured cost per call rather
        // than the list price — the same answer for every candidate.
        public bool $costIsMeasured,
        public ?float $callCost,
    ) {}
}
