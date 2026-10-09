<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;

/**
 * What {@see AgentRuntimeInterface::cancelIfWaiting()} did (ADR-214, item 10).
 *
 * `$cancelled` is true only for the call that moved a waiting run to
 * CANCELLED, and `$status` is then CANCELLED. Otherwise `$status` is the
 * run's status read after the attempt:
 *
 * - QUEUED or RUNNING: a decision released the run and it is executing; the
 *   caller must not treat its proposal as withdrawn.
 * - a terminal status: the run had ended by the time of the read-back — by
 *   an operator's cancel, or after a decision released it and it ran, which
 *   COMPLETED says. Not a withdrawn proposal either way.
 * - WAITING_FOR_APPROVAL or WAITING_FOR_INPUT: nothing was cancelled, though
 *   the run waits — a resume claimed it and handed it back in between, or the
 *   store failed. Not withdrawn; the caller retries or answers that the run
 *   is busy.
 * - null: there is nothing the caller may know — the run does not exist, or
 *   the caller is neither its initiator nor an administrator. The two are
 *   deliberately the same answer, so a guessed uuid learns nothing. A lost
 *   attempt whose read-back fails in the store answers null as well.
 *
 * Nothing about the proposal that was withdrawn is part of it.
 *
 * @api
 */
final readonly class GuardedCancelResult
{
    public function __construct(
        public bool $cancelled,
        public ?AgentRunStatus $status,
    ) {}
}
