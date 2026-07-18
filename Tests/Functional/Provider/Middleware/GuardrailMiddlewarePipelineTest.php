<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider\Middleware;

use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\ProviderContentFilterGuardrail;
use Netresearch\NrLlm\Service\Guardrail\SecretRedactionGuardrail;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Verifies the `nr_llm.guardrail` tag discovers the shipped guardrails and wires
 * them into the GuardrailMiddleware via the autowired tagged iterator — the
 * end-to-end DI proof that a guardrail is active simply by existing under
 * Classes/ (ADR-085).
 */
#[CoversClass(GuardrailMiddleware::class)]
final class GuardrailMiddlewarePipelineTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function shippedGuardrailsAreDiscoveredViaTheTag(): void
    {
        $middleware = $this->get(GuardrailMiddleware::class);
        self::assertInstanceOf(GuardrailMiddleware::class, $middleware);

        $property   = (new ReflectionClass($middleware))->getProperty('guardrails');
        $guardrails = $property->getValue($middleware);

        $classes = [];
        self::assertIsIterable($guardrails);
        foreach ($guardrails as $guardrail) {
            self::assertInstanceOf(GuardrailInterface::class, $guardrail);
            $classes[] = $guardrail::class;
        }

        self::assertContains(SecretRedactionGuardrail::class, $classes);
        self::assertContains(ProviderContentFilterGuardrail::class, $classes);
    }
}
