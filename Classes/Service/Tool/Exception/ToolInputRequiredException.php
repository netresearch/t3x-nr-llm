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
 * when the model calls a tool that requires typed user input (ADR-105).
 *
 * The sibling of {@see ToolApprovalRequiredException}: a control-flow signal,
 * not a failure. It carries the {@see SuspendedRunState} — whose $inputToolName
 * and $inputSchema name the target tool and the shape the user must supply — the
 * caller persists to suspend the run WAITING_FOR_INPUT. The caller MUST catch it
 * before any generic `catch (Throwable)` so a suspension is not mistaken for a
 * failed run.
 */
final class ToolInputRequiredException extends RuntimeException
{
    public function __construct(
        public readonly SuspendedRunState $state,
    ) {
        parent::__construct('Tool loop suspended: a called tool requires user input.', 1784600101);
    }

    public static function fromState(SuspendedRunState $state): self
    {
        return new self($state);
    }
}
