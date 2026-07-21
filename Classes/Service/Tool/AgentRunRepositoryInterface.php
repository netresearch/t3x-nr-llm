<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;

/**
 * Persistence contract for agent runs and their event streams (ADR-081).
 *
 * A UI-less append-and-update log, mirroring {@see \Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface}:
 * raw Doctrine access, no Extbase. Split out as an interface so the persister
 * can be unit-tested against a double.
 */
interface AgentRunRepositoryInterface
{
    /**
     * Insert a new run in the RUNNING state and return its primary key.
     */
    public function startRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser): int;

    /**
     * Append one event to a run's stream.
     */
    public function recordEvent(int $runUid, int $sequence, string $kind, int $round, float $durationMs, string $payloadJson): void;

    /**
     * Move a run into a terminal state, recording its final totals and why it
     * ended.
     *
     * Guarded: only a run that is not already terminal transitions. A late or
     * duplicate settle therefore cannot reopen or overwrite a finished run.
     *
     * @return bool true when the run transitioned, false when it was already terminal (or gone)
     */
    public function finishRun(
        int $runUid,
        string $status,
        int $iterations,
        bool $truncated,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        float $estimatedCost,
        string $errorClass,
        string $terminationReason = '',
    ): bool;

    /**
     * Suspend a run for human approval (ADR-084): persist its serialised state
     * and move it to WAITING_FOR_APPROVAL — a non-terminal transition, distinct
     * from finishRun which sets a terminal status and finished_at. Guarded on
     * the run still being RUNNING (ADR-101): false when the transition was
     * refused because a concurrent cancel (or settle) got there first, so a
     * cancelled run is never resurrected into an approval queue.
     */
    public function suspendRun(int $runUid, string $stateJson): bool;

    /**
     * Atomically claim a WAITING_FOR_APPROVAL run for resume (move it to RUNNING);
     * true for the winner, false if already claimed. Prevents two concurrent
     * approvals from double-executing the gated tool (ADR-084).
     */
    public function claimForResume(int $runUid): bool;

    public function findByUuid(string $uuid): ?AgentRun;

    /**
     * @return list<AgentRunEvent> Events for a run, ordered by sequence ascending.
     */
    public function findEvents(int $runUid): array;

    /**
     * The highest event sequence recorded for a run, or -1 when the run has no
     * events yet. A resume continues the stream at max + 1 (ADR-101) —
     * MAX-based, not count-based, so gaps can never cause a duplicate sequence.
     */
    public function maxEventSequence(int $runUid): int;

    /**
     * Delete FINISHED runs (and their events) created before the given
     * timestamp.
     *
     * Only terminal runs — completed, failed, cancelled — are eligible. A run
     * still queued, running or waiting for a human approval carries the state
     * needed to resume it; purging it by age alone would destroy work in flight.
     * Those are reaped separately by {@see purgeUnfinishedOlderThan()}.
     *
     * @return int Number of run rows deleted.
     */
    public function purgeOlderThan(int $timestamp): int;

    /**
     * Delete runs (and their events) created before the given timestamp that
     * never reached a terminal status — abandoned runs and approvals nobody
     * ever decided. Governed by its own, deliberately longer retention window
     * ({@see \Netresearch\NrLlm\Domain\Enum\PrivacyDataCategory::APPROVAL}).
     *
     * @return int Number of run rows deleted.
     */
    public function purgeUnfinishedOlderThan(int $timestamp): int;
}
