<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
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
 */
interface ToolLoopServiceInterface
{
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
    ): ToolLoopResult;

    /**
     * Resume a run suspended for human approval (ADR-084).
     *
     * Restores the run's original allow-list and options from the suspended
     * state, then either executes the pending tool calls ($approved) or appends
     * a denial for each. The tool gate is re-applied at resume time (a tool
     * disabled or made admin-only meanwhile is not executed even when approved),
     * and the pre-suspend counters are folded into the returned totals.
     */
    public function resume(
        SuspendedRunState $state,
        bool $approved,
        LlmConfiguration $configuration,
        ToolExecutionContext $context,
        ?int $maxIterations = null,
        ?RunTrace $runTrace = null,
        ?int $beUserUid = null,
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
