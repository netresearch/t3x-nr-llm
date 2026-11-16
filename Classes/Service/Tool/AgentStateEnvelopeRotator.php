<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Netresearch\NrVault\Crypto\EnvelopeRotationContext;
use Netresearch\NrVault\Crypto\ForeignEnvelopeRotatorInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

use function is_string;

/**
 * Re-wraps the encrypted agent-state columns when the nr-vault master key is
 * rotated (ADR-114, nr-vault ADR-033).
 *
 * Without this, encrypting those columns was a delayed data-loss bug rather than
 * a security improvement. ``vault:rotate-master-key`` re-wraps the data keys it
 * finds in ``tx_nrvault_secret``; the data keys for these two columns live in
 * ``tx_nrllm_agentrun``, where that walk never reaches them. A rotation therefore
 * left every queued and suspended run wrapped under a key the operator was told
 * to destroy — silently, because the rotation genuinely succeeded at everything
 * it knew about.
 *
 * Registered by tagging this service ``nrvault.foreign_envelope_rotator``, which
 * makes the vault call {@see rewrapAll()} inside its own rotation transaction.
 * A failure here rolls the whole rotation back, which is the point: a partial
 * rotation is worse than none.
 *
 * A value is written back only if it is still the one read: most tables
 * compare the old value in the UPDATE. The A2A file store holds values of
 * up to about 15 MiB, too large to send twice in one statement, so its rows
 * are rewrapped one at a time under a row lock, read again under the lock
 * and written by uid alone.
 */
