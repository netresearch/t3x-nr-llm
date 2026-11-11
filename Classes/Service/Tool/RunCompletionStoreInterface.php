<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Closure;

/**
 * A store that keeps a run's result in the transaction that completes the run
 * (ADR-282, spec 051 A2A-R13): the A2A task view first.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface RunCompletionStoreInterface
{
    /**
     * Whether a result of this run is kept by this store; a run it does not
     * keep is settled as before.
     */
    public function keeps(int $runUid): bool;

    /**
     * Settle the run with its final answer, the store's write and the run's
     * transition in one transaction.
     *
     * @param Closure(): bool       $completeRun the run's COMPLETED transition, false when it did not happen
     * @param Closure(string): bool $failRun     the run's FAILED transition (policy-stopped, with the given error class) for a result that may not be kept or cannot be written
     *
     * @return bool whether the run's terminal transition was written by this call
     */
    public function complete(int $runUid, string $answer, Closure $completeRun, Closure $failRun): bool;

    /**
     * Write any other status transition of a run, and the store's mirror of
     * it, in one transaction: a store that keeps the run follows the run's
     * new status there, and only when the transition happened. A run the store
     * does not keep is transitioned as before.
     *
     * @param Closure(): bool $transition the run's transition, false when it did not happen
     *
     * @return bool what the transition returned
     */
    public function transition(int $runUid, Closure $transition): bool;
}
