<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\EventListener;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

use function in_array;

/**
 * Records on the row that an AI wrote it (ADR-239); in a draft workspace on
 * the row the write changed there (ADR-260).
 *
 * Straight to the database: a DataHandler run would add a history entry and a
 * tstamp nobody made, and start the save hooks on a change no person did.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class MarkAiWrittenRecord
{
    public const FIELD = 'tx_nrllm_ai_written';

    private const TABLES = ['pages', 'tt_content'];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    #[AsEventListener(identifier: 'nr-llm/mark-ai-written-record')]
    public function __invoke(AfterAiRecordWrittenEvent $event): void
    {
        $record = $event->record;
        if (!in_array($event->kind, [WriteKind::CREATED, WriteKind::UPDATED], true) || !in_array($record->table, self::TABLES, true)) {
            return;
        }

        if ($event->workspace > 0) {
            $this->markInWorkspace($record->table, $record->uid, $event->workspace);

            return;
        }

        $this->connectionPool->getConnectionForTable($record->table)->update(
            $record->table,
            [self::FIELD => 1],
            ['uid' => $record->uid],
            [self::FIELD => Connection::PARAM_INT, 'uid' => Connection::PARAM_INT],
        );
    }

    /**
     * A write in a draft workspace changed the record's version there, or a
     * record new in that workspace — never the live row, which must stay as
     * it is until the workspace is published (ADR-260). The mark goes on that
     * row and is published with it.
     */
    private function markInWorkspace(string $table, int $uid, int $workspace): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->update($table)
            ->set(self::FIELD, 1, true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspace, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                ),
            )
            ->executeStatement();
    }
}
