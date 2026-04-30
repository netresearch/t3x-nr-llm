<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use Netresearch\NrLlm\Domain\Model\Task;
use Throwable;

/**
 * Orchestrates running a `Task` through the LLM.
 *
 * Concrete implementation: `TaskExecutionService`. The interface exists
 * so the future per-pathway controllers (slice 13e) can mock execution
 * in unit tests, and so the future REC #4 budget pre-flight middleware
 * can wrap a decorator around this seam without changing call sites.
 */
interface TaskExecutionServiceInterface
{
    /**
     * Run a task with the given user input.
     *
     * The caller (controller) is responsible for verifying the task
     * exists and is active before delegating here — the service
     * trusts its argument.
     *
     * @throws Throwable on prompt-build / LLM failure (caller surfaces
     *                   the message)
     */
    public function execute(Task $task, string $input): TaskExecutionResult;
}
