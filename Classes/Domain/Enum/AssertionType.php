<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The deterministic assertion strategies a golden prompt can declare.
 *
 * Each assertion carries a `type` and a `value`; the DeterministicGrader
 * matches the model response against the value according to the type:
 *
 * - EXACT       trimmed response equals the value verbatim
 * - CONTAINS    the value is a substring of the response
 * - REGEX       the value is a PCRE pattern the response matches
 * - JSON_SCHEMA the response parses as JSON and satisfies a lightweight
 *               structural schema (required keys + per-key type), NOT a
 *               full JSON Schema draft validator (ADR-060)
 */
enum AssertionType: string
{
    case EXACT = 'exact';
    case CONTAINS = 'contains';
    case REGEX = 'regex';
    case JSON_SCHEMA = 'json_schema';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn(self $case): string => $case->value,
            self::cases(),
        );
    }
}
