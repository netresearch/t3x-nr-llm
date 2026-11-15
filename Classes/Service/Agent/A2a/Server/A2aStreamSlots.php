<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The capacity of open A2A streams, per task, per client and per
 * installation (spec 051 A2A-SSE3, ADR-283).
 *
 * The installation's capacity is `maxStreams` numbered slots, taken like the
 * waiting slots ({@see A2aWaitingSlots}): one guarded UPDATE per slot, so of
 * two streams that see the same free slot exactly one gets it. The per-client
 * and per-task limits are checked after the slot is held: the stream counts
 * the live slots of its client and its task, its own included, and gives the
 * slot back when a count is over the limit. Every statement runs on its own,
 * outside a transaction, so nothing waits for a lock and MariaDB's snapshot
 * isolation has no snapshot to refuse; no limit is ever exceeded, because
 * each stream that keeps its slot counted every slot held at that moment. Two
 * streams racing for the last place may both give theirs back; the client
 * tries again after `Retry-After`.
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
        $holder     = bin2hex(random_bytes(16));
        $held       = false;
        for ($slot = 1; $slot <= $total && !$held; ++$slot) {
            if ($connection->count('slot', self::TABLE, ['slot' => $slot]) === 0) {
                try {
                    $connection->insert(self::TABLE, ['slot' => $slot, 'holder' => '', 'client_uid' => 0, 'task_id' => '', 'expires' => 0]);
                } catch (UniqueConstraintViolationException) {
                    // Another stream created it first.
                }
            }

            $held = $connection->executeStatement(
                'UPDATE ' . $connection->quoteIdentifier(self::TABLE)
                . ' SET holder = ?, client_uid = ?, task_id = ?, expires = ? WHERE slot = ? AND (holder = ? OR expires < ?)',
                [$holder, $clientUid, $taskId, $now + self::LEASE_SECONDS, $slot, '', $now],
                [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT],
            ) === 1;
        }

        if (!$held) {
            return null;
        }

        if ($this->live('client_uid', $clientUid, $now) > $perClient || ($taskId !== '' && $this->live('task_id', $taskId, $now) > $perTask)) {
            $this->release($holder);

            return null;
        }

        return $holder;
    }

    /**
     * Name the task a slot taken for a not yet existing task reads; false,
     * with the task taken off the slot again, when the task then has more
     * than $perTask live streams. The caller releases the slot.
     */
    public function bind(string $holder, string $taskId, int $perTask, int $now): bool
    {
        if ($this->connection()->update(self::TABLE, ['task_id' => $taskId], ['holder' => $holder]) !== 1) {
            return false;
        }

        if ($this->live('task_id', $taskId, $now) > $perTask) {
            $this->connection()->update(self::TABLE, ['task_id' => ''], ['holder' => $holder]);

            return false;
        }

        return true;
    }

    /**
     * Extend the lease of a hold; false when the hold is gone or its lease ran
     * out. A stream that missed its renewals has been counted out by the
     * streams admitted since, so it ends instead of coming back over a limit.
     */
    public function renew(string $holder, int $now): bool
    {
        $connection = $this->connection();

        return $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier(self::TABLE) . ' SET expires = ? WHERE holder = ? AND expires >= ?',
            [$now + self::LEASE_SECONDS, $holder, $now],
            [Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT],
        ) === 1;
    }

    public function release(string $holder): void
    {
        $this->connection()->update(self::TABLE, ['holder' => '', 'client_uid' => 0, 'task_id' => '', 'expires' => 0], ['holder' => $holder]);
    }

    private function live(string $column, int|string $value, int $now): int
    {
        $connection = $this->connection();
        $count      = $connection->executeQuery(
            'SELECT COUNT(*) FROM ' . $connection->quoteIdentifier(self::TABLE) . ' WHERE ' . $connection->quoteIdentifier($column) . ' = ? AND holder <> ? AND expires >= ?',
            [$value, '', $now],
            [is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR, Connection::PARAM_STR, Connection::PARAM_INT],
        )->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
