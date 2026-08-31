<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

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
        'attach_file_to_content_element',
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

    /**
     * ADR-182's contract invariant: a tool that declares a write effect names
     * the record it wrote, and a read-only tool never does.
     *
     * Asserted over the DECLARED-WRITER LIST rather than tool by tool, so a
     * writer added later fails here without anyone remembering this ticket —
     * which is the failure mode ADR-182 names: "a writer that forgets it reports
     * no outcome and fails no test unless the coverage test above is written to
     * require one from every declared writer".
     *
     * It reads the class source rather than executing the write, because
     * executing seven DataHandler writes to prove a return-value shape would
     * test the DataHandler. What must not silently vanish is the CALL, and the
     * loop-level assertion that the value survives the runtime lives in
     * `ToolLoopServiceTest::aWriteTargetSurvivesTheLoopIntoTheTraceAndTheRunStep()`.
     */
    #[Test]
    public function everyDeclaredWriterNamesTheRecordItWrote(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);

        $silent = [];
        foreach (self::DECLARED_WRITERS as $name) {
            $tool = $registry->get($name);
            self::assertInstanceOf(ToolInterface::class, $tool, sprintf('Declared writer "%s" is not registered.', $name));

            $file = (new ReflectionClass($tool))->getFileName();
            self::assertIsString($file);

            $source = file_get_contents($file);
            self::assertIsString($source);

            if (!str_contains($source, 'withWriteTarget(')) {
                $silent[] = $name;
            }
        }

        self::assertSame(
            [],
            $silent,
            "A tool declares a write effect but never names the record it wrote (ADR-182). Its writes cannot be\n"
            . "joined to sys_history, so they produce no observed outcome and nothing else reports the gap:\n"
            . implode("\n", $silent),
        );
    }

    /**
     * The other direction. A read-only tool that names a written record is a
     * defect, not a curiosity — it would put a write row in the audit stream for
     * a call that changed nothing.
     */
    #[Test]
    public function noReadOnlyToolClaimsAWriteTarget(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);

        $resolver = new ToolEffectResolver($registry);

        $claiming = [];
        foreach ($registry->builtinNames() as $name) {
            if ($resolver->effectFor($name)->isWrite()) {
                continue;
            }

            $tool = $registry->get($name);
            if (!$tool instanceof ToolInterface) {
                continue;
            }

            $file = (new ReflectionClass($tool))->getFileName();
            if (!is_string($file)) {
                continue;
            }

            $source = file_get_contents($file);
            if (is_string($source) && str_contains($source, 'withWriteTarget(')) {
                $claiming[] = $name;
            }
        }

        self::assertSame([], $claiming, 'A read-only tool names a written record: ' . implode(', ', $claiming));
    }
}
