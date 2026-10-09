<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Domain\ValueObject\SkillSourceFacts;

/**
 * Decides whether a skill version is composed as an instruction (ADR-214
 * item 2).
 *
 * Two conditions, both checked at every composition, never only at approval
 * time:
 *
 * - the provenance level of the skill's source, read from the source record
 *   (not the column the sync denormalises onto the skill), is at or above the
 *   instruction threshold ``skills.instructionTrustLevel``; and
 * - an unrevoked approval names the skill, its current source and the digest
 *   of the version being composed.
 *
 * Provenance and approval stay separate: nothing here rewrites a trust level,
 * and "reset to untrusted" means "no unrevoked approval matches".
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillInstructionPolicy
{
    public function __construct(
        private SkillApprovalRepositoryInterface $approvals,
        private SkillSourceLookupInterface $sources,
        private SkillTrustLevel $threshold,
        // Whether the skill record is active (not deleted, hidden, disabled or
        // orphaned), read now: the same rule the pin check applies at resume,
        // so a skill hidden to stop it cannot instruct through a forced list
        // that ignores enable fields. Optional for lean test wiring only;
        // production wires it (ToolLoopGateWiringTest).
        private ?SkillRecordLookupInterface $records = null,
    ) {}

    /**
     * The effective threshold this policy applies.
     */
    public function threshold(): SkillTrustLevel
    {
        return $this->threshold;
    }

    /**
     * Whether this version of the skill instructs.
     *
     * `$versionDigest` is the digest of the version that would be composed;
     * the caller has already verified that it belongs to the record's fields.
     */
    public function isInstruction(Skill $skill, string $versionDigest): bool
    {
        $uid = $skill->getUid();
        if ($uid === null || $uid <= 0 || !SkillVersionDigest::isWellFormed($versionDigest)) {
            return false;
        }

        if (!$this->provenanceSuffices($skill->getSource())) {
            return false;
        }

        if ($this->records instanceof SkillRecordLookupInterface && !$this->records->isActive($uid)) {
            return false;
        }

        return $this->approvals->hasUnrevokedApproval($uid, $skill->getSource(), $versionDigest);
    }

    /**
     * The tool declaration of an approved version of the skill from its
     * current source (ADR-214 item 3): the version with the given digest
     * when that one is approved — the version that instructs — and otherwise
     * the most recent unrevoked approved version. Null when that version
     * declared none; the declared empty list when no version is approved, so
     * an unapproved backend skill grants nothing and still counts as a
     * declaration, and when the approved version is a process skill and the
     * run does not invoke it.
     *
     * @return list<string>|null
     */
    public function approvedToolsOf(Skill $skill, ?string $currentDigest = null, bool $invoked = false): ?array
    {
        $uid = $skill->getUid();
        if ($uid === null || $uid <= 0) {
            return [];
        }

        $approval = $currentDigest !== null && $currentDigest !== ''
            ? $this->approvals->findUnrevoked($uid, $skill->getSource(), $currentDigest)
            : null;
        $approval ??= $this->approvals->findLatestUnrevokedFromSource($uid, $skill->getSource());

        // A process version grants tools only to a run that invokes it.
        if (!$approval instanceof SkillApproval || ($approval->process && !$invoked)) {
            return [];
        }

        return $approval->allowedTools;
    }

    /**
     * Whether the source record vouches at or above the threshold right now.
     */
    public function provenanceSuffices(int $sourceUid): bool
    {
        $facts = $this->sources->find($sourceUid);

        return $facts instanceof SkillSourceFacts && $facts->trustLevel->satisfies($this->threshold);
    }
}
