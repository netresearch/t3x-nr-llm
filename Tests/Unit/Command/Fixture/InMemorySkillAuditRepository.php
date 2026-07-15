<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command\Fixture;

use Netresearch\NrLlm\Service\Skill\SkillAuditRepositoryInterface;

/**
 * In-memory skill audit repository for command tests: captures the cutoff a
 * purge was asked to run so the command's retention flow can be exercised
 * without a database.
 */
final class InMemorySkillAuditRepository implements SkillAuditRepositoryInterface
{
    /** The cutoff timestamp the last purgeOlderThan() was asked to delete below. */
    public ?int $purgeCutoff = null;

    /** The row count purgeOlderThan() reports as deleted. */
    public int $purgeReturns = 0;

    public function record(
        string $event,
        int $sourceUid,
        string $skillIdentifier,
        string $sourceSha,
        string $bodyChecksum,
        string $trustLevel,
        string $scanResult,
        int $actorUid,
        string $detail,
    ): void {
        // Not needed by the command tests.
    }

    public function findBySourceUid(int $sourceUid): array
    {
        return [];
    }

    public function countAll(): int
    {
        return 0;
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $this->purgeCutoff = $timestamp;

        return $this->purgeReturns;
    }
}
