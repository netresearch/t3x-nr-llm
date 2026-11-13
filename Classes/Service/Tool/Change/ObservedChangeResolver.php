<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Change;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Domain\Enum\ChangeCoverage;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChange;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChangeSet;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Turns what the hooks collected into an {@see ObservedChangeSet} (ADR-292):
 * adds the records the DataHandler's own history names and reads each row's
 * stored state.
 *
 * Two sources, because each misses something. The hooks see a record when a
 * datamap or command names it; they do not see the inline children a delete
 * takes along. `sys_history` names every record a DataHandler chain logged
 * under one correlation scope, and is empty where logging is off or the table
 * was pruned. The union is the list; with the history gone the hooks stand
 * alone. `history_data` is never selected (ADR-185).
 *
 * The stored state — workspace, live uid, language, the disable flag and the
 * row's own time and group restrictions — is read from the row, not from what
 * the tool said, and is null where the row no longer exists. No other column
 * is kept, and no value of the record reaches the set.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ObservedChangeResolver
{
    /** History rows read per call; the set is bounded far below this. */
    private const HISTORY_LIMIT = 500;

    private const HISTORY_ACTIONS = [
        1 => ChangeAction::CREATED,
        2 => ChangeAction::UPDATED,
        3 => ChangeAction::MOVED,
        4 => ChangeAction::DELETED,
        5 => ChangeAction::RESTORED,
    ];

    public function __construct(private ConnectionPool $connectionPool) {}

    /**
     * @param array<string, array{table: string, uid: int, action: ChangeAction, workspace: int}> $records the hooks' records by `table:uid`
     * @param list<string>                                                                        $scopes  the correlation scopes of the DataHandler chains that wrote them
     * @param int|null                                                                            $after   the highest `sys_history` uid before the call; null reads no history
     */
    public function resolve(array $records, array $scopes, ?int $after = null): ObservedChangeSet
    {
        $confirmed = $after === null ? [] : $this->fromHistory($scopes, $after);
        foreach ($confirmed as $key => $entry) {
            $was           = $records[$key]['action'] ?? null;
            $records[$key] = $was instanceof ChangeAction ? [...$entry, 'action' => ToolChangeCollector::stronger($was, $entry['action'])] : $entry;
        }

        // A command names the live row of a version it wrote, and the history
        // names the version: after the entries are settled the two meet.
        $settled = [];
        foreach ($records as $entry) {
            $applied = $this->applied($entry, $confirmed);
            if ($applied === null) {
                continue;
            }

            $key = $applied['table'] . ':' . $applied['uid'];
            $was = $settled[$key]['action'] ?? null;

            $settled[$key] = $was instanceof ChangeAction ? [...$applied, 'action' => ToolChangeCollector::stronger($was, $applied['action'])] : $applied;
        }

        $changes = array_map($this->change(...), array_slice(array_values($settled), 0, ObservedChangeSet::LIMIT));

        return new ObservedChangeSet($changes, ChangeCoverage::DATAHANDLER, count($settled) - count($changes));
    }

    /**
     * @param list<string> $scopes
     *
     * @return array<string, array{table: string, uid: int, action: ChangeAction, workspace: int}>
     */
    private function fromHistory(array $scopes, int $after): array
    {
        $found = [];
        foreach ($scopes as $scope) {
            foreach ($this->historyRows($scope, $after) as $row) {
                $entry = $this->historyEntry($row);
                if ($entry === null) {
                    continue;
                }

                $key         = $entry['table'] . ':' . $entry['uid'];
                $was         = $found[$key]['action'] ?? null;
                $found[$key] = $was instanceof ChangeAction ? [...$entry, 'action' => ToolChangeCollector::stronger($was, $entry['action'])] : $entry;
            }
        }

        return $found;
    }

    /**
     * The history rows of one DataHandler chain written after `$after`, oldest
     * first; none where the history cannot be read, and the hooks' records
     * stand alone.
     *
     * @return list<array<string, mixed>>
     */
    private function historyRows(string $scope, int $after): array
    {
        try {
            $query = $this->connectionPool->getQueryBuilderForTable('sys_history');

            return $query
                ->select('tablename', 'recuid', 'actiontype', 'workspace')
                ->from('sys_history')
                ->where(
                    $query->expr()->gt('uid', $query->createNamedParameter($after, Connection::PARAM_INT)),
                    $query->expr()->like('correlation_id', $query->createNamedParameter('%$' . $query->escapeLikeWildcards($scope) . ':%')),
                )
                ->orderBy('uid')
                ->setMaxResults(self::HISTORY_LIMIT)
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{table: string, uid: int, action: ChangeAction, workspace: int}|null
     */
    private function historyEntry(array $row): ?array
    {
        $table  = is_string($row['tablename'] ?? null) ? $row['tablename'] : '';
        $uid    = is_numeric($row['recuid'] ?? null) ? (int) $row['recuid'] : 0;
        $action = self::HISTORY_ACTIONS[is_numeric($row['actiontype'] ?? null) ? (int) $row['actiontype'] : 0] ?? null;
        if ($uid < 1 || !$action instanceof ChangeAction || preg_match('/\A\w{1,64}\z/', $table) !== 1) {
            return null;
        }

        return [
            'table'     => $table,
            'uid'       => $uid,
            'action'    => $action,
            'workspace' => is_numeric($row['workspace'] ?? null) ? max(0, (int) $row['workspace']) : 0,
        ];
    }

    /**
     * The entry as the stored rows bear it out, or null where they do not.
     *
     * The hook of a command hears of the command, not of its outcome: a delete
     * of a uid that no row bears reaches it all the same. A delete or undelete
     * of a row that is gone counts only where the DataHandler's own history
     * names it. A delete in a draft workspace counts when a version of the live
     * row was written, and the entry then names that version.
     *
     * @param array{table: string, uid: int, action: ChangeAction, workspace: int}                $entry
     * @param array<string, array{table: string, uid: int, action: ChangeAction, workspace: int}> $confirmed the history's entries by `table:uid`
     *
     * @return array{table: string, uid: int, action: ChangeAction, workspace: int}|null
     */
    private function applied(array $entry, array $confirmed): ?array
    {
        if ($entry['action'] !== ChangeAction::DELETED && $entry['action'] !== ChangeAction::RESTORED) {
            return $entry;
        }

        $row = $this->row($entry['table'], $entry['uid']);
        if ($row === null) {
            return isset($confirmed[$entry['table'] . ':' . $entry['uid']]) ? $entry : null;
        }

        $liveRow = !is_numeric($row['t3ver_wsid'] ?? null) || (int) $row['t3ver_wsid'] === 0;
        if ($entry['action'] === ChangeAction::DELETED && $entry['workspace'] > 0 && $liveRow) {
            $version = $this->versionIn($entry['table'], $entry['uid'], $entry['workspace']);

            return $version === null ? null : [...$entry, 'uid' => $version];
        }

        return $entry;
    }

    /**
     * The uid of the version a draft workspace holds of a live row, or null.
     */
    private function versionIn(string $table, int $uid, int $workspace): ?int
    {
        try {
            $query = $this->connectionPool->getQueryBuilderForTable($table);
            $query->getRestrictions()->removeAll();
            $version = $query
                ->select('uid')
                ->from($table)
                ->where(
                    $query->expr()->eq('t3ver_oid', $query->createNamedParameter($uid, Connection::PARAM_INT)),
                    $query->expr()->eq('t3ver_wsid', $query->createNamedParameter($workspace, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchOne();
        } catch (Throwable) {
            return null;
        }

        return is_numeric($version) && (int) $version > 0 ? (int) $version : null;
    }

    /**
     * @param array{table: string, uid: int, action: ChangeAction, workspace: int} $entry
     */
    private function change(array $entry): ObservedChange
    {
        $record = new RecordReference($entry['table'], $entry['uid']);
        $row    = $this->row($entry['table'], $entry['uid']);

        // Without the row only the writer's workspace is known.
        if ($row === null) {
            return new ObservedChange($record, $entry['action'], $entry['workspace']);
        }

        $ctrl = $this->ctrl($entry['table']);

        // The row says where it is stored. The writer's workspace does not: a
        // user in a draft workspace writes a table without versioning live.
        $workspace = is_numeric($row['t3ver_wsid'] ?? null) ? max(0, (int) $row['t3ver_wsid']) : 0;
        $liveUid   = is_numeric($row['t3ver_oid'] ?? null) && (int) $row['t3ver_oid'] > 0 ? (int) $row['t3ver_oid'] : null;

        $languageField = is_string($ctrl['languageField'] ?? null) ? $ctrl['languageField'] : null;
        $language      = $languageField !== null && is_numeric($row[$languageField] ?? null) ? (int) $row[$languageField] : null;

        $enable                  = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        [$hidden, $restrictions] = $this->enableState($row, $enable);

        return new ObservedChange($record, $entry['action'], $workspace, $liveUid, $language, false, $hidden, $restrictions);
    }

    /**
     * The row's own disable flag and its time and group restrictions, by the
     * columns its table's `enablecolumns` name.
     *
     * @param array<string, mixed> $row
     * @param array<mixed>         $enable
     *
     * @return array{0: bool|null, 1: list<string>}
     */
    private function enableState(array $row, array $enable): array
    {
        $disabled = is_string($enable['disabled'] ?? null) ? $enable['disabled'] : null;
        $hidden   = $disabled !== null && array_key_exists($disabled, $row) ? (bool) $row[$disabled] : null;

        $restrictions = [];
        foreach (['starttime', 'endtime'] as $kind) {
            $column = is_string($enable[$kind] ?? null) ? $enable[$kind] : null;
            if ($column !== null && is_numeric($row[$column] ?? null) && (int) $row[$column] > 0) {
                $restrictions[] = $kind;
            }
        }

        $groups = is_string($enable['fe_group'] ?? null) ? $enable['fe_group'] : null;
        if ($groups !== null && is_scalar($row[$groups] ?? null) && trim((string) $row[$groups], " \t\n\r\0\x0B,0") !== '') {
            $restrictions[] = 'fe_group';
        }

        return [$hidden, $restrictions];
    }

    /**
     * @return array<string, mixed>
     */
    private function ctrl(string $table): array
    {
        // @phpstan-ignore-next-line TCA access returns mixed at each nesting level
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? null;

        if (!is_array($ctrl)) {
            return [];
        }

        /** @var array<string, mixed> $ctrl */
        return $ctrl;
    }

    /**
     * The stored row, deleted ones included, or null where it does not exist
     * or its table is not a TCA table.
     *
     * @return array<string, mixed>|null
     */
    private function row(string $table, int $uid): ?array
    {
        // @phpstan-ignore-next-line TCA access returns mixed at each nesting level
        if (!isset($GLOBALS['TCA'][$table])) {
            return null;
        }

        try {
            $query = $this->connectionPool->getQueryBuilderForTable($table);
            $query->getRestrictions()->removeAll();
            $row = $query
                ->select('*')
                ->from($table)
                ->where($query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? $row : null;
    }
}
