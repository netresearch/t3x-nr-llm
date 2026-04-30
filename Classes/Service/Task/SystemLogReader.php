<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Reads recent rows from TYPO3's `sys_log` table for the Task
 * "syslog" input source.
 *
 * Returns raw rows; formatting (timestamp localisation, type/severity
 * label translation) is the caller's job — kept here is only the DB
 * read so the SQL doesn't live in a controller.
 */
final readonly class SystemLogReader implements SystemLogReaderInterface
{
    private const TABLE = 'sys_log';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function readRecent(int $limit, bool $errorOnly): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults($limit);

        if ($errorOnly) {
            $queryBuilder->where(
                $queryBuilder->expr()->gt('error', 0),
            );
        }

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }
}
