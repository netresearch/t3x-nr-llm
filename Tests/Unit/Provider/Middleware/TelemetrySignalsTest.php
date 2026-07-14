<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Provider\Middleware\TelemetrySignals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TelemetrySignals::class)]
final class TelemetrySignalsTest extends TestCase
{
    #[Test]
    public function freshInstanceReadsAsNothingHappened(): void
    {
        $signals = new TelemetrySignals();

        self::assertFalse($signals->cacheHit);
        self::assertSame(0, $signals->fallbackAttempts);
    }

    #[Test]
    public function recordCacheHitIsIdempotentlyTrue(): void
    {
        $signals = new TelemetrySignals();
        $signals->recordCacheHit();
        $signals->recordCacheHit();

        self::assertTrue($signals->cacheHit);
    }

    #[Test]
    public function recordFallbackAttemptCountsEachCall(): void
    {
        $signals = new TelemetrySignals();
        $signals->recordFallbackAttempt();
        $signals->recordFallbackAttempt();
        $signals->recordFallbackAttempt();

        self::assertSame(3, $signals->fallbackAttempts);
    }
}
