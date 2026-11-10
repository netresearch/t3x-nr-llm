<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Information\Typo3Version;
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
    /** How many levels of inline children {@see self::workspaceRowsAround()} reads below the record. */
    private const CHILD_LEVELS_READ = 4;

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
     * The third member is what the workspace held around the record just
     * before the version was made ({@see self::workspaceRowsAround()}), for
     * the take-back ({@see self::discardVersion()}); where the record is new
     * in the workspace or had its draft already, what it held before the
     * write; empty in live and where somebody else made the version in the
     * meantime
     *
     * @return array{int, string, array<string, array<string, int|string|null>>}|ToolResult the version made now (or 0), TYPO3's complaints as {@see self::complaintsNote()} words them, and the workspace before
     */
    private function versionForTheWrite(string $table, int $uid, BackendUserAuthentication $user): array|ToolResult
    {
        $workspace = $user->workspace;
        $stored    = $this->storedRowIn($table, $uid, $workspace);
        if ($workspace <= 0 || $stored === null) {
            return [0, '', []];
        }

        // A record new in the workspace, or one with its draft there already:
        // no version to make, and the reading of before serves a take-back
        // after a throw ({@see self::takeBackAfterAThrow()}).
        if (self::toInt($stored['t3ver_wsid'] ?? 0) !== 0 || $this->versionUidIn($table, $uid, $workspace) > 0) {
            return [0, '', $this->workspaceRowsAround($table, $uid, $workspace)];
        }

        $before      = $this->workspaceRowsAround($table, $uid, $workspace);
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['version' => ['action' => 'new', 'label' => 'AI assistant']]]], $user);
        try {
            $dataHandler->process_cmdmap();
        } catch (Throwable $throwable) {
            // Thrown after core inserted the versions and before it mapped
            // them (a `processCmdmap_postProcess` hook, say): the uid of the
            // version never reaches this call, the reading of before does.
            $this->takeBackTo($table, $uid, $before, $user);

            throw $throwable;
        }

        // The version THIS command made: core maps the record to the copy it
        // made (`copyRecord_raw()`), and maps nothing where it found a
        // version already there — one the editor made in the meantime is not
        // this call's to discard.
        $mapped  = $dataHandler->copyMappingArray_merged[$table] ?? null;
        $version = is_array($mapped) ? self::toInt($mapped[$uid] ?? 0) : 0;

        if ($version > 0) {
            return [$version, $this->complaintsNote($dataHandler), $before];
        }

        // Made by somebody else between the question and the command: the
        // write goes onto that version, and nothing of it is this call's.
        if ($this->versionUidIn($table, $uid, $workspace) > 0) {
            return [0, $this->complaintsNote($dataHandler), []];
        }

        return $this->refuseOnDataHandlerErrors($dataHandler)
            ?? ToolResult::error(sprintf('The draft of %s [%d] could not be made in the workspace, and the DataHandler reported no error. Nothing was written.', $table, $uid));
    }

    /**
     * Take back a version this call made and what the write added with it,
     * and say what of the workspace is not as it was before: nothing, where
     * the take-back worked.
     *
     * `$before` is what the workspace held around the record before the
     * version was made ({@see self::workspaceRowsAround()}). Every row there
     * now that was not there then is this call's, and is discarded — a
     * `delete` of a workspace row is a discard in core
     * (`DataHandler::deleteAction()`): the record's version, its children's
     * rows, and the drafts core made of its translations in the same run,
     * which core's discard of the version does not reach
     * (`discardLocalizationOverlayRecords()` looks for translations by the
     * version's uid, and a translation's version points at the live record).
     * Every row that was there before is kept, also where core's discard of
     * the version would take it along as a child
     * ({@see ToolDataHandler::keepInTheWorkspace()}): a draft an editor made
     * of a reference before the call is the editor's, not the call's.
     *
     * The run carries the parent's table in its datamap, and nothing else:
     * core discards each file reference under the right it asks for updating
     * one (`DataHandler::hasPermissionToUpdate()`), which is CONTENT_EDIT
     * unless a `pages` row is in the run — PAGE_EDIT then, the right the write
     * itself was made under (TYPO3 14.3.10 and 13.4.37). The datamap is never
     * processed.
     *
     * What is left is read from the rows afterwards and compared with
     * `$before`. A row of before that the write CHANGED — core's
     * synchronisation turning a translation's draft into a delete
     * placeholder, say — stays changed: putting it back would take discarding
     * the draft, and the take-back never discards what was there before
     * (ADR-274).
     *
     * @param non-empty-string                              $table
     * @param array<string, array<string, int|string|null>> $before
     *
     * @return list<string> what differs from before, each in words; empty when nothing does
     */
    private function discardVersion(string $table, int $versionUid, array $before, BackendUserAuthentication $user): array
    {
        return $this->takeBackTo($table, $this->versionedUidOf($table, $versionUid), $before, $user);
    }

    /**
     * {@see self::discardVersion()} by the record's live uid, for a version
     * whose uid the call never learned.
     *
     * @param non-empty-string                              $table
     * @param array<string, array<string, int|string|null>> $before
     *
     * @return list<string> what differs from before, each in words; empty when nothing does
     */
    private function takeBackTo(string $table, int $liveUid, array $before, BackendUserAuthentication $user): array
    {
        $workspace = max(0, $user->workspace);
        $made      = array_diff_key($this->workspaceRowsAround($table, $liveUid, $workspace), $before);

        // A row the discard of another takes along is gone when its own
        // command comes, and that command finds nothing to do.
        //
        // `delete` on a workspace uid discarded it up to TYPO3 14.3.10; since
        // 14.3.11 it deletes the live record when the uid is a modified
        // version of the user's workspace, and the version becomes a delete
        // placeholder. 14.x has the `discard` command, which reaches the
        // discard `delete` did. 13.4 has none, and its `delete` still
        // discards (13.4.39). The `clearWSID` action of `version` discards on
        // both, but a hook that fails on `version` — the failure a take-back
        // may be answering — would fail again here.
        $discard = (new Typo3Version())->getMajorVersion() >= 14 ? ['discard' => 1] : ['delete' => 1];
        $cmdmap  = [];
        foreach ($made as $row) {
            $cmdmap[self::toStr($row['table'] ?? '')][self::toInt($row['uid'] ?? 0)] = $discard;
        }

        $kept = [];
        foreach ($before as $key => $row) {
            $kept[] = $key;
            if (self::toInt($row['t3ver_oid'] ?? 0) > 0) {
                $kept[] = self::toStr($row['table'] ?? '') . ':' . self::toInt($row['t3ver_oid'] ?? 0);
            }
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->keepInTheWorkspace($kept);
        $dataHandler->start([$table => []], $cmdmap, $user);
        $dataHandler->process_cmdmap();

        return $this->differencesFrom($before, $this->workspaceRowsAround($table, $liveUid, $workspace));
    }

    /**
     * Take back what a write that threw made in the workspace: the version
     * this call made, with {@see self::discardVersion()}; where it made none
     * — the record new in the workspace or with its draft already — the rows
     * the write added, by the reading of before. Rows of before stay, also
     * where the write changed them. Nothing in live, where the reading is
     * empty.
     *
     * @param non-empty-string                              $table
     * @param array<string, array<string, int|string|null>> $before
     */
    private function takeBackAfterAThrow(string $table, int $uid, int $version, array $before, BackendUserAuthentication $user): void
    {
        if ($version > 0) {
            $this->discardVersion($table, $version, $before, $user);
        } elseif ($before !== []) {
            $this->takeBackTo($table, $uid, $before, $user);
        }
    }

    /**
     * The sentence a writer's answer ends on after {@see self::discardVersion()}:
     * the workspace is as it was, or what is not and where to look.
     *
     * @param list<string> $residue
     */
    private function takeBackSentence(string $record, array $residue): string
    {
        if ($residue === []) {
            return sprintf('The draft of %s this call made was discarded, and the workspace left as it was.', $record);
        }

        return sprintf(
            'The draft of %s this call made was taken back, but the workspace is not as it was before the call: %s. Check it in the Workspaces module.',
            $record,
            implode('; ', $residue),
        );
    }

    /**
     * What a write to the record can touch in `$workspace`, by `table:uid`:
     * the rows there of the record and of its translations — versions,
     * delete placeholders, translations new there — and the rows there of
     * their inline children, file references among them, which core's
     * discard of a version takes along (`discardRecordRelations()`). Each
     * row with the columns a take-back has to leave as it found them; empty
     * in live. Children of children are read as well, down the inline
     * relations of each child table: core's discard of a child's version
     * takes that child's own children along.
     *
     * Core relates a version's children to the LIVE uid of their parent, and
     * a translation's to the translation's live uid, so the children are
     * asked by those.
     *
     * @return array<string, array<string, int|string|null>>
     */
    private function workspaceRowsAround(string $table, int $uid, int $workspace): array
    {
        if ($workspace <= 0 || $uid < 1) {
            return [];
        }

        $parents   = [$uid, ...$this->translationUidsOf($table, $uid, $workspace)];
        $relations = $this->childRelationsOf($table);
        $rows      = [];

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $records = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspace, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->in('t3ver_oid', $queryBuilder->createNamedParameter($parents, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($parents, Connection::PARAM_INT_ARRAY)),
                ),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($records as $row) {
            $rows[$table . ':' . self::toInt($row['uid'] ?? 0)] = $this->comparedColumns($table, $row, ['uid', 't3ver_oid', 't3ver_state', 'deleted', ...array_keys($relations)]);
        }

        $this->readChildRowsIn($table, $parents, $workspace, $rows, 1);

        ksort($rows);

        return $rows;
    }

    /**
     * The rows in `$workspace` of the inline children of `$parents` of
     * `$table`, into `$rows`, and of their children in turn. A child is
     * followed by its live uid — the live row's, a version's `t3ver_oid`, or
     * the uid of a child new in the workspace — since core relates
     * grandchildren to that uid. Four levels deep at most, which also ends a
     * relation that leads back to its own table (a container's elements).
     *
     * @param list<int>                                     $parents
     * @param array<string, array<string, int|string|null>> $rows
     */
    private function readChildRowsIn(string $table, array $parents, int $workspace, array &$rows, int $depth): void
    {
        foreach ($this->childRelationsOf($table) as $relation) {
            $child        = $relation['table'];
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($child);
            $queryBuilder->getRestrictions()->removeAll();
            $constraints = [
                $queryBuilder->expr()->in('t3ver_wsid', $queryBuilder->createNamedParameter([0, $workspace], Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->in($relation['pointer'], $queryBuilder->createNamedParameter($parents, Connection::PARAM_INT_ARRAY)),
            ];
            if ($relation['tableField'] !== '') {
                $constraints[] = $queryBuilder->expr()->eq($relation['tableField'], $queryBuilder->createNamedParameter($table));
            }

            $grandparents = [];
            foreach ($queryBuilder->select('*')->from($child)->where(...$constraints)->orderBy('uid')->executeQuery()->fetchAllAssociative() as $row) {
                $original       = self::toInt($row['t3ver_oid'] ?? 0);
                $grandparents[] = $original > 0 ? $original : self::toInt($row['uid'] ?? 0);
                if (self::toInt($row['t3ver_wsid'] ?? 0) === $workspace) {
                    $rows[$child . ':' . self::toInt($row['uid'] ?? 0)] = $this->comparedColumns(
                        $child,
                        $row,
                        ['uid', 't3ver_oid', 't3ver_state', 'deleted', ...$this->columnsOf($child)],
                    );
                }
            }

            if ($grandparents !== [] && $depth < self::CHILD_LEVELS_READ) {
                $this->readChildRowsIn($child, array_values(array_unique($grandparents)), $workspace, $rows, $depth + 1);
            }
        }
    }

    /**
     * The live uids of a record's translations and the uids of its
     * translations new in `$workspace`; none for a table without
     * translations.
     *
     * @return list<int>
     */
    private function translationUidsOf(string $table, int $uid, int $workspace): array
    {
        $pointer = self::toStr($this->tcaSection($table, 'ctrl')['transOrigPointerField'] ?? '');
        if ($pointer === '') {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return array_map(static fn (mixed $uid): int => self::toInt($uid), $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($pointer, $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspace, Connection::PARAM_INT)),
                        $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    ),
                ),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * The inline and file columns of a table whose children are versioned
     * with their parent, by column: the child table, the column that points
     * at the parent, and the column that names the parent's table ('' where
     * there is none).
     *
     * @return array<string, array{table: non-empty-string, pointer: non-empty-string, tableField: string}>
     */
    private function childRelationsOf(string $table): array
    {
        $relations = [];
        foreach ($this->tcaSection($table, 'columns') as $name => $column) {
            $config   = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];
            $relation = $this->childRelationIn($config);
            if ($relation !== null) {
                $relations[(string) $name] = $relation;
            }
        }

        return $relations;
    }

    /**
     * The child table, the pointer to the parent and the column naming the
     * parent's table of one inline or file column; null for any other
     * column, and for a child table TYPO3 keeps no drafts of.
     *
     * @param array<mixed> $config
     *
     * @return array{table: non-empty-string, pointer: non-empty-string, tableField: string}|null
     */
    private function childRelationIn(array $config): ?array
    {
        $relation = match (self::toStr($config['type'] ?? '')) {
            'file'   => ['sys_file_reference', 'uid_foreign', 'tablenames'],
            'inline' => [self::toStr($config['foreign_table'] ?? ''), self::toStr($config['foreign_field'] ?? ''), self::toStr($config['foreign_table_field'] ?? '')],
            default  => ['', '', ''],
        };
        [$child, $pointer, $tableField] = $relation;
        if ($child === '' || $pointer === '' || !(bool) ($this->tcaSection($child, 'ctrl')['versioningWS'] ?? false)) {
            return null;
        }

        return ['table' => $child, 'pointer' => $pointer, 'tableField' => $tableField];
    }

    /**
     * The columns the TCA of a table declares.
     *
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        return array_map(strval(...), array_keys($this->tcaSection($table, 'columns')));
    }

    /**
     * One section of a table's TCA, `ctrl` or `columns`; empty where there
     * is none.
     *
     * @return array<mixed>
     */
    private function tcaSection(string $table, string $section): array
    {
        $tca        = $GLOBALS['TCA'] ?? null;
        $definition = is_array($tca) && is_array($tca[$table] ?? null) ? $tca[$table] : [];

        return is_array($definition[$section] ?? null) ? $definition[$section] : [];
    }

    /**
     * A row cut to `$columns` — those it has — and named by its table.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $columns
     *
     * @return array<string, int|string|null>
     */
    private function comparedColumns(string $table, array $row, array $columns): array
    {
        $compared = ['table' => $table];
        foreach ($columns as $column) {
            if (array_key_exists($column, $row)) {
                $value             = $row[$column];
                $compared[$column] = is_scalar($value) ? (string) $value : null;
            }
        }

        return $compared;
    }

    /**
     * What differs between two readings of {@see self::workspaceRowsAround()},
     * each in words: a row that stayed, a row that is gone, a row that
     * changed and how.
     *
     * @param array<string, array<string, int|string|null>> $before
     * @param array<string, array<string, int|string|null>> $after
     *
     * @return list<string>
     */
    private function differencesFrom(array $before, array $after): array
    {
        $differences = [];
        foreach ($after as $key => $row) {
            if (!isset($before[$key])) {
                $differences[] = sprintf('%s, made by this call, stayed', $this->workspaceRowName($row));
            }
        }

        foreach ($before as $key => $row) {
            if (!isset($after[$key])) {
                $differences[] = sprintf('%s, there before the call, is gone', $this->workspaceRowName($row));

                continue;
            }

            $changes = $this->changesOf($row, $after[$key]);
            if ($changes !== []) {
                $differences[] = sprintf('%s, there before the call, changed (%s)', $this->workspaceRowName($row), implode(', ', $changes));
            }
        }

        return $differences;
    }

    /**
     * How a row of before reads now, column by column, in words: the
     * columns that changed by name, never their values, which are an
     * editor's content and would reach the model and the run's trace.
     *
     * @param array<string, int|string|null> $row
     * @param array<string, int|string|null> $now
     *
     * @return list<string>
     */
    private function changesOf(array $row, array $now): array
    {
        $changes = [];
        foreach ($row as $column => $value) {
            $current = $now[$column] ?? null;
            if ($current === $value) {
                continue;
            }

            $changes[] = $column === 't3ver_state' && $current === '2'
                ? sprintf('became a delete placeholder, so publishing the workspace deletes [%d] live', self::toInt($row['t3ver_oid'] ?? 0))
                : $column;
        }

        return $changes;
    }

    /**
     * A workspace row by table, uid and what it is there.
     *
     * @param array<string, int|string|null> $row
     */
    private function workspaceRowName(array $row): string
    {
        $versioned = self::toInt($row['t3ver_oid'] ?? 0);
        $what      = match (true) {
            self::toInt($row['t3ver_state'] ?? 0) === 1 => 'new in the workspace',
            self::toInt($row['t3ver_state'] ?? 0) === 2 => sprintf('the delete placeholder of [%d]', $versioned),
            default                                     => sprintf('the draft of [%d]', $versioned),
        };

        return sprintf('%s [%d] (%s)', self::toStr($row['table'] ?? ''), self::toInt($row['uid'] ?? 0), $what);
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
     * Whether a record has a move version in `$workspace` (`t3ver_state` 4):
     * moved there with core's `move` command, or moved along with a parent
     * that was.
     */
    private function isMovedIn(string $table, int $uid, int $workspace): bool
    {
        if ($workspace <= 0 || $uid < 1) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return self::toInt($queryBuilder
            ->count('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspace, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_state', $queryBuilder->createNamedParameter(4, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne()) > 0;
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
