<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;

/**
 * Why an actor may not act on one pending call — the shared verdict of the
 * approver gate (ADR-133) and the submitter gate (ADR-150).
 *
 * The two gates differ in WHICH calls they check and in the exception they
 * raise, never in how a single call is judged: both ask
 * {@see \Netresearch\NrLlm\Service\Tool\ToolCallPolicy} about the acting user.
 * This carries that one judgement back so each gate can word its own refusal.
 *
 * Two cases, distinguished by `$reason`:
 *
 * - `null` — there is no live backend user to evaluate the gate against (a
 *   service account, or a uid that no longer resolves to an enabled user). "No
 *   user" is not "permitted", and the policy was never asked.
 * - set — the gate itself denied the tool, and says why.
 *
 * A pending entry too corrupt to yield a call at all is NOT one of these: it is
 * refused before the walk reaches the policy, because there is no tool name to
 * judge.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class PendingCallRefusal
{
    public function __construct(
        public string $toolName,
        public ?ToolDenialReason $reason = null,
    ) {}
}
