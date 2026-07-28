<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

/**
 * The real-DI security wiring (ADR-118): resolves the tool loop from the
 * container and asserts its REQUIRED security collaborators are the REAL
 * production implementations — the composite ToolCallPolicy (itself composed
 * over the real AllowedToolsResolver) and the JsonSchemaValidator.
 *
 * This is the load-bearing guard against a Services.yaml regression silently
 * swapping a security collaborator for a lean test stand-in (the
 * Testing\NullToolCallPolicy has no trust-zone axis and must never reach
 * production) while every isolated unit test — which wires the
 * Testing\ToolLoopBuilder — stays green.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceWiringTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function containerWiresTheRealSecurityCollaborators(): void
    {
        $service = $this->get(ToolLoopServiceInterface::class);
        self::assertInstanceOf(ToolLoopService::class, $service);

        // The gate is the real composite policy (ADR-094), not a test stand-in.
        $policy = (new ReflectionProperty(ToolLoopService::class, 'toolPolicy'))->getValue($service);
        self::assertInstanceOf(ToolCallPolicy::class, $policy);

        // ... and that policy composes the real per-configuration resolver.
        $resolver = (new ReflectionProperty(ToolCallPolicy::class, 'allowedTools'))->getValue($policy);
        self::assertInstanceOf(AllowedToolsResolver::class, $resolver);

        // The resume-input re-validation is armed.
        $validator = (new ReflectionProperty(ToolLoopService::class, 'schemaValidator'))->getValue($service);
        self::assertInstanceOf(JsonSchemaValidator::class, $validator);
    }
}
