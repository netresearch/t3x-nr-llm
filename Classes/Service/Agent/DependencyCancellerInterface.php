<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;

/**
 * Cancels a run that waits for an external dependency together with the
 * operation it waits for (ADR-281, spec 051 A2A-R12).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface DependencyCancellerInterface
{
    /**
     * Move the run WAITING_FOR_DEPENDENCY -> CANCELLED in a guarded transition
     * and, only when this call won it, ask the remote once to cancel. The run
     * ends CANCELLED whatever the remote answers. False when the run was no
     * longer waiting (a resume claimed it, or it settled).
     */
    public function cancelWaiting(AgentRun $run): bool;
}
