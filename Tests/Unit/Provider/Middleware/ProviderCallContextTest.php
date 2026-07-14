<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\TelemetrySignals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderCallContext::class)]
final class ProviderCallContextTest extends TestCase
{
    #[Test]
    public function forGeneratesRfc4122CorrelationId(): void
    {
        $context = ProviderCallContext::for(ProviderOperation::Chat);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $context->correlationId,
        );
    }

    #[Test]
    public function eachContextGetsItsOwnFreshSignalSink(): void
    {
        $a = ProviderCallContext::for(ProviderOperation::Chat);
        $b = ProviderCallContext::for(ProviderOperation::Chat);

        // The `new TelemetrySignals()` default must be evaluated per call, not
        // shared — otherwise one run's cache hit would bleed into the next.
        self::assertNotSame($a->telemetrySignals, $b->telemetrySignals);

        $a->telemetrySignals->recordCacheHit();
        self::assertTrue($a->telemetrySignals->cacheHit);
        self::assertFalse($b->telemetrySignals->cacheHit);
    }

    #[Test]
    public function withMetadataMergesAndKeepsTheSameSignalSink(): void
    {
        $base = new ProviderCallContext(
            operation: ProviderOperation::Embedding,
            correlationId: 'corr-1',
            metadata: ['a' => 1],
        );
        $base->telemetrySignals->recordFallbackAttempt();

        $derived = $base->withMetadata(['b' => 2]);

        self::assertSame(['a' => 1, 'b' => 2], $derived->metadata);
        self::assertSame('corr-1', $derived->correlationId);
        // A context re-derived mid-run must carry the SAME signal sink so
        // signals collected against the original survive.
        self::assertSame($base->telemetrySignals, $derived->telemetrySignals);
        self::assertSame(1, $derived->telemetrySignals->fallbackAttempts);
    }

    #[Test]
    public function defaultSignalSinkReadsAsNothingHappened(): void
    {
        $context = ProviderCallContext::for(ProviderOperation::Vision);

        self::assertInstanceOf(TelemetrySignals::class, $context->telemetrySignals);
        self::assertFalse($context->telemetrySignals->cacheHit);
        self::assertSame(0, $context->telemetrySignals->fallbackAttempts);
    }
}
