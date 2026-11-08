<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What the writers that CREATE a record — a content element, a page, a record
 * of another table, a file reference — need to do that in the draft
 * workspace a run was started in (ADR-264).
 *
 * The DataHandler stores a record created by a user in a draft workspace as a
 * row new in that workspace (`t3ver_wsid` = the workspace, `t3ver_state` = 1,
 * `t3ver_oid` = 0) and a change of an existing parent as that parent's version
 * there. Everything around the write has to look at the records the same
 * way: the page and the anchor the new record is placed by may themselves be
 * new in the workspace or changed there, the read-back finds a row the live
 * queries filter out, and a file reference list is the one the workspace
 * shows. Each method is the live behaviour in workspace 0, so a writer asks
 * them unconditionally.
 *
 * Another workspace's rows are never found: a version row is reached through
 * its live record, and a row new in another workspace is not there.
 *
 * The consuming class must provide `self::toInt()` (via
 * {@see \Netresearch\NrLlm\Utility\SafeCastTrait}), a
 * `private ConnectionPool $connectionPool`, and
 * {@see WritesThroughDataHandlerTrait}.
 */
trait CreatesRecordsInTheRunsWorkspaceTrait
{
    /**
     * A row by uid as a user in `$workspace` sees it — overlaid with its
     * version there, the live uid kept — or null: not there, deleted (in the
     * workspace too), a version row addressed by its own uid, or a row of
     * another workspace. In live exactly the live row.
     *
     * @return array<string, mixed>|null
     */
    private function rowSeenIn(string $table, int $uid, int $workspace): ?array
    {
        $row = $this->storedRowIn($table, $uid, $workspace);

        return $row === null ? null : $this->rowInWorkspace($table, $row, max(0, $workspace));
    }

    /**
     * The STORED row a uid names for a user in `$workspace`, without the
     * overlay: the live row, or the row new in that workspace. This is the row
     * the DataHandler asks a page permission of when it creates a record on a
     * page (`BackendUtility::getRecord('pages', $pid)` in `process_datamap()`,
     * TYPO3 13.4 and 14.3): a version keeps the permissions it was made with,
     * so the overlay would answer for an older state than core does (ADR-263).
     *
     * @return array<string, mixed>|null
     */
    private function storedRowIn(string $table, int $uid, int $workspace): ?array
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
                ...$this->workspaceVersionConstraints($queryBuilder, $table, max(0, $workspace)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * A page the user may act on with `$permission`, as their workspace
     * shows it — or null. The permission is asked of the stored row, as the
     * DataHandler asks it ({@see self::storedRowIn()}); the title and the
     * place come from the overlay, and a page deleted in the workspace is not
     * there.
     *
     * @return array<string, mixed>|null
     */
    private function pageForWriteIn(int $uid, BackendUserAuthentication $user, int $permission): ?array
    {
        $workspace = max(0, $user->workspace);
        $stored    = $this->storedRowIn('pages', $uid, $workspace);
        if ($stored === null || !$user->doesUserHaveAccess($stored, $permission)) {
            return null;
        }

        return $this->rowInWorkspace('pages', $stored, $workspace);
    }

    /**
     * Make sure an existing record has its version in the user's draft
     * workspace before a write changes its inline children, and say whether
     * this call made it: the uid of the version made now, 0 where none was
     * needed (live, a record new in the workspace, a version already there).
     *
     * Made by its own command so that a write which fails its read-back can
     * be taken back whole: discarding the version takes the references the
     * write added with it ({@see self::discardVersion()}). Core's versioning
     * of the children stamps `tstamp` and renumbers `sorting_foreign` 1..n on
     * the LIVE child rows (`RelationHandler::writeForeignField()`, TYPO3 13.4
     * and 14.3) — their order and content stay; it does so for an editor's
     * own save in a workspace as well (ADR-264).
     *
     * The version that is there decides, not the command's error log
     * (ADR-198): a complaint about another record of the run leaves the
     * version made, and the write goes on. The complaint comes back as the
     * second member, for the writer's answer; the write that follows reads
     * back on its own and takes the version back where it fails.
     *
     * @param non-empty-string $table
     *
     * @return array{int, string}|ToolResult the version made now (or 0) and TYPO3's complaints as {@see self::complaintsNote()} words them
     */
    private function versionForTheWrite(string $table, int $uid, BackendUserAuthentication $user): array|ToolResult
    {
        $workspace = $user->workspace;
        $stored    = $this->storedRowIn($table, $uid, $workspace);
        if ($workspace <= 0 || $stored === null || self::toInt($stored['t3ver_wsid'] ?? 0) !== 0
            || $this->versionUidIn($table, $uid, $workspace) > 0
        ) {
            return [0, ''];
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['version' => ['action' => 'new', 'label' => 'AI assistant']]]], $user);
        $dataHandler->process_cmdmap();

        // The version THIS command made: core maps the record to the copy it
        // made (`copyRecord_raw()`), and maps nothing where it found a
        // version already there — one the editor made in the meantime is not
        // this call's to discard.
        $mapped  = $dataHandler->copyMappingArray_merged[$table] ?? null;
        $version = is_array($mapped) ? self::toInt($mapped[$uid] ?? 0) : 0;