final readonly class AgentStateEnvelopeRotator implements ForeignEnvelopeRotatorInterface
{
    use SafeCastTrait;

    /**
     * The sealed columns per table, each with the AAD identifier it was sealed
     * under. Passing the wrong identifier would fail authentication, so this
     * mapping is load-bearing rather than cosmetic. The A2A task view and its
     * event journal (ADR-282) are sealed with the same codec, so a rotation
     * that left them out would lose them the same way.
     */
    private const SEALED = [
        'tx_nrllm_agentrun' => [
            'queued_request'  => AgentStateCodec::PURPOSE_QUEUED_REQUEST,
            'suspended_state' => AgentStateCodec::PURPOSE_SUSPENDED_STATE,
            'answer'          => AgentStateCodec::PURPOSE_RUN_ANSWER,
        ],
        'tx_nrllm_a2a_task' => [
            'status_message' => AgentStateCodec::PURPOSE_A2A_TASK_CONTENT,
            'artifacts'      => AgentStateCodec::PURPOSE_A2A_TASK_CONTENT,
            'history'        => AgentStateCodec::PURPOSE_A2A_TASK_CONTENT,
        ],
        'tx_nrllm_a2a_event' => [
            'payload' => AgentStateCodec::PURPOSE_A2A_EVENT,
        ],
        'tx_nrllm_a2a_push_config' => [
            'token'       => AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL,
            'credentials' => AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL,
        ],
        'tx_nrllm_a2a_push_inbox' => [
            'body' => AgentStateCodec::PURPOSE_A2A_PUSH_INBOX,
        ],
        'tx_nrllm_a2a_file' => [
            'source_url' => AgentStateCodec::PURPOSE_A2A_FILE,
            'content'    => AgentStateCodec::PURPOSE_A2A_FILE,
        ],
    ];

    /**
     * Rows read per batch. The whole pass runs in the vault's single transaction,
     * so the batch bounds memory, not transaction size.
     */
    private const BATCH_SIZE = 200;

    /**
     * Tables whose values are too large for a page of BATCH_SIZE rows. A
     * sealed A2A file holds up to about 15 MiB (1.81 times the 8 MiB
     * `a2a.maxRawPartBytes` allows), so 200 of them could hold GiBs; that
     * table is read one row at a time.
     */
    private const TABLE_BATCH_SIZES = [
        'tx_nrllm_a2a_file' => 1,
    ];

    /**
     * Tables rewrapped row by row under a row lock instead of by comparing
     * the old value in the UPDATE. An UPDATE that names both the old and the
     * new sealed file carries about 30 MiB, more than the 16 MiB a MariaDB
     * packet holds by default (`max_allowed_packet`); under the lock the
     * statement carries the new value only.
     */
    private const ROW_LOCKED_TABLES = [
        'tx_nrllm_a2a_file' => true,
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getIdentifier(): string
    {
        return 'nr-llm: agent run state';
    }

    public function getTables(): array
    {
        return array_keys(self::SEALED);
    }

    public function countEnvelopes(): int
    {
        $total = 0;

        foreach (self::SEALED as $table => $columns) {
            $connection = $this->connectionPool->getConnectionForTable($table);
            foreach (array_keys($columns) as $column) {
                $queryBuilder = $connection->createQueryBuilder();
                $queryBuilder->getRestrictions()->removeAll();
                $queryBuilder
                    ->count('uid')
                    ->from($table)
                    ->where($this->sealedPredicate($queryBuilder, $column));

                $total += self::toInt($queryBuilder->executeQuery()->fetchOne());
            }
        }

        return $total;
    }

    public function rewrapAll(EnvelopeRotationContext $context): int
    {
        $rewrapped = 0;

        foreach (self::SEALED as $table => $columns) {
            $rewrapped += $this->rewrapTable($context, $table, $columns);
        }

        return $rewrapped;
    }

    /**
     * @param array<string, string> $columns
     */
    private function rewrapTable(EnvelopeRotationContext $context, string $table, array $columns): int
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $rewrapped  = 0;

        foreach ($columns as $column => $identifier) {
            $lastUid = 0;

            while (true) {
                $rows = $this->fetchBatch($connection, $table, $column, $lastUid);
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $lastUid = $row['uid'];
                    $rewrapped += isset(self::ROW_LOCKED_TABLES[$table])
                        ? $this->rewrapLockedRow($context, $connection, $table, $column, $identifier, $row['uid'])
                        : $this->rewrapRow($context, $connection, $table, $column, $identifier, $row);
                }
            }
        }

        return $rewrapped;
    }

    /**
     * Rewrap one value under the new key: 1 when it was rewritten, 0 when it
     * holds nothing this rotation seals.
     *
     * @param array{uid: int, value: string} $row
     */
    private function rewrapRow(EnvelopeRotationContext $context, Connection $connection, string $table, string $column, string $identifier, array $row): int
    {
        // Normalise BEFORE the guard. The vault's isSealed() only recognises
        // its own marker, so testing the raw value skipped every legacy row —
        // found by the SQL predicate, then silently passed over, and left
        // wrapped under the retired key.
        $sealed = AgentStateEnvelopeMarker::normalise($row['value']);
        if ($sealed === null || !$context->isSealed($sealed)) {
            return 0;
        }

        // Writing back the normalised form means a rotation also migrates the
        // marker, so the legacy branch empties over time.
        $updated = $connection->update(
            $table,
            [$column => $context->rewrap($sealed, $identifier)],
            ['uid' => $row['uid'], $column => $row['value']],
        );
        // A privacy scrub or state transition may have replaced the value
        // since the batch read. Never resurrect that snapshot. Abort the vault
        // transaction so the old key stays valid.
        if ($updated !== 1) {
            throw new RuntimeException(
                'Agent run state changed during key rotation; retry the rotation.',
                1794045001,
            );
        }

        return 1;
    }

    /**
     * Rewrap one value of a row-locked table: lock the row, read the value
     * again under the lock, and write the new value by uid alone. A value
     * that is no longer sealed (a privacy scrub or the task's end emptied it
     * since the page was read) is skipped.
     */
    private function rewrapLockedRow(EnvelopeRotationContext $context, Connection $connection, string $table, string $column, string $identifier, int $uid): int
    {
        return $connection->transactional(function (Connection $connection) use ($context, $table, $column, $identifier, $uid): int {
            $connection->executeStatement(
                'UPDATE ' . $connection->quoteIdentifier($table) . ' SET uid = uid WHERE uid = ?',
                [$uid],
                [Connection::PARAM_INT],
            );

            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll();
            $value = $queryBuilder
                ->select($column)
                ->from($table)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchOne();

            $sealed = is_string($value) ? AgentStateEnvelopeMarker::normalise($value) : null;
            if ($sealed === null || !$context->isSealed($sealed)) {
                return 0;
            }

            $connection->update($table, [$column => $context->rewrap($sealed, $identifier)], ['uid' => $uid]);

            return 1;
        });
    }

    /**
     * One page of sealed values for a column, keyed by uid for a stable cursor.
     *
     * Paged by uid rather than by OFFSET because the rows are being rewritten as
     * the walk proceeds; an OFFSET page would shift under it. The cursor advances
     * over every returned row, so the loop terminates exactly when the query stops
     * matching.
     *
     * @return list<array{uid: int, value: string}>
     */
    private function fetchBatch(Connection $connection, string $table, string $column, int $afterUid): array
    {
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        // A row-locked table reads its value again under the lock, so the
        // page carries uids only and no sealed value is held twice.
        $locked = isset(self::ROW_LOCKED_TABLES[$table]);
        $result = $queryBuilder
            ->select(...($locked ? ['uid'] : ['uid', $column]))
            ->from($table)
            ->where(
                $queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($afterUid, Connection::PARAM_INT)),
                $this->sealedPredicate($queryBuilder, $column),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(self::TABLE_BATCH_SIZES[$table] ?? self::BATCH_SIZE)
            ->executeQuery();

        $rows = [];
        while ($row = $result->fetchAssociative()) {
            // Every row the query returned is kept, including any whose value does
            // not coerce to a usable string. Dropping rows HERE would stop the
            // cursor advancing past them, and a page that dropped entirely would
            // end the walk early — leaving later rows un-rotated. The caller skips
            // non-envelopes instead, so an empty batch means "no more rows", not
            // "no more interesting rows".
            $value = $row[$column] ?? null;

            $rows[] = [
                'uid'   => self::toInt($row['uid'] ?? null),
                'value' => is_string($value) ? $value : '',
            ];
        }

        return $rows;
    }

    /**
     * Match a value that STARTS with either marker.
     *
     * Both are matched, not just the current one: rows written by nr-llm
     * 0.24.0-0.25.x carry the legacy ``v2:`` marker and are sealed under the same
     * master key, so leaving them out would be exactly the silent loss this class
     * exists to prevent. Anchored at the start rather than searched for anywhere,
     * so a plaintext payload that happens to contain a marker-like substring is
     * not picked up.
     */
    private function sealedPredicate(QueryBuilder $queryBuilder, string $column): CompositeExpression
    {
        return $queryBuilder->expr()->or(
            ...array_map(
                static fn (string $prefix): string => $queryBuilder->expr()->like(
                    $column,
                    $queryBuilder->createNamedParameter(
                        $queryBuilder->escapeLikeWildcards($prefix) . '%',
                    ),
                ),
                AgentStateEnvelopeMarker::markers(),
            ),
        );
    }
}
