<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Utility\SafeCastTrait;
use Netresearch\NrVault\Crypto\EnvelopeRotationContext;
use Netresearch\NrVault\Crypto\ForeignEnvelopeRotatorInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

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
 */
final readonly class AgentStateEnvelopeRotator implements ForeignEnvelopeRotatorInterface
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_agentrun';

    /**
     * The two sealed columns, each with the AAD identifier it was sealed under.
     * Passing the wrong identifier would fail authentication, so this mapping is
     * load-bearing rather than cosmetic.
     */
    private const SEALED_COLUMNS = [
        'queued_request' => AgentStateCodec::PURPOSE_QUEUED_REQUEST,
        'suspended_state' => AgentStateCodec::PURPOSE_SUSPENDED_STATE,
    ];

    /**
     * Rows read per batch. The whole pass runs in the vault's single transaction,
     * so the batch bounds memory, not transaction size.
     */
    private const BATCH_SIZE = 200;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getIdentifier(): string
    {
        return 'nr-llm: agent run state';
    }

    public function getTables(): array
    {
        return [self::TABLE];
    }

    public function countEnvelopes(): int
    {
        $connection = $this->connection();
        $total = 0;

        foreach (array_keys(self::SEALED_COLUMNS) as $column) {
            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder
                ->count('uid')
                ->from(self::TABLE)
                ->where($this->sealedPredicate($queryBuilder, $column));

            $total += self::toInt($queryBuilder->executeQuery()->fetchOne());
        }

        return $total;
    }

    public function rewrapAll(EnvelopeRotationContext $context): int
    {
        $connection = $this->connection();
        $rewrapped = 0;

        foreach (self::SEALED_COLUMNS as $column => $identifier) {
            $lastUid = 0;

            while (true) {
                $rows = $this->fetchBatch($connection, $column, $lastUid);
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $lastUid = $row['uid'];

                    // Normalise BEFORE the guard. The vault's isSealed() only
                    // recognises its own marker, so testing the raw value skipped
                    // every legacy row — found by the SQL predicate, then silently
                    // passed over, and left wrapped under the retired key.
                    $sealed = AgentStateEnvelopeMarker::normalise($row['value']);
                    if ($sealed === null || !$context->isSealed($sealed)) {
                        continue;
                    }

                    // Writing back the normalised form means a rotation also
                    // migrates the marker, so the legacy branch empties over time.
                    $connection->update(
                        self::TABLE,
                        [$column => $context->rewrap($sealed, $identifier)],
                        ['uid' => $row['uid']],
                    );
                    ++$rewrapped;
                }
            }
        }

        return $rewrapped;
    }

    /**
     * One page of sealed values for a column, keyed by uid for a stable cursor.
     *
     * Paged by uid rather than by OFFSET because the rows are being rewritten as
     * the walk proceeds; an OFFSET page would shift under it.
     *
     * @return list<array{uid: int, value: string}>
     */
    private function fetchBatch(Connection $connection, string $column, int $afterUid): array
    {
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder
            ->select('uid', $column)
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($afterUid, Connection::PARAM_INT)),
                $this->sealedPredicate($queryBuilder, $column),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(self::BATCH_SIZE)
            ->executeQuery();

        $rows = [];
        while ($row = $result->fetchAssociative()) {
            $value = $row[$column] ?? null;
            if (!\is_string($value) || $value === '') {
                continue;
            }

            $rows[] = ['uid' => self::toInt($row['uid'] ?? null), 'value' => $value];
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
                static fn(string $prefix): string => $queryBuilder->expr()->like(
                    $column,
                    $queryBuilder->createNamedParameter(
                        $queryBuilder->escapeLikeWildcards($prefix) . '%',
                    ),
                ),
                AgentStateEnvelopeMarker::markers(),
            ),
        );
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
