<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider\Middleware;

use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Provider\Middleware\CircuitBreakerMiddleware;
use Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\IdempotencyMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Verifies that the provider middleware pipeline is assembled in the
 * documented order via the autowired tagged iterator.
 *
 * The order is security-relevant: CacheMiddleware must be the outermost
 * BEHAVIOURAL layer so that a cache hit short-circuits the pipeline before any
 * budget is consumed (CacheMiddleware / BudgetMiddleware docblocks). Only the
 * observation-only TelemetryMiddleware (ADR-058) sits further out, so measured
 * latency includes the cache lookup and a cache-served response still produces
 * a telemetry row. Symfony orders a tagged iterator by descending tag priority;
 * this test resolves the real MiddlewarePipeline from the DI container and
 * asserts the resolved order empirically, so the priority *direction* is proven
 * rather than assumed.
 */
#[CoversClass(MiddlewarePipeline::class)]
final class MiddlewarePipelineOrderTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function pipelineIsAssembledInDocumentedPriorityOrder(): void
    {
        $pipeline = $this->get(MiddlewarePipeline::class);
        self::assertInstanceOf(MiddlewarePipeline::class, $pipeline);

        $order = $this->resolveMiddlewareOrder($pipeline);

        self::assertSame(
            [
                TelemetryMiddleware::class,      // 110 outermost observer (ADR-058)
                IdempotencyMiddleware::class,    // 105 replays a stored result by key (ADR-063)
                CacheMiddleware::class,          // 100 outermost behavioural layer: a cache hit short-circuits
                GuardrailMiddleware::class,      //  90 screens/redacts the response INSIDE the persistence layers, so no unredacted response is ever stored (ADR-085)
                BudgetMiddleware::class,         //  75 pre-flight budget gate on a miss
                FallbackMiddleware::class,       //  50 swaps configuration on retryable failure
                UsageMiddleware::class,          //  25 records the served call
                CircuitBreakerMiddleware::class, //  20 innermost: guards the provider call (ADR-063)
            ],
            $order,
        );
    }

    /**
     * Read the resolved middleware list (a private property of the pipeline)
     * and return the concrete class names in pipeline order.
     *
     * @return list<class-string<ProviderMiddlewareInterface>>
     */
    private function resolveMiddlewareOrder(MiddlewarePipeline $pipeline): array
    {
        $reflection = new ReflectionClass($pipeline);
        $property   = $reflection->getProperty('middleware');
        $middleware = $property->getValue($pipeline);
        self::assertIsArray($middleware);

        $order = [];
        foreach ($middleware as $instance) {
            self::assertInstanceOf(ProviderMiddlewareInterface::class, $instance);
            $order[] = $instance::class;
        }

        return $order;
    }
}
