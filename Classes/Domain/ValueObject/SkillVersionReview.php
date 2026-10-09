<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;

/**
 * Everything the approval form shows about a skill's current version
 * (ADR-214 item 2).
 *
 * `currentDigest` is null when the record fails its integrity check and ''
 * for a legacy row without a digest; neither can be approved. The diff fields
 * compare the current version with `latestApproval`, the most recent
 * unrevoked approval of the skill; they are '' when there is none to compare
 * against. The diffs are HTML produced by TYPO3's DiffUtility, which escapes
 * both sides.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillVersionReview
{
    /**
     * @param list<string>|null   $allowedTools the current version's tool declaration, null when it declares none
     * @param list<SkillApproval> $history      every approval of the skill, newest first
     */
    public function __construct(
        public ?string $currentDigest,
        public bool $isInstruction,
        public bool $currentDigestApproved,
        public ?SkillTrustLevel $provenance,
        public SkillTrustLevel $threshold,
        public ?array $allowedTools,
        public ?SkillApproval $latestApproval,
        public string $nameDiff,
        public string $descriptionDiff,
        public string $bodyDiff,
        public string $allowedToolsDiff,
        public array $history,
    ) {}

    public function isApprovable(): bool
    {
        return $this->currentDigest !== null && $this->currentDigest !== '' && !$this->currentDigestApproved;
    }

    public function getIsApprovable(): bool
    {
        return $this->isApprovable();
    }

    public function isIntegrityFailure(): bool
    {
        return $this->currentDigest === null;
    }

    public function getIsIntegrityFailure(): bool
    {
        return $this->isIntegrityFailure();
    }

    public function isLegacy(): bool
    {
        return $this->currentDigest === '';
    }

    public function getIsLegacy(): bool
    {
        return $this->isLegacy();
    }

    /**
     * Whether the current version declares an allow-list at all; an empty
     * declaration is one, and grants no tools.
     */
    public function getDeclaresTools(): bool
    {
        return $this->allowedTools !== null;
    }

    public function provenanceSuffices(): bool
    {
        return $this->provenance instanceof SkillTrustLevel && $this->provenance->satisfies($this->threshold);
    }

    public function getProvenanceSuffices(): bool
    {
        return $this->provenanceSuffices();
    }
}
