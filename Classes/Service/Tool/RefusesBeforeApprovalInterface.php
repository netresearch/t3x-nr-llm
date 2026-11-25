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
 * The first case is a write to a table TYPO3 cannot version, asked for in a
 * draft workspace: there is no draft to store, and writing the live row would
 * publish the change past the workspace's review. Another is a call the tool's
 * own plan refuses, such as a draft with a value that does not exist
 * (ADR-328). The call is refused whatever the approver decides, so a card that
 * asks for a decision is a question without an answer.
 *
 * The loop asks {@see self::refusalBeforeApproval()} where it asks
 * {@see ToolApprovalRule::requiresApproval()} for a call: a refusal there
 * means the call does not suspend the run for approval. The refusal can rest
 * on rows that change between that question and the moment the call is
 * settled, so the loop never executes such a call (ADR-328): it asks again,
 * and the answer is the refusal or, where the tool no longer refuses, that the
 * call requires an approval that was not given.
 *
 * Contract for implementors:
 *
 * - **Read-only.** No write, no counter, no clock. The loop and `execute()`
 *   must get the same answer for the same call, context and records. The loop
 *   asks more than once per call, in the environment a write gets
 *   ({@see WorkerWriteEnvironment}).
 * - **`execute()` asks this before anything else and returns its refusal
 *   unchanged.** An approved call reaches `execute()`, and its approver may
 *   have read the refusal on the card.
 * - **The preview asks it too** and shows the refusal instead of the change:
 *   in a turn that suspends for another call, the card must not describe a
 *   write that will not happen.
 * - Null whenever the call may be written, including whenever the answer is
 *   not known — no acting user, a process that lacks what the answer needs:
 *   the call then suspends for approval as before. A refusal that describes
 *   the process is no answer, because the execution can run in another one.
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
