<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Fixtures;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use RuntimeException;

/**
 * The real, database-backed repository with ONE fault injected: the event of a
 * chosen kind cannot be written, or — with `$failsApprovalDeciderRead` — the
 * approval deciders of the inbox's listed runs cannot be read.
 *
 * Models the store hiccup that hits a single audit record rather than the whole
 * connection, which is what {@see \Netresearch\NrLlm\Service\Tool\AgentRunPersister::recordApproval()}'s
 * boolean is about (ADR-132). Everything else — the claim, the suspend, the
 * status transitions the assertions read back — stays real, so the test asserts
 * the row the operator would actually see.
 *
 * The read fault is the mirror for ADR-173: it enters
 * {@see \Netresearch\NrLlm\Service\Tool\AgentRunPersister::findApprovalDeciders()}'s
 * catch, which a delegating fixture never does — and against a delegating
 * fixture, swallow-and-return-`[]` and a rethrow are the same test.
 */
final readonly class ApprovalEventFailingRunRepository implements AgentRunRepositoryInterface
{
    public function __construct(
        private AgentRunRepositoryInterface $inner,
        private string $failingKind,
        private bool $failsApprovalDeciderRead = false,
    ) {}

    public function recordEvent(int $runUid, int $sequence, string $kind, int $round, float $durationMs, string $payloadJson): void
    {
        if ($kind === $this->failingKind) {
            throw new RuntimeException(sprintf('recordEvent(%s) failed', $kind), 1785200002);
        }

        $this->inner->recordEvent($runUid, $sequence, $kind, $round, $durationMs, $payloadJson);
    }

    public function startRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser, string $claimedBy = '', int $leaseExpires = 0): int
    {
        return $this->inner->startRun($uuid, $configurationUid, $configurationIdentifier, $beUser, $claimedBy, $leaseExpires);
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

    public function claimForResume(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        return $this->inner->claimForResume($runUid, $claimedBy, $leaseExpires);
    }

    public function claimForResumeFromInput(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        return $this->inner->claimForResumeFromInput($runUid, $claimedBy, $leaseExpires);
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

    public function markPendingEffect(int $runUid, string $claimedBy, string $effect, int $leaseExpires): bool
    {
        return $this->inner->markPendingEffect($runUid, $claimedBy, $effect, $leaseExpires);
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

    public function findEvents(int $runUid, int $afterSequence = -1): array
    {
        return $this->inner->findEvents($runUid, $afterSequence);
    }

    public function findApprovalDeciders(array $runUids): array
    {
        if ($this->failsApprovalDeciderRead) {
            throw new RuntimeException('findApprovalDeciders() failed', 1786500001);
        }

        return $this->inner->findApprovalDeciders($runUids);
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
