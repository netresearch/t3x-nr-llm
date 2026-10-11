<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\ValueObject\SkillSourceFacts;

/**
 * Reads a skill source record's type and trust level as they are now
 * (ADR-214 item 2).
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
interface SkillSourceLookupInterface
{
    /**
     * The source's current facts, or null for a nonpositive UID or a missing,
     * deleted, hidden or disabled source. A source without an active row vouches
     * for nothing. An unknown stored type reads as null; an unknown stored trust
     * level reads as untrusted.
     */
    public function find(int $sourceUid): ?SkillSourceFacts;
}
