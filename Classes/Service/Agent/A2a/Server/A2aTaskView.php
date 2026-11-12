<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use DateTimeImmutable;
use DateTimeZone;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTimestamp;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Reads the A2A task view for a client and renders a `Task` (spec 051
 * A2A-SV6, A2A-SEC2, ADR-282).
 *
 * The owner, the client record's uid, is in the `WHERE` clause of every
 * query, so a task of another client and a task that does not exist are the
 * same: absent. Only committed rows of the view are read; the content is the
 * sealed content the store kept at the run's answer level.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTaskView
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private AgentStateCodec $codec,
    ) {}

    /**
     * @return array<string, mixed>|null the row, or null when the client owns no such task
     */
    public function find(int $ownerClientUid, string $taskId): ?array
    {
        if ($taskId === '' || $ownerClientUid < 1) {
            return null;
        }

        $row = $this->builder()->select('*')->from(A2aTaskStore::TABLE)
            ->where('owner_client_uid = :owner AND task_id = :task')
            ->setParameter('owner', $ownerClientUid, Connection::PARAM_INT)
            ->setParameter('task', $taskId)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Whether a context belongs to the client: one of its tasks carries it.
     */
    public function ownsContext(int $ownerClientUid, string $contextId): bool
    {
        return $this->connection()->count('uid', A2aTaskStore::TABLE, ['owner_client_uid' => $ownerClientUid, 'context_id' => $contextId]) > 0;
    }

    /**
     * Whether any task of any client carries the context: a client-supplied
     * context that is "new" must not be one (A2A-SV6).
     */
    public function contextExists(string $contextId): bool
    {
        return $this->connection()->count('uid', A2aTaskStore::TABLE, ['context_id' => $contextId]) > 0;
    }

    /**
     * The client's tasks that hold a worker's capacity: queued or running.
     */
    public function countActiveOfClient(int $ownerClientUid): int
    {
        return $this->countActive('owner_client_uid', $ownerClientUid);
    }

    public function countActiveOfProfile(int $profileUid): int
    {
        return $this->countActive('profile_uid', $profileUid);
    }

    private function countActive(string $column, int $value): int
    {
        $builder = $this->builder();

        $count = $builder->count('uid')->from(A2aTaskStore::TABLE)
            ->where(
                $builder->expr()->eq($column, $builder->createNamedParameter($value, Connection::PARAM_INT)),
                $builder->expr()->in('state', $builder->createNamedParameter([A2aTaskState::SUBMITTED->value, A2aTaskState::WORKING->value], Connection::PARAM_STR_ARRAY)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * One page of the client's tasks, newest status first, ties by task id
     * (A2A-D7), and the count of all matching tasks.
     *
     * @param array{ts: int, id: string}|null $after
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(int $ownerClientUid, A2aListFilter $filter, ?array $after, int $pageSize): array
    {
        $count = $this->filtered($ownerClientUid, $filter)->count('uid')->executeQuery()->fetchOne();

        $builder = $this->filtered($ownerClientUid, $filter)->select('*');
        if ($after !== null) {
            $builder->andWhere($builder->expr()->or(
                $builder->expr()->lt('status_timestamp', $builder->createNamedParameter($after['ts'], Connection::PARAM_INT)),
                $builder->expr()->and(
                    $builder->expr()->eq('status_timestamp', $builder->createNamedParameter($after['ts'], Connection::PARAM_INT)),
                    $builder->expr()->gt('task_id', $builder->createNamedParameter($after['id'])),
                ),
            ));
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder->orderBy('status_timestamp', 'DESC')->addOrderBy('task_id', 'ASC')
            ->setMaxResults($pageSize + 1)
            ->executeQuery()
            ->fetchAllAssociative();

        return ['rows' => $rows, 'total' => is_numeric($count) ? (int) $count : 0];
    }

    private function filtered(int $ownerClientUid, A2aListFilter $filter): QueryBuilder
    {
        $builder = $this->builder();
        $builder->from(A2aTaskStore::TABLE)
            ->where($builder->expr()->eq('owner_client_uid', $builder->createNamedParameter($ownerClientUid, Connection::PARAM_INT)));
        if ($filter->contextId !== '') {
            $builder->andWhere($builder->expr()->eq('context_id', $builder->createNamedParameter($filter->contextId)));
        }

        if ($filter->state instanceof A2aTaskState) {
            $builder->andWhere($builder->expr()->eq('state', $builder->createNamedParameter($filter->state->value)));
        }

        if ($filter->statusTimestampAfter !== null) {
            $builder->andWhere($builder->expr()->gte('status_timestamp', $builder->createNamedParameter($filter->statusTimestampAfter, Connection::PARAM_INT)));
        }

        return $builder;
    }

    /**
     * The `Task` of a row.
     *
     * @param array<string, mixed> $row
     * @param int|null             $historyLength null: the whole history; 0: none; n: the n most recent
     *
     * @return array<string, mixed>
     */
    public function render(array $row, ?int $historyLength, bool $withArtifacts = true): array
    {
        $task = [
            'id'        => is_string($row['task_id'] ?? null) ? $row['task_id'] : '',
            'contextId' => is_string($row['context_id'] ?? null) ? $row['context_id'] : '',
            'status'    => $this->status($row),
        ];

        $artifacts = $this->open($row, 'artifacts');
        if ($withArtifacts && is_array($artifacts) && $artifacts !== []) {
            $task['artifacts'] = $artifacts;
        }

        $history = $this->history($row, $historyLength);
        if ($history !== []) {
            $task['history'] = $history;
        }

        if (is_string($row['outcome_reason'] ?? null) && $row['outcome_reason'] !== '') {
            $task['metadata'] = ['reason' => $row['outcome_reason']];
        }

        return $task;
    }

    /**
     * The `TaskStatus` of a row.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function status(array $row): array
    {
        $status  = ['state' => is_string($row['state'] ?? null) ? $row['state'] : A2aTaskState::UNSPECIFIED->value];
        $message = $this->open($row, 'status_message');
        if (is_array($message)) {
            $status['message'] = $message;
        }

        if (is_numeric($row['status_timestamp'] ?? null)) {
            $millis  = (int) $row['status_timestamp'];
            $instant = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($millis, 1000), ($millis % 1000) * 1000), new DateTimeZone('UTC'));
            if ($instant !== false) {
                $status['timestamp'] = A2aTimestamp::format($instant);
            }
        }

        return $status;
    }

    /**
     * The history of a row, cut to the `historyLength` the client asked for.
     *
     * @param array<string, mixed> $row
     *
     * @return list<mixed>
     */
    private function history(array $row, ?int $historyLength): array
    {
        $history = $this->open($row, 'history');
        if (!is_array($history) || $historyLength === 0) {
            return [];
        }

        $history = array_values($history);

        return $historyLength !== null && count($history) > $historyLength ? array_slice($history, -$historyLength) : $history;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function open(array $row, string $column): mixed
    {
        $stored = is_string($row[$column] ?? null) ? $row[$column] : '';
        if ($stored === '') {
            return null;
        }

        return json_decode($this->codec->decode($stored, AgentStateCodec::PURPOSE_A2A_TASK_CONTENT), true);
    }

    private function builder(): QueryBuilder
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(A2aTaskStore::TABLE);
        $builder->getRestrictions()->removeAll();

        return $builder;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE);
    }
}
