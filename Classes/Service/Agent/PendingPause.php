<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;

/**
 * The pause a run waits in, as a decider sees it before deciding (ADR-281,
 * spec 051 A2A-MT2).
 *
 * `$generation` names this pause: the run's next event sequence while it
 * waits. Every segment that runs records events, so a run that went on and
 * paused again is at a later generation, also when the new pause asks the
 * same question. `$turnDigest` is the {@see PendingTurnDigest} of the pending
 * turn, the value an {@see InputSubmission} or an {@see ApprovalDecision}
 * carries.
 *
 * @api
 */
final readonly class PendingPause
{
    public const KIND_INPUT = 'input';

    public const KIND_APPROVAL = 'approval';

    public function __construct(
        public string $runUuid,
        public string $kind,
        public int $generation,
        public string $turnDigest,
        public SuspendedRunState $state,
    ) {}

    public function isInput(): bool
    {
        return $this->kind === self::KIND_INPUT;
    }
}
