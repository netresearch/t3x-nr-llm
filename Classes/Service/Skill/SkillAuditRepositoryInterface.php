<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

/**
 * Persistence contract for the append-only skill audit trail (ADR-061).
 *
 * Deliberately narrow: append one immutable row, read a source's rows, count
 * rows, and — the one retention exception (ADR-064) — purge rows older than a
 * cutoff. There is no update path.
 */
interface SkillAuditRepositoryInterface
{
    /**
     * Append one immutable audit row.
     */
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
    ): void;

    /**
     * The audit rows for a source, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function findBySourceUid(int $sourceUid): array;

    /**
     * Total number of audit rows.
     */
    public function countAll(): int;

    /**
     * Delete rows created strictly before the given UNIX timestamp.
     *
     * @return int number of rows deleted
     */
    public function purgeOlderThan(int $timestamp): int;
}
