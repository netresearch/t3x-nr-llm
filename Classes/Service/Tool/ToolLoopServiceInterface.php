<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ApprovalDenialReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Option\ToolOptions;

/**
 * Runs the bounded function-calling agent loop.
 *
 * Extracted so consumers can depend on — and test-double — the loop while
 * {@see ToolLoopService} stays final, mirroring the pattern used by
 * {@see \Netresearch\NrLlm\Service\LlmServiceManagerInterface} and
 * {@see \Netresearch\NrLlm\Service\UsageTrackerServiceInterface}.
 *
 * @api
 */
interface ToolLoopServiceInterface
{
    /**
     * The fixed reason token a denied approval's tool result leads with, and
     * the three values of its `decided_by` (ADR-200). Tokens, not prose, so a
     * consumer or a model can tell a human "no" apart from any other refusal.
     * Declared here, on the @api interface, so the API-surface snapshot guards
     * the exact strings.
     */
    public const APPROVAL_DENIED = 'approval_denied';

    public const DECIDED_BY_RUN_OWNER = 'run_owner';

    public const DECIDED_BY_OTHER_USER = 'other_user';

    public const DECIDED_BY_UNKNOWN = 'unknown';

    /**
     * The two tokens a denied write proposal of a guided process carries as
     * its `reason` beside `decided_by` (ADR-214, amending ADR-200), one per
     * case of {@see \Netresearch\NrLlm\Domain\Enum\ApprovalDenialReason}.
     * Declared here for the same reason as the tokens above: the snapshot
     * guards the exact strings the model reads.
     */
    public const DENIAL_REASON_VARIANT = 'variant';

    public const DENIAL_REASON_SKIP = 'skip';

    /**
     * Run the bounded agent loop and return its outcome.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<string>|null                      $allowedToolNames null ⇒ the
     *                                                                 globally-enabled
     *                                                                 set; a list ⇒
     *                                                                 that set ∩
     *                                                                 enabled; `[]` ⇒
     *                                                                 no tools
     * @param SkillToolAllowList|null                $skillAllowList   the skill allow-list the run
     *                                                                 started with, as a queued run
     *                                                                 stores it (ADR-038 item 5); the
     *                                                                 loop holds the run to it,
     *                                                                 intersected with the list it
     *                                                                 resolves now. Null resolves the
     *                                                                 list now. A caller that resumes a
     *                                                                 suspended state uses
     *                                                                 {@see self::resume()}, which
     *                                                                 applies the stored list itself;
     *                                                                 `skipAssembly` alone does not.
     *
     * `skipAssembly` replays the transcript as given: no skill sections are
     * composed and no pins are carried, so approved instructions an earlier
     * run baked into the transcript are not re-checked against revocation
     * (ADR-214 item 6). A suspended run continues through {@see self::resume()}
     * or {@see self::resumeWithInput()}, which check the pins it stored.
     */
    public function runLoop(
        array $messages,
        LlmConfiguration $configuration,
        ToolExecutionContext $context,
        ?array $allowedToolNames,
        ?ToolOptions $options = null,
        ?int $maxIterations = null,
        ?RunTrace $runTrace = null,
        ?RunAugmentation $augmentation = null,
        bool $skipAssembly = false,
        int $seedIterations = 0,
        int $seedPromptTokens = 0,
        int $seedCompletionTokens = 0,
        ?SkillToolAllowList $skillAllowList = null,
    ): ToolLoopResult;

    /**
     * Resume a run suspended for human approval (ADR-084).
     *
     * Restores the run's original allow-list and options from the suspended
     * state, then either executes the pending tool calls ($approved) or appends
     * a denial for each. The tool gate is re-applied at resume time (a tool
     * disabled or made admin-only meanwhile is not executed even when approved),
     * and the pre-suspend counters are folded into the returned totals.
     *
     * @param ApprovalDenialReason|null $denialReason why a denial was made
     *                                                (ADR-214). Rendered as the
     *                                                `reason` token beside
     *                                                `decided_by` on the result of
     *                                                a pending call that declares
     *                                                a write; a call that is no
     *                                                write is no proposal and keeps
     *                                                the plain denial. Ignored on
     *                                                an approval.
     */
    public function resume(
        SuspendedRunState $state,
        bool $approved,
        LlmConfiguration $configuration,
        ToolExecutionContext $context,
        ?int $maxIterations = null,
        ?RunTrace $runTrace = null,
        ?int $beUserUid = null,
        ?ApprovalDenialReason $denialReason = null,
    ): ToolLoopResult;

    /**
     * Resume a run suspended for typed user input (ADR-105) — the input sibling
     * of {@see self::resume()}.
     *
     * Executes the pending turn's calls with the user's validated $inputData
     * overlaid (bounded to the schema-declared keys) onto the input-requiring
     * target; refuses a disabled sibling and any second input-requiring call.
     * The gate is re-applied at resume time and the pre-suspend counters are
     * folded into the returned totals, as approval resume does.
     *
     * @param array<string, mixed> $inputData validated against the tool's schema before this call
     */
    public function resumeWithInput(
        SuspendedRunState $state,
        array $inputData,
        LlmConfiguration $configuration,
        ToolExecutionContext $context,
        ?int $maxIterations = null,
        ?RunTrace $runTrace = null,
        ?int $beUserUid = null,
    ): ToolLoopResult;
}
