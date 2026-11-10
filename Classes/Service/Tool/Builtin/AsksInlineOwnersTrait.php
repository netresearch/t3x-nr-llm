<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Exception\PlanRefusedException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The rights a writer asks of a record it writes a file reference for, and of
 * every record that holds that record as an inline child (ADR-230).
 *
 * The DataHandler asks about the record it writes and nothing above it. The
 * backend form reaches an inline child (a carousel item) only through the
 * records that hold it, so an editor who may not edit the outer content
 * element — its edit lock, a type outside their `authMode` grant, a page they
 * may not edit — must not reach the item through a tool either.
 * {@see self::refuseUnlessOwnersMayBeEdited()} walks that chain and asks each
 * owner what {@see SetFileReferenceAlternativeTextTool} asks its reference's
 * own record.
 *
 * The owners and their pages are read in the acting user's workspace
 * (ADR-274): the live row, or the row new in that workspace — the stored row,
 * without the overlay, as the record-level checks ask it. An owner that exists
 * in the draft only is asked like a live one; read live it was not found, and
 * a live row of another table claiming the same pointer counted as the holder
 * in its place.
 *
 * The consuming class must hold a `ConnectionPool` in `$this->connectionPool`
 * and a `TableReadAccessService` in `$this->tableAccess`, declare the
 * constants `NOT_PERMITTED` (its neutral refusal) and `PAGES_TABLE`, and use
 * {@see WritesThroughDataHandlerTrait}, {@see ActsOnAnExistingRecordTrait},
 * {@see RefusesPlanStepsTrait} and {@see \Netresearch\NrLlm\Utility\SafeCastTrait}.
 */
trait AsksInlineOwnersTrait
{
    /**
     * Whether the table is one a writer may touch for this user: not
     * on the read-side denylist, not `readOnly`, and not `adminOnly` unless
     * the user is an admin.
     */
    private function mayWriteTable(string $table, BackendUserAuthentication $user): bool
    {
        if ($this->tableAccess->isSensitiveTable($table)) {
            return false;
        }

        $ctrl = $this->ctrlOf($table);
        if ((bool) ($ctrl['readOnly'] ?? false)) {
            return false;
        }

        return $user->isAdmin() || !(bool) ($ctrl['adminOnly'] ?? false);
    }

    /**
     * The page the record is edited on, once the user's right to edit
     * there is established — or the refusal thrown.
     *
     * A page's own file field is a page property: the form needs "edit page".
     * The DataHandler, writing the reference alone, asks "edit content" on the
     * reference's page (it asks "edit page" only when `pages` is in the same
     * datamap), so a page's reference needs both. Every other record is
     * content of its page and needs "edit content". A record on the root level
     * (pid 0) has no page, and only an admin edits there.
     *
     * Neither `doesUserHaveAccess()` nor the permission bits know the page's
     * edit lock, which the DataHandler refuses for a non-admin, so it is asked
     * here. The two named refusals only reach a user who may otherwise edit
     * on that page.
     *
     * @param non-empty-string     $table
     * @param array<string, mixed> $record
     * @param int                  $workspace the workspace the page is read in; 0 reads it live
     *
     * @return array<string, mixed> the page row, empty for a record on the root level
     *
     * @throws PlanRefusedException
     */
    private function editablePageOf(string $table, array $record, BackendUserAuthentication $user, int $workspace = 0): array
    {
        if ($table === self::PAGES_TABLE) {
            $page = $this->pageWithItsOwnFileField($record, $user);
        } else {
            $pid = self::toInt($record['pid'] ?? 0);
            if ($pid === 0) {
                if (!$user->isAdmin()) {
                    self::refuse(self::NOT_PERMITTED);
                }

                return [];
            }

            $page = $this->fetchOwnerRow(self::PAGES_TABLE, $pid, $workspace);
            if ($page === null || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
                self::refuse(self::NOT_PERMITTED);
            }
        }

        $editlock = $this->ctrlColumn(self::PAGES_TABLE, 'editlock');
        if (!$user->isAdmin() && $editlock !== null && (bool) ($page[$editlock] ?? false)) {
            self::refuse(sprintf('Refused: page [%d] is locked for editing (edit lock). Nothing was written.', self::toInt($page['uid'] ?? 0)));
        }

        return $page;
    }

