<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Timestamps of the wire format (A2A-P3): ISO 8601 in UTC with `Z`.
 *
 * Emitted with millisecond precision. Read with any number of fractional
 * digits, because a2a-python 1.3.0 sends microseconds
 * (`2026-11-10T21:54:29.148533Z`); digits beyond the microsecond are dropped.
 * Any other offset, `+00:00` included, is not accepted.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aTimestamp
{
    private const PATTERN = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?Z$/';

    public static function format(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * The instant, or null when the value is not a UTC timestamp of this form.
     */
    public static function parse(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $value, $match) !== 1) {
            return null;
        }

        $fraction = substr(str_pad($match[2] ?? '', 6, '0'), 0, 6);
        $parsed   = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u',
            $match[1] . '.' . $fraction,
            new DateTimeZone('UTC'),
        );
        if ($parsed === false) {
            return null;
        }

        // createFromFormat() rolls 2026-02-30 over into March; a value that
        // does not survive the round trip was not a real date.
        return $parsed->format('Y-m-d\TH:i:s') === $match[1] ? $parsed : null;
    }
}
