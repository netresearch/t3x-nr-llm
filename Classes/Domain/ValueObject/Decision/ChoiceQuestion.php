<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject\Decision;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * Pick one option out of a set the profile defines (ADR-211).
 *
 * The options are a list of names and their descriptions a separate map, so
 * a name like "0" or "1" means the same thing as any other: one map of name
 * to description could not tell `['red', 'green']` from options named "0"
 * and "1", because PHP stores both with integer keys.
 *
 * @api
 */
final readonly class ChoiceQuestion implements DecisionQuestion
{
    public const MIN_OPTIONS = 2;

    /** The most options TypeSafe accepts in one choice question. */
    public const MAX_OPTIONS = 255;

    /**
     * @param list<string>             $options      the option names, unique
     * @param array<array-key, string> $descriptions option name => what it stands for; an option
     *                                               without an entry needs none
     */
    public function __construct(
        public string $key,
        public string $instructions,
        public array $options,
        public array $descriptions = [],
    ) {
        QuestionGuard::key($key);
        QuestionGuard::text($instructions, 'instructions', $key);
        QuestionGuard::list($options, 'options', $key);
        QuestionGuard::count(count($options), self::MIN_OPTIONS, self::MAX_OPTIONS, 'options', $key);
        foreach ($options as $option) {
            QuestionGuard::text($option, 'option names', $key);
        }

        if (count(array_unique($options)) !== count($options)) {
            throw new InvalidArgumentException(
                sprintf('Decision question "%s" names an option more than once.', $key),
                1795211004,
            );
        }

        $unknown = array_diff(
            array_map(static fn(int|string $name): string => (string)$name, array_keys($descriptions)),
            $options,
        );
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                sprintf('Decision question "%s" describes the option "%s", which it does not offer.', $key, reset($unknown)),
                1795211005,
            );
        }

        foreach ($descriptions as $description) {
            QuestionGuard::text($description, 'option descriptions', $key);
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function type(): QuestionType
    {
        return QuestionType::Choice;
    }

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * What the option stands for, '' when the profile gives no description.
     */
    public function describe(string $option): string
    {
        return $this->descriptions[$option] ?? '';
    }
}