    /**
     * The page whose own file field is written, once the user holds both
     * "edit page" and "edit content" on it ({@see self::editablePageOf()}) —
     * or the refusal thrown.
     *
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     *
     * @throws PlanRefusedException
     */
    private function pageWithItsOwnFileField(array $page, BackendUserAuthentication $user): array
    {
        if (!$user->doesUserHaveAccess($page, Permission::PAGE_EDIT)) {
            self::refuse(self::NOT_PERMITTED);
        }

        if (!$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            self::refuse(sprintf(
                'Refused: an image on a page\'s own file field needs both "edit page" and "edit content" on page [%d], '
                . 'and the acting backend user lacks "edit content" there. Nothing was written.',
                self::toInt($page['uid'] ?? 0),
            ));
        }

        return $page;
    }

    /**
     * Refuses unless the acting user may edit every record that holds the
     * given record as an inline child, up the whole chain (ADR-230).
     *
     * Each owner is asked the table ({@see self::mayWriteTable()}), the page
     * ({@see self::editablePageOf()}) and the record-level rights
     * (`mayEditRecord()`), with the consuming tool's neutral refusal. The
     * record itself is not asked here: each tool asks its own record what its
     * act needs. Owners and their pages are read in the user's workspace:
     * an owner moved there is asked on the page it left and on the page it
     * has there ({@see self::askOwner()}), and each owner's own owner is found
     * through its row as that workspace shows it.
     *
     * @param non-empty-string     $table
     * @param array<string, mixed> $record
     *
     * @throws PlanRefusedException
     */
    private function refuseUnlessOwnersMayBeEdited(string $table, array $record, BackendUserAuthentication $user): void
    {
        $workspace = max(0, $user->workspace);
        // A cycle in the data must not loop.
        $seen    = [$table . ':' . self::toInt($record['uid'] ?? 0) => true];
        $pending = [[$table, $record]];
        while (($child = array_shift($pending)) !== null) {
            foreach ($this->inlineOwnersOf($child[0], $child[1], $workspace) as [$ownerTable, $owner]) {
                $key = $ownerTable . ':' . self::toInt($owner['uid'] ?? 0);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                // The next owner is the one the workspace shows: a draft can
                // point elsewhere.
                $pending[] = [$ownerTable, $this->askOwner($ownerTable, $owner, $user, $workspace)];
            }
        }
    }

    /**
     * Refuses unless the acting user may edit one owner, and returns the
     * owner as the workspace shows it.
     *
     * The record-level rights are asked of the stored row, as core asks a
     * record's own row (ADR-263). The page is asked twice where the two
     * differ: the page of the stored row — for an owner moved in the draft,
     * the page it left, where it stays live until the workspace is published
     * — and the page the owner has in the workspace, which its move version
     * holds. Either one the editor may not edit content on refuses.
     *
     * @param non-empty-string     $ownerTable
     * @param array<string, mixed> $owner      the stored row
     *
     * @return array<string, mixed>
     *
     * @throws PlanRefusedException
     */
    private function askOwner(string $ownerTable, array $owner, BackendUserAuthentication $user, int $workspace): array
    {
        if (!$this->mayWriteTable($ownerTable, $user)) {
            self::refuse(self::NOT_PERMITTED);
        }

        $shown = $this->rowInWorkspace($ownerTable, $owner, $workspace) ?? $owner;
        $this->editablePageOf($ownerTable, $owner, $user, $workspace);
        if ($ownerTable !== self::PAGES_TABLE && self::toInt($shown['pid'] ?? 0) !== self::toInt($owner['pid'] ?? 0)) {
            $this->editablePageOf($ownerTable, $shown, $user, $workspace);
        }

        if (!$this->mayEditRecord($ownerTable, $owner, $user)) {
            self::refuse(self::NOT_PERMITTED);
        }

        return $shown;
    }

