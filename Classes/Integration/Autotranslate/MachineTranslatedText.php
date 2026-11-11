<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Integration\Autotranslate;

use ThieleUndKlose\Autotranslate\Event\AfterTranslateEvent;

use function in_array;
use function is_array;
use function is_string;
use function trim;

/**
 * Whether an autotranslate translation put machine-translated text into the
 * record (ADR-289).
 *
 * autotranslate's event carries the translated columns together with the
 * fields it copies unchanged (`fieldsToCopy`). A text column (`input`, `text`
 * or `flex`) counts when it holds text and the translation changed it from the
 * source record. A copied field equals the source and does not count, and
 * neither does a text the translator returned unchanged.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class MachineTranslatedText
{
    private const TEXT_TYPES = ['input', 'text', 'flex'];

    public static function in(AfterTranslateEvent $event): bool
    {
        $table  = $event->getTable();
        $source = $event->getSourceRecord();
        foreach ($event->getTranslatedColumns() as $column => $value) {
            if (!is_string($value) || trim($value) === '' || !self::isTextColumn($table, (string) $column)) {
                continue;
            }

            if ($value !== ($source[$column] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The TCA `config` of a column, or null when the table has no such column.
     *
     * @return array<array-key, mixed>|null
     */
    public static function columnConfig(string $table, string $column): ?array
    {
        $tca     = $GLOBALS['TCA'] ?? null;
        $columns = is_array($tca) && is_array($tca[$table] ?? null) ? ($tca[$table]['columns'] ?? null) : null;
        $field   = is_array($columns) ? ($columns[$column] ?? null) : null;
        $config  = is_array($field) ? ($field['config'] ?? null) : null;

        return is_array($config) ? $config : null;
    }

    private static function isTextColumn(string $table, string $column): bool
    {
        return in_array(self::columnConfig($table, $column)['type'] ?? null, self::TEXT_TYPES, true);
    }
}
