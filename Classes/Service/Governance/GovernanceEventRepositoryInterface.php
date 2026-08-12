<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;

/**
 * Persistence boundary for the append-only governance-decision audit trail
 * (tx_nrllm_governance_event).
 *
 * Append one immutable row, purge old rows, read a small set of dashboard
 * aggregates, or list the decisions of ONE agent run. Mirrors
 * {@see \Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface}:
 * there is no update path — governance events are immutable. The rows a read
 * hands out are metadata by construction (ADR-064): the table has no column for
 * a prompt, a response, a tool argument or a tool result.
 */
interface GovernanceEventRepositoryInterface
{
    /**
     * Append one governance-decision row. Never throws for a caller-visible
     * reason: recording a denial must not break the run it observes.
     */
    public function record(GovernanceEvent $event): void;

    /**
     * Delete rows created strictly before the given UNIX timestamp.
     *
     * @return int number of rows deleted
     */
    public function purgeOlderThan(int $timestamp): int;

    /**
     * Count events per decision kind on/after $since (0 = all time). Keys are
     * raw {@see \Netresearch\NrLlm\Domain\Enum\GovernanceDecision} values.
     *
     * @return array<string, int>
     */
    public function countByDecision(int $since = 0): array;

    /**
     * Count tool-gate denials per denial reason on/after $since (0 = all time):
     * rows with decision = tool_denied, grouped by reason.
     *
     * @return array<string, int>
     */
    public function countToolDenialsByReason(int $since = 0): array;

    /**
     * Count tool-gate decisions per tool name on/after $since (0 = all time):
     * rows carrying a non-empty tool_name, grouped by tool_name. This measures
     * gate DECISIONS by tool (which tools get blocked/observed), not successful
     * invocation volume.
     *
     * @return array<string, int>
     */
    public function countToolDecisionsByName(int $since = 0): array;

    /**
     * The governance decisions of one agent run, oldest first (ADR-153).
     *
     * Matched on EITHER key, because the three write points know different
     * halves of the run's identity: the tool gate and the input-context gate
     * write the run uid, the guardrail middleware writes the correlation id
     * (which for a run IS its uuid). A row is returned once regardless of how
     * many of the two it carries.
     *
     * Both arguments are ignored when they are the "unknown" marker (uid <= 0,
     * correlation ''), and an empty list comes back when both are.
     *
     * @return list<RecordedGovernanceEvent>
     */
    public function findForRun(int $agentRunUid, string $correlationId): array;
}
