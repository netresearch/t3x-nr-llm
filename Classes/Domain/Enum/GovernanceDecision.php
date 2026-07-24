<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What kind of governance decision an append-only governance event records
 * (tx_nrllm_governance_event).
 *
 * One vocabulary spanning the two write points that were previously only logged
 * or reflected on a run: a tool-gate denial ({@see self::TOOL_DENIED}, from the
 * tool loop) and a guardrail outcome on a provider response
 * ({@see self::RESPONSE_BLOCKED}, {@see self::APPROVAL_REQUIRED},
 * {@see self::CONTENT_FILTER}, from the guardrail middleware). Keeps the
 * `decision` column type-safe, mirroring the sibling enums'
 * {@see self::values()} contract.
 */
enum GovernanceDecision: string
{
    /**
     * The tool gate denied (or observe-mode flagged) a tool for a run.
     */
    case TOOL_DENIED = 'tool_denied';

    /**
     * A guardrail denied a provider response outright (GuardrailVerdict::DENY).
     */
    case RESPONSE_BLOCKED = 'response_blocked';

    /**
     * A guardrail routed a provider response to human approval
     * (GuardrailVerdict::REQUIRE_APPROVAL).
     */
    case APPROVAL_REQUIRED = 'approval_required';

    /**
     * A guardrail denied a response the provider itself flagged as filtered
     * (finishReason = content_filter) — distinguished from a generic block so a
     * provider-side safety stop is separately measurable.
     */
    case CONTENT_FILTER = 'content_filter';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
