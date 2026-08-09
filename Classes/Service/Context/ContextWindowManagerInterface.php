<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Context;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ContextFitResult;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * Keeps an agent-loop transcript within the model's context window (ADR-107).
 *
 * Extracted as an interface so {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}
 * can depend on and test-double it while the implementation stays final. It is a
 * stateful, per-run collaborator (it accumulates a calibration factor across the
 * run's provider calls), so one instance serves one run.
 */
interface ContextWindowManagerInterface
{
    /**
     * Fit the transcript into the configuration's model context window, dropping
     * oldest whole tool-call turns as needed while preserving the leading
     * system/task messages, the tool-call/tool-result pairing and the newest
     * turn. The returned {@see ContextFitResult::$messages} is what to send; when
     * {@see ContextFitResult::$overflowAtFloor} is true the caller must not send
     * it (even the floor overflows).
     *
     * The two trailing arguments describe payload the send carries that is not
     * in `$messages`. They are separate because they differ in kind: one is
     * ADDED to the list by a later stage, the other REPLACES the prompt this
     * manager would otherwise derive.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<array<string, mixed>>             $toolSpecs             the tool schemas on the wire for THIS send; empty for a plain completion
     * @param UsageStatistics|null                   $lastUsage             the previous call's usage, to calibrate the estimator; null before the first call
     * @param string                                 $injectedText          prose a LATER stage prepends into the message list for THIS send — the skill block; it is on the wire, so it is counted against the budget, but it is never added to the returned messages
     * @param string|null                            $effectiveSystemPrompt the system prompt the caller will actually put on the wire when the transcript carries none — the configuration's prompt AFTER per-call overrides and composed snippets (ADR-031). Null means "derive it from the configuration", the pre-composition behaviour.
     */
    public function fit(
        array $messages,
        LlmConfiguration $configuration,
        ?ChatOptions $options,
        ?UsageStatistics $lastUsage,
        array $toolSpecs = [],
        string $injectedText = '',
        ?string $effectiveSystemPrompt = null,
    ): ContextFitResult;
}
