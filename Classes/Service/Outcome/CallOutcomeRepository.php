<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Outcome;

use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
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
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class CallOutcomeRepository implements CallOutcomeRepositoryInterface, SingletonInterface
{
    private const TABLE = 'tx_nrllm_call_outcome';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function record(string $correlationId, CallOutcome $outcome): void
    {
        if ($correlationId === '') {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now        = time();

        $sameSource = array_map(
            static fn(CallOutcome $case): string => $case->value,
            array_filter(
                CallOutcome::cases(),
                static fn(CallOutcome $case): bool => $case->source() === $outcome->source(),
            ),
        );

        $query = $connection->createQueryBuilder();
        $query->delete(self::TABLE)
            ->where(
                $query->expr()->eq('correlation_id', $query->createNamedParameter($correlationId)),
                $query->expr()->in('outcome', $query->createNamedParameter($sameSource, Connection::PARAM_STR_ARRAY)),
            )
            ->executeStatement();

        $connection->insert(self::TABLE, [
            'pid'            => 0,
            'correlation_id' => $correlationId,
            'outcome'        => $outcome->value,
            'crdate'         => $now,
            'tstamp'         => $now,
        ]);
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
