<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Reading records as the run's draft workspace shows them, in SQL (ADR-263).
 *
 * Since TYPO3 11 a workspace version is a complete row: a change, a move (the
 * version carries the new `pid`) and a deletion (`t3ver_state` 2) are each one
 * row with `t3ver_oid` naming the live record, and a record new in the
 * workspace is one row with `t3ver_oid` 0. So "the rows a user in workspace W
 * sees" is a row predicate, and every filter, LIKE, enable-field restriction,
 * count, limit and offset applied to it is applied to what the user sees:
 *
 * - a live row that has no version in W, and
 * - a row of W itself — version or new record — that is not a deletion.
 *
 * Each record appears once, under its live uid ({@see self::liveUid()}). Rows
 * of another workspace never match. Core's own `WorkspaceRestriction` with a
 * workspace drops plain version rows and leaves the overlay to the caller,
 * which would move filters and limits behind PHP; this keeps them in SQL.
 *
 * The workspace read is the acting user's, and only where the run recorded it
 * ({@see self::workspaceToRead()}); a run without a recorded workspace reads
 * live, as before. In live every caller keeps its own query unchanged.
 *
 * Needs {@see \Netresearch\NrLlm\Utility\SafeCastTrait} and a
 * `private ConnectionPool $connectionPool` in the using class.
 */
trait ReadsTheRunsWorkspaceTrait
{
    /** `t3ver_state` of a record deleted in a workspace. */
    private const READ_DELETE_PLACEHOLDER = 2;

    /**
     * The draft workspace this run reads, or 0 for live (ADR-260): the acting
     * user's workspace where the run's actor recorded the same one; live for a
     * run that recorded none, and for a run without a user.
     */
    private function workspaceToRead(ToolExecutionContext $context, ?BackendUserAuthentication $user): int
    {
        return $this->workspaceOfTheRun($context->actor, $user);
    }

    /**
     * {@see self::workspaceToRead()} for a caller that holds the actor rather
     * than a tool context: the field fill and the editor-action batches
     * (ADR-265).
     */
    private function workspaceOfTheRun(AiActorContext $actor, ?BackendUserAuthentication $user): int
    {
        if (!$user instanceof BackendUserAuthentication || $user->workspace <= 0) {
            return 0;
        }

        return $actor->workspaceId === $user->workspace ? $user->workspace : 0;
    }

