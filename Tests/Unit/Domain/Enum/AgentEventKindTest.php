<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class AgentEventKindTest extends TestCase
{
    #[Test]
    public function valuesListsAllBackingStrings(): void
    {
        self::assertSame(['request', 'llm', 'tool', 'assembled', 'context', 'dropped', 'tool_write', 'approval', 'input'], AgentEventKind::values());
    }

    #[Test]
    public function isValidIsTrueForKnownAndFalseForUnknown(): void
    {
        self::assertTrue(AgentEventKind::isValid('llm'));
        // The operator-decision event emitted by the AgentRuntime (ADR-101).
        self::assertTrue(AgentEventKind::isValid('approval'));
        self::assertFalse(AgentEventKind::isValid('artifact'));
    }

    #[Test]
    public function fromRunStepKindMapsEveryRunStepKind(): void
    {
        self::assertSame(AgentEventKind::REQUEST, AgentEventKind::fromRunStepKind(RunStep::KIND_REQUEST));
        self::assertSame(AgentEventKind::LLM, AgentEventKind::fromRunStepKind(RunStep::KIND_LLM));
        self::assertSame(AgentEventKind::TOOL, AgentEventKind::fromRunStepKind(RunStep::KIND_TOOL));
        self::assertSame(AgentEventKind::ASSEMBLED, AgentEventKind::fromRunStepKind(RunStep::KIND_ASSEMBLED));
        // ADR-151. Persisted like the other four: the fit records it on every
        // traced run, not only in the playground.
        self::assertSame(AgentEventKind::CONTEXT, AgentEventKind::fromRunStepKind(RunStep::KIND_CONTEXT));
    }

    #[Test]
    public function fromRunStepKindReturnsNullForNonRunStepKinds(): void
    {
        // 'approval' (ADR-101) and 'input' (ADR-105) are valid EVENT kinds but
        // not RunStep kinds — they must not masquerade as one; hydrate stored
        // kinds via tryFrom().
        self::assertNull(AgentEventKind::fromRunStepKind('approval'));
        self::assertNull(AgentEventKind::fromRunStepKind('input'));
        self::assertNull(AgentEventKind::fromRunStepKind('artifact'));
    }

    /**
     * The limitation this enum states about itself, asserted rather than
     * intended (#900).
     *
     * It drifted twice without anything noticing: `dropped` has been written to
     * `tx_nrllm_agentrun_event.kind` since ADR-179 and `tool_write` since
     * ADR-182, and neither had a case. Nothing broke — the repository inserts
     * the raw string — but `AgentRunEvent::kindEnum()` answered null for both,
     * and the two functional suites that filter an event stream by typed kind
     * would have lost exactly the rows they were looking for.
     *
     * Reflection over `RunStep`'s own constants rather than a second hand-kept
     * list, because a hand-kept list is what failed.
     */
    #[Test]
    public function everyRunStepKindHasACase(): void
    {
        $missing = [];
        foreach ((new ReflectionClass(RunStep::class))->getConstants() as $name => $value) {
            if (!str_starts_with($name, 'KIND_') || !is_string($value)) {
                continue;
            }

            if (!AgentEventKind::isValid($value)) {
                $missing[] = sprintf('%s (%s)', $name, $value);
            }
        }

        self::assertSame(
            [],
            $missing,
            "A RunStep kind is persisted with no case here, so AgentRunEvent::kindEnum() answers null for it\n"
            . "and anything filtering an event stream by typed kind silently drops those rows:\n"
            . implode("\n", $missing),
        );
    }

    /**
     * The other direction, so the enum cannot grow a case for a kind nothing
     * emits. APPROVAL and INPUT are the two deliberate exceptions — they are
     * AgentRuntime events, not RunStep kinds, which is exactly why
     * `fromRunStepKind()` refuses to resolve them.
     */
    #[Test]
    public function everyCaseIsEitherARunStepKindOrOneOfTheTwoRuntimeEvents(): void
    {
        $stepKinds = [];
        foreach ((new ReflectionClass(RunStep::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'KIND_') && is_string($value)) {
                $stepKinds[] = (string)$value;
            }
        }

        $unexplained = [];
        foreach (AgentEventKind::cases() as $case) {
            if (in_array($case, [AgentEventKind::APPROVAL, AgentEventKind::INPUT], true)) {
                continue;
            }

            if (!in_array($case->value, $stepKinds, true)) {
                $unexplained[] = (string)$case->value;
            }
        }

        self::assertSame([], $unexplained, 'A case exists for a kind no RunStep emits: ' . implode(', ', $unexplained));
    }

    #[Test]
    public function theTwoRunStepKindsThatWereMissingResolve(): void
    {
        self::assertSame(AgentEventKind::DROPPED, AgentEventKind::fromRunStepKind(RunStep::KIND_DROPPED));
        self::assertSame(AgentEventKind::TOOL_WRITE, AgentEventKind::fromRunStepKind(RunStep::KIND_WRITE));
    }
}
