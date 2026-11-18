<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Database;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;
use TYPO3\CMS\Core\Database\Middleware\UsableForConnectionInterface;

/**
 * A test-only driver middleware for PostgreSQL: after an INSERT that names
 * `uid`, the table's uid sequence moves up to the largest uid.
 *
 * Fixtures throughout the suite insert rows with a fixed uid and then let
 * DataHandler or a repository insert more. MySQL's AUTO_INCREMENT and
 * SQLite's rowid follow an explicit value; a PostgreSQL sequence does not,
 * so the next generated uid collided with a fixture row ("duplicate key value
 * violates unique constraint"). The testing framework resets the sequences
 * after a CSV import (Testbase::resetTableSequences()); this does the same
 * for a direct insert, where the framework cannot.
 *
 * Registered for every functional test by
 * {@see \Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase}.
 */
final class ExplicitUidSequenceMiddleware implements UsableForConnectionInterface
{
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): ConnectionInterface
            {
                return new ExplicitUidSequenceConnection(parent::connect($params));
            }
        };
    }

    /**
     * @param array<string, mixed> $connectionParams
     */
    public function canBeUsedForConnection(string $identifier, array $connectionParams): bool
    {
        return ($connectionParams['driver'] ?? '') === 'pdo_pgsql';
    }

    /**
     * The table an `INSERT INTO <table> (..., uid, ...)` writes, or null for any other statement.
     */
    public static function tableWithExplicitUid(string $sql): ?string
    {
        if (preg_match('/^\s*INSERT\s+INTO\s+"?(\w+)"?\s*\(([^)]*)\)/i', $sql, $match) !== 1) {
            return null;
        }

        foreach (explode(',', $match[2]) as $column) {
            if (strtolower(trim($column, " \t\n\"")) === 'uid') {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * Move the uid sequence of `$table` to its largest uid; a table without
     * a sequence on uid is left alone (`setval(NULL, ...)` is NULL).
     */
    public static function followUid(string $table): string
    {
        return sprintf(
            "SELECT setval(pg_get_serial_sequence('\"%1\$s\"', 'uid'), GREATEST((SELECT MAX(uid) FROM \"%1\$s\"), 1))",
            $table,
        );
    }
}
