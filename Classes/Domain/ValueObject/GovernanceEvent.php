<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One immutable governance-decision row, produced at a denial/gate choke point
 * and written by GovernanceEventRepository (tx_nrllm_governance_event).
 *
 * Privacy by construction (ADR-064): no prompt, no response, no tool arguments
 * or output. `detail` carries only policy facts (trust zone, ceiling,
 * observe-mode flag) or a guardrail's policy reason — never response content —
 * and `guardrail` is the deciding guardrail's FQCN only, matching telemetry's
 * class-name-not-message stance.
 */
final readonly class GovernanceEvent
{
    /**
     * @param string $correlationId           trace id linking a guardrail-origin row to its telemetry / run; '' when unknown at the write point
     * @param string $decision                a {@see \Netresearch\NrLlm\Domain\Enum\GovernanceDecision} value
     * @param string $reason                  raw enum value: ToolDenialReason, GuardrailVerdict, or the 'content_filter' literal
     * @param string $provider                provider identifier ('' when unknown)
     * @param string $model                   model identifier ('' when unknown)
     * @param string $configurationIdentifier configuration identifier ('' when unknown)
     * @param int    $beUser                  acting backend user uid (0 for CLI / scheduler / unauthenticated)
     * @param string $toolName                the tool this decision was about; '' for guardrail / content_filter rows
     * @param int    $agentrunUid             the agent run this decision belongs to; 0 when not available at the write point
     * @param string $guardrail               the deciding guardrail FQCN for guardrail rows; '' for tool_denied rows
     * @param string $detail                  optional short policy detail — never content
     */
    public function __construct(
        public string $correlationId,
        public string $decision,
        public string $reason,
        public string $provider,
        public string $model,
        public string $configurationIdentifier,
        public int $beUser,
        public string $toolName,
        public int $agentrunUid,
        public string $guardrail,
        public string $detail,
    ) {}
}
