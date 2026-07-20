<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
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

    #[Test]
    public function forConfigurationBindsTheEntityAndTelemetryReadsItOverTheStrings(): void
    {
        $config = new LlmConfiguration();
        $config->setIdentifier('editorial');

        $context = ProviderCallContext::forConfiguration(ProviderOperation::Chat, $config);

        self::assertSame($config, $context->configuration);
        // The entity is the source of truth for telemetry while it is present.
        self::assertSame('editorial', $context->telemetryConfigurationIdentifier());
    }

    #[Test]
    public function forServiceCarriesProviderModelStringsForACallWithoutAConfigurationEntity(): void
    {
        // The enabling case (ADR-096): an image/speech/translation call with no
        // LlmConfiguration entity still populates telemetry from the strings.
        $context = ProviderCallContext::forService(ProviderOperation::ImageGeneration, 'dall-e', 'gpt-image-2', 'image-default');

        self::assertNull($context->configuration);
        self::assertSame('dall-e', $context->telemetryProvider());
        self::assertSame('gpt-image-2', $context->telemetryModel());
        self::assertSame('image-default', $context->telemetryConfigurationIdentifier());
    }

    #[Test]
    public function withConfigurationSwapsTheEntityButKeepsCorrelationAndSignals(): void
    {
        $primary  = (new LlmConfiguration());
        $primary->setIdentifier('primary');
        $fallback = (new LlmConfiguration());
        $fallback->setIdentifier('fallback');

        $base = ProviderCallContext::forConfiguration(ProviderOperation::Chat, $primary);
        $base->telemetrySignals->recordFallbackAttempt();

        $swapped = $base->withConfiguration($fallback);

        self::assertSame($fallback, $swapped->configuration);
        self::assertSame($base->correlationId, $swapped->correlationId);
        // Fallback substitution mid-run must keep the accumulated signals.
        self::assertSame($base->telemetrySignals, $swapped->telemetrySignals);
        self::assertSame(1, $swapped->telemetrySignals->fallbackAttempts);
    }
}
