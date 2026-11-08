<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Exception\RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Writes and reads the per-call outcome rows (ADR-176).
 *
 * Uses the Doctrine ConnectionPool directly, like
 * {@see \Netresearch\NrLlm\Service\Telemetry\TelemetryRepository}: a UI-less
 * table with no Extbase persistence needs.
 *
 * The table has no source column. This class is where the one-row-per-source
 * rule is enforced, by clearing the same source before inserting — so a rater
 * who changes their mind replaces their answer rather than leaving two rows
 * and a readout that has to guess which one counts.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class CallOutcomeRepository implements CallOutcomeRepositoryInterface, SingletonInterface
{
    private const TABLE = 'tx_nrllm_call_outcome';

    public function __construct(
        private ConnectionPool $connectionPool,
        private LockFactory $lockFactory = new LockFactory(),
    ) {}

    /**
     * Serialize each run/source on one of 256 bounded lock stripes, then replace transactionally. Collisions serialize unrelated keys without changing outcome identity. Multi-node installations require a shared lock strategy.
     */
    public function record(string $correlationId, CallOutcome $outcome): void
    {
        if ($correlationId === '') {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $lock       = $this->lockFactory->createLocker(
            'nrllm-outcome-' . substr(
                hash('sha256', $correlationId . ':' . $outcome->source()->value),
                0,
                2,
            ),
        );
        if (!$lock->acquire()) {
            throw new RuntimeException(
                'The outcome replacement lock could not be acquired.',
                7374557069,
            );
        }

        try {
            $connection->transactional(
                function (
                    Connection $connection,
                ) use ($correlationId, $outcome): void {
                    $sameSource = array_map(
                        static fn (CallOutcome $case): string => $case->value,
                        array_filter(
                            CallOutcome::cases(),
                            static fn (
                                CallOutcome $case,
                            ): bool => $case->source() === $outcome->source(),
                        ),
                    );
                    $query = $connection->createQueryBuilder();
                    $query
                        ->delete(self::TABLE)
                        ->where(
                            $query->expr()->eq(
                                'correlation_id',
                                $query->createNamedParameter($correlationId),
                            ),
                            $query->expr()->in(
                                'outcome',
                                $query->createNamedParameter(
                                    $sameSource,
                                    Connection::PARAM_STR_ARRAY,
                                ),
                            ),
                        )
                        ->executeStatement();
                    $now = time();
                    $connection->insert(
                        self::TABLE,
                        [
                            'pid'            => 0,
                            'correlation_id' => $correlationId,
                            'outcome'        => $outcome->value,
                            'crdate'         => $now,
                            'tstamp'         => $now,
                        ],
                    );
                },
            );
        } finally {
            $lock->release();
        }
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $query = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $query->delete(self::TABLE)
            ->where($query->expr()->lt('crdate', $query->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeStatement();
    }

    public function findByCorrelation(string $correlationId): array
    {
        if ($correlationId === '') {
            return [];
        }

        $query = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows  = $query->select('outcome')
            ->from(self::TABLE)
            ->where($query->expr()->eq('correlation_id', $query->createNamedParameter($correlationId)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        $outcomes = [];
        foreach ($rows as $value) {
            // An unknown value is a row written by a newer version, or by hand.
            // Skipping it keeps the reader working; failing would take a whole
            // page down over one row nothing depends on.
            $case = is_string($value) ? CallOutcome::tryFrom($value) : null;
            if ($case instanceof CallOutcome) {
                $outcomes[] = $case;
            }
        }

        return $outcomes;
    }
}
