<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Utility;

/**
 * Trait for type-safe casting of mixed values.
 *
 * Used to satisfy PHPStan level 10 "Cannot cast mixed" rules
 * when processing untyped data from JSON, request bodies, etc.
 */
trait SafeCastTrait
{
    private static function toStr(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? (string)$value : '';
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }
}
