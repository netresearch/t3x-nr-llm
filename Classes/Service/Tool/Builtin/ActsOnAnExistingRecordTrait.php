<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What the writers of ADR-198 share because they act on a record that
 * already exists — a page or a content element the editor points at.
 *
 * {@see PublishRecordTool}, {@see DeleteRecordTool}, {@see CopyRecordTool},
 * {@see MovePageTool}, {@see UpdateContentElementTool} and
 * {@see ReplaceFileReferenceTool} each address ONE existing row of `pages` or
 * `tt_content` and must answer the same questions about it before their own:
 * which of the two tables, which language, which translations hang off it,
 * which column hides it, and whether the acting user may touch it at all. The answers here are
 * the TCA's and the user's; what each tool DOES with the record stays in the
 * tool, as {@see WritesThroughDataHandlerTrait} keeps its decisions out.
 *
 * The consuming class must provide `self::toStr()` / `self::toInt()` (via
 * {@see \Netresearch\NrLlm\Utility\SafeCastTrait}), a
 * `private ConnectionPool $connectionPool`, and `tcaColumnsFor()` (via
 * {@see WritesThroughDataHandlerTrait}).
 */
trait ActsOnAnExistingRecordTrait
{
    /**
     * The tables the ADR-198 writers act on. Every other table is refused by
     * name: the rules below were read against these two tables' TCA and core's
     * handling of them, and a third table is a third review (ADR-135).
     *
     * @var list<non-empty-string>
     */
    private const EXISTING_RECORD_TABLES = ['pages', 'tt_content'];

    /**
     * The table a call names, or null when it names neither of the two.
     *
     * @param array<string, mixed> $arguments
     *
     * @return 'pages'|'tt_content'|null
     */
    private function existingRecordTable(array $arguments): ?string
    {
        $table = $arguments['table'] ?? null;
        if (!in_array($table, self::EXISTING_RECORD_TABLES, true)) {
            return null;
        }

        return $table === 'pages' ? 'pages' : 'tt_content';
    }

    /**
     * The `ctrl` section of a table's live TCA, narrowed.
     *
     * @return array<array-key, mixed>
     */
    private function ctrlOf(string $table): array
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[$table] ?? null) ? ($tca[$table]['ctrl'] ?? null) : null;

        return is_array($ctrl) ? $ctrl : [];
    }

    /**
     * The name of a column the `ctrl` section declares under `$key`, or null.
     *
     * @return non-empty-string|null
     */
    private function ctrlColumn(string $table, string $key): ?string
    {
        $name = $this->ctrlOf($table)[$key] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The column that hides a record — `ctrl.enablecolumns.disabled` — or
     * null for a table without one.
     *
     * @return non-empty-string|null
     */
    private function disabledColumnOf(string $table): ?string
    {
        $enable = $this->ctrlOf($table)['enablecolumns'] ?? null;
        $name   = is_array($enable) ? ($enable['disabled'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The language of a row, 0 where the table declares no language column.
     *
     * @param array<string, mixed> $row
     */
    private function languageOf(string $table, array $row): int
    {
        $field = $this->ctrlColumn($table, 'languageField');

        return $field === null ? 0 : self::toInt($row[$field] ?? 0);
    }

    /**
     * The uid of the default-language record a row translates, 0 where it
     * translates none — a default-language record or a free-mode one.
     *
     * @param array<string, mixed> $row
     */
    private function translationParentOf(string $table, array $row): int
    {
        $field = $this->ctrlColumn($table, 'transOrigPointerField');

        return $field === null ? 0 : self::toInt($row[$field] ?? 0);
    }

    /**
     * The row's label as the TCA declares it — `title` for a page, `header`
     * for a content element.
     *
     * @param array<string, mixed> $row
     */
    private function labelOf(string $table, array $row): string
    {
        $field = $this->ctrlColumn($table, 'label');

        return $field === null ? '' : self::toStr($row[$field] ?? '');
    }

    /**
     * The undeleted translations of a default-language record: every row
     * whose translation pointer names it. Empty for a table without one.
     *
     * The DataHandler carries these along — a delete deletes them, a copy
     * copies them, a move of a page moves them — so a tool that acts on the
     * parent acts on them too and has to say so, and ask for their languages.
     *
     * @return list<array<string, mixed>>
     */
    private function translationsOf(string $table, int $uid): array
    {
        $pointer  = $this->ctrlColumn($table, 'transOrigPointerField');
        $language = $this->ctrlColumn($table, 'languageField');
        if ($pointer === null || $language === null || $uid < 1) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($pointer, $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt($language, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * Whether the acting user may edit this row at record level — the
     * questions {@see BackendUserAuthentication::checkRecordEditAccess()}
     * asks in TYPO3 14 and `recordEditAccessInternals()` asked in 13, asked
     * here without either: the first does not exist in 13, the second is
     * deprecated in 14.
     *
     * An admin may. Anyone else needs `tables_modify` for the table, access
     * to the row's language, the `authMode` grant for every select value of
     * the row that declares one (`tt_content.CType` among them), and a row
     * that is not edit-locked. Page permissions are NOT asked here: which
     * bit a tool needs on which page is that tool's decision.
     *
     * Core's `recordEditAccessInternals` hooks are not run here; the
     * DataHandler runs them when it asks the same question inside the write,
     * and a refusal there reaches the caller through its error log.
     *
     * @param array<string, mixed> $row the full row, as {@see PlansOneEditorialWriteTrait::fetchRowByUid()} reads it
     */
    private function mayEditRecord(string $table, array $row, BackendUserAuthentication $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$user->check('tables_modify', $table)) {
            return false;
        }

        if (!$user->checkLanguageAccess($this->languageOf($table, $row))) {
            return false;
        }

        foreach ($this->tcaColumnsFor($table) ?? [] as $name => $column) {
            $config = is_array($column) ? ($column['config'] ?? null) : null;
            if (!is_array($config) || ($config['type'] ?? null) !== 'select' || !(bool)($config['authMode'] ?? false)) {
                continue;
            }

            if (array_key_exists($name, $row) && !$user->checkAuthMode($table, self::toStr($name), self::toStr($row[$name]))) {
                return false;
            }
        }

        $editlock = $this->ctrlColumn($table, 'editlock');

        return $editlock === null || !(bool)($row[$editlock] ?? false);
    }

    /**
     * The columns among `$columns` the user may not write because the TCA
     * marks them `exclude` and the user holds no `non_exclude_fields` grant
     * — the question the DataHandler asks before it drops such a column in
     * silence.
     *
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private function columnsTheUserMayNotSet(BackendUserAuthentication $user, string $table, array $columns): array
    {
        if ($user->isAdmin()) {
            return [];
        }

        $tcaColumns = $this->tcaColumnsFor($table) ?? [];

        $ungranted = [];
        foreach ($columns as $column) {
            $definition = $tcaColumns[$column] ?? null;
            if (is_array($definition) && (bool)($definition['exclude'] ?? false)
                && !$user->check('non_exclude_fields', $table . ':' . $column)
            ) {
                $ungranted[] = $column;
            }
        }

        return $ungranted;
    }
}
