<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Process;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;

/**
 * Whether a run holds a process pin (ADR-214, items 6, 9 and 10).
 *
 * Every rule that applies only to a guided process asks this one question:
 * the turn rules of the tool loop (one approval-bound call per turn, reads
 * before the suspend), the refusal of every decider but the initiator, the
 * four-eyes stop, and the refusal of the backend surfaces that could decide a
 * run outside the chat.
 *
 * A process pin is a pin (ADR-214 item 6) whose approved version is a
 * process: the `process` flag of the approval snapshot of its digest
 * ({@see \Netresearch\NrLlm\Domain\ValueObject\SkillApproval}), not of
 * the skill record, which a later sync may have changed.
 *
 * Two questions, because a run's pins live in two places. A run that waits
 * for a human stores them in its suspended state; that is what the backend
 * surfaces and the resume path ask about, by run. A run that executes holds
 * them in the tool loop, which asks about its own list.
 *
 * @internal
 */
interface ProcessPinProbe
{
    /**
     * Whether this stored run holds a process pin, read from the row the
     * caller already holds rather than read again: a second read that fails
     * would answer "no" and switch the guards off. A run that is not suspended,
     * or whose stored state cannot be read, answers false: every resume path
     * refuses an unreadable state before anything runs, as it does for any
     * run, so the process rules have nothing to add there.
     */
    public function holdsProcessPin(AgentRun $run): bool;

    /**
     * The answer of {@see holdsProcessPin()}, or null when it rests on an
     * approval lookup that failed. holdsProcessPin() reads null as yes, so
     * the guards apply; the four-eyes stop, which ends the run and cannot be
     * undone, waits for an answer that rests on approvals that were read.
     */
    public function processPinOf(AgentRun $run): ?bool;

    /**
     * Whether one of these pins is a process pin.
     *
     * @param list<SkillPin> $pins
     */
    public function anyProcessPin(array $pins): bool;
}
