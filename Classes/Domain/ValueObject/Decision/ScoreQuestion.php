<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject\Decision;

/**
 * Rate the subject on ordered, described levels (ADR-211).
 *
 * Level 0 is the first entry of `$levels`; the answer's value lies between 0
 * and `count($levels) - 1`.
 *
 * @api
 */
final readonly class ScoreQuestion implements DecisionQuestion
{
    public const MIN_LEVELS = 2;

    /** The most levels TypeSafe accepts in one score question. */
    public const MAX_LEVELS = 10;

    /**
     * @param list<string> $levels level descriptions, lowest first
     */
    public function __construct(
        public string $key,
        public string $instructions,
        public array $levels,
    ) {
        QuestionGuard::key($key);
        QuestionGuard::text($instructions, 'instructions', $key);
        QuestionGuard::list($levels, 'levels', $key);
        QuestionGuard::count(count($levels), self::MIN_LEVELS, self::MAX_LEVELS, 'levels', $key);
        foreach ($levels as $level) {
            QuestionGuard::text($level, 'level descriptions', $key);
        }

        // Two levels described alike leave the model no way to tell them apart.
        QuestionGuard::unique($levels, 'level descriptions', $key);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function type(): QuestionType
    {
        return QuestionType::Score;
    }

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * The highest level index — the top of the answer's value range.
     */
    public function maxLevel(): int
    {
        return count($this->levels) - 1;
    }
}
