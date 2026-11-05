<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;

/**
 * Opt-in: an approval-bound tool that knows, before any human decides, that a
 * call cannot be written in the run's context (ADR-262).
 *
 * The case it exists for is a write to a table TYPO3 cannot version, asked
 * for in a draft workspace: there is no draft to store, and writing the live
 * row would publish the change past the workspace's review. The call is
 * refused whatever the approver decides, so a card that asks for a decision
 * is a question without an answer.
 *
 * The loop asks {@see self::refusalBeforeApproval()} where it asks
 * {@see ToolApprovalRule::requiresApproval()} for a call: a refusal there
 * means the call does not suspend the run for approval and goes to the
 * tool, which refuses it. That is safe only because the tool asks the SAME
 * method first in its `execute()`: skipping the approval can never lead to a
 * write.
 *
 * Contract for implementors:
 *
 * - **Pure and read-only.** No write, no counter, no clock. The loop and
 *   `execute()` must get the same answer for the same call and context.
 * - **`execute()` asks this method before anything else and returns its
 *   refusal unchanged.** A refusal that `execute()` does not repeat would let
 *   a call run without the approval it needs.
 * - **The preview asks it too** and shows the refusal instead of the change:
 *   in a turn that suspends for another call, the card must not describe a
 *   write that will not happen.
 * - Null whenever the call may be written, including whenever the answer is
 *   not known: the call then suspends for approval as before.
 *
 * {@see ToolApprovalRule} stays context-free on purpose: the registry's boot
 * validation, the Governance simulator and the unattended filter ask whether
 * a tool needs approval in general, and for these tools it does.
 *
 * @internal
 */
interface RefusesBeforeApprovalInterface
{
    /**
     * The refusal this call meets whatever the approver decides, or null.
     *
     * @param array<string, mixed> $arguments the model-chosen arguments of the call
     */
    public function refusalBeforeApproval(array $arguments, ToolExecutionContext $context): ?ToolResult;
}
