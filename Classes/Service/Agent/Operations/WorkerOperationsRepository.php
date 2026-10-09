<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Operations;

use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal Operational aggregate reads and process heartbeats (ADR-219).
 */
final readonly class WorkerOperationsRepository implements WorkerOperationsReaderInterface
{
    use SafeCastTrait;

    private const RUN = 'tx_nrllm_agentrun';

    private const WORKER = 'tx_nrllm_worker_heartbeat';

    public function __construct(private ConnectionPool $connectionPool) {}

    public function touch(string $workerId, string $transport, int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::WORKER);
        $identity = ['worker_id' => $workerId, 'transport' => $transport];
        if ($connection->select(['uid'], self::WORKER, $identity)->fetchOne() === false) {
            $connection->insert(self::WORKER, $identity + ['last_seen' => $now]);
            return;
        }

        $connection->update(self::WORKER, ['last_seen' => $now], $identity);
    }

    public function remove(string $workerId): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::WORKER)
            ->delete(self::WORKER, ['worker_id' => $workerId]);
    }

    public function prune(int $before): void
    {
        $builder = $this->connectionPool
            ->getConnectionForTable(self::WORKER)
            ->createQueryBuilder();
        $builder
            ->delete(self::WORKER)
            ->where(
                $builder->expr()->lt(
                    'last_seen',
                    $builder->createNamedParameter(
                        $before,
                        Connection::PARAM_INT,
                    ),
                ),
            )
            ->executeStatement();
    }

    public function snapshot(
        int $now,
        int $workerMaxAge = 120,
        string $transport = 'doctrine',
    ): WorkerOperationsSnapshot {
        $connection = $this->connectionPool->getConnectionForTable(self::RUN);
        $counts = $connection
            ->executeQuery(
                'SELECT status, COUNT(*) AS total FROM ' . self::RUN . ' GROUP BY status',
            )
            ->fetchAllAssociative();
        $byStatus = [];
        foreach ($counts as $row) {
            if (is_string($row['status'] ?? null)) {
                $byStatus[$row['status']] = self::toInt($row['total'] ?? 0);
            }
        }

        $dead = self::toInt(
            $connection
                ->executeQuery(
                    'SELECT COUNT(*) FROM ' . self::RUN . ' WHERE status = ? AND termination_reason IN (?, ?)',
                    ['failed', 'retries_exhausted', 'not_retryable'],
                )
                ->fetchOne(),
        );
        $expired = self::toInt(
            $connection
                ->executeQuery(
                    'SELECT COUNT(*) FROM ' . self::RUN . ' WHERE status = ? AND lease_expires > 0 AND lease_expires < ?',
                    ['running', $now],
                )
                ->fetchOne(),
        );
        $oldest = $connection
            ->executeQuery(
                'SELECT MIN(queued_at) FROM ' . self::RUN . ' WHERE status = ? AND queued_at > 0 AND queued_at <= ?',
                ['queued', $now],
            )
            ->fetchOne();
        $wait = is_numeric($oldest) ? $now - (int)$oldest : null;
        $unknown = self::toInt(
            $connection
                ->executeQuery(
                    'SELECT COUNT(*) FROM ' . self::RUN . ' WHERE status = ? AND (queued_at <= 0 OR queued_at > ?)',
                    ['queued', $now],
                )
                ->fetchOne(),
        );
        $workerConnection = $this->connectionPool->getConnectionForTable(self::WORKER);
        $workers = self::toInt(
            $workerConnection
                ->executeQuery(
                    'SELECT COUNT(*) FROM ' . self::WORKER . ' WHERE transport = ? AND last_seen >= ? AND last_seen <= ?',
                    [$transport, max(0, $now - $workerMaxAge), $now],
                )
                ->fetchOne(),
        );
        return new WorkerOperationsSnapshot(
            $byStatus['queued'] ?? 0,
            $byStatus['running'] ?? 0,
            $dead,
            $expired,
            $wait,
            $unknown,
            $workers,
        );
    }
}
