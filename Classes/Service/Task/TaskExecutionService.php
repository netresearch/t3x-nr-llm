<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;

/**
 * Orchestrates running a `Task` through the LLM.
 *
 * Owns the steps that previously sat inline in
 * `TaskController::executeAction()`: build the prompt with the user
 * input, route through the task's `LlmConfiguration` if one is set
 * (otherwise fall back to the global default chat options), and
 * package the response as a typed `TaskExecutionResult`.
 *
 * **REC #4 (audit) hook point.** The future automatic budget pre-flight
 * + usage tracking will plug in here — the natural sequence is:
 *
 *   1. read the calling backend user uid from the controller call
 *      (will need a small signature extension, e.g. an
 *      `ExecutionContext` argument carrying `beUserUid` and any
 *      planned-cost estimate);
 *   2. ask `BudgetServiceInterface::check()` whether the call is
 *      allowed and short-circuit with a typed exception on denial;
 *   3. let the existing pipeline middleware (`UsageMiddleware`,
 *      `BudgetMiddleware`) handle the rest via `LlmServiceManager`.
 *
 * Slice 13c intentionally keeps this surface lean — the controller
 * still passes `(Task, string $input)` and the service still proxies
 * to the manager directly. REC #4 will land in a follow-up.
 */
final readonly class TaskExecutionService implements TaskExecutionServiceInterface
{
    public function __construct(
        private LlmServiceManagerInterface $llmServiceManager,
    ) {}

    public function execute(Task $task, string $input): TaskExecutionResult
    {
        $prompt = $task->buildPrompt(['input' => $input]);

        $configuration = $task->getConfiguration();
        $response = $configuration !== null
            ? $this->llmServiceManager->completeWithConfiguration($prompt, $configuration)
            : $this->llmServiceManager->complete($prompt, new ChatOptions());

        return new TaskExecutionResult(
            content: $response->content,
            model: $response->model,
            outputFormat: $task->getOutputFormat(),
            usage: $response->usage,
        );
    }
}
