<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Throwable;

/**
 * The settled result of one AgentRuntime run() / approve() call (ADR-101).
 *
 * The runtime never throws for a run outcome — a consumer always receives a
 * result whose persisted status already matches. Which optional fields are set
 * depends on the outcome:
 *
 *  - COMPLETED:                    loopResult
 *  - AWAITING_APPROVAL:            suspendedState (pending calls via toolCalls())
 *  - SUSPEND_FAILED:               error (the approval exception; run settled
 *                                  FAILED, no resume possible — fail-closed,
 *                                  ADR-092)
 *  - GUARDRAIL_BLOCKED /
 *    GUARDRAIL_APPROVAL_REQUIRED:  guardrailClass + error (the guardrail
 *                                  exception; message = reason)
 *  - FAILED:                       error
 *
 * `steps` carries the trace recorded up to the end of the segment on every
 * outcome. `runUuid` is '' when the run could not be persisted (the run still
 * executed, unrecorded — the persister's fail-soft contract).
 */
final readonly class AgentRunResult
{
    /**
     * @param list<RunStep> $steps
     */
    public function __construct(
        public AgentRunOutcome $outcome,
        public string $runUuid,
        public array $steps,
        public ?ToolLoopResult $loopResult = null,
        public ?SuspendedRunState $suspendedState = null,
        public ?string $guardrailClass = null,
        public ?Throwable $error = null,
    ) {}
}
