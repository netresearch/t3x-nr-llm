<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Formatting;

use MessageFormatter;

/**
 * A large count in the locale's short compact form: "17,5 Mio." for a German
 * reader, "17.5M" for an English one (CLDR compact-short, at most one
 * decimal, halves rounded away from zero).
 *
 * Below a million the full grouped number is returned, as
 * {@see LocalizedNumberFormatter::number()} prints it: it fits, and German
 * CLDR has no short form for thousands and prints them without grouping
 * ("3714"), which reads worse than "3.714". The short form drops digits, so a
 * caller shows the full value next to it (title, screen-reader text).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class CompactNumberFormatter
{
    private const FROM = 1_000_000;

    private const PATTERN = '{0, number, ::compact-short .# rounding-mode-half-up}';

    public function __construct(
        private LocalizedNumberFormatter $numbers,
    ) {}

    public static function forBackendUser(): self
    {
        return new self(LocalizedNumberFormatter::forBackendUser());
    }

    public function format(float|int $value): string
    {
        if (abs($value) < self::FROM) {
            return $this->numbers->number($value);
        }

        $formatted = MessageFormatter::formatMessage($this->numbers->locale, self::PATTERN, [$value]);

        return is_string($formatted) && $formatted !== '' ? $formatted : $this->numbers->number($value);
    }
}
