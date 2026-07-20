<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;

/**
 * Outcome of a bounded function-calling agent loop ({@see ToolLoopService}).
 *
 * Bundles the final assistant answer with the ordered trace of every tool
 * the model invoked, the number of model round-trips spent, whether the loop
 * stopped early, why it stopped, and the summed token usage across all
 * round-trips.
 *
 * `truncated` and `terminationReason` are not redundant: `truncated` says the
 * answer is incomplete, the reason says what made it so. An iteration cap and
 * an exhausted budget both truncate, and only the reason tells them apart
 * (ADR-092).
 */
final readonly class ToolLoopResult
{
    /**
     * @param list<ToolInvocation> $trace Ordered record of every executed tool call.
     */
    public function __construct(
        public string $finalContent,
        public array $trace,
        public int $iterations,
        public bool $truncated,
        public UsageStatistics $usage,
        public AgentRunTerminationReason $terminationReason = AgentRunTerminationReason::COMPLETED,
    ) {}
}
