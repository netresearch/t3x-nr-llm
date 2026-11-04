<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Service\Agent\AgentRoundLimitsInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillWriterInterface;
use Netresearch\NrLlm\Service\Agent\QueuedRunCoordinator;
use Netresearch\NrLlm\Service\Agent\ResumeCoordinator;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\Vision\VisionActionBuilder;
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

    /**
     * Whichever coordinator production uses has to know the privacy level:
     * without it `privacy.level = none` would keep the answer of a background
     * run (ADR-234), and every hand-wired test would still pass.
     */
    #[Test]
    public function theCoordinatorOfTheAutowiredRuntimeKnowsThePrivacyLevel(): void
    {
        $runtime = $this->getService(AgentRuntimeInterface::class);
        self::assertInstanceOf(AgentRuntime::class, $runtime);

        $coordinator = (new ReflectionProperty(AgentRuntime::class, 'queueCoordinator'))->getValue($runtime);

        $policy = $coordinator instanceof QueuedRunCoordinator
            ? (new ReflectionProperty(QueuedRunCoordinator::class, 'privacyPolicy'))->getValue($coordinator)
            : (new ReflectionProperty(AgentRuntime::class, 'privacyPolicy'))->getValue($runtime);

        self::assertInstanceOf(PrivacyPolicyInterface::class, $policy);
    }

    /**
     * A queued vision action fails in the worker when either collaborator is
     * missing (ADR-236), which no hand-wired test would notice.
     */
    #[Test]
    public function theCoordinatorOfTheAutowiredRuntimeCanReadAQueuedImage(): void
    {
        $runtime = $this->getService(AgentRuntimeInterface::class);
        self::assertInstanceOf(AgentRuntime::class, $runtime);

        $coordinator = (new ReflectionProperty(AgentRuntime::class, 'queueCoordinator'))->getValue($runtime);

        if ($coordinator instanceof QueuedRunCoordinator) {
            self::assertInstanceOf(VisionActionBuilder::class, (new ReflectionProperty(QueuedRunCoordinator::class, 'vision'))->getValue($coordinator));
            self::assertInstanceOf(ActingBackendUserResolverInterface::class, (new ReflectionProperty(QueuedRunCoordinator::class, 'actingUsers'))->getValue($coordinator));

            return;
        }

        self::assertInstanceOf(VisionActionBuilder::class, (new ReflectionProperty(AgentRuntime::class, 'visionActionBuilder'))->getValue($runtime));
        self::assertInstanceOf(FieldFillWriterInterface::class, (new ReflectionProperty(AgentRuntime::class, 'fieldFillWriter'))->getValue($runtime));
        self::assertInstanceOf(ActingBackendUserResolverInterface::class, (new ReflectionProperty(AgentRuntime::class, 'actingBackendUserResolver'))->getValue($runtime));
    }

    /**
     * The round limits are optional in every signature and fall back to 5 and
     * 20, the shipped values, so a collaborator the container did not wire
     * would look right on a default installation (ADR-101, amendment of
     * 2026-11-04). Ask the container for each place that reads them.
     */
    #[Test]
    public function everyReaderOfTheRoundLimitsGetsThemFromTheContainer(): void
    {
        $runtime = $this->getService(AgentRuntimeInterface::class);
        self::assertInstanceOf(AgentRuntime::class, $runtime);

        $executor = (new ReflectionProperty(AgentRuntime::class, 'runExecutor'))->getValue($runtime);
        $resume   = (new ReflectionProperty(AgentRuntime::class, 'resumeCoordinator'))->getValue($runtime);

        self::assertInstanceOf(AgentRoundLimitsInterface::class, $executor instanceof AgentRunExecutor
            ? (new ReflectionProperty(AgentRunExecutor::class, 'roundLimits'))->getValue($executor)
            : (new ReflectionProperty(AgentRuntime::class, 'roundLimits'))->getValue($runtime));
        self::assertInstanceOf(AgentRoundLimitsInterface::class, $resume instanceof ResumeCoordinator
            ? (new ReflectionProperty(ResumeCoordinator::class, 'roundLimits'))->getValue($resume)
            : (new ReflectionProperty(AgentRuntime::class, 'roundLimits'))->getValue($runtime));

        $loop = $this->getService(ToolLoopService::class);
        self::assertInstanceOf(AgentRoundLimitsInterface::class, (new ReflectionProperty(ToolLoopService::class, 'roundLimits'))->getValue($loop));
    }
}
