<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * The two reads the observed outcome needs, and nothing else (ADR-185).
 *
 * One over this extension's own event stream — which records were written, when,
 * and under which correlation id — and one over `sys_history`, for what happened
 * to them afterwards.
 *
 * The history read takes `tstamp` and `actiontype` and never `history_data`.
 * That column carries the old and new field VALUES, and reading it would move
 * record content into a derivation whose whole design keeps content out
 * (ADR-064, ADR-185).
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class WrittenRecordRepository implements WrittenRecordRepositoryInterface, SingletonInterface
{
    private const TABLE_EVENT = 'tx_nrllm_agentrun_event';

    private const TABLE_RUN = 'tx_nrllm_agentrun';

    private const TABLE_HISTORY = 'sys_history';

    /** TYPO3's own action codes; see `RecordHistoryStore`. */
    private const ACTION_DELETE = 4;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Every recorded write whose observation window has closed.
     *
     * The correlation id comes from the run's uuid, because that IS the
     * correlation id every provider call of the run reports under — the runs
     * table has no column of that name (ADR-185).
     *
     * A malformed payload is skipped rather than guessed at: the identity is
     * the only thing this row is for, so half of one is worth nothing.
     *
     * @return list<ObservedWrite>
     */
    public function findWritesSettledBefore(int $timestamp, int $limit): array
    {
        $query = $this->connectionPool->getQueryBuilderForTable(self::TABLE_EVENT);

        $rows = $query
            ->select('e.payload', 'e.crdate', 'r.uuid')
            ->from(self::TABLE_EVENT, 'e')
            ->innerJoin('e', self::TABLE_RUN, 'r', 'r.uid = e.run')
            ->where(
                $query->expr()->eq('e.kind', $query->createNamedParameter(AgentEventKind::TOOL_WRITE->value)),
                $query->expr()->lt('e.crdate', $query->createNamedParameter($timestamp, Connection::PARAM_INT)),
                $query->expr()->neq('r.uuid', $query->createNamedParameter('')),
            )
            ->orderBy('e.uid', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $writes = [];
        foreach ($rows as $row) {
            $write = $this->writeFromRow($row);
            if ($write instanceof ObservedWrite) {
                $writes[] = $write;
            }
        }

        return $writes;
    }

    /**
     * What happened to a record after it was written: the history action codes
     * of every row strictly newer than the write, plus whether the write's own
     * row is still there at all.
     *
     * The second half is what separates "nothing happened" from "the evidence
     * is gone". A record whose history has been purged looks exactly like a
     * record nobody touched, and reporting that as ACCEPTED_UNCHANGED would be
     * this signal's most plausible lie (ADR-185).
     *
     * @return array{later: list<int>, hasOwnRow: bool}
     */
    public function historyAfter(RecordReference $record, int $writtenAt): array
    {
        $query = $this->connectionPool->getQueryBuilderForTable(self::TABLE_HISTORY);

        $rows = $query
            ->select('tstamp', 'actiontype')
            ->from(self::TABLE_HISTORY)
            ->where(
                $query->expr()->eq('tablename', $query->createNamedParameter($record->table)),
                $query->expr()->eq('recuid', $query->createNamedParameter($record->uid, Connection::PARAM_INT)),
            )
            ->orderBy('tstamp', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $later     = [];
        $hasOwnRow = false;
        foreach ($rows as $row) {
            $tstamp = $this->toInt($row['tstamp'] ?? null);
            $action = $this->toInt($row['actiontype'] ?? null);

            if ($tstamp <= $writtenAt) {
                $hasOwnRow = true;

                continue;
            }

            $later[] = $action;
        }

        return ['later' => $later, 'hasOwnRow' => $hasOwnRow];
    }

    /**
     * Whether the record still exists at all.
     *
     * A `DELETE` action in the history is the ordinary way a record goes away,
     * but not the only one: a hard delete leaves no history row, and an
     * installation that prunes history leaves none either. Asked separately so
     * DISCARDED does not depend on the evidence surviving.
     *
     * A table this instance does not have is not an error here — an extension
     * can be removed while its records' history stays — and answers "gone".
     */
    public function recordExists(RecordReference $record): bool
    {
        try {
            $query = $this->connectionPool->getQueryBuilderForTable($record->table);
            $query->getRestrictions()->removeAll();

            $found = $query
                ->select('uid')
                ->from($record->table)
                ->where($query->expr()->eq('uid', $query->createNamedParameter($record->uid, Connection::PARAM_INT)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            return $found !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the history says the record was deleted.
     */
    public function isDeletion(int $actionType): bool
    {
        return $actionType === self::ACTION_DELETE;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function writeFromRow(array $row): ?ObservedWrite
    {
        $uuid = $row['uuid'] ?? null;
        if (!is_string($uuid) || $uuid === '') {
            return null;
        }

        $payload = $row['payload'] ?? null;
        if (!is_string($payload)) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $table = $decoded['writeTargetTable'] ?? null;
        $uid   = $decoded['writeTargetUid'] ?? null;
        if (!is_string($table) || !is_int($uid)) {
            return null;
        }

        try {
            $record = new RecordReference($table, $uid);
        } catch (Throwable) {
            return null;
        }

        return new ObservedWrite($uuid, $record, $this->toInt($row['crdate'] ?? null));
    }

    /**
     * A database column read back as an integer.
     *
     * Doctrine hands these back as `mixed` — a string on some platforms, an int
     * on others — and level 10 refuses a bare cast for that reason.
     */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}
