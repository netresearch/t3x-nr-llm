<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;

/**
 * Result of running a Task through the LLM.
 *
 * Owned by `TaskExecutionService::execute()`. Carries everything the
 * frontend needs to render the result (the LLM-produced content, the
 * model that produced it, the Task's preferred output format for
 * client-side rendering, and the usage statistics so the
 * cost / quota indicators stay in sync). Wrapping the raw
 * `CompletionResponse` lets us add Task-specific fields (currently
 * just `outputFormat`) without leaking that concept into the LLM
 * abstraction.
 */
final readonly class TaskExecutionResult
{
    public function __construct(
        public string $content,
        public string $model,
        public string $outputFormat,
        public UsageStatistics $usage,
    ) {}
}
