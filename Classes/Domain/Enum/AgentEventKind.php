<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

use Netresearch\NrLlm\Domain\ValueObject\RunStep;

/**
 * Kind of a persisted agent-run event (ADR-081).
 *
 * REQUEST / LLM / TOOL / ASSEMBLED / CONTEXT / DROPPED / TOOL_WRITE map
 * one-to-one onto the {@see RunStep} kinds the tool loop emits through
 * {@see \Netresearch\NrLlm\Service\Tool\RunTrace}; their payload is the decoded
 * {@see RunStep::toArray()} snapshot. APPROVAL (ADR-101) is emitted by the
 * AgentRuntime when an operator decides a suspended run, with the payload
 * ``{approved: bool, decidedBy: int}`` — it is NOT a RunStep kind. The enum
 * stays limited to what is actually emitted; richer kinds (artifacts, streamed
 * text deltas) are added by the epics that emit them, not speculatively here.
 *
 * "What is actually emitted" is now asserted rather than intended.
 * {@see \Netresearch\NrLlm\Tests\Unit\Domain\Enum\AgentEventKindTest} holds
 * every `RunStep::KIND_*` constant against {@see self::values()}, because the
 * limitation drifted twice without anything noticing: `dropped` was written from
 * ADR-179 and `tool_write` from ADR-182, and neither had a case here. Nothing
 * broke — {@see \Netresearch\NrLlm\Service\Tool\AgentRunRepository::recordEvent()}
 * inserts the raw string — but {@see \Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent::kindEnum()}
 * answered null for both, and a caller filtering a stream by typed kind loses
 * exactly the rows it is looking for (#900).
 */
enum AgentEventKind: string
{
    case REQUEST = 'request';
    case LLM = 'llm';
    case TOOL = 'tool';
    case ASSEMBLED = 'assembled';
    // The round's context-window accounting (ADR-151), recorded by the tool
    // loop's fit ahead of the request step it explains. It IS a RunStep kind:
    // every traced run — playground, interactive, queued, resumed — persists
    // one of these per round.
    case CONTEXT = 'context';
    // The forced sources a run asked for and did not get (ADR-179). A RunStep
    // kind: the loop records one before the first round, and only when
    // something was actually dropped.
    case DROPPED = 'dropped';
    // The record one tool write produced (ADR-182). A RunStep kind, recorded
    // after the tool step it belongs to, and the row the observed outcome
    // joins sys_history against.
    case TOOL_WRITE = 'tool_write';
    case APPROVAL = 'approval';
    // Emitted by the AgentRuntime when an operator submits typed input for a run
    // suspended WAITING_FOR_INPUT (ADR-105), payload ``{submittedBy: int}`` — the
    // who/when, never the submitted values (ADR-064). Like APPROVAL it is NOT a
    // RunStep kind.
    case INPUT = 'input';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * Map a {@see RunStep} kind constant onto its event kind. Returns null for an
     * unknown value so a future RunStep kind cannot silently masquerade as a
     * known one. Deliberately restricted to the RunStep kinds: APPROVAL is
     * an AgentRuntime event, not a RunStep, so it must not resolve here even
     * though it is a valid stored event kind (use {@see self::tryFrom()} to
     * hydrate a stored kind). INPUT (ADR-105) is excluded for the same reason.
     */
    public static function fromRunStepKind(string $kind): ?self
    {
        $case = self::tryFrom($kind);

        return in_array($case, [self::APPROVAL, self::INPUT], true) ? null : $case;
    }
}
