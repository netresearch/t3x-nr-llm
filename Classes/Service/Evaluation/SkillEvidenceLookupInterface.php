<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Domain\ValueObject\SkillEvidence;

/**
 * The evaluations that measured a skill version (ADR-322).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface SkillEvidenceLookupInterface
{
    /** How many evaluations a run records per version, newest first. */
    public const LIMIT = 3;

    /**
     * The evaluations stored now that measured this skill at this digest,
     * newest first, at most {@see self::LIMIT}. Their fit is unknown.
     *
     * @return list<SkillEvidence>
     */
    public function evidenceFor(int $skillUid, string $versionDigest): array;

    /**
     * Whether the evaluation a snapshot was taken from is still stored: the
     * same uid with the same run date, benchmark fingerprint and set. False once
     * retention purged it, and for a row that reuses the uid.
     */
    public function isAvailable(SkillEvidence $evidence): bool;
}
