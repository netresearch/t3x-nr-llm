<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture;

use Netresearch\NrLlm\Service\Skill\SkillRecordLookupInterface;

/**
 * Skill records that exist and are not orphaned; a uid not listed is gone.
 */
final class FixedSkillRecordLookup implements SkillRecordLookupInterface
{
    /**
     * @param list<int> $present
     */
    public function __construct(
        public array $present = [],
    ) {}

    public function existsAndNotOrphaned(int $skillUid): bool
    {
        return in_array($skillUid, $this->present, true);
    }
}
