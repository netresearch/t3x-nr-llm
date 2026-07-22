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
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<array<string, mixed>>             $toolSpecs the tool schemas on the wire for THIS send; empty for a plain completion
     * @param UsageStatistics|null                   $lastUsage the previous call's usage, to calibrate the estimator; null before the first call
     */
    public function fit(
        array $messages,
        LlmConfiguration $configuration,
        ?ChatOptions $options,
        ?UsageStatistics $lastUsage,
        array $toolSpecs = [],
    ): ContextFitResult;
}
