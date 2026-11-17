<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Closure;
use Netresearch\NrLlm\Exception\AssignmentScopeNotEnforceableException;
use Throwable;
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
 * A savepoint is taken at the start even for the outermost transaction. On
 * MySQL and MariaDB a statement that commits implicitly (`TRUNCATE`, so a flush of
 * a database cache group) ends the transaction behind DBAL's back, and a plain
 * `ROLLBACK` then succeeds without undoing anything. `ROLLBACK TO SAVEPOINT`
 * fails instead, because the implicit commit also dropped the savepoint, so a
 * rollback that did not happen is seen as one.
 *
 * Cache flushes the DataHandler itself asks for are therefore deferred
 * ({@see self::defer()}): run after the commit, dropped on a rollback. A hook
 * or listener of the installation can still end the transaction in the middle
 * of a call: one that flushes a cache on the database backend truncates its
 * tables (NEXT-240). What the call wrote up to that statement is committed
 * with it, and every later statement commits on its own, because the server
 * is back in autocommit mode. Such a call cannot be rolled back any more.
 *
 * Which of the two happened is not guessed from the failed savepoint: a call
 * also writes a marker row into `sys_registry` inside its transaction, right
 * after the savepoint. When the savepoint is gone, the marker is deleted on the
 * same connection after the call's own transaction is ended. A delete that
 * finds it means the marker was committed, and so was the call:
 * {@see self::commit()} returns and {@see self::wasEndedByTheDatabase()}
 * says what happened. A marker that is not there means that the transaction
 * was rolled back, or that the connection was lost. Some writes of the call
 * may then be stored and some not, and the commit throws. On the normal way
 * out, the marker is deleted inside the transaction and never becomes
 * visible. A call inside a transaction of its caller is not covered: there
 * the delete runs inside that transaction, which may still be open, so a
 * savepoint a hook released through an unbalanced commit is taken for a
 * commit although the caller's transaction can still be rolled back.
 *
 * Not undone, because they are not in this database connection: everything an
 * installation's hook or PSR-14 listener does beyond the database, a web
 * request or a file for instance. A tool that writes files or calls out is not
 * covered, and none of the built-in write tools does.
 *
 * @internal
 */
final class AssignmentScopeTransaction
{
    /** The `sys_registry` namespace of the markers. */
    private const MARKER_NAMESPACE = 'tx_nrllm_scope';

    private static int $savepoints = 0;

    /**
     * The flushes asked for during each open call, innermost last.
     *
     * @var list<list<Closure(): void>>
     */
    private static array $frames = [];

    /** @var list<Closure(): void> */
    private array $deferred = [];

    private bool $ended = false;

    private bool $endedByTheDatabase = false;

    private function __construct(
        private readonly Connection $connection,
        private readonly string $savepoint,
        private readonly bool $owned,
        private readonly string $marker,
    ) {}

    /**
     * Runs a cache flush after the call's commit when a scoped call is open, and
     * says so; false means no call is open and the caller flushes itself.
     *
     * @param Closure(): void $flush
     */
    public static function defer(Closure $flush): bool
    {
        if (self::$frames === []) {
            return false;
        }

        self::$frames[array_key_last(self::$frames)][] = $flush;

        return true;
    }

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
        if (!$connection->getDatabasePlatform()->supportsSavepoints()) {
            throw new AssignmentScopeNotEnforceableException('The database cannot take a savepoint, so a call cannot be taken back reliably.', 1799990241);
        }

        $owned = !$connection->isTransactionActive();
        if ($owned) {
            $connection->beginTransaction();
        }

        $name   = 'nrllm_scope_' . ++self::$savepoints;
        $marker = $name . '_' . bin2hex(random_bytes(12));

        try {
            $connection->createSavepoint($name);
        } catch (Throwable $failure) {
            if ($owned) {
                $connection->rollBack();
            }

            throw $failure;
        }

