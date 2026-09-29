<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject\Decision;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * The answer to one question, as the model reported it (ADR-211).
 *
 * Only what was measured is filled in. `$probabilities` is empty and
 * `$confidence` null wherever the model reported none — TypeSafe reports no
 * confidence for a yes/no answer, and a chat model asked through structured
 * output reports neither. Neither is ever derived here.
 *
 * - yes/no: `$value` is the probability of yes, 0..1 (a hard 0 or 1 from an
 *   uncalibrated model)
 * - choice: `$choice` is the chosen option, `$value` is null
 * - score: `$value` is the level value, 0..max level; it may lie between two
 *   levels when the model weights them by probability
 *
 * @api
 */
final readonly class DecisionAnswer
{
    /**
     * @param array<array-key, float> $probabilities option name or level index => probability; PHP stores a
     *                                               numeric name ("0", "1") as an integer key
     */
    private function __construct(
        public string $key,
        public QuestionType $type,
        public ?float $value,
        public ?string $choice,
        public array $probabilities,
        public ?float $confidence,
    ) {
        foreach ($probabilities as $probability) {
            self::assertUnitInterval($probability, 'probability', $key);
        }

        if ($confidence !== null) {
            self::assertUnitInterval($confidence, 'confidence', $key);
        }
    }

    public static function yesNo(string $key, float $probabilityOfYes, ?float $confidence = null): self
    {
        self::assertUnitInterval($probabilityOfYes, 'probability of yes', $key);

        return new self($key, QuestionType::YesNo, $probabilityOfYes, null, [], $confidence);
    }

    /**
     * @param array<array-key, float> $probabilities
     */
    public static function choice(string $key, string $option, array $probabilities = [], ?float $confidence = null): self
    {
        return new self($key, QuestionType::Choice, null, $option, $probabilities, $confidence);
    }

    /**
     * @param array<array-key, float> $probabilities
     */
    public static function score(string $key, float $value, array $probabilities = [], ?float $confidence = null): self
    {
        if ($value < 0.0 || is_nan($value)) {
            throw new InvalidArgumentException(
                sprintf('The score of decision answer "%s" must not be negative, %F given.', $key, $value),
                1795211031,
            );
        }

        return new self($key, QuestionType::Score, $value, null, $probabilities, $confidence);
    }

    private static function assertUnitInterval(float $value, string $what, string $key): void
    {
        if ($value < 0.0 || $value > 1.0 || is_nan($value)) {
            throw new InvalidArgumentException(
                sprintf('The %s of decision answer "%s" must lie between 0 and 1, %F given.', $what, $key, $value),
                1795211030,
            );
        }
    }
}
