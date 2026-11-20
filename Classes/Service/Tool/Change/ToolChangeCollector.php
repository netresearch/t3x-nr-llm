<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Change;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Domain\Enum\ChangeCoverage;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChangeSet;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Collects the records the DataHandler runs write while one tool call executes
 * (ADR-292).
 *
 * TYPO3 13.4 and 14.3 dispatch no PSR-14 event for a DataHandler record
 * operation, so the collector is fed by the hooks of {@see ObservedChangeHook}.
 * It records only while armed: the tool loop arms it around
 * `ToolInterface::execute()` and takes the result afterwards, and again around
 * the dispatch of the call's write events, whose listeners' writes join the
 * call's list (#1432), so a backend request outside a tool call is untouched.
 *
 * Static, like the failure list of {@see \Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler}:
 * every tool builds its own DataHandler, the hooks are global, and neither the
 * loop nor a tool hands the collector to the other. Armings nest, as when a
 * tool runs another loop, and the inner take hands its records to the frame
 * around it, which saw the same writes.
 *
 * `sys_history` has no index on its correlation id, so its rows are read only
 * from the uid the table had when the first DataHandler run of the call began
 * (the watermark): a range of the primary key, as long as the call's own writes.
 * The first hook of a run takes it. A call whose DataHandler runs never reached
 * that hook has no watermark, and its history is not read. A watermark that
 * cannot be read is {@see self::UNREADABLE}: the history is not read either,
 * and the call's list is incomplete.
 *
 * What it sees is what goes through a DataHandler. It states that and nothing
 * more: see {@see ChangeCoverage}.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ToolChangeCollector
{
    /** The watermark of a call whose read of it failed. */
    public const UNREADABLE = -1;

    /** @var list<array{records: array<string, array{table: string, uid: int, action: ChangeAction, workspace: int}>, scopes: array<string, true>, watermark: int|null, marked: bool}> */
    private static array $frames = [];

    /** @var array<string, array<int, array{workspace: int, stage: int}>|null> the rows a publish names, before it ran, by `table:uid` */
    private static array $beforePublish = [];

    /** The set of the call that just ended, until the run trace records it. */
    private static ?ObservedChangeSet $held = null;

    /**
     * Start collecting for one tool call.
     */
    public static function arm(): void
    {
        // A set nobody recorded belongs to an earlier call.
        self::$held     = null;
        self::$frames[] = ['records' => [], 'scopes' => [], 'watermark' => null, 'marked' => false];
    }

    /**
     * How many calls have armed the collector and not taken it yet.
     */
    public static function depth(): int
    {
        return count(self::$frames);
    }

    public static function isArmed(): bool
    {
        return self::$frames !== [];
    }

    /**
     * A DataHandler run is about to write: remember where `sys_history` stands,
     * once per armed call, before the first row of the call is written.
     */
    public static function beforeWrite(): void
    {
        if (!self::isArmed() || !in_array(false, array_column(self::$frames, 'marked'), true)) {
            return;
        }

        try {
            $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_history');
            $top   = $query->selectLiteral('MAX(uid)')->from('sys_history')->executeQuery()->fetchOne();
            $mark  = is_numeric($top) ? (int) $top : 0;
        } catch (Throwable) {
            // What could not be read is not "nothing was written".
            $mark = self::UNREADABLE;
        }

        foreach (self::$frames as $index => $frame) {
            if (!$frame['marked']) {
                self::$frames[$index]['watermark'] = $mark;
                self::$frames[$index]['marked']    = true;
            }
        }
    }

    /**
     * Note one record a DataHandler run wrote. Ignored while not armed.
     *
     * @param string|null $scope the correlation scope of the DataHandler chain, which its `sys_history` rows share
     */
    public static function record(string $table, int $uid, ChangeAction $action, int $workspace, ?string $scope): void
    {
        $last = array_key_last(self::$frames);
        if ($last === null || $uid < 1 || preg_match('/\A\w{1,64}\z/', $table) !== 1) {
            return;
        }

        $key = $table . ':' . $uid;
        $was = self::$frames[$last]['records'][$key]['action'] ?? null;

        self::$frames[$last]['records'][$key] = [
            'table'     => $table,
            'uid'       => $uid,
            'action'    => $was instanceof ChangeAction ? self::stronger($was, $action) : $action,
            'workspace' => $workspace,
        ];

        if ($scope !== null && $scope !== '') {
            self::$frames[$last]['scopes'][$scope] = true;
        }
    }

    /**
     * Stop collecting for the innermost call and return what it saw.
     */
    /**
     * What the innermost armed call has written so far, without ending it: the
     * hooks' records, the DataHandler chains and the history watermark, for a
     * check of the effect of the call (ADR-295).
     *
     * @return array{records: array<string, array{table: string, uid: int, action: ChangeAction, workspace: int}>, scopes: list<string>, watermark: int|null}|null
     */
    public static function peek(): ?array
    {
        $last = array_key_last(self::$frames);
        if ($last === null) {
            return null;
        }

        $frame = self::$frames[$last];

        return ['records' => $frame['records'], 'scopes' => array_keys($frame['scopes']), 'watermark' => $frame['watermark']];
    }

    /**
     * @param bool $intoOuter false keeps the records out of the frame around
     *                        this one, for writes the effect check of that
     *                        frame must not judge (ADR-295, #1438)
     */
    public static function take(?RecordReference $writeTarget = null, bool $intoOuter = true): ObservedChangeSet
    {
        $frame = array_pop(self::$frames);
        if ($frame === null) {
            return new ObservedChangeSet([], ChangeCoverage::NOT_CAPTURED);
        }

        $outer = array_key_last(self::$frames);
        if ($outer !== null && $intoOuter) {
            foreach ($frame['records'] as $key => $entry) {
                $was                                   = self::$frames[$outer]['records'][$key]['action'] ?? null;
                self::$frames[$outer]['records'][$key] = $was instanceof ChangeAction ? [...$entry, 'action' => self::stronger($was, $entry['action'])] : $entry;
            }

            self::$frames[$outer]['scopes'] += $frame['scopes'];
        }

        try {
            $set = ChangeSets::withTargetMarked(
                (new ObservedChangeResolver(GeneralUtility::makeInstance(ConnectionPool::class)))->resolve($frame['records'], array_keys($frame['scopes']), $frame['watermark'], $writeTarget),
                $writeTarget,
            );
        } catch (Throwable $failure) {
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->warning(
                'The records a tool call changed could not be resolved.',
                ['exception' => $failure],
            );

            return new ObservedChangeSet([], ChangeCoverage::NOT_CAPTURED);
        }

        return $writeTarget instanceof RecordReference && !ChangeSets::holdsTarget($set, $writeTarget)
            ? ChangeSets::withCoverage($set, ChangeCoverage::NOT_CAPTURED)
            : $set;
    }

    /**
     * Keep the set of the call that just ended for the run trace, which
     * records it right after the call's tool step.
     */
    public static function hold(ObservedChangeSet $set): void
    {
        self::$held = $set;
    }

    /**
     * The held set, once.
     */
    public static function releaseHeld(): ?ObservedChangeSet
    {
        $set        = self::$held;
        self::$held = null;

        return $set;
    }

    /**
     * Forget every arming and the held set, for the tests of a process that
     * keeps its statics.
     */
    public static function reset(): void
    {
        self::$frames        = [];
        self::$held          = null;
        self::$beforePublish = [];
    }

    /**
     * The draft a publish swaps with, read as the workspaces extension reads
     * it (`(int) $value['swapWith']`), so `'12abc'` is draft 12; 0 for none,
     * null for a value that is no integer to it at all.
     */
    public static function swapWithOf(mixed $value): ?int
    {
        $swapWith = is_array($value) ? ($value['swapWith'] ?? null) : null;
        if (is_array($swapWith)) {
            return $swapWith === [] ? 0 : 1;
        }

        return $swapWith === null || is_scalar($swapWith) ? (int) $swapWith : null;
    }

    /**
     * Note the rows a publish names, before core carries it out (#1442), for
     * {@see self::wasPublished()}.
     */
    public static function beforePublish(string $table, int $id, int $swapWith): void
    {
        if (self::isArmed()) {
            self::$beforePublish[$table . ':' . $id] = self::rowStates($table, [$id, $swapWith]);
        }
    }

    /**
     * Whether a publish took place: core deletes the draft it published into
     * the live row (`swapWith`), or a new record's draft becomes the live row.
     * A publish core refused (no access, the wrong stage, no draft to swap
     * with) leaves both as they were. A read that fails counts as published,
     * so the record is listed and judged.
     */
    public static function wasPublished(string $table, int $id, int $swapWith): bool
    {
        $before = self::$beforePublish[$table . ':' . $id] ?? null;
        unset(self::$beforePublish[$table . ':' . $id]);
        $after = self::rowStates($table, [$id, $swapWith]);
        if ($before === null || $after === null) {
            return true;
        }

        if (($after[$id]['workspace'] ?? null) !== 0) {
            return false;
        }

        $swapped  = $swapWith > 0 && $swapWith !== $id && isset($before[$swapWith]) && !isset($after[$swapWith]);
        $madeLive = ($before[$id]['workspace'] ?? 0) > 0;

        return $swapped || $madeLive;
    }

    /**
     * Whether a stage change took: the draft carries the stage asked for. A
     * read that fails counts as taken, so the record is listed and judged.
     */
    public static function stageTook(string $table, int $id, mixed $stage): bool
    {
        $after = self::rowStates($table, [$id]);
        if ($after === null) {
            return true;
        }

        return isset($after[$id]) && $after[$id]['workspace'] > 0 && is_numeric($stage) && $after[$id]['stage'] === (int) $stage;
    }

    /**
     * The workspace and stage of each row of a versioned table, deleted ones
     * included, by uid; no entry for a uid no row bears. An empty list for a
     * table without versioning, null when the read fails.
     *
     * @param list<int> $uids
     *
     * @return array<int, array{workspace: int, stage: int}>|null
     */
    public static function rowStates(string $table, array $uids): ?array
    {
        // @phpstan-ignore-next-line TCA access returns mixed at each nesting level
        if (!(bool) ($GLOBALS['TCA'][$table]['ctrl']['versioningWS'] ?? false)) {
            return [];
        }

        try {
            $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
            $query->getRestrictions()->removeAll();
            $rows = $query
                ->select('uid', 't3ver_wsid', 't3ver_stage')
                ->from($table)
                ->where($query->expr()->in('uid', $query->createNamedParameter(array_values(array_filter($uids, static fn (int $uid): bool => $uid > 0)) ?: [0], Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (Throwable) {
            return null;
        }

        $states = [];
        foreach ($rows as $row) {
            if (is_numeric($row['uid'] ?? null)) {
                $states[(int) $row['uid']] = [
                    'workspace' => is_numeric($row['t3ver_wsid'] ?? null) ? (int) $row['t3ver_wsid'] : 0,
                    'stage'     => is_numeric($row['t3ver_stage'] ?? null) ? (int) $row['t3ver_stage'] : 0,
                ];
            }
        }

        return $states;
    }

    /**
     * Of two actions on one record in one call, the one the list states: a
     * record that was created is created however often it was updated after, and
     * one that was deleted is deleted.
     */
    public static function stronger(ChangeAction $a, ChangeAction $b): ChangeAction
    {
        $rank = [
            ChangeAction::DELETED->value  => 5,
            ChangeAction::CREATED->value  => 4,
            ChangeAction::RESTORED->value => 3,
            ChangeAction::MOVED->value    => 2,
            ChangeAction::UPDATED->value  => 1,
        ];

        return $rank[$b->value] > $rank[$a->value] ? $b : $a;
    }
}
