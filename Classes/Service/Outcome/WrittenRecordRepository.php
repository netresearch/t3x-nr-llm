<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Domain\Enum\CallOutcomeSource;
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

    private const TABLE_OUTCOME = 'tx_nrllm_call_outcome';

    /** TYPO3's own action codes; see `RecordHistoryStore`. */
    private const ACTION_DELETE = 4;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Correlations whose writes have ALL settled and that carry no observed
     * outcome yet.
     *
     * `MAX(e.crdate)`, not "any write is old enough". A run's writes share a
     * correlation id, the outcome is one row for the run, and the exclusion
     * below then stops it being reconsidered — so selecting a run because its
     * FIRST write settled would judge it while a later write was still inside
     * its window, and that judgement would be final.
     *
     * The exclusion is a subquery rather than a filter in PHP, because a page
     * of the oldest writes that had all been answered would return the same
     * page forever and the command would report no work while writes piled up
     * behind it.
     *
     * @return list<string>
     */
    public function findUnansweredCorrelations(int $timestamp, int $limit): array
    {
        $query = $this->connectionPool->getQueryBuilderForTable(self::TABLE_EVENT);
        $sub   = $this->connectionPool->getQueryBuilderForTable(self::TABLE_OUTCOME);

        $sub->select('o.correlation_id')
            ->from(self::TABLE_OUTCOME, 'o')
            ->where($sub->expr()->in('o.outcome', $query->createNamedParameter($this->observedValues(), Connection::PARAM_STR_ARRAY)));

        $rows = $query
            ->select('r.uuid')
            ->from(self::TABLE_EVENT, 'e')
            ->innerJoin('e', self::TABLE_RUN, 'r', 'r.uid = e.run')
            ->where(
                $query->expr()->eq('e.kind', $query->createNamedParameter(AgentEventKind::TOOL_WRITE->value)),
                $query->expr()->neq('r.uuid', $query->createNamedParameter('')),
                $query->expr()->notIn('r.uuid', $sub->getSQL()),
            )
            ->groupBy('r.uuid')
            // Written out rather than built through `expr()`: the builder quotes
            // the left-hand side as an identifier, and `MAX(e.crdate)` is an
            // expression — SQLite answers "no such column: MAX(e.crdate)".
            ->having('MAX(e.crdate) < ' . $query->createNamedParameter($timestamp, Connection::PARAM_INT))
            ->orderBy('r.uuid', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();

        $uuids = [];
        foreach ($rows as $uuid) {
            if (is_string($uuid) && $uuid !== '') {
                $uuids[] = $uuid;
            }
        }

        return $uuids;
    }

    /**
     * Every write one run recorded, oldest first.
     *
     * A malformed payload is skipped rather than guessed at: the identity is
     * the only thing the row is for, so half of one is worth nothing.
     *
     * @return list<ObservedWrite>
     */
    public function findWritesForCorrelation(string $correlationId): array
    {
        if ($correlationId === '') {
            return [];
        }

        $query = $this->connectionPool->getQueryBuilderForTable(self::TABLE_EVENT);

        $rows = $query
            ->select('e.payload', 'e.crdate', 'r.uuid')
            ->from(self::TABLE_EVENT, 'e')
            ->innerJoin('e', self::TABLE_RUN, 'r', 'r.uid = e.run')
            ->where(
                $query->expr()->eq('e.kind', $query->createNamedParameter(AgentEventKind::TOOL_WRITE->value)),
                $query->expr()->eq('r.uuid', $query->createNamedParameter($correlationId)),
            )
            ->orderBy('e.uid', 'ASC')
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
     * The outcome values that belong to the observed source.
     *
     * @return list<string>
     */
    private function observedValues(): array
    {
        $values = [];
        foreach (CallOutcome::cases() as $case) {
            if ($case->source() === CallOutcomeSource::OBSERVED) {
                $values[] = $case->value;
            }
        }

        return $values;
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
     * @return array{later: list<int>, oldestRetained: int|null}
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

        $later          = [];
        $oldestRetained = null;
        foreach ($rows as $row) {
            $tstamp = $this->toInt($row['tstamp'] ?? null);
            $action = $this->toInt($row['actiontype'] ?? null);

            if ($oldestRetained === null || $tstamp < $oldestRetained) {
                $oldestRetained = $tstamp;
            }

            if ($tstamp > $writtenAt) {
                $later[] = $action;
            }
        }

        return ['later' => $later, 'oldestRetained' => $oldestRetained];
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
        $uuid    = $row['uuid'] ?? null;
        $payload = $this->decodePayload($row['payload'] ?? null);
        if (!is_string($uuid) || $uuid === '' || $payload === null) {
            return null;
        }

        $record = $this->referenceFrom($payload);

        return $record instanceof RecordReference
            ? new ObservedWrite($uuid, $record, $this->toInt($row['crdate'] ?? null))
            : null;
    }

    /**
     * The record a step payload names, or null when it names none usable.
     *
     * The reference validates its own table name and uid (ADR-182), so a
     * hand-edited or truncated payload is refused here rather than becoming an
     * outcome row pointing at nothing.
     *
     * @param array<array-key, mixed> $payload
     */
    private function referenceFrom(array $payload): ?RecordReference
    {
        $table = $payload['writeTargetTable'] ?? null;
        $uid   = $payload['writeTargetUid'] ?? null;
        if (!is_string($table) || !is_int($uid)) {
            return null;
        }

        try {
            return new RecordReference($table, $uid);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The step payload as an array, or null when it is not one.
     *
     * @return array<array-key, mixed>|null
     */
    private function decodePayload(mixed $payload): ?array
    {
        if (!is_string($payload)) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
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
