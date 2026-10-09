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
 * CANCELLED. `$status` is the run's status after the attempt, so a caller
 * that lost tells apart a run somebody is still executing (QUEUED, RUNNING)
 * from one that already ended. It is null when there is nothing the caller
 * may know: the run does not exist, or the caller is neither its initiator
 * nor an administrator — the two are deliberately the same answer, so a
 * guessed uuid learns nothing.
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
