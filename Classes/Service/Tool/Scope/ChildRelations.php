<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

/**
 * Which tables are the children of an inline or file column, and which of their
 * columns point back at the parent (ADR-295).
 *
 * Read from the TCA: the `foreign_field` of the parent's column names the child
 * column that holds the parent's uid, and the `foreign_table_field` the one that
 * holds the parent's table. A child row is judged on the record it belongs to,
 * so a write that changes either pointer is a write to that record.
 *
 * @internal
 */
final class ChildRelations
{
    /** @var array<string, array{field: string, tableField: string|null, parents: list<string>}|null>|null */
    private static ?array $relations = null;

    /**
     * @return array{field: string, tableField: string|null, parents: list<string>}|null null when the table is the child of no column
     */
    public static function of(string $table): ?array
    {
        return self::all()[$table] ?? null;
    }

    public static function reset(): void
    {
        self::$relations = null;
    }

    /**
     * @return array<string, array{field: string, tableField: string|null, parents: list<string>}|null>
     */
    private static function all(): array
    {
        if (self::$relations !== null) {
            return self::$relations;
        }

        $relations = [];
        $tca       = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        foreach ($tca as $parent => $schema) {
            $columns = is_array($schema) && is_array($schema['columns'] ?? null) ? $schema['columns'] : [];
            foreach ($columns as $column) {
                $config = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];
                $type   = $config['type'] ?? null;
                $child  = $type === 'file' ? 'sys_file_reference' : ($config['foreign_table'] ?? null);
                $field  = $type === 'file' ? 'uid_foreign' : ($config['foreign_field'] ?? null);
                if (!in_array($type, ['inline', 'file'], true) || !is_string($child) || !is_string($field) || !is_string($parent)) {
                    continue;
                }

                $tableField = $type === 'file' ? 'tablenames' : ($config['foreign_table_field'] ?? null);
                $tableField = is_string($tableField) ? $tableField : null;

                $known             = $relations[$child] ?? ['field' => $field, 'tableField' => $tableField, 'parents' => []];
                $known['parents']  = array_values(array_unique([...$known['parents'], $parent]));
                $relations[$child] = $known;
            }
        }

        return self::$relations = $relations;
    }
}
