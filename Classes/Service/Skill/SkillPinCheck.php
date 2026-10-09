<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SkillSourceFacts;
use Netresearch\NrLlm\Exception\SkillInstructionWithdrawnException;

/**
 * The pin rules of ADR-214 item 6: whether an instruction a run holds may
 * keep steering it.
 *
 * A pin holds while
 *
 * - an unrevoked approval for its skill, source and digest exists,
 * - that approval's snapshot still hashes to the digest,
 * - the skill record is active (not deleted, hidden, disabled or orphaned),
 *   and
 * - the source's provenance is at or above the instruction threshold.
 *
 * The check runs wherever a run continues with text it composed earlier:
 * today at every resume of a suspended run, before the approved write
 * executes. A continuation that derives pins from a predecessor run (the
 * process-wiring change of ADR-214 items 6 and 10) calls the same check.
 *
 * Attachment is deliberately not checked here: detaching a skill ends its pin
 * at the next turn without stopping the run, which is the continuation's rule,
 * not the resume's.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillPinCheck
{
    public function __construct(
        private SkillApprovalRepositoryInterface $approvals,
        private SkillSourceLookupInterface $sources,
        private SkillRecordLookupInterface $records,
        private SkillComposerFactory $composerFactory,
    ) {}

    /**
     * Why the pin no longer holds, or null when it holds.
     */
    public function failure(SkillPin $pin): ?string
    {
        $approval = $this->approvals->findUnrevoked($pin->skillUid, $pin->sourceUid, $pin->versionDigest);
        if (!$approval instanceof SkillApproval) {
            return 'its approval was revoked';
        }

        if (!hash_equals($pin->versionDigest, SkillVersionDigest::ofFields($approval->versionFields()))) {
            return 'its approved text no longer matches its version digest';
        }

        if (!$this->records->isActive($pin->skillUid)) {
            return 'the skill was deleted, disabled or orphaned';
        }

        $source = $this->sources->find($pin->sourceUid);
        if (!$source instanceof SkillSourceFacts || !$source->trustLevel->satisfies($this->composerFactory->instructionTrustLevel())) {
            return 'its source is no longer trusted for instructions';
        }

        return null;
    }

    /**
     * Throw for the first pin that no longer holds.
     *
     * @param list<SkillPin> $pins
     *
     * @throws SkillInstructionWithdrawnException
     */
    public function assertHeld(array $pins): void
    {
        foreach ($pins as $pin) {
            $reason = $this->failure($pin);
            if ($reason === null) {
                continue;
            }

            throw new SkillInstructionWithdrawnException($pin, $this->nameOf($pin), $reason);
        }
    }

    /**
     * The skill's name as the approver saw it at this digest, revoked or not.
     */
    private function nameOf(SkillPin $pin): string
    {
        foreach ($this->approvals->findBySkill($pin->skillUid) as $approval) {
            if ($approval->versionDigest === $pin->versionDigest) {
                return $approval->name;
            }
        }

        return 'unknown';
    }
}
