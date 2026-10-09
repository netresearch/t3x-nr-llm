<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * Result of composing attached skills into a prompt.
 *
 * Produced by {@see \Netresearch\NrLlm\Service\Skill\SkillComposer}. Two
 * channels, never mixed (ADR-214 item 2):
 *
 * - `block` is the delimited, lower-trust text prepended to the first user
 *   message — every admitted skill that is not an approved instruction (empty
 *   when nothing was composed);
 * - `instructions` is the labelled section for the system message — every
 *   skill version an administrator approved and whose source meets the
 *   instruction threshold (empty when there is none).
 *
 * `included` / `dropped` list the skill identifiers that made it into either
 * channel resp. were excluded (integrity mismatch, process skill on a path
 * that is not an invocation, budget truncation of the fenced block), and
 * `warnings` carries a human-readable explanation for every exclusion.
 * `instructionIncluded` lists the identifiers composed as instructions; it is a
 * subset of `included`.
 */
final readonly class SkillCompositionResult
{
    /**
     * @param list<string>   $included
     * @param list<string>   $dropped
     * @param list<string>   $warnings
     * @param list<string>   $instructionIncluded
     * @param list<SkillPin> $instructionPins     the version each instruction section was composed from (ADR-214 item 6)
     */
    public function __construct(
        public string $block,
        public array $included = [],
        public array $dropped = [],
        public array $warnings = [],
        public string $instructions = '',
        public array $instructionIncluded = [],
        public array $instructionPins = [],
    ) {}
}
