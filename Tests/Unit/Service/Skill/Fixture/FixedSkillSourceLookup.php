<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\ValueObject\SkillSourceFacts;
use Netresearch\NrLlm\Service\Skill\SkillSourceLookupInterface;

/**
 * Skill sources with fixed trust levels; a source not listed does not exist.
 */
final class FixedSkillSourceLookup implements SkillSourceLookupInterface
{
    /**
     * @param array<int, SkillTrustLevel> $levels source uid → trust level
     */
    public function __construct(
        public array $levels = [],
    ) {}

    public function find(int $sourceUid): ?SkillSourceFacts
    {
        $level = $this->levels[$sourceUid] ?? null;

        return $level instanceof SkillTrustLevel
            ? new SkillSourceFacts($sourceUid, SkillSourceType::REPO, $level)
            : null;
    }
}
