<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Task;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Writes the A2A event journal from the committed task view (spec 051
 * A2A-R14, A2A-R15; ADR-282).
 *
 * - It reads only what is committed and only what may leave the
 *   installation: the task's state, status timestamp, status message,
 *   artifacts and outcome reason, as the store kept them. Never a prompt, a
 *   tool argument, raw provider output or an audit row; it never sees one.
 * - It is idempotent per revision: an event is keyed by (task, revision) in
 *   the database, so projecting a revision twice yields it once, and two
 *   workers projecting at the same time write it once.
 * - A failed projection is retried by {@see self::projectPending()} from the
 *   committed state; nothing here can run the model or a tool.
 *
 * Every committed change of a task raises its revision; a revision that was
 * superseded before it was projected is not written: the event carries the
 * state as committed at projection time, under the newest revision.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTaskProjector
{
    public const TABLE = 'tx_nrllm_a2a_event';

    public const KIND_TASK = 'task';

    private const RUN_TABLE = 'tx_nrllm_agentrun';

    public function __construct(
        private ConnectionPool $connectionPool,
        private AgentStateCodec $codec,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Project one task up to its committed revision.
     *
     * @return int how many events this call wrote (0 or 1)
     */
    public function project(int $taskUid): int
    {
        $connection = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE);

        return $connection->transactional(function (Connection $connection) use ($taskUid): int {
            // Every read here is a locking read. Under REPEATABLE READ
            // (MariaDB, MySQL) the first plain read would fix the snapshot
            // before the run lock below is held, and the task read after it
            // could then be older than a scrub that committed in between. A
            // locking read creates no snapshot and returns the latest
            // committed row. SQLite has no FOR UPDATE and serialises writers.
            $runUid = $connection->executeQuery(
                'SELECT run_uid FROM ' . $connection->quoteIdentifier(A2aTaskStore::TABLE) . ' WHERE uid = ?' . $this->lockingRead($connection),
                [$taskUid],
                [Connection::PARAM_INT],
            )->fetchOne();
            if (!is_numeric($runUid)) {
                return 0;
            }

            // The run-row lock a privacy tightening holds while it scrubs the
            // task and its events (ADR-270, ADR-282). Taken before the task is
            // read, so a projection never writes content it read before a
            // scrub committed: it waits for the scrub, or the scrub rewrites
            // the event this projection wrote.
            $connection->executeStatement(
                'UPDATE ' . $connection->quoteIdentifier(self::RUN_TABLE) . ' SET uid = uid WHERE uid = ?',
                [(int) $runUid],
                [Connection::PARAM_INT],
            );

            $task = $connection->executeQuery(
                'SELECT * FROM ' . $connection->quoteIdentifier(A2aTaskStore::TABLE) . ' WHERE uid = ?' . $this->lockingRead($connection),
                [$taskUid],
                [Connection::PARAM_INT],
            )->fetchAssociative();
            if (!is_array($task)) {
                return 0;
            }

            $revision  = is_numeric($task['revision'] ?? null) ? (int) $task['revision'] : 0;
            $projected = is_numeric($task['projected_revision'] ?? null) ? (int) $task['projected_revision'] : 0;
            if ($revision <= $projected) {
                return 0;
            }

            $written = 0;
            try {
                $connection->insert(self::TABLE, [
                    'task_uid' => $taskUid,
                    'revision' => $revision,
                    'kind'     => self::KIND_TASK,
                    'payload'  => $this->codec->encode($this->payload($task), AgentStateCodec::PURPOSE_A2A_EVENT),
                    'crdate'   => time(),
                ]);
                $written = 1;
            } catch (UniqueConstraintViolationException) {
                // Another projection of this revision won; it is the same event.
            }

            $connection->executeStatement(
                'UPDATE ' . $connection->quoteIdentifier(A2aTaskStore::TABLE) . ' SET projected_revision = ? WHERE uid = ? AND projected_revision < ?',
                [$revision, $taskUid, $revision],
                [Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT],
            );

            return $written;
        });
    }

    /**
     * Project every task whose committed revision has no event yet: the
     * worker's retry of a projection that failed after its commit.
     *
     * @return int events written
     */
    public function projectPending(int $limit = 100): int
    {
        $connection = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE);
        $uids       = $connection->executeQuery(
            'SELECT uid FROM ' . $connection->quoteIdentifier(A2aTaskStore::TABLE) . ' WHERE projected_revision < revision ORDER BY uid LIMIT ' . max(1, $limit),
        )->fetchFirstColumn();

        $written = 0;
        foreach ($uids as $uid) {
            try {
                $written += is_numeric($uid) ? $this->project((int) $uid) : 0;
            } catch (DbalException) {
                // A conflict (a lock wait, MariaDB's snapshot-isolation
                // error) rolled this projection back; it is retried on the
                // next pass, and the other tasks go on.
            } catch (Throwable $e) {
                // A task whose sealed content cannot be read (a damaged
                // envelope, a key that is gone) must not stop the others. It
                // stays pending and is named in the log for an operator.
                $this->logger?->error('An A2A task could not be projected; the other tasks go on', ['task' => $uid, 'exception' => $e]);
            }
        }

        return $written;
    }

    /**
     * The events of a task in revision order, decoded.
     *
     * @return list<array{revision: int, kind: string, payload: array<string, mixed>}>
     */
    public function events(int $taskUid): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['revision', 'kind', 'payload'], self::TABLE, ['task_uid' => $taskUid], [], ['revision' => 'ASC'])
            ->fetchAllAssociative();

        $events = [];
        foreach ($rows as $row) {
            $payload = json_decode($this->codec->decode(is_string($row['payload'] ?? null) ? $row['payload'] : '', AgentStateCodec::PURPOSE_A2A_EVENT), true);
            /** @var array<string, mixed> $payload */
            $payload  = is_array($payload) ? $payload : [];
            $events[] = [
                'revision' => is_numeric($row['revision'] ?? null) ? (int) $row['revision'] : 0,
                'kind'     => is_string($row['kind'] ?? null) ? $row['kind'] : '',
                'payload'  => $payload,
            ];
        }

        return $events;
    }

    private function lockingRead(Connection $connection): string
    {
        return $connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    /**
     * @param array<string, mixed> $task
     */
    private function payload(array $task): string
    {
        $payload = [
            'taskId'          => $task['task_id'] ?? '',
            'contextId'       => $task['context_id'] ?? '',
            'state'           => $task['state'] ?? '',
            'statusTimestamp' => is_numeric($task['status_timestamp'] ?? null) ? (int) $task['status_timestamp'] : null,
        ];
        foreach (['status_message' => 'statusMessage', 'artifacts' => 'artifacts'] as $column => $key) {
            $stored = is_string($task[$column] ?? null) ? $task[$column] : '';
            if ($stored !== '') {
                $payload[$key] = json_decode($this->codec->decode($stored, AgentStateCodec::PURPOSE_A2A_TASK_CONTENT), true);
            }
        }

        if (is_string($task['outcome_reason'] ?? null) && $task['outcome_reason'] !== '') {
            $payload['metadata'] = ['reason' => $task['outcome_reason']];
        }

        return json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
