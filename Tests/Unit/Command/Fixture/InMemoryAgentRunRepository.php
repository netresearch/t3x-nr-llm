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

    public function claimForResume(int $runUid): bool
    {
        return false;
    }

    public function findByUuid(string $uuid): ?AgentRun
    {
        return null;
    }

    public function findEvents(int $runUid): array
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
