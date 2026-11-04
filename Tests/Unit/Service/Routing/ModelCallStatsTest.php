<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Routing;

use Netresearch\NrLlm\Service\Routing\ModelCallStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModelCallStats::class)]
final class ModelCallStatsTest extends TestCase
{
    #[Test]
    public function theCostCountsOnlyThePricedCalls(): void
    {
        // Twenty calls are enough to trust the latency; four priced ones are
        // not enough to trust the cost.
        $stats = new ModelCallStats(20, 1500.0, ModelCallStats::MIN_SAMPLES - 1, 0.01);

        self::assertSame(1500.0, $stats->latencyMs());
        self::assertNull($stats->costPerCall());
    }

    #[Test]
    public function aFigureCountsFromTheMinimumOn(): void
    {
        $stats = new ModelCallStats(ModelCallStats::MIN_SAMPLES, 1500.0, ModelCallStats::MIN_SAMPLES, 0.01);

        self::assertSame(1500.0, $stats->latencyMs());
        self::assertSame(0.01, $stats->costPerCall());
    }

    #[Test]
    public function theKeyKeepsItsThreePartsApart(): void
    {
        self::assertNotSame(ModelCallStats::key('a', 'bc', 'chat'), ModelCallStats::key('ab', 'c', 'chat'));
    }
}
