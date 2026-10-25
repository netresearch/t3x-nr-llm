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
     *
     * @return array<string, mixed> the page row, empty for a record on the root level
     *
     * @throws PlanRefusedException
     */
    private function editablePageOf(string $table, array $record, BackendUserAuthentication $user): array
    {
        if ($table === self::PAGES_TABLE) {
            if (!$user->doesUserHaveAccess($record, Permission::PAGE_EDIT)) {
                self::refuse(self::NOT_PERMITTED);
            }

            if (!$user->doesUserHaveAccess($record, Permission::CONTENT_EDIT)) {
                self::refuse(sprintf(
                    'Refused: an image on a page\'s own file field needs both "edit page" and "edit content" on page [%d], '
                    . 'and the acting backend user lacks "edit content" there. Nothing was written.',
                    self::toInt($record['uid'] ?? 0),
                ));
            }

            $page = $record;
        } else {
            $pid = self::toInt($record['pid'] ?? 0);
            if ($pid === 0) {
                if (!$user->isAdmin()) {
                    self::refuse(self::NOT_PERMITTED);
                }

                return [];
            }

            $page = $this->fetchOwnerRow(self::PAGES_TABLE, $pid);
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
     * Refuses unless the acting user may edit every record that holds the
     * given record as an inline child, up the whole chain (ADR-230).
     *
     * Each owner is asked the table ({@see self::mayWriteTable()}), the page
     * ({@see self::editablePageOf()}) and the record-level rights
     * (`mayEditRecord()`), with the consuming tool's neutral refusal. The
     * record itself is not asked here: each tool asks its own record what its
     * act needs.
     *
     * @param non-empty-string     $table
     * @param array<string, mixed> $record
     *
     * @throws PlanRefusedException
     */
    private function refuseUnlessOwnersMayBeEdited(string $table, array $record, BackendUserAuthentication $user): void
    {
        // A cycle in the data must not loop.
        $seen    = [$table . ':' . self::toInt($record['uid'] ?? 0) => true];
        $pending = [[$table, $record]];
        while (($child = array_shift($pending)) !== null) {
            foreach ($this->inlineOwnersOf($child[0], $child[1]) as [$ownerTable, $owner]) {
                $key = $ownerTable . ':' . self::toInt($owner['uid'] ?? 0);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                if (!$this->mayWriteTable($ownerTable, $user)) {
                    self::refuse(self::NOT_PERMITTED);
                }

                $this->editablePageOf($ownerTable, $owner, $user);
                if (!$this->mayEditRecord($ownerTable, $owner, $user)) {
                    self::refuse(self::NOT_PERMITTED);
                }

                $pending[] = [$ownerTable, $owner];
            }
        }
    }

    /**
     * The live records that hold this row as an inline child: for every
     * `type => inline` column of any table whose `foreign_table` is this
     * table, the record the row's `foreign_field` points to, where the row's
     * `foreign_table_field` and `foreign_match_fields` agree.
     *
     * Without a `foreign_table_field`, several tables may claim the same
     * pointer; each that holds the record counts as an owner. A pointer that
     * no claiming table holds is an orphan's, and refused.
     *
     * @param array<string, mixed> $row
     *
     * @return list<array{non-empty-string, array<string, mixed>}>
     *
     * @throws PlanRefusedException
     */
    private function inlineOwnersOf(string $table, array $row): array
    {
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];

        $owners = [];
        // Per pointer column: whether any claiming table holds the record.
        $held = [];
        foreach (array_keys($tca) as $ownerTable) {
            $ownerTable = self::toStr($ownerTable);
            if ($ownerTable === '') {
                continue;
            }

            foreach ($this->tcaColumnsFor($ownerTable) ?? [] as $column) {
                $config       = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];
                $foreignField = self::toStr($config['foreign_field'] ?? '');
                $uid          = self::toInt($row[$foreignField] ?? 0);
                if (($config['type'] ?? null) !== 'inline' || ($config['foreign_table'] ?? null) !== $table || $foreignField === '' || $uid < 1
                    || !$this->claims($config, $ownerTable, $row)
                ) {
                    continue;
                }

                $owner               = $this->fetchOwnerRow($ownerTable, $uid);
                $held[$foreignField] = ($held[$foreignField] ?? false) || $owner !== null;
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
     * A live, undeleted row by uid, a hidden one included; a workspace version
     * row is not one (ADR-198).
     *
     * @return array<string, mixed>|null
     */
    private function fetchOwnerRow(string $table, int $uid): ?array
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
                ...$this->liveVersionConstraints($queryBuilder, $table),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
