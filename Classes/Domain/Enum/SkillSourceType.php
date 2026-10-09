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
     * Skills authored in the backend through FormEngine (ADR-214 item 3).
     * Nothing is fetched or synced; the record is edited in place, so its
     * version digest is computed from the current fields rather than stored.
     */
    case BACKEND = 'backend';

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

    /**
     * Whether skills of this source are written by the sync. For those the
     * sync is the only legitimate writer, so a stored-value integrity check
     * catches a backend edit; a backend source has no such writer.
     */
    public function isSynced(): bool
    {
        return $this !== self::BACKEND;
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
