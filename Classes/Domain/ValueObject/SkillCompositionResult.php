<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * Result of composing attached skills into a prompt block.
 *
 * Produced by {@see \Netresearch\NrLlm\Service\Skill\SkillComposer}. The
 * `block` is the delimited, lower-trust text prepended to the user prompt
 * (empty when nothing was composed). `included` / `dropped` list the skill
 * identifiers that made it into the block resp. were excluded (integrity
 * mismatch or budget truncation), and `warnings` carries human-readable
 * explanations for every exclusion.
 */
final readonly class SkillCompositionResult
{
    /**
     * @param list<string> $included
     * @param list<string> $dropped
     * @param list<string> $warnings
     */
    public function __construct(
        public string $block,
        public array $included = [],
        public array $dropped = [],
        public array $warnings = [],
    ) {}
}
