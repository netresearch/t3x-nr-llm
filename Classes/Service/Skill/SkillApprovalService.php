<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillApprovalOutcome;
use Netresearch\NrLlm\Domain\Enum\SkillAuditEvent;
use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Domain\ValueObject\SkillSourceFacts;
use Netresearch\NrLlm\Domain\ValueObject\SkillVersionReview;
use TYPO3\CMS\Core\Utility\DiffUtility;

/**
 * Approves and revokes skill versions (ADR-214 item 2).
 *
 * An approval is a decision about the state the approver saw: the form posts
 * the digest it rendered, and {@see self::approveVersion()} recomputes the record's
 * current digest and refuses when the two differ — the rule ADR-184 applies to
 * a write, applied to a skill version. The record must also pass the same
 * integrity check the composer applies ({@see SkillVersionDigest::verified()}),
 * so a version the composer would skip can never be approved.
 *
 * Approving does not need the source's provenance to meet the instruction
 * threshold: provenance and approval are separate fields. A version approved
 * on a source below the threshold stays fenced until the source is
 * re-classified or the threshold lowered, and the form says so.
 *
 * Every approval, revocation and refused approval is written to the
 * append-only skill audit trail with the digest it concerns.
 *
 * Callers authorise: only administrators may approve or revoke until the
 * permission is decided (ADR-214, open questions).
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillApprovalService
{
    public function __construct(
        private SkillApprovalRepositoryInterface $approvals,
        private SkillSourceLookupInterface $sources,
        private SkillComposerFactory $composerFactory,
        private SkillAuditService $audit,
        private DiffUtility $diff,
    ) {}

    /**
     * What the approval form shows for the skill's current version.
     */
    public function review(Skill $skill): SkillVersionReview
    {
        $uid       = (int)$skill->getUid();
        $digest    = SkillVersionDigest::verified($skill);
        $policy    = $this->composerFactory->instructionPolicy();
        $threshold = $policy?->threshold() ?? $this->composerFactory->instructionTrustLevel();
        $latest    = $this->approvals->findLatestUnrevoked($uid);
        $fields    = SkillVersionDigest::fieldsOf($skill);

        $approved = $digest !== null && $digest !== ''
            && $this->approvals->hasUnrevokedApproval($uid, $skill->getSource(), $digest);

        return new SkillVersionReview(
            currentDigest: $digest,
            isInstruction: $approved && $digest !== null && $policy?->isInstruction($skill, $digest) === true,
            currentDigestApproved: $approved,
            provenance: $this->provenanceOf($skill),
            threshold: $threshold,
            allowedTools: $fields['allowed_tools'],
            latestApproval: $latest,
            nameDiff: $latest instanceof SkillApproval ? $this->diff->diff($latest->name, $fields['name']) : '',
            descriptionDiff: $latest instanceof SkillApproval ? $this->diff->diff($latest->description, $fields['description']) : '',
            bodyDiff: $latest instanceof SkillApproval ? $this->diff->diff($latest->body, $fields['body']) : '',
            allowedToolsDiff: $latest instanceof SkillApproval
                ? $this->diff->diff($this->toolsText($latest->allowedTools), $this->toolsText($fields['allowed_tools']))
                : '',
            history: $this->approvals->findBySkill($uid),
            invisibleCharacters: $this->invisibleIn($skill),
        );
    }

    /**
     * Approve the version the approver saw, if it is still the current one.
     *
     * @param string $seenDigest the digest the approval form rendered
     */
    public function approveVersion(Skill $skill, string $seenDigest, int $actorUid): SkillApprovalOutcome
    {
        $outcome = $this->refusal($skill, $seenDigest);
        $trust   = $this->trustLevelNow($skill);

        if ($outcome instanceof SkillApprovalOutcome) {
            $this->audit->recordVersionEvent(
                SkillAuditEvent::VERSION_APPROVAL_REFUSED,
                $skill,
                $this->auditable($seenDigest),
                $trust,
                sprintf('%s; current digest %s', $outcome->value, SkillVersionDigest::verified($skill) ?? 'unverifiable'),
            );

            return $outcome;
        }

        $this->approvals->add(
            (int)$skill->getUid(),
            $skill->getSource(),
            $seenDigest,
            SkillVersionDigest::fieldsOf($skill),
            $trust,
            $actorUid,
        );
        $this->audit->recordVersionEvent(SkillAuditEvent::VERSION_APPROVED, $skill, $seenDigest, $trust);

        return SkillApprovalOutcome::APPROVED;
    }

    /**
     * Revoke every approval of one version of the skill. Returns the number
     * of approvals revoked; the revocation is audited even when that is 0, so
     * the trail shows the administrator's act.
     */
    public function revokeVersion(Skill $skill, string $versionDigest, int $actorUid): int
    {
        $revoked = $this->approvals->revoke((int)$skill->getUid(), $versionDigest, $actorUid);
        $this->audit->recordVersionEvent(
            SkillAuditEvent::VERSION_REVOKED,
            $skill,
            $this->auditable($versionDigest),
            $this->trustLevelNow($skill),
            sprintf('%d approval(s) revoked', $revoked),
        );

        return $revoked;
    }

    /**
     * The posted digest as the audit row may store it: a well-formed digest
     * as is, anything else as empty. The value comes from a form, so the row
     * must not carry arbitrary text in a column sized for a digest.
     */
    private function auditable(string $postedDigest): string
    {
        return SkillVersionDigest::isWellFormed($postedDigest) ? $postedDigest : '';
    }

    /**
     * Why an approval of the seen digest must be refused, or null when it may
     * proceed. The order is the order of the checks the form can explain: a
     * record that fails its integrity check is reported as such even when the
     * posted digest also differs.
     */
    private function refusal(Skill $skill, string $seenDigest): ?SkillApprovalOutcome
    {
        if ($skill->isOrphaned()) {
            return SkillApprovalOutcome::REFUSED_ORPHANED;
        }

        $current = SkillVersionDigest::verified($skill);
        if ($current === null) {
            return SkillApprovalOutcome::REFUSED_INTEGRITY;
        }

        if ($current === '') {
            return SkillApprovalOutcome::REFUSED_LEGACY;
        }

        if ((int)$skill->getUid() <= 0 || !hash_equals($current, $seenDigest)) {
            return SkillApprovalOutcome::REFUSED_STALE;
        }

        if ($this->invisibleIn($skill) !== []) {
            return SkillApprovalOutcome::REFUSED_INVISIBLE;
        }

        return null;
    }

    /**
     * The characters of the version a model reads and the review page cannot
     * show (ADR-214 item 2): the approval binds to bytes, so every byte must
     * be visible to the approver.
     *
     * @return list<string>
     */
    private function invisibleIn(Skill $skill): array
    {
        return SkillInvisibleCharacters::findIn([
            'name'        => $skill->getName(),
            'description' => $skill->getDescription(),
            'body'        => $skill->getBody(),
        ]);
    }

    /**
     * The provenance level recorded with an approval or a revocation: the
     * source record's current level, or the level the sync denormalised onto
     * the skill when the source record is gone.
     */
    private function trustLevelNow(Skill $skill): string
    {
        $level = $this->provenanceOf($skill);

        return $level instanceof SkillTrustLevel ? $level->value : $skill->getTrustLevel();
    }

    private function provenanceOf(Skill $skill): ?SkillTrustLevel
    {
        $facts = $this->sources->find($skill->getSource());

        return $facts instanceof SkillSourceFacts ? $facts->trustLevel : null;
    }

    /**
     * @param list<string>|null $tools
     */
    private function toolsText(?array $tools): string
    {
        return $tools === null ? '(no declaration)' : implode("\n", $tools);
    }
}