        if ($version > 0) {
            return [$version, $this->complaintsNote($dataHandler)];
        }

        // Made by somebody else between the question and the command: the
        // write goes onto that version, and nothing of it is this call's.
        if ($this->versionUidIn($table, $uid, $workspace) > 0) {
            return [0, $this->complaintsNote($dataHandler)];
        }

        return $this->refuseOnDataHandlerErrors($dataHandler)
            ?? ToolResult::error(sprintf('The draft of %s [%d] could not be made in the workspace, and the DataHandler reported no error. Nothing was written.', $table, $uid));
    }

    /**
     * Take back a version this call made, with everything the write added
     * under it — and say whether it is gone. A `delete` of a workspace row is
     * a discard in core (`DataHandler::deleteAction()`): the version row and
     * its inline children's versions and new children are removed outright.
     *
     * The run carries the parent's table in its datamap, and nothing else:
     * core discards each file reference under the right it asks for updating
     * one (`DataHandler::hasPermissionToUpdate()`), which is CONTENT_EDIT
     * unless a `pages` row is in the run — PAGE_EDIT then, the right the write
     * itself was made under (TYPO3 14.3.10 and 13.4.37). Without it, an editor
     * of a page's properties gets the page version discarded and its
     * references left in the workspace (ADR-272). The datamap is never
     * processed.
     *
     * Gone means the version row AND every file reference of the record in
     * that workspace: a version made by this call had none before it.
     *
     * @param non-empty-string $table
     */
    private function discardVersion(string $table, int $versionUid, BackendUserAuthentication $user): bool
    {
        $liveUid = $this->versionedUidOf($table, $versionUid);

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([$table => []], [$table => [$versionUid => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        return !$this->rowExists($table, $versionUid)
            && ($liveUid < 1 || $this->referenceRowsIn($table, [$liveUid], max(0, $user->workspace)) === []);
    }

    /**
     * The live uid a version row stands for, or 0.
     */
    private function versionedUidOf(string $table, int $versionUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return self::toInt($queryBuilder
            ->select('t3ver_oid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($versionUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne());
    }

    /**
     * The uids of every `sys_file_reference` row of `$workspace` — versions,
     * delete placeholders, rows new there, deleted or not — that relates to
     * one of `$uids` of `$table`, in uid order.
     *
     * @param list<int> $uids live uids
     *
     * @return list<int>
     */
    private function referenceRowsIn(string $table, array $uids, int $workspace): array
    {
        if ($workspace <= 0 || $uids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        return array_map(static fn (mixed $uid): int => self::toInt($uid), $queryBuilder
            ->select('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspace, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->in('uid_foreign', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * The uid of a record's version in a workspace, or 0. Read from the
     * database each time: a command between two calls makes one.
     *
     * @phpstan-impure
     */
    private function versionUidIn(string $table, int $uid, int $workspace): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return self::toInt($queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspace, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne());
    }

    /**
     * Whether any row, deleted or not, still carries the uid: a discard
     * removes the row, so a row a hook only marked deleted is still there,
     * with whatever it holds.
     */
    private function rowExists(string $table, int $uid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return self::toInt($queryBuilder
            ->count('uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne()) > 0;
    }

    /**
     * The file references of a record's field as a user in `$workspace` sees
     * them, in their order there, each by the uid of the row that holds it in
     * that workspace: its version there, else the live row or the row new
     * there; a reference deleted in the workspace is left out. In live the
     * live references, as before.
     *
     * The uids are the list a datamap names the field with. Named by their
     * live uids instead, the DataHandler writes the new order onto the LIVE
     * rows and leaves the versions' order as it was, so a new reference does
     * not come last in the workspace (measured on TYPO3 14.3.10, ADR-264).
     * The DataHandler relates a new reference to the LIVE uid of a parent
     * that has a version, so the query asks the parent's live uid.
     *
     * @return list<int>
     */
    private function referencesSeenIn(string $table, int $uid, string $field, int $workspace): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
                ...$this->workspaceVersionConstraints($queryBuilder, 'sys_file_reference', max(0, $workspace)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $seen = [];
        foreach ($rows as $row) {
            $overlaid = $this->rowInWorkspace('sys_file_reference', $row, max(0, $workspace));
            if ($overlaid === null
                || self::toInt($overlaid['uid_foreign'] ?? 0) !== $uid
                || self::toInt($overlaid['deleted'] ?? 0) !== 0
            ) {
                continue;
            }

            $seen[] = $overlaid;
        }

        usort($seen, static fn (array $a, array $b): int => [self::toInt($a['sorting_foreign'] ?? 0), self::toInt($a['uid'] ?? 0)]
            <=> [self::toInt($b['sorting_foreign'] ?? 0), self::toInt($b['uid'] ?? 0)]);

        return array_map(static fn (array $row): int => self::toInt($row['_ORIG_uid'] ?? $row['uid'] ?? 0), $seen);
    }
}
