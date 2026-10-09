<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture;

use Netresearch\NrLlm\Service\Skill\SkillLoadLookupInterface;

/**
 * Stored load facts: which skills are attached on demand (by configuration
 * uid) and which stored digest a loadable skill has. Anything not listed is
 * not attached, or not loadable.
 */
final class FixedSkillLoadLookup implements SkillLoadLookupInterface
{
    /**
     * @param array<int, list<int>> $onDemand configuration uid => skill uids
     * @param array<int, string>    $digests  skill uid => stored digest of a loadable record
     */
    public function __construct(
        public array $onDemand = [],
        public array $digests = [],
    ) {}

    public function isAttachedOnDemand(int $configurationUid, int $skillUid): bool
    {
        return in_array($skillUid, $this->onDemand[$configurationUid] ?? [], true);
    }

    public function loadableDigest(int $skillUid): ?string
    {
        return $this->digests[$skillUid] ?? null;
    }
}
