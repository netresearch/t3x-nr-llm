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
        ?string $ownedBy = null,
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
     * Suspend a run for typed user input (ADR-105): the input sibling of
     * {@see suspendRun()} — persist the serialised state (carrying the tool's
     * declared input schema) and move RUNNING -> WAITING_FOR_INPUT, same
     * anti-resurrection guard. False when a concurrent cancel/settle won first.
     */
    public function suspendRunForInput(int $runUid, string $stateJson): bool;

    /**
     * Atomically claim a WAITING_FOR_APPROVAL run for resume (move it to RUNNING);
     * true for the winner, false if already claimed. Prevents two concurrent
     * approvals from double-executing the gated tool (ADR-084).
     */
    public function claimForResume(int $runUid): bool;

    /**
     * Atomically claim a WAITING_FOR_INPUT run for resume (ADR-105): the input
     * sibling of {@see claimForResume()}. True for the winner, false if another
     * submitInput already claimed it — so two concurrent submissions cannot both
     * resume the run.
     */
    public function claimForResumeFromInput(int $runUid): bool;

    /**
     * Insert a QUEUED run carrying its serialised request, so a worker in
     * another process can claim, rehydrate and execute it (ADR-102).
     * started_at stays 0 until the claim.
     *
     * @return int The new run's uid.
     */
    public function enqueueRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser, string $requestJson): int;

    /**
     * Atomically claim a QUEUED run for execution (move it to RUNNING, stamp
     * started_at and the worker lease); true for the winning worker, false when
     * another worker already claimed it or the run was cancelled while queued
     * (ADR-102).
     */
    public function claimQueued(int $runUid, string $claimedBy, int $leaseExpires): bool;

    /**
     * Extend the lease on a running run this worker owns (ADR-104 heartbeat).
     * Guarded on status = running AND claimed_by = the caller; false means the
     * worker no longer owns the run (reaped, cancelled or settled) and must
     * stop before doing further work.
     */
    public function renewLease(int $runUid, string $claimedBy, int $leaseExpires): bool;

    /**
     * Stamp the effect of the tool about to execute (or '' to clear it once the
     * tool completed) and renew the lease in the same guarded write (ADR-111
     * lease-before-op). Guarded like {@see renewLease()}; false means the worker
     * lost the run. The reaper reads pending_effect to refuse retrying a run
     * reaped mid non-idempotent-write.
     */
    public function markPendingEffect(int $runUid, string $claimedBy, string $effect, int $leaseExpires): bool;

    /**
     * Put a running run this worker owns back on the queue for a retry (ADR-104
     * failure retry): ownership-guarded (status = running AND claimed_by = the
     * caller), increments requeue_count, clears claim and lease, keeps
     * queued_request. False when the worker no longer owned the run.
     */
    public function requeue(int $runUid, string $claimedBy): bool;

    /**
     * Running runs whose lease has expired at $now (ADR-104 reaper). Excludes
     * interactive runs, which never take a lease.
     *
     * @return list<AgentRun>
     */
    public function findStaleRunning(int $now, int $limit = 50): array;

    /**
     * Reclaim a stale running run onto the queue (ADR-104 reaper): re-checks
     * staleness inside the UPDATE so a concurrent heartbeat renewal wins,
     * increments requeue_count, clears claim and lease. False when the run was
     * no longer stale (renewed) or already moved on.
     */
    public function requeueStale(int $runUid, int $now): bool;

    /**
     * Dead-letter a stale running run whose requeue budget is spent (ADR-104
     * reaper): terminal FAILED with the given reason, staleness-guarded like
     * {@see requeueStale()} so a heartbeat renewal wins and a live run is never
     * failed. False when the run was no longer stale or already terminal.
     */
    public function deadLetterStale(int $runUid, int $now, string $terminationReason): bool;

    public function findByUuid(string $uuid): ?AgentRun;

    /**
     * Runs awaiting a human decision — WAITING_FOR_APPROVAL or WAITING_FOR_INPUT
     * (ADR-084/105) — oldest first (FIFO), carrying suspended_state so the
     * approvals inbox can surface the pending tool call / input schema.
     *
     * @return list<AgentRun>
     */
    public function findAwaiting(int $limit = 100): array;

    /**
     * The most recent terminal runs (completed, failed, cancelled), newest
     * first — read-only context for the approvals inbox. suspended_state is not
     * needed here and is deliberately not surfaced (ADR-064).
     *
     * @return list<AgentRun>
     */
    public function findRecentTerminal(int $limit = 20): array;

    /**
     * @param int $afterSequence only events with sequence > this value; -1
     *                           (the default) returns the full stream. Filtered
     *                           in SQL so a poller does not re-hydrate the whole
     *                           history on every page (ADR-101).
     *
     * @return list<AgentRunEvent> Events for a run, ordered by sequence ascending.
     */
    public function findEvents(int $runUid, int $afterSequence = -1): array;

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
