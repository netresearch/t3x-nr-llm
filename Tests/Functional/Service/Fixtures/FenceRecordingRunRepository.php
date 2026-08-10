<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Fixtures;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;

/**
 * A pass-through repository that records the ADR-111 write fence as it is
 * written.
 *
 * The fence is a stamp-then-clear pair around a side effect: by the time a
 * synchronous run returns, `pending_effect` is empty again and the database
 * cannot tell "fenced and cleared" from "never fenced". This decorator keeps the
 * ORDER, so a test can assert the write was fenced BEFORE it ran rather than
 * inferring it from a value that is gone.
 *
 * Everything else is the real repository on the real connection — only the
 * observation is added.
 */
final class FenceRecordingRunRepository implements AgentRunRepositoryInterface
{
    /** @var list<array{effect: string, claimedBy: string}> in the order they were written */
    public array $fenceWrites = [];

    /** @var list<array{claimedBy: string, leaseExpires: int}> */
    public array $resumeClaims = [];

    public function __construct(private readonly AgentRunRepositoryInterface $inner) {}

    public function markPendingEffect(int $runUid, string $claimedBy, string $effect, int $leaseExpires): bool
    {
        $this->fenceWrites[] = ['effect' => $effect, 'claimedBy' => $claimedBy];

        return $this->inner->markPendingEffect($runUid, $claimedBy, $effect, $leaseExpires);
    }

    public function claimForResume(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        $granted = $this->inner->claimForResume($runUid, $claimedBy, $leaseExpires);
        if ($granted) {
            $this->resumeClaims[] = ['claimedBy' => $claimedBy, 'leaseExpires' => $leaseExpires];
        }

        return $granted;
    }

    public function claimForResumeFromInput(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        $granted = $this->inner->claimForResumeFromInput($runUid, $claimedBy, $leaseExpires);
        if ($granted) {
            $this->resumeClaims[] = ['claimedBy' => $claimedBy, 'leaseExpires' => $leaseExpires];
        }

        return $granted;
    }

    public function startRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser, string $claimedBy = '', int $leaseExpires = 0): int
    {
        return $this->inner->startRun($uuid, $configurationUid, $configurationIdentifier, $beUser, $claimedBy, $leaseExpires);
    }

    public function recordEvent(int $runUid, int $sequence, string $kind, int $round, float $durationMs, string $payloadJson): void
    {
        $this->inner->recordEvent($runUid, $sequence, $kind, $round, $durationMs, $payloadJson);
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
        return $this->inner->finishRun($runUid, $status, $iterations, $truncated, $promptTokens, $completionTokens, $totalTokens, $estimatedCost, $errorClass, $terminationReason, $ownedBy);
    }

    public function suspendRun(int $runUid, string $stateJson): bool
    {
        return $this->inner->suspendRun($runUid, $stateJson);
    }

    public function suspendRunForInput(int $runUid, string $stateJson): bool
    {
        return $this->inner->suspendRunForInput($runUid, $stateJson);
    }

    public function enqueueRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser, string $requestJson): int
    {
        return $this->inner->enqueueRun($uuid, $configurationUid, $configurationIdentifier, $beUser, $requestJson);
    }

    public function claimQueued(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        return $this->inner->claimQueued($runUid, $claimedBy, $leaseExpires);
    }

    public function renewLease(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        return $this->inner->renewLease($runUid, $claimedBy, $leaseExpires);
    }

    public function requeue(int $runUid, string $claimedBy): bool
    {
        return $this->inner->requeue($runUid, $claimedBy);
    }

    public function findStaleRunning(int $now, int $limit = 50): array
    {
        return $this->inner->findStaleRunning($now, $limit);
    }

    public function requeueStale(int $runUid, int $now): bool
    {
        return $this->inner->requeueStale($runUid, $now);
    }

    public function deadLetterStale(int $runUid, int $now, string $terminationReason): bool
    {
        return $this->inner->deadLetterStale($runUid, $now, $terminationReason);
    }

    public function findByUuid(string $uuid): ?AgentRun
    {
        return $this->inner->findByUuid($uuid);
    }

    public function findAwaiting(int $limit = 100, ?int $beUser = null): array
    {
        return $this->inner->findAwaiting($limit, $beUser);
    }

    public function findRecentTerminal(int $limit = 20, ?int $beUser = null): array
    {
        return $this->inner->findRecentTerminal($limit, $beUser);
    }

    public function countByStatus(int $since = 0): array
    {
        return $this->inner->countByStatus($since);
    }

    public function countByTerminationReason(int $since = 0): array
    {
        return $this->inner->countByTerminationReason($since);
    }

    public function countInStatus(AgentRunStatus $status): int
    {
        return $this->inner->countInStatus($status);
    }

    /**
     * @return list<AgentRunEvent>
     */
    public function findEvents(int $runUid, int $afterSequence = -1): array
    {
        return $this->inner->findEvents($runUid, $afterSequence);
    }

    public function maxEventSequence(int $runUid): int
    {
        return $this->inner->maxEventSequence($runUid);
    }

    public function purgeOlderThan(int $timestamp): int
    {
        return $this->inner->purgeOlderThan($timestamp);
    }

    public function purgeUnfinishedOlderThan(int $timestamp): int
    {
        return $this->inner->purgeUnfinishedOlderThan($timestamp);
    }
}
