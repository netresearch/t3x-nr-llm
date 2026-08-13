<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

/**
 * The production clock: PHP's monotonic timer (ADR-170).
 *
 * The same source {@see McpHttpTransport} already measures a round trip with,
 * so the latency an operator is shown and the budget a leg is granted cannot
 * disagree about how long something took.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class McpMonotonicClock implements McpClockInterface
{
    public function monotonicNanoseconds(): int
    {
        // int on any 64-bit build; the cast is what makes the 32-bit float
        // return a type the arithmetic below can rely on.
        return (int)hrtime(true);
    }
}
