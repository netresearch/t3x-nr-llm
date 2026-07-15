<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Health;

use Netresearch\NrLlm\Service\Health\ProviderHealthScore;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ProviderHealthScore::class)]
final class ProviderHealthScoreTest extends AbstractUnitTestCase
{
    #[Test]
    public function unknownProviderIsNeutralNotUnhealthy(): void
    {
        $score = ProviderHealthScore::unknown('openai');

        self::assertSame('openai', $score->provider);
        self::assertSame(0, $score->sampleCount);
        self::assertSame(ProviderHealthScore::NEUTRAL_SCORE, $score->score);
    }

    #[Test]
    public function zeroSamplesFallBackToUnknown(): void
    {
        $score = ProviderHealthScore::fromSamples('openai', 0, 0, 0.0);

        self::assertSame(0, $score->sampleCount);
        self::assertSame(ProviderHealthScore::NEUTRAL_SCORE, $score->score);
    }

    #[Test]
    public function perfectHealthScoresOne(): void
    {
        // All successful, no latency → full score on both terms.
        $score = ProviderHealthScore::fromSamples('openai', 5, 5, 0.0);

        self::assertSame(1.0, $score->successRate);
        self::assertEqualsWithDelta(1.0, $score->score, 0.0001);
    }

    #[Test]
    public function successRateDominatesLatency(): void
    {
        // 90% success, low latency.
        $score = ProviderHealthScore::fromSamples('openai', 10, 9, 200.0);

        self::assertEqualsWithDelta(0.9, $score->successRate, 0.0001);
        self::assertEqualsWithDelta(200.0, $score->avgLatencyMs, 0.0001);
        // 0.8*0.9 + 0.2*(1 - 200/5000) = 0.72 + 0.192 = 0.912
        self::assertEqualsWithDelta(0.912, $score->score, 0.0001);
    }

    #[Test]
    public function allFailuresScoreLowEvenWhenFast(): void
    {
        // 0% success but fast: the failure weight (0.8) sinks it well below a
        // healthy provider, so it will never be preferred.
        $score = ProviderHealthScore::fromSamples('groq', 4, 0, 1000.0);

        self::assertSame(0.0, $score->successRate);
        // 0.8*0 + 0.2*(1 - 1000/5000) = 0.2*0.8 = 0.16
        self::assertEqualsWithDelta(0.16, $score->score, 0.0001);
    }

    #[Test]
    public function latencyPenaltyClampsAtTheCeiling(): void
    {
        // Latency far beyond the ceiling contributes nothing further.
        $score = ProviderHealthScore::fromSamples('slow', 2, 2, 50_000.0);

        // 0.8*1 + 0.2*(1 - min(1, 10)) = 0.8 + 0 = 0.8
        self::assertEqualsWithDelta(0.8, $score->score, 0.0001);
    }
}
