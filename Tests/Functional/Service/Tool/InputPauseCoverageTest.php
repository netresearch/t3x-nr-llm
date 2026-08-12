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
 * The answer today is still none, and the assertion is unchanged. What changed
 * is why it is here.
 *
 * It used to stand in for a missing gate: `ResumeCoordinator::submitInput()`
 * authorised the submitter with `agent_approve` and nothing else while
 * `ToolLoopService::resumeWithInput()` executed the pending calls under the RUN
 * OWNER's context (ADR-083), so a non-admin could satisfy an admin-only tool
 * that then ran on the owner's authority (#649/#690). ADR-150 closed both
 * halves — the submitter is now checked against every pending call and the
 * declared input tool, and a submission must name the turn its form was
 * rendered from — so the gap is no longer what makes an entry here dangerous.
 *
 * The list stays empty and stays asserted because shipping the FIRST tool that
 * pauses a run for input is still a product decision, not bookkeeping: it turns
 * an untrusted, user-supplied payload into tool arguments on a path that
 * executes under someone else's identity. Adding a name means someone weighed
 * that for that tool. The two questions the entry used to commit you to are
 * answered by ADR-150; what remains is whether THIS tool should be able to
 * pause a run at all.
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
            "A tool can now suspend a run for input. The submitter gate and the turn binding exist\n"
            . "(ADR-150), so this is no longer a latent hole — but an input pause turns an untrusted\n"
            . "payload into tool arguments on a path that executes under the run OWNER's identity\n"
            . "(ADR-083). Decide that this tool should be able to do that before listing it here:\n"
            . implode("\n", $inputRequiring),
        );
    }
}
