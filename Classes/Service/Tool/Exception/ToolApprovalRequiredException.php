<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Exception;

use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use RuntimeException;

/**
 * Thrown by {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService::runLoop()}
 * when the model calls a tool that requires human approval (ADR-084).
 *
 * It is a control-flow signal, not a failure: it propagates out of the loop
 * (the loop's only catch is for {@see \Netresearch\NrLlm\Exception\BudgetExceededException})
 * carrying the {@see SuspendedRunState} the caller persists to suspend the run.
 * The caller MUST catch this before any generic `catch (Throwable)` so a
 * suspension is not mistaken for a failed run.
 */
final class ToolApprovalRequiredException extends RuntimeException
{
    public function __construct(
        public readonly SuspendedRunState $state,
    ) {
        parent::__construct('Tool loop suspended: a called tool requires human approval.', 1784600100);
    }

    /**
     * Named constructor — thrown via this factory (not `throw new`) so the code
     * is fixed inside the constructor rather than appended at each throw site.
     */
    public static function fromState(SuspendedRunState $state): self
    {
        return new self($state);
    }
}
