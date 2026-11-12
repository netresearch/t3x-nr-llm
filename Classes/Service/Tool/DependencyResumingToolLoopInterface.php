<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;

/**
 * Continues a run that waited for an external dependency (ADR-281). Additive:
 * the `@api` {@see ToolLoopServiceInterface} keeps its signatures.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface DependencyResumingToolLoopInterface
{
    /**
     * Answer exactly the pending call the checkpoint names with $result and
     * continue the loop with the run's counters. The tool is not executed
     * again (spec 051 A2A-R4).
     */
    public function resumeWithDependency(
        SuspendedRunState $state,
        DependencyCheckpoint $checkpoint,
        ToolResult $result,
        LlmConfiguration $configuration,
        ToolExecutionContext $context,
        ?int $maxIterations = null,
        ?RunTrace $runTrace = null,
    ): ToolLoopResult;
}
