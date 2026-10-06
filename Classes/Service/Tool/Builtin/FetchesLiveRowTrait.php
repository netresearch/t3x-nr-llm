<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Core\Database\Connection;

/**
 * The live, undeleted row lookup the two file-attaching writers share verbatim.
 *
 * For the soft-deleting tables only: a table without a `deleted` column needs
 * its own lookup, see {@see FetchesSysFileRowTrait}. A hidden row is returned,
 * a workspace version row is not (ADR-198).
 *
 * The consuming class must hold a `ConnectionPool` in `$this->connectionPool`
 * and use {@see WritesThroughDataHandlerTrait} for `liveVersionConstraints()`.
 */
trait FetchesLiveRowTrait
{
    /**
     * A live, undeleted row by uid — a hidden one included.
     *
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $table, int $uid): ?array
    {
        if ($uid < 1) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // Live rows only: a workspace version row is another
                // workspace's draft (ADR-198).
                ...$this->liveVersionConstraints($queryBuilder, $table),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }
}
