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
 * `CompletionResponse` lets us add Task-specific fields (`outputFormat`
 * for client-side rendering and `appliedSkills` for cost attribution)
 * without leaking those concepts into the LLM abstraction.
 *
 * `appliedSkills` lists the identifiers of the skills whose prose was
 * injected into the prompt for this run. Their token cost is already
 * folded into the provider-reported `$usage` (the post-call figure),
 * so the list is a cost-attribution surface — not a separate token
 * count and not a pre-flight character estimate.
 */
final readonly class TaskExecutionResult
{
    /**
     * @param list<string> $appliedSkills identifiers of skills injected into the prompt
     */
    public function __construct(
        public string $content,
        public string $model,
        public string $outputFormat,
        public UsageStatistics $usage,
        public array $appliedSkills = [],
        /**
         * The correlation id this run was traced under (ADR-176).
         *
         * Chosen here rather than read back: the id is minted inside the
         * provider pipeline and never returned, and CompletionResponse is
         * frozen, so the caller supplies one instead. It is what lets the
         * surface showing this result record an outcome against the call that
         * produced it.
         *
         * Empty only for a result built by something that did not go through
         * TaskExecutionService — a test double, most likely. A surface must
         * treat empty as "cannot be rated" rather than as a call id.
         */
        public string $correlationId = '',
    ) {}
}
