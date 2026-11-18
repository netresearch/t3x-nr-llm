<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Database;

use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * A connection pool whose query builders work, except the one or more it hands
 * out for one table from a given request on: that request throws, as a read
 * that fails does.
 *
 * The failure is raised in PHP before any statement is sent, so no database
 * aborts its transaction over it: the read fails the same way on SQLite,
 * MariaDB and PostgreSQL, and what follows it runs on a working connection.
 */
final class FailsOneReadConnectionPool extends ConnectionPool
{
    /** How many reads of the table it failed. */
    public int $failed = 0;

    private int $asked = 0;

    private string $table = '';

    private int $from = 1;

    private int $to = 0;

    /**
     * No constructor of its own: core's differs between 13.4 (none) and 14.3.
     *
     * @param int $from the first request for the table that fails, counted from 1
     * @param int $to   the last one, or 0 for every request from $from on
     */
    public static function failing(string $table, int $from = 1, int $to = 0): self
    {
        $pool        = new self();
        $pool->table = $table;
        $pool->from  = $from;
        $pool->to    = $to;

        return $pool;
    }

    public function getQueryBuilderForTable(string $tableName): QueryBuilder
    {
        if ($tableName === $this->table) {
            ++$this->asked;
            if ($this->asked >= $this->from && ($this->to === 0 || $this->asked <= $this->to)) {
                ++$this->failed;

                throw new RuntimeException('The read of ' . $tableName . ' failed.', 1799990401);
            }
        }

        return parent::getQueryBuilderForTable($tableName);
    }
}
