<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationTarget;

/**
 * Resolves targets and restricts calls after the existing offerability gate.
 * An invocation policy cannot override a prior permission denial.
 *
 * @api
 */
interface ToolInvocationPolicyInterface
{
    public function resolveTarget(
        ToolCall $call,
        ToolExecutionContext $context,
    ): ?ToolInvocationTarget;

    public function decide(
        ToolInvocationContext $context,
    ): ToolInvocationDecision;
}
