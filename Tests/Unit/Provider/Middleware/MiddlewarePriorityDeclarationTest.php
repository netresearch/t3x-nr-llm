<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Provider\Middleware\CircuitBreakerMiddleware;
use Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\IdempotencyMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Guards HOW the pipeline order is declared, which
 * {@see MiddlewarePipelineOrderTest} cannot: that test asserts the resulting
 * order, and it passes with either declaration style — right up until the
 * container stops honouring tag attributes, at which point the order silently
 * inverts and an unredacted response reaches the idempotency cache (ADR-085).
 *
 * The attribute form is unsafe here because ProviderMiddlewareInterface
 * carries the same tag: the container sees it twice per middleware and
 * deduplicates, and the survivor is the interface's priority-less
 * declaration. symfony/dependency-injection 7.4.16 made that path live
 * (symfony/symfony#65120) and unsorted the whole stack.
 */
final class MiddlewarePriorityDeclarationTest extends AbstractUnitTestCase
{
    /**
     * @return array<string, array{class-string, int}>
     */
    public static function middlewareProvider(): array
    {
        return [
            'telemetry'       => [TelemetryMiddleware::class, 110],
            'idempotency'     => [IdempotencyMiddleware::class, 105],
            'cache'           => [CacheMiddleware::class, 100],
            'guardrail'       => [GuardrailMiddleware::class, 90],
            'budget'          => [BudgetMiddleware::class, 75],
            'fallback'        => [FallbackMiddleware::class, 50],
            'usage'           => [UsageMiddleware::class, 25],
            'circuit breaker' => [CircuitBreakerMiddleware::class, 20],
        ];
    }

    /**
     * @param class-string $middleware
     */
    #[Test]
    #[DataProvider('middlewareProvider')]
    public function priorityIsDeclaredInCodeAndNotAsATagAttribute(string $middleware, int $expected): void
    {
        $reflection = new ReflectionClass($middleware);

        self::assertTrue(
            $reflection->hasMethod('getDefaultPriority'),
            $middleware . ' must declare getDefaultPriority(); the tagged iterator reads the order from it.',
        );

        $method = $reflection->getMethod('getDefaultPriority');

        // Symfony rejects a non-static or non-public method outright, so these
        // two assertions are the difference between a wired pipeline and a
        // container that refuses to compile.
        self::assertTrue($method->isStatic(), $middleware . '::getDefaultPriority() must be static.');
        self::assertTrue($method->isPublic(), $middleware . '::getDefaultPriority() must be public.');
        self::assertSame($expected, $middleware::getDefaultPriority());

        foreach ($reflection->getAttributes(AutoconfigureTag::class) as $attribute) {
            $arguments = $attribute->getArguments();
            $tagName   = $arguments['name'] ?? $arguments[0] ?? null;

            if ($tagName !== ProviderMiddlewareInterface::TAG_NAME) {
                continue;
            }

            /** @var array<string, mixed> $tagAttributes */
            $tagAttributes = $arguments['attributes'] ?? $arguments[1] ?? [];

            self::assertArrayNotHasKey(
                'priority',
                $tagAttributes,
                $middleware . ' declares its priority as a tag attribute. An attribute priority wins over '
                . 'getDefaultPriority() and is dropped when the interface-level tag survives deduplication, '
                . 'which unsorts the pipeline without failing anything else.',
            );
        }
    }
}