    /**
     * The records in `$workspace` that hold this row as an inline child: for every
     * `type => inline` column of any table whose `foreign_table` is this
     * table, the record the row's `foreign_field` points to, where the row's
     * `foreign_table_field` and `foreign_match_fields` agree.
     *
     * Without a `foreign_table_field`, several tables may claim the same
     * pointer; each that holds the record counts as an owner. A pointer that
     * no claiming table holds is an orphan's, and refused.
     *
     * A relation of a table to itself is not followed. That is the container
     * pattern — EXT:gridelements declares `tt_content.tx_gridelements_children`
     * as an inline relation to `tt_content` — and there the page module edits
     * each child on its own, as the DataHandler does; the container does not
     * stand between the editor and the child.
     *
     * @param array<string, mixed> $row
     *
     * @return list<array{non-empty-string, array<string, mixed>}>
     *
     * @throws PlanRefusedException
     */
    private function inlineOwnersOf(string $table, array $row, int $workspace): array
    {
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];

        $owners = [];
        // Per pointer column: whether any claiming table holds the record.
        $held = [];
        foreach (array_keys($tca) as $ownerTable) {
            $ownerTable = self::toStr($ownerTable);
            if ($ownerTable === '' || $ownerTable === $table) {
                continue;
            }

            foreach ($this->tcaColumnsFor($ownerTable) ?? [] as $column) {
                $pointer = $this->ownerPointer($column, $ownerTable, $table, $row);
                if ($pointer === null) {
                    continue;
                }

                [$foreignField, $uid] = $pointer;
                $owner                = $this->fetchOwnerRow($ownerTable, $uid, $workspace);
                $held[$foreignField]  = ($held[$foreignField] ?? false) || $owner !== null;
                if ($owner !== null) {
                    $owners[] = [$ownerTable, $owner];
                }
            }
        }

        if (in_array(false, $held, true)) {
            self::refuse(self::NOT_PERMITTED);
        }

        return $owners;
    }

    /**
     * The pointer column and the owner uid by which this inline column of
     * `$ownerTable` holds the row, or null when it does not hold it.
     *
     * @param array<string, mixed> $row
     *
     * @return array{string, int}|null
     */
    private function ownerPointer(mixed $column, string $ownerTable, string $table, array $row): ?array
    {
        $config = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];
        if (($config['type'] ?? null) !== 'inline' || ($config['foreign_table'] ?? null) !== $table) {
            return null;
        }

        $foreignField = self::toStr($config['foreign_field'] ?? '');
        $uid          = self::toInt($row[$foreignField] ?? 0);
        if ($foreignField === '' || $uid < 1 || !$this->claims($config, $ownerTable, $row)) {
            return null;
        }

        return [$foreignField, $uid];
    }

    /**
     * Whether the row's `foreign_table_field` and `foreign_match_fields` name
     * this inline column of `$ownerTable` as the one that holds it.
     *
     * @param array<array-key, mixed> $config
     * @param array<string, mixed>    $row
     */
    private function claims(array $config, string $ownerTable, array $row): bool
    {
        $tableField = self::toStr($config['foreign_table_field'] ?? '');
        if ($tableField !== '' && self::toStr($row[$tableField] ?? '') !== $ownerTable) {
            return false;
        }

        $matchFields = is_array($config['foreign_match_fields'] ?? null) ? $config['foreign_match_fields'] : [];
        foreach ($matchFields as $name => $value) {
            if (self::toStr($row[self::toStr($name)] ?? '') !== self::toStr($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * An undeleted row by uid, a hidden one included, as stored for a user in
     * `$workspace`: the live row, or the row new in that workspace (ADR-274).
     * A version row addressed by its own uid and a row of another workspace
     * are not one (ADR-198). In workspace 0 the live row only.
     *
     * @return array<string, mixed>|null
     */
    private function fetchOwnerRow(string $table, int $uid, int $workspace): ?array
    {
        if ($uid < 1) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                ...$this->workspaceVersionConstraints($queryBuilder, $table, $workspace),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
