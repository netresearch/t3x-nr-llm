<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;

/**
 * Outcome of a bounded function-calling agent loop ({@see ToolLoopService}).
 *
 * Bundles the final assistant answer with the ordered trace of every tool
 * the model invoked, the number of model round-trips spent, whether the
 * loop hit its iteration cap (or budget limit) before the model stopped
 * requesting tools, and the summed token usage across all round-trips.
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
    ) {}
}
