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
     * Discards the workspace versions of the records that lie outside the scope.
     * Run after the call, with its change collector and guard released, so the
     * discard is neither recorded as the call's nor held to the scope.
     *
     * @param list<array{refusal: ScopeRefusal, workspace: int}> $violations
     *
     * @return array{discarded: int, kept: int} how many records were taken back, and how many could not be
     */
    public static function undo(array $violations, ?BackendUserAuthentication $user): array
    {
        // First what each record has in the workspace, before anything is
        // discarded: the entries of one call name the same version more than once.
        $versions = [];
        foreach ($violations as $violation) {
            $table = $violation['refusal']->table;
            $uid   = $violation['refusal']->uid;
            $key   = $table . ':' . $uid;
            if (!isset($versions[$key])) {
                $versions[$key] = [
                    'table'     => $table,
                    'uid'       => $uid,
                    'rows'      => $violation['workspace'] > 0 && $uid > 0 && $user instanceof BackendUserAuthentication ? self::versionsOf($table, $uid, $violation['workspace']) : [],
                    'workspace' => $violation['workspace'],
                ];
            }
        }

        $discard = [];
        foreach ($versions as $entry) {
            foreach ($entry['rows'] as $row) {
                $discard[$entry['table']][$row] = ['discard' => 1];
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
        $kept      = 0;
        foreach ($versions as $entry) {
            $left = $entry['rows'] === [] ? [] : self::versionsOf($entry['table'], $entry['uid'], $entry['workspace']);
            if ($entry['rows'] !== [] && $left === []) {
                ++$discarded;
            } else {
                ++$kept;
            }
        }

        return ['discarded' => $discarded, 'kept' => $kept];
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
