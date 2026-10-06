<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The optional `include_hidden` of the two record readers, so that an agent can
 * read the hidden draft it created itself and edit it in a follow-up step.
 *
 * A hidden record is unpublished content, and a tool result goes to an external
 * provider. The option therefore lifts the hidden restriction and nothing else
 * (deleted, timed and workspace rows stay out), and a non-admin gets a hidden
 * row only where the backend would let them edit it: `tables_modify` on the
 * table and the edit permission on the page — content edit for a record,
 * page edit for a page. Seeing the page is not enough.
 *
 * Needs {@see \Netresearch\NrLlm\Utility\SafeCastTrait} in the using class.
 */
trait ReadsHiddenRecordsTrait
{
    /**
     * Whether the call asked for hidden records. A model may send the boolean
     * as `true` or as the string "true"; anything else is false.
     *
     * @param array<string, mixed> $arguments
     */
    private function wantsHiddenRecords(array $arguments): bool
    {
        return filter_var($arguments['include_hidden'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The table's "disabled" enable column, or null where it has none (there
     * is nothing hidden to include).
     */
    private function hiddenColumnOf(string $table): ?string
    {
        $tca     = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $definition = is_array($tca[$table] ?? null) ? $tca[$table] : [];
        $ctrl    = is_array($definition['ctrl'] ?? null) ? $definition['ctrl'] : [];
        $columns = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        $column  = $columns['disabled'] ?? null;

        return is_string($column) && $column !== '' ? $column : null;
    }

    private function liftHiddenRestriction(QueryBuilder $queryBuilder): void
    {
        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
    }

    /**
     * Whether the acting user may have this row: always for a visible row, and
     * for a hidden one only where they may edit it. Admins may have all.
     * Memoised per page and permission.
     *
     * @param array<string, mixed> $row
     * @param array<string, bool>  $cache
     */
    private function mayReadRowThatMayBeHidden(
        BackendUserAuthentication $user,
        string $table,
        array $row,
        string $hiddenColumn,
        array &$cache,
    ): bool {
        if ($user->isAdmin() || self::toInt($row[$hiddenColumn] ?? 0) === 0) {
            return true;
        }

        if (!$user->check('tables_modify', $table)) {
            return false;
        }

        $isPage     = $table === 'pages';
        $pageUid    = $isPage ? self::toInt($row['uid'] ?? 0) : self::toInt($row['pid'] ?? 0);
        $permission = $isPage ? Permission::PAGE_EDIT : Permission::CONTENT_EDIT;
        if ($pageUid < 1) {
            return false;
        }

        $key         = $pageUid . ':' . $permission;
        $cache[$key] ??= is_array(BackendUtility::readPageAccess($pageUid, self::toStr($user->getPagePermsClause($permission))));

        return $cache[$key];
    }
}
