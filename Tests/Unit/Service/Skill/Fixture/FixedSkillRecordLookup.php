<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture;

use Netresearch\NrLlm\Service\Skill\SkillRecordLookupInterface;

/**
 * Active skill records; a uid not listed is deleted, disabled or orphaned.
 */
final class FixedSkillRecordLookup implements SkillRecordLookupInterface
{
    /**
     * @param list<int> $present
     */
    public function __construct(
        public array $present = [],
    ) {}

    public function isActive(int $skillUid): bool
    {
        return in_array($skillUid, $this->present, true);
    }
}
