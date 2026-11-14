<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;
use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use Netresearch\NrLlm\Service\Tool\Change\ObservedChangeResolver;
use Netresearch\NrLlm\Service\Tool\Change\ToolChangeCollector;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The third check of the scope of an assignment (ADR-295): what a call really
 * wrote.
 *
 * The two checks before it read what a call says and what the DataHandler is
 * given, and each has to understand the shapes of that input. This one does
 * not: after the call it takes the records the DataHandler reported as written,
 * moved, copied, localized or deleted (the hooks' records and the DataHandler's
 * own history, without the limit of the list a run step keeps), reads each from
 * the database as it now is (final pid, language, translation parent) and
 * judges it by the same membership rules. A write a parser let through is
 * found here.
 *
 * In a draft workspace a record out of scope is a version, and the version is
 * discarded: no live effect remains. In the live workspace nothing can be
 * undone reliably, so the call is only reported as having exceeded its scope.
 * That is why a configuration that requires a scope also requires a draft
 * workspace.
 *
 * @internal
 */
final class AssignmentScopeEffect
{
    /**
     * The records of the innermost armed call that lie outside the scope.
     *
     * @return list<array{refusal: ScopeRefusal, workspace: int}>
     */
    public static function violations(AssignmentScope $scope, AssignmentScopeChecker $checker, int $workspace): array
    {
        $frame = ToolChangeCollector::peek();
        if ($frame === null) {
            return [];
        }

        $truncated = false;
        try {
            $entries = (new ObservedChangeResolver(GeneralUtility::makeInstance(ConnectionPool::class)))->entries($frame['records'], $frame['scopes'], $frame['watermark'], $truncated);
        } catch (Throwable) {
            return [['refusal' => new ScopeRefusal('effect', 'pages', 0, 0, ScopeRefusal::UNRESOLVABLE), 'workspace' => $workspace]];
        }

        $found = [];
        foreach ($entries as $entry) {
            $refusal = $checker->recordRefusal($scope, 'effect_' . $entry['action']->value, $entry['table'], $entry['uid'], $workspace);
            if (!$refusal instanceof ScopeRefusal) {
                continue;
            }

            // A hard-deleted row is gone and cannot be read; nothing is left of it.
            if ($refusal->reason === ScopeRefusal::UNRESOLVABLE && $entry['action'] === ChangeAction::DELETED) {
                continue;
            }

            $found[] = ['refusal' => $refusal, 'workspace' => $entry['workspace']];
        }

        return $found;
    }

    /**
     * The highest uid of every versionable table, taken before the call. A
     * workspace version with a higher uid was made by the call; one with a lower
     * uid existed before it and holds changes of an editor or of an earlier call.
     *
     * @return array<string, int>
     */
    public static function snapshot(): array
    {
        $tca      = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $snapshot = [];
        foreach ($tca as $table => $schema) {
            $ctrl = is_array($schema) && is_array($schema['ctrl'] ?? null) ? $schema['ctrl'] : [];
            if (!is_string($table) || ($ctrl['versioningWS'] ?? false) !== true || preg_match('/\A\w+\z/', $table) !== 1) {
                continue;
            }

            try {
                $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
                $query->getRestrictions()->removeAll();
                $top = $query->selectLiteral('MAX(uid)')->from($table)->executeQuery()->fetchOne();
            } catch (Throwable) {
                continue;
            }

            $snapshot[$table] = is_numeric($top) ? (int) $top : 0;
        }

        return $snapshot;
    }

