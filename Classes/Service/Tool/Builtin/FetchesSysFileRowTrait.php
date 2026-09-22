<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Core\Database\Connection;

/**
 * The one row lookup two writers share verbatim: a `sys_file` row by uid.
 *
 * {@see WritesThroughDataHandlerTrait} keeps row lookups out of the shared
 * mechanics because which restrictions apply is a decision about what counts
 * as existing. For `sys_file` that decision is the same wherever it is made:
 * the table carries no `deleted` column, so a soft-delete predicate against it
 * is an SQL error rather than a narrower query, and every restriction is
 * removed. {@see AttachFileToContentElementTool} and
 * {@see SetPageSocialImageTool} both reference an existing file, and the
 * columns they need to name it and to check its storage and extension are the
 * same five.
 *
 * The consuming class must hold a `ConnectionPool` in `$this->connectionPool`.
 */
trait FetchesSysFileRowTrait
{
    /**
     * @return array<string, mixed>|null
     */
    private function fetchFile(int $uid): ?array
    {
        if ($uid < 1) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid', 'storage', 'identifier', 'name', 'extension')
            ->from('sys_file')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
