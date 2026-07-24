<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The content-bearing data categories the retention policy governs (ADR-064).
 *
 * Each category names one class of persisted rows with its own lifetime: an
 * operator who keeps telemetry for a quarter may still want conversation
 * transcripts gone after a week. Every category resolves to a retention window
 * through {@see \Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface::retentionDaysFor()},
 * falling back to the global `privacy.retentionDays` default when unconfigured.
 *
 * Adding a content-bearing table means adding a case here — the coverage test
 * in Tests/Functional/Service/Privacy/ fails otherwise.
 */
enum PrivacyDataCategory: string
{
    /**
     * Graded evaluation output (tx_nrllm_eval_result).
     */
    case EVALUATION = 'evaluation';

    /**
     * Skill scan findings (tx_nrllm_skill_audit).
     */
    case SKILL_AUDIT = 'skillAudit';

    /**
     * Provider pipeline metadata, no prompts (tx_nrllm_telemetry).
     */
    case TELEMETRY = 'telemetry';

    /**
     * Conversation sessions and their message transcripts (tx_nrllm_ai_session*).
     */
    case CONVERSATION = 'conversation';

    /**
     * Finished agent runs and their event payloads (tx_nrllm_agentrun*).
     */
    case AGENT_RUN = 'agentRun';

    /**
     * Agent runs that never reached a terminal status — most importantly runs
     * suspended for a human decision. They carry the resumable transcript, so
     * they are purged on their own, deliberately longer, window: deleting a run
     * that is merely waiting for an approver destroys work in flight.
     */
    case APPROVAL = 'approval';

    /**
     * Governance decisions — tool-gate denials and guardrail blocks
     * (tx_nrllm_governance_event). Attributable metadata (be_user, decision,
     * tool name), no prompts or responses; purged like telemetry.
     */
    case GOVERNANCE = 'governance';

    /**
     * The `privacy.retention.<key>` extension-configuration key this category
     * reads its override from.
     */
    public function configKey(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }
}
