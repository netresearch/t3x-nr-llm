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
     * The input-context gate refused (or observe-mode flagged) a call because
     * the context it injects is classified above the trust zone the call can
     * reach (ADR-144).
     *
     * A separate case from {@see self::TOOL_DENIED} although both apply the
     * same data-class-versus-trust-zone rule: that one is about what a tool may
     * READ for a run, this one about what the run may SEND. Collapsing them
     * would make "which direction leaks" unanswerable from the audit.
     */
    case CONTEXT_BLOCKED = 'context_blocked';

    /**
     * A pending write was refused on resume because no human had approved it.
     *
     * A separate case from {@see self::TOOL_DENIED}, which means "the gate did
     * not offer this tool". Here the gate DID offer it — that is how the call
     * reached the branch at all. It is refused because the turn it belongs to
     * suspended for typed input rather than for approval, and an input
     * submission is not an approval (see
     * {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService::resumeWithInput()}).
     * Folding the two together would make the same value mean two things,
     * separable only by a `reason` string.
     *
     * Also distinct from {@see self::APPROVAL_REQUIRED}, which is the
     * guardrail's verdict on a provider RESPONSE. This is a tool call that was
     * stopped, not a response routed for review.
     *
     * The approval TRAIL is deliberately not in this table — it lives on the
     * run, under the longer `approval` retention window, because a run awaiting
     * an approver carries resumable work. A refusal carries none, so it belongs
     * with the other governance telemetry.
     */
    case WRITE_UNAPPROVED = 'write_unapproved';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
