<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Database;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Triggers a functional test puts into the database to play a failure or a
 * concurrent write that no PHP double can fake, in the syntax of SQLite,
 * MySQL/MariaDB and PostgreSQL alike.
 *
 * `$condition` and `$statement` are plain SQL that may name `NEW` and `OLD`;
 * all three databases read them the same way inside a row trigger.
 */
final class TestTrigger
{
    /**
     * Make each `$event` (INSERT, UPDATE or DELETE) on `$table` for which
     * `$condition` holds fail with `$message`.
     */
    public static function refuse(Connection $connection, string $name, string $timing, string $event, string $table, string $condition, string $message = 'forced'): void
    {
        $platform = $connection->getDatabasePlatform();
        $quoted   = $connection->quote($message);
        if ($platform instanceof SQLitePlatform) {
            $connection->executeStatement(sprintf('CREATE TRIGGER %s %s %s ON %s FOR EACH ROW WHEN %s BEGIN SELECT RAISE(ABORT, %s); END', $name, $timing, $event, $table, $condition, $quoted));

            return;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            self::postgres($connection, $name, $timing, $event, $table, sprintf('IF %s THEN RAISE EXCEPTION %s; END IF;', $condition, $quoted));

            return;
        }

        $connection->executeStatement(sprintf("CREATE TRIGGER %s %s %s ON %s FOR EACH ROW BEGIN IF %s THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = %s; END IF; END", $name, $timing, $event, $table, $condition, $quoted));
    }

    /**
     * Run `$statement` on each `$event` on `$table`, inside the statement
     * that triggered it.
     */
    public static function run(Connection $connection, string $name, string $timing, string $event, string $table, string $statement): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            $connection->executeStatement(sprintf('CREATE TRIGGER %s %s %s ON %s FOR EACH ROW BEGIN %s; END', $name, $timing, $event, $table, $statement));

            return;
        }

        if ($platform instanceof PostgreSQLPlatform) {
            self::postgres($connection, $name, $timing, $event, $table, $statement . ';');

            return;
        }

        $connection->executeStatement(sprintf('CREATE TRIGGER %s %s %s ON %s FOR EACH ROW %s', $name, $timing, $event, $table, $statement));
    }

    /**
     * Remove a trigger {@see self::refuse()} or {@see self::run()} created; one that is not there is no error.
     */
    public static function drop(Connection $connection, string $name, string $table): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement(sprintf('DROP TRIGGER IF EXISTS %s ON %s', $name, $table));
            $connection->executeStatement(sprintf('DROP FUNCTION IF EXISTS %s()', self::function($name)));

            return;
        }

        $connection->executeStatement('DROP TRIGGER IF EXISTS ' . $name);
    }

    /**
     * PostgreSQL runs a trigger's body as a function of its own.
     */
    private static function postgres(Connection $connection, string $name, string $timing, string $event, string $table, string $body): void
    {
        $connection->executeStatement(sprintf(
            "CREATE OR REPLACE FUNCTION %s() RETURNS trigger LANGUAGE plpgsql AS \$body\$ BEGIN %s IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END \$body\$",
            self::function($name),
            $body,
        ));
        $connection->executeStatement(sprintf('CREATE TRIGGER %s %s %s ON %s FOR EACH ROW EXECUTE FUNCTION %s()', $name, $timing, $event, $table, self::function($name)));
    }

    private static function function(string $name): string
    {
        return $name . '_fn';
    }
}
