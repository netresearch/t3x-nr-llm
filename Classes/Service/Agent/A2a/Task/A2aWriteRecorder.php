<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Task;

use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Keeps the write event of every write a run of an A2A task executed (spec
 * 051 A2A-MT6, B13): the record's table and uid, the workspace the write
 * went into and its kind, as the tool loop announced it after the write
 * landed (ADR-187, ADR-260). A write the model only claims has no such event.
 *
 * Identity only, never a value of the record. Purged with the task.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aWriteRecorder
{
    public const TABLE = 'tx_nrllm_a2a_write';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    #[AsEventListener(identifier: 'nr-llm/a2a-write-recorder')]
    public function __invoke(AfterAiRecordWrittenEvent $event): void
    {
        $connection = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE);
        $taskUid    = $connection->executeQuery(
            'SELECT t.uid FROM ' . $connection->quoteIdentifier(A2aTaskStore::TABLE) . ' t'
                . ' INNER JOIN ' . $connection->quoteIdentifier('tx_nrllm_agentrun') . ' r ON r.uid = t.run_uid'
                . ' WHERE r.uuid = ?',
            [$event->correlationId],
        )->fetchOne();
        if (!is_numeric($taskUid)) {
            return;
        }

        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'task_uid'     => (int) $taskUid,
            'record_table' => $event->record->table,
            'record_uid'   => $event->record->uid,
            'workspace'    => max(0, $event->workspace),
            'kind'         => $event->kind->value,
            'crdate'       => time(),
        ]);
    }
}
