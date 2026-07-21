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
 * REQUEST / LLM / TOOL / ASSEMBLED map one-to-one onto the four {@see RunStep}
 * kinds the tool loop emits through
 * {@see \Netresearch\NrLlm\Service\Tool\RunTrace}; their payload is the decoded
 * {@see RunStep::toArray()} snapshot. APPROVAL (ADR-101) is emitted by the
 * AgentRuntime when an operator decides a suspended run, with the payload
 * ``{approved: bool, decidedBy: int}`` — it is NOT a RunStep kind. The enum
 * stays limited to what is actually emitted; richer kinds (artifacts, streamed
 * text deltas) are added by the epics that emit them, not speculatively here.
 */
enum AgentEventKind: string
{
    case REQUEST = 'request';
    case LLM = 'llm';
    case TOOL = 'tool';
    case ASSEMBLED = 'assembled';
    case APPROVAL = 'approval';

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
     * known one. Deliberately restricted to the four RunStep kinds: APPROVAL is
     * an AgentRuntime event, not a RunStep, so it must not resolve here even
     * though it is a valid stored event kind (use {@see self::tryFrom()} to
     * hydrate a stored kind).
     */
    public static function fromRunStepKind(string $kind): ?self
    {
        $case = self::tryFrom($kind);

        return $case === self::APPROVAL ? null : $case;
    }
}
