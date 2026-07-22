<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command\Fixture;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;

/**
 * In-memory agent-run repository for command tests: captures the cutoffs the
 * two purge paths (finished runs vs. runs still awaiting a decision) were asked
 * to run, so the retention flow can be exercised without a database.
 */
final class InMemoryAgentRunRepository implements AgentRunRepositoryInterface
{
    /** Cutoff the last purgeOlderThan() (finished runs) was asked to delete below. */
    public ?int $purgeCutoff = null;

    /** Cutoff the last purgeUnfinishedOlderThan() was asked to delete below. */
    public ?int $purgeUnfinishedCutoff = null;

    public int $purgeReturns = 0;

    public int $purgeUnfinishedReturns = 0;

    public function startRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser): int
    {
        return 0;
    }

    public function recordEvent(int $runUid, int $sequence, string $kind, int $round, float $durationMs, string $payloadJson): void
    {
        // Not needed by the command tests.
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
    ): bool {
        return true;
    }

    public function suspendRun(int $runUid, string $stateJson): bool
    {
        // Not needed by the command tests.
        return true;
    }

    public function suspendRunForInput(int $runUid, string $stateJson): bool
    {
        // Not needed by the command tests.
        return true;
    }

    public function enqueueRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser, string $requestJson): int
    {
        // Not needed by the command tests.
        return 0;
    }

    public function claimQueued(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        // Not needed by the command tests.
        return false;
    }

    public function claimForResume(int $runUid): bool
    {
        return false;
    }

    public function claimForResumeFromInput(int $runUid): bool
    {
        return false;
    }

    public function renewLease(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        // Not needed by the command tests.
        return true;
    }

    public function requeue(int $runUid, string $claimedBy): bool
    {
        // Not needed by the command tests.
        return true;
    }

    /** @var list<AgentRun> Stale runs the reaper command will iterate. */
    public array $staleRunning = [];

    /** @var list<array{runUid: int, now: int}> requeueStale() calls the reaper made. */
    public array $staleRequeues = [];

    /** uids for which requeueStale() reports the run was already renewed/moved on. */
    public bool $refuseRequeueStale = false;

    public function findStaleRunning(int $now, int $limit = 50): array
    {
        return $this->staleRunning;
    }

    public function requeueStale(int $runUid, int $now): bool
    {
        if ($this->refuseRequeueStale) {
            return false;
        }
        $this->staleRequeues[] = ['runUid' => $runUid, 'now' => $now];

        return true;
    }

    /** @var list<array{runUid: int, now: int, reason: string}> deadLetterStale() calls the reaper made. */
    public array $staleDeadLetters = [];

    /** requeueStale/deadLetterStale report the run was already renewed/moved on. */
    public bool $refuseDeadLetterStale = false;

    public function deadLetterStale(int $runUid, int $now, string $terminationReason): bool
    {
        if ($this->refuseDeadLetterStale) {
            return false;
        }
        $this->staleDeadLetters[] = ['runUid' => $runUid, 'now' => $now, 'reason' => $terminationReason];

        return true;
    }

    public function findByUuid(string $uuid): ?AgentRun
    {
        return null;
    }

    public function findEvents(int $runUid, int $afterSequence = -1): array
    {
        return [];
    }

    public function maxEventSequence(int $runUid): int
    {
        return -1;
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $this->purgeCutoff = $timestamp;

        return $this->purgeReturns;
    }

    public function purgeUnfinishedOlderThan(int $timestamp): int
    {
        $this->purgeUnfinishedCutoff = $timestamp;

        return $this->purgeUnfinishedReturns;
    }
}
