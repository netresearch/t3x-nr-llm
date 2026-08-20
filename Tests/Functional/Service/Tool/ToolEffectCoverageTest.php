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
 * This test is the reminder. It pins the current answer, so a tool that starts
 * or stops writing forces a conscious edit here, and with it a look at the fence
 * in the run executor and at the retry decision that reads the persisted effect.
 * The list was empty until `update_page_metadata` landed (ADR-135) — that is the
 * edit this test was built to demand.
 */
#[CoversClass(ToolEffectResolver::class)]
final class ToolEffectCoverageTest extends AbstractFunctionalTestCase
{
    /**
     * Tools known to mutate something.
     *
     * Adding a name here is a decision, not bookkeeping — see the class
     * docblock. `update_page_metadata` is the first entry (ADR-135): it sets a
     * fixed allow-list of descriptive fields on one page through the
     * DataHandler, which converges on repeat, hence IDEMPOTENT_WRITE.
     * `set_file_alternative_text` is the second, on the same terms: one named
     * scalar field on one `sys_file_metadata` record.
     *
     * The three from ADR-146 split on the retry question rather than following
     * the first two: `move_content_element` relocates a record that keeps its
     * uid, so a repeat lands it in the same place (IDEMPOTENT_WRITE), while
     * `create_content_element_draft` and `create_translation_draft` bring a
     * record into being and cannot be repeated without either doubling it or
     * discarding work (NON_IDEMPOTENT_WRITE).
     *
     * @var list<string>
     */
    private const DECLARED_WRITERS = [
        'create_content_element_draft',
        'create_page_draft',
        'create_translation_draft',
        'move_content_element',
        'set_file_alternative_text',
        'update_page_metadata',
    ];

    #[Test]
    public function onlyToolsListedAsWritersResolveToAWriteEffect(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        self::assertNotSame([], $registry->builtinNames(), 'The builtin set must not be empty, or this test proves nothing.');

        $resolver = new ToolEffectResolver($registry);

        $writers = [];
        foreach ($registry->builtinNames() as $name) {
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
