<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Exception\AssignmentScopeNotEnforceableException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The database transaction around the write of one scoped tool call (ADR-295).
 *
 * The effect check reads what a call wrote before the transaction is
 * committed, and rolls it back when anything lies outside the scope. The
 * database is then exactly as before the call: rows, `sys_history`,
 * `sys_refindex`, `sys_log` and workspace versions alike, with nothing to undo
 * and nothing to restore.
 *
 * It holds because the DataHandler (TYPO3 13.4 and 14.3) neither opens nor
 * commits a transaction of its own, and writes every table through
 * `ConnectionPool::getConnectionForTable()`, which is the default connection
 * unless `DB/TableMapping` says otherwise. An installation that maps tables to
 * another connection cannot be covered by one transaction, and
 * {@see self::begin()} refuses it.
 *
 * Inside a transaction that is already open the call gets a savepoint, so a
 * rollback undoes the call and leaves the outer transaction as it was.
 *
 * Outside the transaction, and so not undone: cache flushes (harmless, a cache
 * is rebuilt), and everything an installation's hook or PSR-14 listener does
 * beyond the database, a web request or a file for instance. A tool that writes
 * files or calls out is not covered, and none of the built-in write tools does.
 *
 * @internal
 */
final class AssignmentScopeTransaction
{
    private static int $savepoints = 0;

    private function __construct(
        private readonly Connection $connection,
        private readonly ?string $savepoint,
    ) {}

    /**
     * @throws AssignmentScopeNotEnforceableException when one transaction cannot cover the tables a DataHandler run writes
     */
    public static function begin(): self
    {
        /** @var array<string, mixed> $configuration */
        $configuration = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $database      = is_array($configuration['DB'] ?? null) ? $configuration['DB'] : [];
        $mapping       = $database['TableMapping'] ?? [];
        foreach (is_array($mapping) ? $mapping : [] as $connectionName) {
            if ($connectionName !== ConnectionPool::DEFAULT_CONNECTION_NAME) {
                throw new AssignmentScopeNotEnforceableException('Tables are mapped to a connection other than the default one, so no single transaction covers a write.', 1799990240);
            }
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        if (!$connection->isTransactionActive()) {
            $connection->beginTransaction();

            return new self($connection, null);
        }

        if (!$connection->getDatabasePlatform()->supportsSavepoints()) {
            throw new AssignmentScopeNotEnforceableException('A transaction is open and the database cannot nest one.', 1799990241);
        }

        $name = 'nrllm_scope_' . ++self::$savepoints;
        $connection->createSavepoint($name);

        return new self($connection, $name);
    }

    public function commit(): void
    {
        if ($this->savepoint === null) {
            $this->connection->commit();

            return;
        }

        $this->connection->releaseSavepoint($this->savepoint);
    }

    public function rollBack(): void
    {
        if ($this->savepoint === null) {
            $this->connection->rollBack();

            return;
        }

        $this->connection->rollbackSavepoint($this->savepoint);
    }
}