        try {
            $connection->insert('sys_registry', ['entry_namespace' => self::MARKER_NAMESPACE, 'entry_key' => $marker, 'entry_value' => null]);
        } catch (Throwable $failure) {
            if ($owned) {
                $connection->rollBack();
            } else {
                $connection->rollbackSavepoint($name);
                $connection->releaseSavepoint($name);
            }

            throw $failure;
        }

        self::$frames[] = [];

        return new self($connection, $name, $owned, $marker);
    }

    /**
     * What the call asked to have flushed, now that it is committed and the
     * connection is free for statements that commit on their own. Empty for a
     * rolled-back call, and for a call inside another one, whose flushes wait for
     * the outermost.
     *
     * @return list<Closure(): void>
     */
    public function deferred(): array
    {
        return $this->deferred;
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    /**
     * Whether the database ended the transaction before {@see self::commit()}
     * did, and the call's writes are stored all the same: the commit found its
     * savepoint gone and its marker committed.
     */
    public function wasEndedByTheDatabase(): bool
    {
        return $this->endedByTheDatabase;
    }

    /**
     * Returns when what the call wrote is stored. This is also the case when
     * the database ended the transaction itself ({@see self::wasEndedByTheDatabase()}).
     *
     * @throws AssignmentScopeNotEnforceableException when the transaction was ended behind our back and the marker is gone with it, so whether the call's writes are stored is unknown
     */
    public function commit(): void
    {
        $closures = $this->leave();
        if (self::$frames !== []) {
            $last                = array_key_last(self::$frames);
            self::$frames[$last] = [...self::$frames[$last], ...$closures];
            $closures            = [];
        }

        $this->deferred = $closures;

        try {
            $this->connection->releaseSavepoint($this->savepoint);
        } catch (Throwable $lost) {
            // The server ended the transaction. Bring DBAL's own count back in
            // line, then read the marker: it says whether that end committed.
            $this->resetOwned();
            if ($this->takeCommittedMarker()) {
                $this->endedByTheDatabase = true;

                return;
            }

            throw new AssignmentScopeNotEnforceableException('The transaction of a scoped call was ended by the database before the call did, and not by a commit: whether its changes are stored is unknown.', 1799990242, $lost);
        }

        try {
            $this->connection->delete('sys_registry', ['entry_namespace' => self::MARKER_NAMESPACE, 'entry_key' => $this->marker]);
        } catch (Throwable $failure) {
            // The transaction is not committed. Ended here, DBAL does not stay
            // inside it for the next write of a long-lived worker.
            $this->resetOwned();

            throw $failure;
        }

        if ($this->owned) {
            $this->connection->commit();
        }
    }

    /**
     * @throws AssignmentScopeNotEnforceableException when the changes of the call could not be taken back
     */
    public function rollBack(): void
    {
        $this->leave();
        $this->deferred = [];

        try {
            $this->connection->rollbackSavepoint($this->savepoint);
        } catch (Throwable $lost) {
            $this->resetOwned();
            $this->takeCommittedMarker();

            throw new AssignmentScopeNotEnforceableException('The changes of a scoped call could not be rolled back: the transaction was ended by the database.', 1799990243, $lost);
        }

        if ($this->owned) {
            $this->connection->rollBack();

            return;
        }

        $this->connection->releaseSavepoint($this->savepoint);
    }

    /**
     * @return list<Closure(): void>
     */
    private function leave(): array
    {
        $this->ended = true;

        return array_pop(self::$frames) ?? [];
    }

    /**
     * Whether the marker of this call is stored outside any transaction of
     * ours, which means that it was committed. It is deleted, so a committed
     * marker never stays behind. A read that fails counts as not committed.
     */
    private function takeCommittedMarker(): bool
    {
        try {
            return $this->connection->delete('sys_registry', ['entry_namespace' => self::MARKER_NAMESPACE, 'entry_key' => $this->marker]) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function resetOwned(): void
    {
        if (!$this->owned) {
            return;
        }

        try {
            $this->connection->rollBack();
        } catch (Throwable) {
            // Nothing is left to roll back; DBAL's count is what mattered.
        }
    }
}
