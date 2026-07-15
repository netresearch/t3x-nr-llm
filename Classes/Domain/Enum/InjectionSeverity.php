<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Confidence tier of a prompt-injection scanner finding (ADR-061).
 *
 * Only {@see self::HIGH} is treated as fail-closed at ingest (the skill is
 * force-disabled and must be re-reviewed); {@see self::LOW} and
 * {@see self::MEDIUM} flag the record for review without blocking, because the
 * repo/marketplace skills they most often appear on already arrive disabled.
 */
enum InjectionSeverity: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

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

    public function rank(): int
    {
        return match ($this) {
            self::LOW    => 0,
            self::MEDIUM => 1,
            self::HIGH   => 2,
        };
    }
}
