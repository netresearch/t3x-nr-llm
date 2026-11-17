<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture;

use Netresearch\NrLlm\Domain\ValueObject\SkillEvidence;
use Netresearch\NrLlm\Service\Evaluation\SkillEvidenceLookupInterface;

/**
 * The evaluations of skill versions as a test sets them up (ADR-322).
 */
final class InMemorySkillEvidenceLookup implements SkillEvidenceLookupInterface
{
    /** @var array<string, list<SkillEvidence>> by skill uid and digest */
    public array $evidence = [];

    /** @var list<int> the evaluation uids that are still stored */
    public array $stored = [];

    public function add(int $skillUid, string $versionDigest, SkillEvidence $evidence): void
    {
        $this->evidence[$skillUid . "\x00" . $versionDigest][] = $evidence;
        $this->stored[]                                        = $evidence->evaluationUid;
    }

    public function evidenceFor(int $skillUid, string $versionDigest): array
    {
        return array_slice($this->evidence[$skillUid . "\x00" . $versionDigest] ?? [], 0, self::LIMIT);
    }

    public function isAvailable(SkillEvidence $evidence): bool
    {
        return in_array($evidence->evaluationUid, $this->stored, true);
    }
}
