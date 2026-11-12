<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How a table task hands its rows to the model (ADR-286).
 *
 * Set by the `format` option of a table task's `input_source`. JSON is the
 * default and the fallback; TOON is opt-in and applies only to rows it can
 * represent as one table.
 */
enum TableInputFormat: string
{
    case JSON = 'json';
    case TOON = 'toon';

    /**
     * Read the `format` option. Anything other than a known value, including
     * a missing option, is JSON, the behaviour before the option existed.
     */
    public static function fromOption(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::JSON) : self::JSON;
    }
}
