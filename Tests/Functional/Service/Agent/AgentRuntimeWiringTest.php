<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

/**
 * The write fence is only as real as its wiring (ADR-141).
 *
 * Every other test in this suite builds {@see AgentRuntime} by hand, which
 * proves the mechanism and says nothing about the container. The fence's whole
 * guarantee rests on one collaborator being present in production: without a
 * {@see ToolEffectResolver} every tool reads as READ_ONLY, so no write is ever
 * fenced AND none is ever refused — the axis fails OPEN, silently, and every
 * hand-wired test still passes.
 *
 * The constructor argument is optional (it exists for the positional test
 * wiring), so nothing in the type system says production gets one. This asks
 * the container instead of assuming.
 */
#[CoversClass(AgentRuntime::class)]
final class AgentRuntimeWiringTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function theAutowiredRuntimeCanClassifyAToolsEffect(): void
    {
        $runtime = $this->getService(AgentRuntimeInterface::class);
        self::assertInstanceOf(AgentRuntime::class, $runtime);

        // White-box on purpose: the claim under test is a DI fact, the argument
        // is a promoted constructor property with no getter, and asserting it
        // through behaviour would need a scripted provider for a question that
        // is not about behaviour at all.
        $resolver = new ReflectionProperty(AgentRuntime::class, 'toolEffectResolver');

        self::assertInstanceOf(
            ToolEffectResolver::class,
            $resolver->getValue($runtime),
            'Without this collaborator the write fence and the fail-closed write audit both degrade to no-ops.',
        );
    }
}
