<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Enum\RequestShape;
use Netresearch\NrLlm\Domain\ValueObject\ProviderCallUsage;
use Netresearch\NrLlm\Domain\ValueObject\RequestFacts;
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

    /**
     * ADR-174 states the two writers resolve collisions in OPPOSITE directions,
     * and both docblocks say so. Neither rule was asserted anywhere, and both
     * are silent when broken: the facts and the usage would simply describe a
     * different call than the one the row claims.
     *
     * The facts keep the FIRST write. They describe the request the caller
     * made, and the manager records them once per call — but the method is on
     * the `@api` surface, so a second writer is reachable from outside this
     * extension, and this is the answer it gets.
     */
    #[Test]
    public function theFirstRequestFactsWriteIsTheOneThatSurvives(): void
    {
        $signals = new TelemetrySignals();
        $first   = new RequestFacts(4, 3, 1, 400, 120, RequestShape::MULTI_TURN->value);

        $signals->recordRequestFacts($first);
        $signals->recordRequestFacts(new RequestFacts(9, 9, 9, 9000, 9000, RequestShape::TOOL_ASSISTED->value));

        self::assertSame($first, $signals->requestFacts);
    }

    /**
     * The usage keeps the LAST write, and that is not symmetry for its own
     * sake: on a fallback swap the sibling's response is the one that was
     * served, so its tokens are the ones that were spent.
     */
    #[Test]
    public function theLastCallUsageWriteIsTheOneThatSurvives(): void
    {
        $signals = new TelemetrySignals();
        $served  = new ProviderCallUsage(1200, 340, 0.02, 'fallback-model');

        $signals->recordCallUsage(new ProviderCallUsage(10, 5, 0.001, 'primary-model'));
        $signals->recordCallUsage($served);

        self::assertSame($served, $signals->callUsage);
    }
}