    /**
     * Whether the table keeps workspace versions (`ctrl.versioningWS`, read
     * from the TCA at runtime). Only such a table has the `t3ver_*` columns.
     */
    private function isWorkspaceAware(string $table): bool
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[$table] ?? null) ? ($tca[$table]['ctrl'] ?? null) : null;

        return is_array($ctrl) && (bool) ($ctrl['versioningWS'] ?? false);
    }

    /**
     * Whether queries on the table take the workspace path: a draft workspace
     * and a workspace-aware table. Everything else keeps the live query.
     */
    private function readsWorkspaceRows(string $table, int $workspace): bool
    {
        return $workspace > 0 && $this->isWorkspaceAware($table);
    }

    /**
     * The predicate "this row is the record as workspace `$workspace` shows
     * it" (see the trait docblock). Only for {@see self::readsWorkspaceRows()};
     * the main query must NOT also carry a `WorkspaceRestriction`, which drops
     * the version rows this predicate selects.
     *
     * The "has a version" subquery carries no enable-field restriction: a
     * version hidden in the workspace still hides the live row. Like core's
     * `BackendUtility::getWorkspaceVersionOfRecord()` it does carry the
     * deleted restriction.
     *
     * @param string $alias the table's alias in the query, '' for none
     */
    private function shownInWorkspace(QueryBuilder $queryBuilder, string $table, int $workspace, string $alias = ''): string
    {
        $prefix    = $alias === '' ? $table . '.' : $alias . '.';
        $workspace = $queryBuilder->createNamedParameter($workspace, Connection::PARAM_INT);
        $zero      = $queryBuilder->createNamedParameter(0, Connection::PARAM_INT);

        // The version row is aliased `ws_version`; the outer row is named by
        // its quoted identifier. Every value is a named parameter of the
        // outer builder, which is the one that binds them.
        $version     = $queryBuilder->getConnection()->createQueryBuilder();
        $isVersionOf = $version->expr()->eq('ws_version.t3ver_oid', $queryBuilder->quoteIdentifier($prefix . 'uid'));
        $inWorkspace = $version->expr()->eq('ws_version.t3ver_wsid', $workspace);
        $version->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $version
            ->select('ws_version.uid')
            ->from($table, 'ws_version')
            ->where($isVersionOf, $inWorkspace);

        return (string) $queryBuilder->expr()->or(
            $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq($prefix . 't3ver_wsid', $zero),
                $queryBuilder->expr()->eq($prefix . 't3ver_oid', $zero),
                'NOT EXISTS (' . $version->getSQL() . ')',
            ),
            $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq($prefix . 't3ver_wsid', $workspace),
                $queryBuilder->expr()->neq($prefix . 't3ver_state', $queryBuilder->createNamedParameter(self::READ_DELETE_PLACEHOLDER, Connection::PARAM_INT)),
            ),
        );
    }

    /**
     * The predicate "this row is record `$uid`", where `$uid` is a live uid
     * (or the uid of a record new in the workspace): the row itself, or its
     * version. A version row's own uid matches nothing. Combine with
     * {@see self::shownInWorkspace()}.
     */
    private function isRecordInWorkspace(QueryBuilder $queryBuilder, int $uid, string $prefix = ''): string
    {
        $uid = $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT);

        return (string) $queryBuilder->expr()->or(
            $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq($prefix . 'uid', $uid),
                $queryBuilder->expr()->eq($prefix . 't3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            ),
            $queryBuilder->expr()->eq($prefix . 't3ver_oid', $uid),
        );
    }

    /**
     * Order a workspace query by live uid, so that a version row sorts where
     * its record does and offset pagination stays stable.
     */
    private function orderByLiveUid(QueryBuilder $queryBuilder, string $prefix = ''): void
    {
        $original = $queryBuilder->quoteIdentifier($prefix . 't3ver_oid');
        $queryBuilder->getConcreteQueryBuilder()->orderBy(
            sprintf('CASE WHEN %s > 0 THEN %s ELSE %s END', $original, $original, $queryBuilder->quoteIdentifier($prefix . 'uid')),
            'ASC',
        );
    }

    /**
     * The constraints that keep a query to the rows a writer in `$workspace`
     * works on (ADR-266): the predicate of {@see self::shownInWorkspace()} in
     * a draft workspace on a workspace-aware table, `$live` — the caller's own
     * live-row constraints — everywhere else.
     *
     * @param list<string> $live
     *
     * @return list<string>
     */
    private function rowsShownIn(QueryBuilder $queryBuilder, string $table, int $workspace, array $live): array
    {
        return $this->readsWorkspaceRows($table, $workspace) ? [$this->shownInWorkspace($queryBuilder, $table, $workspace)] : $live;
    }

    /**
     * The uid of a live record's undeleted version in `$workspace`, or 0.
     */
    private function versionUidIn(string $table, int $uid, int $workspace): int
    {
        if (!$this->readsWorkspaceRows($table, $workspace) || $uid < 1) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

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
     * The translations of a record as `$workspace` shows them (ADR-266), each
     * under its live uid: the rows whose translation pointer names the record
     * or — as core points the version of a translation it moves or deletes
     * along — the record's version there; a translation deleted there is left
     * out, one new there is included.
     *
     * @param non-empty-string $pointer       the table's `transOrigPointerField`
     * @param non-empty-string $languageField the table's `languageField`
     *
     * @return list<array<string, mixed>>
     */
    private function translationsInWorkspace(string $table, int $uid, int $workspace, string $pointer, string $languageField): array
    {
        $parents = array_values(array_filter([$uid, $this->versionUidIn($table, $uid, $workspace)], static fn (int $parent): bool => $parent > 0));

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in($pointer, $queryBuilder->createNamedParameter($parents, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $this->shownInWorkspace($queryBuilder, $table, $workspace),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $translations = [];
        foreach ($rows as $row) {
            $row['uid']                             = $this->liveUid($row);
            $translations[self::toInt($row['uid'])] = $row;
        }

        ksort($translations);

        return array_values($translations);
    }

    /**
     * The uid a row is reported and addressed under: its live record's for a
     * version row, its own otherwise.
     *
     * @param array<string, mixed> $row
     */
    private function liveUid(array $row): int
    {
        $original = self::toInt($row['t3ver_oid'] ?? 0);

        return $original > 0 ? $original : self::toInt($row['uid'] ?? 0);
    }
}