    /**
     * Takes back what the call wrote outside the scope in a draft workspace. A
     * version the call made is discarded. A version that existed before the call
     * is never discarded, because it holds work that is not the call's: its fields
     * are put back to what they were before the call, from the history of the
     * call's DataHandler chain, and where that cannot be done the record is left
     * and reported. Run after the call, with its change collector and guard
     * released, so the work is neither recorded as the call's nor held to the
     * scope.
     *
     * @param list<array{refusal: ScopeRefusal, workspace: int}>                           $violations
     * @param array<string, int>                                                           $snapshot   {@see self::snapshot()}
     * @param array{records: array<mixed>, scopes: list<string>, watermark: int|null}|null $frame      the call's collector frame, read before it was released
     *
     * @return array{discarded: int, restored: int, kept: int}
     */
    public static function undo(array $violations, ?BackendUserAuthentication $user, array $snapshot = [], ?array $frame = null): array
    {
        $found = [];
        foreach ($violations as $violation) {
            $table = $violation['refusal']->table;
            $uid   = $violation['refusal']->uid;
            $key   = $table . ':' . $uid;
            if (isset($found[$key])) {
                continue;
            }

            $found[$key] = [
                'table'     => $table,
                'uid'       => $uid,
                'workspace' => $violation['workspace'],
                'rows'      => $violation['workspace'] > 0 && $uid > 0 && $user instanceof BackendUserAuthentication ? self::versionsOf($table, $uid, $violation['workspace']) : [],
            ];
        }

        // Before anything is changed, which rows are the call's own.
        $discard = [];
        $restore = [];
        foreach ($found as $key => $entry) {
            foreach ($entry['rows'] as $row) {
                if ($row > ($snapshot[$entry['table']] ?? PHP_INT_MAX)) {
                    $discard[$entry['table']][$row] = ['discard' => 1];
                } else {
                    $restore[$key][] = $row;
                }
            }
        }

        foreach ($discard as $table => $commands) {
            try {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start([], [$table => $commands], $user);
                $dataHandler->process_cmdmap();
            } catch (Throwable) {
                // What is still there is counted below.
            }
        }

        $discarded = 0;
        $restored  = 0;
        $kept      = 0;
        foreach ($found as $key => $entry) {
            if ($entry['rows'] === []) {
                ++$kept;

                continue;
            }

            $ok = true;
            foreach ($entry['rows'] as $row) {
                $ok = ($row > ($snapshot[$entry['table']] ?? PHP_INT_MAX) ? !in_array($row, self::versionsOf($entry['table'], $entry['uid'], $entry['workspace']), true) : self::restore($entry['table'], $row, $entry['uid'], $frame)) && $ok;
            }

            if (!$ok) {
                ++$kept;
            } elseif (isset($restore[$key])) {
                ++$restored;
            } else {
                ++$discarded;
            }
        }

        return ['discarded' => $discarded, 'restored' => $restored, 'kept' => $kept];
    }

    /**
     * Puts the fields of a version that existed before the call back to the
     * values the call's history records as the old ones (the oldest of them when
     * the call changed a field more than once). False where the history has
     * nothing for it.
     *
     * @param array{records: array<mixed>, scopes: list<string>, watermark: int|null}|null $frame
     */
    private static function restore(string $table, int $version, int $entryUid, ?array $frame): bool
    {
        if ($frame === null || $frame['watermark'] === null || $frame['scopes'] === []) {
            return false;
        }

        $fields = [];
        try {
            foreach ($frame['scopes'] as $scope) {
                $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_history');
                $rows  = $query
                    ->select('history_data')
                    ->from('sys_history')
                    ->where(
                        $query->expr()->gt('uid', $query->createNamedParameter($frame['watermark'], Connection::PARAM_INT)),
                        $query->expr()->eq('tablename', $query->createNamedParameter($table)),
                        $query->expr()->eq('actiontype', $query->createNamedParameter(2, Connection::PARAM_INT)),
                        $query->expr()->in('recuid', $query->createNamedParameter([$version, $entryUid], Connection::PARAM_INT_ARRAY)),
                        $query->expr()->like('correlation_id', $query->createNamedParameter('%$' . $query->escapeLikeWildcards($scope) . ':%')),
                    )
                    ->orderBy('uid')
                    ->executeQuery()
                    ->fetchFirstColumn();
                foreach ($rows as $json) {
                    $data = is_string($json) ? json_decode($json, true) : null;
                    $old  = is_array($data) && is_array($data['oldRecord'] ?? null) ? $data['oldRecord'] : [];
                    foreach ($old as $name => $value) {
                        if (is_string($name) && !array_key_exists($name, $fields) && !in_array($name, ['uid', 'pid', 'deleted'], true) && !str_starts_with($name, 't3ver_') && (is_scalar($value) || $value === null)) {
                            $fields[$name] = $value;
                        }
                    }
                }
            }

            if ($fields === []) {
                return false;
            }

            GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->update($table, $fields, ['uid' => $version]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * The uids of the rows a draft workspace holds for the record: its versions,
     * and the row itself when it was created there.
     *
     * @return list<int>
     */
    private static function versionsOf(string $table, int $uid, int $workspace): array
    {
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        if (!isset($tca[$table])) {
            return [];
        }

        try {
            $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
            $query->getRestrictions()->removeAll();
            $rows = $query
                ->select('uid')
                ->from($table)
                ->where(
                    $query->expr()->eq('t3ver_wsid', $query->createNamedParameter($workspace, Connection::PARAM_INT)),
                    $query->expr()->or(
                        $query->expr()->eq('t3ver_oid', $query->createNamedParameter($uid, Connection::PARAM_INT)),
                        $query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)),
                    ),
                )
                ->executeQuery()
                ->fetchFirstColumn();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_map(static fn (mixed $row): int => is_numeric($row) ? (int) $row : 0, array_filter($rows, is_numeric(...))));
    }
}
