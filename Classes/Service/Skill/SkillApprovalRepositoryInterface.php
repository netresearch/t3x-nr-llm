<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;

/**
 * Store for the approvals of skill versions (ADR-214 item 2).
 *
 * An approval names one skill, one source and one version digest. A version
 * instructs only while an unrevoked approval for exactly that triple exists, so
 * moving a skill to another source, or changing one byte of what the model
 * reads, leaves it without a match. Revocation names a skill and a digest and
 * wins over every approval of that pair made before it; only a new approval
 * makes the digest instruct again.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface SkillApprovalRepositoryInterface
{
    /**
     * Whether an unrevoked approval names this skill, source and digest.
     */
    public function hasUnrevokedApproval(int $skillUid, int $sourceUid, string $versionDigest): bool;

    /**
     * The most recent unrevoked approval of exactly this skill, source and
     * digest — the snapshot a pinned section is composed from — or null.
     */
    public function findUnrevoked(int $skillUid, int $sourceUid, string $versionDigest): ?SkillApproval;

    /**
     * The most recent unrevoked approval of the skill, whatever its digest or
     * source, or null. What the approval form diffs the current version against.
     */
    public function findLatestUnrevoked(int $skillUid): ?SkillApproval;

    /**
     * The most recent unrevoked approval of the skill from exactly this
     * source, whatever its digest, or null. What a backend-authored skill's
     * tool declaration is read from (ADR-214 item 3).
     */
    public function findLatestUnrevokedFromSource(int $skillUid, int $sourceUid): ?SkillApproval;

    /**
     * Every approval of the skill, newest first.
     *
     * @return list<SkillApproval>
     */
    public function findBySkill(int $skillUid): array;

    /**
     * Store an approval snapshot and return its uid.
     *
     * @param array{name: string, description: string, body: string, support_status: string, allowed_tools: list<string>|null, process: bool} $fields
     */
    public function add(int $skillUid, int $sourceUid, string $versionDigest, array $fields, string $trustLevel, int $approvedBy): int;

    /**
     * Revoke every unrevoked approval of this skill and digest, whatever its
     * source. Returns the number of approvals revoked.
     */
    public function revoke(int $skillUid, string $versionDigest, int $revokedBy): int;
}
