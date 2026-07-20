<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized;

use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Tests\Fixture\CapturingMiddleware;

/**
 * Shared assertion for the specialized-service pipeline-routing tests (ADR-097).
 *
 * Every specialized service (DALL·E, FAL, Whisper, TTS, DeepL) wraps its HTTP
 * dispatch in a MiddlewarePipeline run with a service-scoped
 * {@see ProviderCallContext}. Each service test drives one such call through a
 * {@see CapturingMiddleware} and asserts the captured context here, so the
 * identical five-line expectation lives once instead of per test file.
 */
trait PipelineRoutingAssertionTrait
{
    private function assertRoutedThroughPipeline(
        CapturingMiddleware $capture,
        ProviderOperation $expectedOperation,
    ): void {
        $captured = $capture->captured;
        self::assertInstanceOf(ProviderCallContext::class, $captured);
        self::assertSame($expectedOperation, $captured->operation);
        self::assertNotSame('', $captured->telemetryProvider());
        self::assertNotSame('', $captured->correlationId);
    }
}
