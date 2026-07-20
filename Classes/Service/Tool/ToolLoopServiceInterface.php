<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
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
}
