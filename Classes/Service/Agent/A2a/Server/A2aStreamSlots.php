<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The capacity of open A2A streams, per task, per client and per
 * installation (spec 051 A2A-SSE3, ADR-283).
 *
 * The installation's capacity is `maxStreams` numbered slots, taken like the
 * waiting slots ({@see A2aWaitingSlots}): one guarded UPDATE per slot, so of
 * two streams that see the same free slot exactly one gets it. The per-client
 * and per-task counts are taken under the client record's row lock, the lock
 * the acceptance of a task takes too, so two streams of one client cannot both
 * pass a count the other one changes. A task belongs to one client, so that
 * lock serialises the per-task count as well.
 *
 * A hold is a lease, not a reservation for the whole stream: the stream renews
 * it while it is open. With `ignore_user_abort` off, PHP ends the script at the
 * first write after the client went away, and a `finally` that would release
 * the slot may never run; the slot is free again when the lease runs out.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aStreamSlots
{
    public const TABLE = 'tx_nrllm_a2a_stream_slot';

    /** Seconds a hold lasts unless the stream renews it. */
    public const LEASE_SECONDS = 30;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Take a slot for a stream of the client on the task ('' for a task that
     * does not exist yet: the new task of a SendStreamingMessage).
     *
     * @return string|null the holder token, null when one of the three limits is reached
     */
    public function take(int $clientUid, string $taskId, int $perTask, int $perClient, int $total, int $now): ?string
    {
        // A per-task limit of 0 refuses a stream of a task that does not exist
        // yet as well: its slot would be refused only after the task was
        // accepted.
        if ($perClient < 1 || $total < 1 || $perTask < 1) {
            return null;
        }

        $connection = $this->connection();

        return $connection->transactional(function (Connection $connection) use ($clientUid, $taskId, $perTask, $perClient, $total, $now): ?string {
            $this->lockClient($connection, $clientUid);
            if ($this->live($connection, 'client_uid', $clientUid, $now) >= $perClient) {
                return null;
            }

            if ($taskId !== '' && $this->live($connection, 'task_id', $taskId, $now) >= $perTask) {
                return null;
            }

            $holder = bin2hex(random_bytes(16));
            for ($slot = 1; $slot <= $total; ++$slot) {
                $exists = $connection->executeQuery(
                    'SELECT COUNT(*) FROM ' . $connection->quoteIdentifier(self::TABLE) . ' WHERE slot = ?' . $this->lockingRead($connection),
                    [$slot],
                    [Connection::PARAM_INT],
                )->fetchOne();
                if (is_numeric($exists) && (int) $exists === 0) {
                    try {
                        $connection->insert(self::TABLE, ['slot' => $slot, 'holder' => '', 'client_uid' => 0, 'task_id' => '', 'expires' => 0]);
                    } catch (UniqueConstraintViolationException) {
                        // Another stream created it first.
                    }
                }

                $taken = $connection->executeStatement(
                    'UPDATE ' . $connection->quoteIdentifier(self::TABLE)
                    . ' SET holder = ?, client_uid = ?, task_id = ?, expires = ? WHERE slot = ? AND (holder = ? OR expires < ?)',
                    [$holder, $clientUid, $taskId, $now + self::LEASE_SECONDS, $slot, '', $now],
                    [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT],
                );
                if ($taken === 1) {
                    return $holder;
                }
            }

            return null;
        });
    }

    /**
     * Name the task a slot taken for a not yet existing task reads, unless
     * the task already has $perTask other live streams.
     */
    public function bind(string $holder, int $clientUid, string $taskId, int $perTask, int $now): bool
    {
        return $this->connection()->transactional(function (Connection $connection) use ($holder, $clientUid, $taskId, $perTask, $now): bool {
            $this->lockClient($connection, $clientUid);
            $others = $connection->executeQuery(
                'SELECT COUNT(*) FROM ' . $connection->quoteIdentifier(self::TABLE) . ' WHERE task_id = ? AND holder <> ? AND holder <> ? AND expires >= ?' . $this->lockingRead($connection),
                [$taskId, '', $holder, $now],
                [Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT],
            )->fetchOne();
            if (!is_numeric($others) || (int) $others >= $perTask) {
                return false;
            }

            return $connection->update(self::TABLE, ['task_id' => $taskId], ['holder' => $holder]) === 1;
        });
    }

    /**
     * Extend the lease of a hold; false when the hold is gone (it ran out and
     * another stream took the slot).
     */
    public function renew(string $holder, int $now): bool
    {
        return $this->connection()->update(self::TABLE, ['expires' => $now + self::LEASE_SECONDS], ['holder' => $holder]) === 1;
    }

    public function release(string $holder): void
    {
        $this->connection()->update(self::TABLE, ['holder' => '', 'client_uid' => 0, 'task_id' => '', 'expires' => 0], ['holder' => $holder]);
    }

    /**
     * The client row lock the acceptance takes as well (A2A-SV5).
     */
    private function lockClient(Connection $connection, int $clientUid): void
    {
        $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier(A2aInboundClientRepository::TABLE) . ' SET uid = uid WHERE uid = ?',
            [$clientUid],
            [Connection::PARAM_INT],
        );
    }

    private function live(Connection $connection, string $column, int|string $value, int $now): int
    {
        $count = $connection->executeQuery(
            'SELECT COUNT(*) FROM ' . $connection->quoteIdentifier(self::TABLE) . ' WHERE ' . $connection->quoteIdentifier($column) . ' = ? AND holder <> ? AND expires >= ?' . $this->lockingRead($connection),
            [$value, '', $now],
            [is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT],
        )->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Every read inside the transaction is a locking read. Under REPEATABLE
     * READ (MariaDB, MySQL) a plain read would fix a snapshot, and the guarded
     * UPDATE of a slot another stream renewed meanwhile would then fail with
     * MariaDB's snapshot-isolation error instead of moving to the next slot.
     * A locking read creates no snapshot. SQLite serialises writers.
     */
    private function lockingRead(Connection $connection): string
    {
        return $connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
