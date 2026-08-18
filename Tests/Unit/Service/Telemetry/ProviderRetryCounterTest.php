<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Telemetry;

use Netresearch\NrLlm\Service\Telemetry\ProviderRetryCounter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderRetryCounter::class)]
final class ProviderRetryCounterTest extends TestCase
{
    #[Test]
    public function aFreshCounterHasSeenNoRetries(): void
    {
        self::assertSame(0, (new ProviderRetryCounter())->total());
    }

    /**
     * Monotonic on purpose (ADR-174). The write sites take a difference, and a
     * difference is what makes nesting work: a tool loop runs pipeline runs
     * inside a pipeline run, and a counter that reset would make the outer row
     * report only the retries that happened after the inner one finished.
     */
    #[Test]
    public function theCounterOnlyEverGrowsSoNestedRunsBothCountCorrectly(): void
    {
        $counter = new ProviderRetryCounter();

        $outerBefore = $counter->total();
        $counter->recordRetry();

        $innerBefore = $counter->total();
        $counter->recordRetry();
        $counter->recordRetry();

        $innerDelta = $counter->total() - $innerBefore;

        $counter->recordRetry();
        $outerDelta = $counter->total() - $outerBefore;

        self::assertSame(2, $innerDelta, 'The inner run consumed two retries.');
        self::assertSame(4, $outerDelta, 'The outer run consumed four, the inner run’s two included.');
    }
}
