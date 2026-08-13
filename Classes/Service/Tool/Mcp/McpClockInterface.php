<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

/**
 * The one clock reading an MCP operation deadline is measured against
 * (ADR-170).
 *
 * Monotonic, not wall clock. A deadline asks "how much of the budget is left",
 * and a wall clock answers that wrongly whenever the system time is stepped
 * mid-operation — an NTP correction during a slow handshake would either hand
 * the last leg a budget nobody granted or declare it exhausted while seconds
 * remain. That is also why {@see \Psr\Clock\ClockInterface} is not used: it
 * returns a `DateTimeImmutable`, which is a wall-clock instant by construction.
 *
 * The extension's other time seam — the request-pinned `date` aspect that
 * {@see McpHealthRecorder} stamps rows with — cannot serve either: it is
 * constant for the whole request, so every reading inside one operation would
 * be the same and nothing would ever expire.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface McpClockInterface
{
    /**
     * A monotonic reading in nanoseconds.
     *
     * Only differences between two readings are meaningful; the origin is
     * arbitrary and is not a date.
     */
    public function monotonicNanoseconds(): int;
}
