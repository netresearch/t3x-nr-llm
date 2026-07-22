<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

/**
 * The typed input a user submits for a run suspended WAITING_FOR_INPUT (ADR-105).
 *
 * The sibling of {@see ApprovalDecision}: where that carries a verdict as data
 * (approve/deny), this carries a payload that must pass the tool's declared
 * input schema BEFORE the run is claimed and resumed — an invalid submission is
 * rejected without consuming the claim, so the user can resubmit. The submitted
 * values are UNTRUSTED content that flow into the tool's arguments and back into
 * the model context; admin-gating the submit path is the injection mitigation
 * (structure-only schema validation does not sanitise content).
 *
 * Persisted only as an {@see \Netresearch\NrLlm\Domain\Enum\AgentEventKind::INPUT}
 * event recording ``{submittedBy: int}`` — never the values (ADR-064).
 */
final readonly class InputSubmission
{
    /**
     * @param array<string, mixed> $data the user-supplied input, validated against the tool's schema before use
     */
    public function __construct(
        public array $data,
        public int $submittedByBeUser,
    ) {}
}
