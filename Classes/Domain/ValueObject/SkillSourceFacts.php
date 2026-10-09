<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;

/**
 * What the skill source record says right now about a skill's provenance
 * (ADR-214 item 2): its type and its trust level, read from
 * ``tx_nrllm_skill_source`` rather than from the column the sync denormalises
 * onto the skill, so a re-classified source takes effect at once.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillSourceFacts
{
    public function __construct(
        public int $uid,
        public ?SkillSourceType $type,
        public SkillTrustLevel $trustLevel,
    ) {}
}
