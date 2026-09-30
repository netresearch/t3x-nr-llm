<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject\Decision;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * The constructor checks the three question types share.
 *
 * @internal
 */
final class QuestionGuard
{
    /**
     * A lower-case identifier, as a key of the answer map and of a JSON schema
     * property: it must survive every provider's wire format unchanged.
     */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    public static function key(string $key): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(
                sprintf('A decision question key must match %s, "%s" does not.', self::KEY_PATTERN, $key),
                1795211001,
            );
        }
    }

    public static function text(string $value, string $what, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(
                sprintf('The %s of decision question "%s" must not be empty.', $what, $key),
                1795211002,
            );
        }
    }

    /**
     * An optional criterion: absent ('') or real text, never blank — a blank
     * one would reach every provider as a meaning that says nothing.
     */
    public static function optionalText(string $value, string $what, string $key): void
    {
        if ($value !== '') {
            self::text($value, $what, $key);
        }
    }

    /**
     * Option names and levels are a list: string keys would make the order
     * — and, for a score, the level index — depend on how they were built.
     *
     * @param array<array-key, mixed> $values
     */
    public static function list(array $values, string $what, string $key): void
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException(
                sprintf('The %s of decision question "%s" must be a list, not a map.', $what, $key),
                1795211007,
            );
        }
    }

    /**
     * @param list<string> $values
     */
    public static function unique(array $values, string $what, string $key): void
    {
        if (count(array_unique($values)) !== count($values)) {
            throw new InvalidArgumentException(
                sprintf('Decision question "%s" names one of its %s more than once.', $key, $what),
                1795211006,
            );
        }
    }

    /**
     * @param int<1, max> $minimum
     * @param int<1, max> $maximum
     */
    public static function count(int $count, int $minimum, int $maximum, string $what, string $key): void
    {
        if ($count < $minimum || $count > $maximum) {
            throw new InvalidArgumentException(
                sprintf('Decision question "%s" needs %d to %d %s, %d given.', $key, $minimum, $maximum, $what, $count),
                1795211003,
            );
        }
    }
}
