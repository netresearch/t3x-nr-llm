<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;

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

    /**
     * The record a child row belongs to, read from its stored pointers: null when
     * the table is no child, a pointer is missing, the table of the pointer is not
     * one of the parents the TCA names, or the table is not told by the row and
     * there is more than one parent to pick.
     *
     * @param array<array-key, mixed> $row
     */
    public static function host(string $table, array $row): ?RecordReference
    {
        $relation = self::of($table);
        if ($relation === null) {
            return null;
        }

        $uid = $row[$relation['field']] ?? 0;
        $uid = is_numeric($uid) ? (int) $uid : 0;
        if ($relation['tableField'] === null) {
            $parent = count($relation['parents']) === 1 ? $relation['parents'][0] : null;
        } else {
            $stored = $row[$relation['tableField']] ?? null;
            $parent = is_string($stored) && in_array($stored, $relation['parents'], true) ? $stored : null;
        }

        return $uid > 0 && $parent !== null ? new RecordReference($parent, $uid) : null;
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
