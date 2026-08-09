<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\RequiresInputInterface;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which registered tools can suspend a run for operator input (ADR-105).
 *
 * The answer today is none, and that is what makes an open authorisation gap
 * on the input path harmless (#649). `ResumeCoordinator::submitInput()`
 * authorises the submitter with `agent_approve` and nothing else — it never
 * checks them against the tool whose input they supply — while
 * `ToolLoopService::resumeWithInput()` executes the pending calls under the RUN
 * OWNER's context (ADR-083). A non-admin could therefore satisfy an admin-only
 * tool that then runs on the owner's authority: the same confused deputy the
 * approval path closed (#622).
 *
 * It is unreachable only because nothing implements the interface. This test
 * pins that, so the first tool that does turns a latent gap into a red build
 * rather than into a shipped one.
 *
 * The gate is deliberately NOT built ahead of that tool, because #622's cannot
 * simply be copied and the difference is not a detail:
 *
 * - **No write axis.** #622 gates the calls that WRITE, since a write is what
 *   must not happen unattended. An input-requiring tool declares no effect —
 *   the two markers are mutually exclusive at registration (ADR-105) — so
 *   there is no "which calls matter" axis. Gating every input submission is a
 *   broader policy, and a different decision.
 * - **No digest.** The approval path binds a decision to the reviewed turn
 *   (ADR-132). The input path has no equivalent, so a submission cannot be
 *   shown to belong to the turn it was written for.
 *
 * Whoever makes this test fail owns both questions: on what axis the submitter
 * is gated against the tool, and whether the input path gets a turn digest of
 * its own.
 */
#[CoversClass(ToolRegistry::class)]
final class InputPauseCoverageTest extends AbstractFunctionalTestCase
{
    /**
     * Tools that suspend a run for operator input. Empty on purpose.
     *
     * Adding a name here is a decision, not bookkeeping — see the class
     * docblock for the two questions it commits you to.
     *
     * @var list<string>
     */
    private const INPUT_REQUIRING_TOOLS = [];

    #[Test]
    public function onlyToolsListedHereCanSuspendARunForInput(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);

        $builtins = $registry->builtinNames();
        // Guards the guard: an empty registry would make the assertion below
        // pass while proving nothing at all.
        self::assertNotSame([], $builtins, 'The builtin set must not be empty, or this test proves nothing.');

        $inputRequiring = [];
        foreach ($builtins as $name) {
            $tool = $registry->get($name);
            if ($tool instanceof ToolInterface && $tool instanceof RequiresInputInterface) {
                $inputRequiring[] = $name;
            }
        }

        sort($inputRequiring);

        $expected = self::INPUT_REQUIRING_TOOLS;
        sort($expected);

        self::assertSame(
            $expected,
            $inputRequiring,
            "A tool can now suspend a run for input, which makes the open authorisation gap on that path\n"
            . "reachable (#649): the submitter is authorised with agent_approve alone and never against the\n"
            . "tool, while the resume runs under the OWNER's context. Decide the gate and the digest before\n"
            . "listing it here:\n"
            . implode("\n", $inputRequiring),
        );
    }
}
