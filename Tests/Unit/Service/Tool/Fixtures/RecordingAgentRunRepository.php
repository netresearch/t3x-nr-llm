<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use RuntimeException;

/**
 * In-memory {@see AgentRunRepositoryInterface} for unit-testing the persister:
 * captures every call for assertion and can be told to throw on any method to
 * exercise the persister's fail-soft behaviour.
 */
final class RecordingAgentRunRepository implements AgentRunRepositoryInterface
{
    public int $nextUid = 1;

    public bool $throwOnStart = false;

    public bool $throwOnRecord = false;

    public bool $throwOnFinish = false;

    /** @var list<array{uuid: string, configurationUid: int, configurationIdentifier: string, beUser: int}> */
    public array $startedRuns = [];

    /** @var list<array{runUid: int, sequence: int, kind: string, round: int, durationMs: float, payloadJson: string}> */
    public array $events = [];

    /** @var array{runUid: int, status: string, iterations: int, truncated: bool, promptTokens: int, completionTokens: int, totalTokens: int, estimatedCost: float, errorClass: string, terminationReason: string, ownedBy: string|null}|null */
    public ?array $finished = null;

    public function startRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser): int
    {
        if ($this->throwOnStart) {
            throw new RuntimeException('startRun failed', 5383517209);
        }
        $this->startedRuns[] = [
            'uuid'                     => $uuid,
            'configurationUid'         => $configurationUid,
            'configurationIdentifier'  => $configurationIdentifier,
            'beUser'                   => $beUser,
        ];

        return $this->nextUid++;
    }

    public function recordEvent(int $runUid, int $sequence, string $kind, int $round, float $durationMs, string $payloadJson): void
    {
        if ($this->throwOnRecord) {
            throw new RuntimeException('recordEvent failed', 9973913396);
        }
        $this->events[] = [
            'runUid'      => $runUid,
            'sequence'    => $sequence,
            'kind'        => $kind,
            'round'       => $round,
            'durationMs'  => $durationMs,
            'payloadJson' => $payloadJson,
        ];
    }

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
    ): bool {
        if ($this->throwOnFinish) {
            throw new RuntimeException('finishRun failed', 7543565687);
        }
        if ($this->refuseFinish) {
            return false;
        }
        $this->finished = [
            'runUid'           => $runUid,
            'status'           => $status,
            'iterations'       => $iterations,
            'truncated'        => $truncated,
            'promptTokens'     => $promptTokens,
            'completionTokens' => $completionTokens,
            'totalTokens'      => $totalTokens,
            'estimatedCost'    => $estimatedCost,
            'errorClass'         => $errorClass,
            'terminationReason'  => $terminationReason,
            'ownedBy'            => $ownedBy,
        ];

        return true;
    }

    /** Simulates a run that is already terminal, so the guarded update matches no row. */
    public bool $refuseFinish = false;

    /** @var array{runUid: int, stateJson: string}|null */
    public ?array $suspended = null;

    public bool $throwOnSuspend = false;

    /** Simulates a run no longer RUNNING (cancelled/settled), so the guarded suspend matches no row. */
    public bool $refuseSuspend = false;

    public function suspendRun(int $runUid, string $stateJson): bool
    {
        if ($this->throwOnSuspend) {
            throw new RuntimeException('suspendRun failed', 1784600401);
        }
        if ($this->refuseSuspend) {
            return false;
        }
        $this->suspended = ['runUid' => $runUid, 'stateJson' => $stateJson];

        return true;
    }

    /** @var array{runUid: int, stateJson: string}|null */
    public ?array $suspendedForInput = null;

    public bool $throwOnSuspendForInput = false;

    /** Simulates a run no longer RUNNING, so the guarded input-suspend matches no row. */
    public bool $refuseSuspendForInput = false;

    public function suspendRunForInput(int $runUid, string $stateJson): bool
    {
        if ($this->throwOnSuspendForInput) {
            throw new RuntimeException('suspendRunForInput failed', 1784600402);
        }
        if ($this->refuseSuspendForInput) {
            return false;
        }
        $this->suspendedForInput = ['runUid' => $runUid, 'stateJson' => $stateJson];

        return true;
    }

    public bool $throwOnEnqueue = false;

    /** @var list<array{uuid: string, configurationUid: int, configurationIdentifier: string, beUser: int, requestJson: string}> */
    public array $enqueuedRuns = [];

    public function enqueueRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser, string $requestJson): int
    {
        if ($this->throwOnEnqueue) {
            throw new RuntimeException('enqueueRun failed', 1784700010);
        }
        $this->enqueuedRuns[] = [
            'uuid'                    => $uuid,
            'configurationUid'        => $configurationUid,
            'configurationIdentifier' => $configurationIdentifier,
            'beUser'                  => $beUser,
            'requestJson'             => $requestJson,
        ];

        return $this->nextUid++;
    }

    public bool $throwOnClaimQueued = false;

    /** Simulates a queued run already claimed by another worker (or cancelled). */
    public bool $refuseClaimQueued = false;

    /** @var array{runUid: int, claimedBy: string, leaseExpires: int}|null */
    public ?array $queuedClaim = null;

    public function claimQueued(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        if ($this->throwOnClaimQueued) {
            throw new RuntimeException('claimQueued failed', 1784700011);
        }
        if ($this->refuseClaimQueued) {
            return false;
        }
        $this->queuedClaim = ['runUid' => $runUid, 'claimedBy' => $claimedBy, 'leaseExpires' => $leaseExpires];

        return true;
    }

    public bool $throwOnClaim = false;

    /** Number of resume claims that were granted; false is returned after the first. */
    public int $claimsGranted = 0;

    public function claimForResume(int $runUid): bool
    {
        if ($this->throwOnClaim) {
            throw new RuntimeException('claimForResume failed', 1784600400);
        }
        // First claim wins; a second concurrent claim on the same run loses.
        if ($this->claimsGranted > 0) {
            return false;
        }
        ++$this->claimsGranted;

        return true;
    }

    public bool $throwOnClaimInput = false;

    /** Number of input-resume claims granted; false after the first (double-submit loses). */
    public int $inputClaimsGranted = 0;

    public function claimForResumeFromInput(int $runUid): bool
    {
        if ($this->throwOnClaimInput) {
            throw new RuntimeException('claimForResumeFromInput failed', 1784600403);
        }
        if ($this->inputClaimsGranted > 0) {
            return false;
        }
        ++$this->inputClaimsGranted;

        return true;
    }

    /** Run returned by findByUuid() (default null = unknown). */
    public ?AgentRun $findResult = null;

    public function findByUuid(string $uuid): ?AgentRun
    {
        return $this->findResult;
    }

    /** @var list<AgentRunEvent> */
    public array $eventsToReturn = [];

    public function findEvents(int $runUid, int $afterSequence = -1): array
    {
        // Mirrors the SQL predicate: only events past the requested position.
        return array_values(array_filter(
            $this->eventsToReturn,
            static fn(AgentRunEvent $event): bool => $event->sequence > $afterSequence,
        ));
    }

    public bool $throwOnMaxSequence = false;

    /** Highest stored event sequence reported to resumeHandle() (-1 = none). */
    public int $maxSequence = -1;

    /**
     * When non-empty, each maxEventSequence() call shifts the next value —
     * models a stream that grows between two position resolutions (the
     * probe-before-claim vs. resolve-after-claim ordering, ADR-101).
     *
     * @var list<int>
     */
    public array $maxSequenceReturns = [];

    public function maxEventSequence(int $runUid): int
    {
        if ($this->throwOnMaxSequence) {
            throw new RuntimeException('maxEventSequence failed', 1784600403);
        }
        if ($this->maxSequenceReturns !== []) {
            return array_shift($this->maxSequenceReturns);
        }

        return $this->maxSequence;
    }

    public bool $throwOnRenewLease = false;

    /** Simulates a lost lease (reaped/cancelled/settled): renewLease matches no row. */
    public bool $refuseRenewLease = false;

    /** @var list<array{runUid: int, claimedBy: string, leaseExpires: int}> */
    public array $leaseRenewals = [];

    public function renewLease(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        if ($this->throwOnRenewLease) {
            throw new RuntimeException('renewLease failed', 1784700020);
        }
        if ($this->refuseRenewLease) {
            return false;
        }
        $this->leaseRenewals[] = ['runUid' => $runUid, 'claimedBy' => $claimedBy, 'leaseExpires' => $leaseExpires];

        return true;
    }

    /** @var list<array{runUid: int, claimedBy: string, effect: string, leaseExpires: int}> */
    public array $pendingEffects = [];

    public function markPendingEffect(int $runUid, string $claimedBy, string $effect, int $leaseExpires): bool
    {
        if ($this->throwOnRenewLease) {
            throw new RuntimeException('markPendingEffect failed', 1785000020);
        }
        if ($this->refuseRenewLease) {
            return false;
        }
        $this->pendingEffects[] = ['runUid' => $runUid, 'claimedBy' => $claimedBy, 'effect' => $effect, 'leaseExpires' => $leaseExpires];

        return true;
    }

    public bool $throwOnRequeue = false;

    /** Simulates a run this worker no longer owns: requeue matches no row. */
    public bool $refuseRequeue = false;

    /** @var list<array{runUid: int, claimedBy: string}> */
    public array $requeues = [];

    public function requeue(int $runUid, string $claimedBy): bool
    {
        if ($this->throwOnRequeue) {
            throw new RuntimeException('requeue failed', 1784700021);
        }
        if ($this->refuseRequeue) {
            return false;
        }
        $this->requeues[] = ['runUid' => $runUid, 'claimedBy' => $claimedBy];

        return true;
    }

    /** @var list<AgentRun> */
    public array $staleRunning = [];

    /** @var list<AgentRun> */
    public array $awaiting = [];

    /** @var list<AgentRun> */
    public array $recentTerminal = [];

    public function findStaleRunning(int $now, int $limit = 50): array
    {
        return $this->staleRunning;
    }

    public function findAwaiting(int $limit = 100): array
    {
        return $this->awaiting;
    }

    public function findRecentTerminal(int $limit = 20): array
    {
        return $this->recentTerminal;
    }

    /** Simulates a run reclaimed by a heartbeat between SELECT and UPDATE. */
    public bool $refuseRequeueStale = false;

    /** @var list<array{runUid: int, now: int}> */
    public array $staleRequeues = [];

    public function requeueStale(int $runUid, int $now): bool
    {
        if ($this->refuseRequeueStale) {
            return false;
        }
        $this->staleRequeues[] = ['runUid' => $runUid, 'now' => $now];

        return true;
    }

    /** @var list<array{runUid: int, now: int, reason: string}> */
    public array $staleDeadLetters = [];

    public bool $refuseDeadLetterStale = false;

    public function deadLetterStale(int $runUid, int $now, string $terminationReason): bool
    {
        if ($this->refuseDeadLetterStale) {
            return false;
        }
        $this->staleDeadLetters[] = ['runUid' => $runUid, 'now' => $now, 'reason' => $terminationReason];

        return true;
    }

    public function purgeOlderThan(int $timestamp): int
    {
        return 0;
    }

    public function purgeUnfinishedOlderThan(int $timestamp): int
    {
        return 0;
    }
}
