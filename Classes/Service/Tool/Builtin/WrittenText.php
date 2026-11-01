<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\WriteKind;

use function in_array;

/**
 * Whether an update changed text, or only a setting of the record (ADR-239).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class WrittenText
{
    private const TEXT_COLUMN_TYPES = ['input', 'text'];

    /**
     * @param list<string> $changedColumns the columns the call did change
     */
    public static function kindOf(string $table, array $changedColumns): WriteKind
    {
        /** @var array<string, array{columns?: array<string, array{config?: array{type?: string}}>}> $tca */
        $tca = $GLOBALS['TCA'] ?? [];

        foreach ($changedColumns as $column) {
            if (in_array($tca[$table]['columns'][$column]['config']['type'] ?? null, self::TEXT_COLUMN_TYPES, true)) {
                return WriteKind::UPDATED;
            }
        }

        return WriteKind::TEXT_UNCHANGED;
    }
}
