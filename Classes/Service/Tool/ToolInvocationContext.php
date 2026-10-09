<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationHistory;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationTarget;

/**
 * Runtime facts for one call, evaluated immediately before tool execution.
 *
 * @api
 */
final readonly class ToolInvocationContext
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $toolName,
        public array $arguments,
        public ?ToolInvocationTarget $target,
        public LlmConfiguration $configuration,
        public ToolExecutionContext $execution,
        public ToolInvocationHistory $history,
    ) {}
}
