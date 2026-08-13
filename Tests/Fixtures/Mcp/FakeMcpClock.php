<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Mcp;

use Netresearch\NrLlm\Service\Tool\Mcp\McpClockInterface;

/**
 * A monotonic clock the test moves by hand.
 *
 * The deadline arithmetic is about elapsed seconds, and the only other way to
 * assert it is to sleep — which makes the suite slow and, worse, flaky on a
 * loaded runner exactly where the numbers are tight.
 *
 * The origin is deliberately not zero. A deadline that accidentally treated the
 * reading as an absolute duration rather than as one half of a difference would
 * pass against a clock starting at 0 and fail here.
 */
final class FakeMcpClock implements McpClockInterface
{
    private int $nanoseconds = 4_242_000_000_000;

    public function monotonicNanoseconds(): int
    {
        return $this->nanoseconds;
    }

    /**
     * Move the clock forward. Fractions are allowed: sub-second remainders are
     * where the leg floor is decided.
     */
    public function advanceSeconds(float $seconds): void
    {
        $this->nanoseconds += (int)round($seconds * 1_000_000_000);
    }
}
