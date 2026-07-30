<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which registered tools declare a write effect (ADR-111).
 *
 * A tool that declares nothing resolves to READ_ONLY, which is the right
 * default for the forty-odd read-only builtins but is exactly the wrong one for
 * a tool that mutates. Nothing in the type system catches that: the declaration
 * is an opt-in interface, so forgetting it is silent and the run loses its
 * write fence and its fail-closed audit.
 *
 * This test is the reminder. It pins the current answer — no builtin writes —
 * so the first tool that does forces a conscious edit here, and with it a look
 * at the fence in the run executor and at the retry decision that reads the
 * persisted effect.
 */
#[CoversClass(ToolEffectResolver::class)]
final class ToolEffectCoverageTest extends AbstractFunctionalTestCase
{
    /**
     * Tools known to mutate something. Empty on purpose: every builtin reads.
     *
     * Adding a name here is a decision, not bookkeeping — see the class
     * docblock.
     *
     * @var list<string>
     */
    private const DECLARED_WRITERS = [];

    #[Test]
    public function onlyToolsListedAsWritersResolveToAWriteEffect(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        self::assertNotSame([], $registry->names(), 'The registry must not be empty, or this test proves nothing.');

        $resolver = new ToolEffectResolver($registry);

        $writers = [];
        foreach ($registry->names() as $name) {
            if ($resolver->effectFor($name)->isWrite()) {
                $writers[] = $name;
            }
        }
        sort($writers);

        $expected = self::DECLARED_WRITERS;
        sort($expected);

        self::assertSame(
            $expected,
            $writers,
            "The set of writing tools changed. A new writer must be listed here deliberately, and a tool that\n"
            . "stopped being listed has lost its write fence and its fail-closed audit silently:\n"
            . implode("\n", $writers),
        );
    }

    #[Test]
    public function anUnknownToolNameIsTreatedAsTheStrictestEffect(): void
    {
        // Fail-closed: a step naming a tool the registry no longer knows — one
        // removed or renamed between attempts — must not be judged safe to
        // retry just because it can no longer be resolved.
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);

        $resolver = new ToolEffectResolver($registry);

        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $resolver->effectFor('a_tool_that_was_removed'));
    }
}
