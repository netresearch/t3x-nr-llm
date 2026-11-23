<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Observability\GenAiTelemetry;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One OpenTelemetry client span per attempt at a model (ADR-291).
 *
 * It sits directly inside {@see FallbackMiddleware}, so every configuration the
 * fallback walk dispatches is its own attempt with its own span, provider and
 * model, and directly outside {@see UsageMiddleware}, so the span covers the
 * usage bookkeeping the attempt causes. A response the cache or the
 * idempotency store serves never reaches this layer and gets no span: no model
 * was called.
 *
 * Which providers are covered is not this class's business: every provider
 * call of the extension passes the pipeline, so this one layer covers all
 * adapters. The streaming path does not pass the pipeline and is instrumented
 * in {@see \Netresearch\NrLlm\Service\Streaming\StreamingDispatcher}.
 *
 * With no OpenTelemetry SDK registered by the host, or with the
 * `telemetry.openTelemetry` extension setting off, the layer is a verbatim
 * pass-through. What is recorded, and what never is, is stated by
 * {@see GenAiTelemetry}.
 *
 * The registered pipeline order, pinned by tag priority:
 *
 *   TelemetryMiddleware        <-- outermost; pure observer          (priority 110)
 *     IdempotencyMiddleware    <-- replays a stored result by key    (priority 105)
 *       CacheMiddleware        <-- short-circuits on hit             (priority 100)
 *         GuardrailMiddleware  <-- screens/redacts the response      (priority 90)
 *           BudgetMiddleware   <-- pre-flight denial                 (priority 75)
 *             FallbackMiddleware <-- swaps config on retryable failure (priority 50)
 *               GenAiTracingMiddleware <-- one span per attempt      (priority 30)
 *                 UsageMiddleware  <-- records the call that ran     (priority 25)
 *                   CircuitBreaker <-- guards the provider call      (priority 20)
 *                     <terminal>
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME)]
final readonly class GenAiTracingMiddleware implements ProviderMiddlewareInterface
{
    /**
     * Pipeline priority, read by the tagged iterator via
     * `defaultPriorityMethod` (ADR-085 ordering). In code rather than in the
     * AutoconfigureTag attribute for the reason {@see MiddlewarePipeline} gives.
     */
    public static function getDefaultPriority(): int
    {
        return 30;
    }

    public function __construct(
        private GenAiTelemetry $telemetry,
    ) {}

    /**
     * @param callable(ProviderCallContext): mixed $next
     */
    public function handle(
        ProviderCallContext $context,
        callable $next,
    ): mixed {
        return $this->telemetry->traceInference($context, $next);
    }
}
