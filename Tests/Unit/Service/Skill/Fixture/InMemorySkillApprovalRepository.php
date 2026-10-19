<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture;

use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Service\Skill\SkillApprovalRepositoryInterface;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;

/**
 * An approval store held in memory, with the same matching rules as the DBAL
 * one: an approval names a skill, a source and a digest; a revocation names a
 * skill and a digest and flags every row of the pair.
 */
final class InMemorySkillApprovalRepository implements SkillApprovalRepositoryInterface
{
    /** @var list<SkillApproval> */
    private array $rows = [];

    public function hasUnrevokedApproval(int $skillUid, int $sourceUid, string $versionDigest): bool
    {
        return $this->findUnrevoked($skillUid, $sourceUid, $versionDigest) instanceof SkillApproval;
    }

    public function findUnrevoked(int $skillUid, int $sourceUid, string $versionDigest): ?SkillApproval
    {
        foreach (array_reverse($this->rows) as $row) {
            if ($row->skillUid === $skillUid && $row->sourceUid === $sourceUid && $row->versionDigest === $versionDigest && !$row->revoked) {
                return $row;
            }
        }

        return null;
    }

    public function findLatestUnrevoked(int $skillUid): ?SkillApproval
    {
        foreach (array_reverse($this->rows) as $row) {
            if ($row->skillUid === $skillUid && !$row->revoked) {
                return $row;
            }
        }

        return null;
    }

    public function findLatestUnrevokedFromSource(int $skillUid, int $sourceUid): ?SkillApproval
    {
        foreach (array_reverse($this->rows) as $row) {
            if ($row->skillUid === $skillUid && $row->sourceUid === $sourceUid && !$row->revoked) {
                return $row;
            }
        }

        return null;
    }

    public function findBySkill(int $skillUid): array
    {
        return array_values(array_filter(array_reverse($this->rows), static fn(SkillApproval $row): bool => $row->skillUid === $skillUid));
    }

    public function add(int $skillUid, int $sourceUid, string $versionDigest, array $fields, string $trustLevel, int $approvedBy): int
    {
        $uid          = count($this->rows) + 1;
        $this->rows[] = new SkillApproval(
            $uid,
            $skillUid,
            $sourceUid,
            $versionDigest,
            $fields['name'],
            $fields['description'],
            $fields['body'],
            $fields['support_status'],
            SkillVersionDigest::normaliseTools($fields['allowed_tools']),
            $fields['process'],
            $trustLevel,
            $approvedBy,
            1,
            false,
            0,
            0,
        );

        return $uid;
    }

    public function revoke(int $skillUid, string $versionDigest, int $revokedBy): int
    {
        $count = 0;
        foreach ($this->rows as $index => $row) {
            if ($row->skillUid !== $skillUid || $row->versionDigest !== $versionDigest || $row->revoked) {
                continue;
            }

            $this->rows[$index] = new SkillApproval(
                $row->uid,
                $row->skillUid,
                $row->sourceUid,
                $row->versionDigest,
                $row->name,
                $row->description,
                $row->body,
                $row->supportStatus,
                $row->allowedTools,
                $row->process,
                $row->trustLevel,
                $row->approvedBy,
                $row->approvedAt,
                true,
                $revokedBy,
                2,
            );
            $count++;
        }

        return $count;
    }
}
