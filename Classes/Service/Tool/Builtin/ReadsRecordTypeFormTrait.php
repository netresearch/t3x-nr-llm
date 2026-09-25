<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

/**
 * The columns of one record type's form, for any table.
 *
 * Extracted from {@see ReadsContentTypeFormsTrait::columnsOfType()}, which
 * reads `tt_content` only and now delegates here, so the content-element
 * writers and the translation draft (ADR-209) read a form by ONE rule.
 *
 * The consuming class must provide `self::toStr()` (via
 * {@see \Netresearch\NrLlm\Utility\SafeCastTrait}).
 */
trait ReadsRecordTypeFormTrait
{
    /**
     * The columns of one type's form — its `showitem` with every `--palette--`
     * expanded, dividers and line breaks skipped, labels stripped — keyed by
     * name, each with the config the DataHandler will apply to it: the
     * column's own, overlaid with the type's `columnsOverrides`.
     *
     * A column the showitem names but the TCA does not define is left out. A
     * type the table does not declare yields no columns; the caller decides
     * what that means ({@see self::recordTypeOf()} resolves a stored value the
     * way core does first).
     *
     * @return array<non-empty-string, array<array-key, mixed>>
     */
    private function formColumnsOf(string $table, string $type): array
    {
        $tca      = $GLOBALS['TCA'] ?? null;
        $tableTca = is_array($tca) && is_array($tca[$table] ?? null) ? $tca[$table] : null;
        if ($tableTca === null) {
            return [];
        }

        $columns   = is_array($tableTca['columns'] ?? null) ? $tableTca['columns'] : [];
        $palettes  = is_array($tableTca['palettes'] ?? null) ? $tableTca['palettes'] : [];
        $types     = is_array($tableTca['types'] ?? null) ? $tableTca['types'] : [];
        $typeConf  = is_array($types[$type] ?? null) ? $types[$type] : [];
        $overrides = is_array($typeConf['columnsOverrides'] ?? null) ? $typeConf['columnsOverrides'] : [];

        $names = [];
        foreach (explode(',', self::toStr($typeConf['showitem'] ?? '')) as $part) {
            $pieces = explode(';', trim($part));
            $name   = trim($pieces[0]);
            if (in_array($name, ['', '--div--', '--linebreak--'], true)) {
                continue;
            }

            if ($name !== '--palette--') {
                $names[] = $name;

                continue;
            }

            $paletteKey = trim($pieces[2] ?? '');
            $palette    = is_array($palettes[$paletteKey] ?? null) ? $palettes[$paletteKey] : [];
            foreach (explode(',', self::toStr($palette['showitem'] ?? '')) as $paletteItem) {
                $paletteName = trim(explode(';', trim($paletteItem))[0]);
                if ($paletteName !== '' && $paletteName !== '--linebreak--') {
                    $names[] = $paletteName;
                }
            }
        }

        $result = [];
        foreach ($names as $name) {
            $column = $columns[$name] ?? null;
            $config = is_array($column) ? ($column['config'] ?? null) : null;
            if (!is_array($config)) {
                continue;
            }

            $override      = is_array($overrides[$name] ?? null) ? ($overrides[$name]['config'] ?? null) : null;
            $result[$name] = is_array($override) ? array_replace_recursive($config, $override) : $config;
        }

        return $result;
    }

    /**
     * The type a stored row has, as core resolves it: the value of the
     * table's `type` field, or `0` — then `1` — when the table declares no
     * type of that value (BackendUtility::getTCAtypeValue()). A table without
     * a `type` field has the one type `0` or `1`. A `type` pointing into a
     * relation (`field:foreign_field`) is not followed; such a table yields
     * no columns.
     *
     * @param array<string, mixed> $row
     */
    private function recordTypeOf(string $table, array $row): string
    {
        $tca      = $GLOBALS['TCA'] ?? null;
        $tableTca = is_array($tca) && is_array($tca[$table] ?? null) ? $tca[$table] : [];
        $ctrl     = is_array($tableTca['ctrl'] ?? null) ? $tableTca['ctrl'] : [];
        $types    = is_array($tableTca['types'] ?? null) ? $tableTca['types'] : [];

        $typeField = self::toStr($ctrl['type'] ?? '');
        if (str_contains($typeField, ':')) {
            return '';
        }

        $type = $typeField === '' ? '' : self::toStr($row[$typeField] ?? '');
        if ($type !== '' && isset($types[$type])) {
            return $type;
        }

        return isset($types['0']) ? '0' : '1';
    }
}
