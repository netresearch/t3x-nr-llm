<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * The workspace reads of the structural writers that open their own query
 * (ADR-266): a record's version in the workspace and its translations as the
 * workspace shows them.
 *
 * Needs {@see ReadsTheRunsWorkspaceTrait},
 * {@see \Netresearch\NrLlm\Utility\SafeCastTrait} and a
 * `private ConnectionPool $connectionPool` in the using class.
 */
trait ReadsVersionsInTheWorkspaceTrait
{
    /**
     * The uid of a live record's undeleted version in `$workspace`, or 0.
     */
    private function undeletedVersionUidIn(string $table, int $uid, int $workspace): int
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
        $parents = array_values(array_filter([$uid, $this->undeletedVersionUidIn($table, $uid, $workspace)], static fn (int $parent): bool => $parent > 0));

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
}
