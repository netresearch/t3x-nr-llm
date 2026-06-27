<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

enum SkillSourceType: string
{
    case SINGLE_FILE = 'single_file';
    case REPO = 'repo';
    case MARKETPLACE = 'marketplace';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
