<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Writes and prunes provider pipeline telemetry rows (ADR-058).
 *
 * Uses the Doctrine ConnectionPool directly — the table is a UI-less append-only
 * log with no Extbase persistence needs, mirroring how UsageTrackerService
 * writes the usage table.
 */
final readonly class TelemetryRepository implements TelemetryRepositoryInterface, SingletonInterface
{
    private const TABLE = 'tx_nrllm_telemetry';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function record(TelemetryRecord $record): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'                      => 0,
            'correlation_id'           => $record->correlationId,
            'operation'                => $record->operation,
            'provider'                 => $record->provider,
            'model'                    => $record->model,
            'configuration_identifier' => $record->configurationIdentifier,
            'be_user'                  => $record->beUser,
            'success'                  => $record->success ? 1 : 0,
            'error_class'              => $record->errorClass,
            'latency_ms'               => $record->latencyMs,
            'cache_hit'                => $record->cacheHit ? 1 : 0,
            'fallback_attempts'        => $record->fallbackAttempts,
            'served_configuration_identifier' => $record->servedConfigurationIdentifier,
            'served_provider'          => $record->servedProvider,
            'served_model'             => $record->servedModel,
            'time_to_first_token_ms'   => $record->timeToFirstTokenMs,
            'crdate'                   => time(),
        ]);
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();

        return $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp)))
            ->executeStatement();
    }

    public function successRatePercent(int $since): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->addSelectLiteral('COUNT(uid) AS total', 'SUM(success) AS ok')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return 0;
        }

        $total = is_numeric($row['total'] ?? null) ? (int)$row['total'] : 0;
        $ok    = is_numeric($row['ok'] ?? null) ? (int)$row['ok'] : 0;

        return $total === 0 ? 0 : (int)round($ok * 100 / $total);
    }

    public function averageLatencyMs(int $since): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $avg = $queryBuilder
            ->addSelectLiteral('AVG(latency_ms) AS avg_latency')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($avg) ? (int)round((float)$avg) : 0;
    }

    public function recentFallbackHops(int $since, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select(
                'correlation_id',
                'operation',
                'configuration_identifier',
                'provider',
                'model',
                'served_configuration_identifier',
                'served_provider',
                'served_model',
                'success',
                'fallback_attempts',
                'latency_ms',
                'crdate',
            )
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('fallback_attempts', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // The swap itself, not just the attempt: '' is a row written
                // before these columns existed, and an identifier equal to the
                // requested one means the chain was exhausted with nobody
                // serving. Both are hops, neither is a rescue. They belong in
                // the query rather than only in the reader because $limit is
                // applied here: a two-hour outage writes thousands of
                // exhausted-chain rows, and filtering afterwards would let them
                // fill the window and report a period's real rescues as none.
                $queryBuilder->expr()->neq(
                    'served_configuration_identifier',
                    $queryBuilder->createNamedParameter('', Connection::PARAM_STR),
                ),
                $queryBuilder->expr()->neq(
                    'served_configuration_identifier',
                    $queryBuilder->quoteIdentifier('configuration_identifier'),
                ),
            )
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        $hops = [];
        foreach ($rows as $row) {
            $hops[] = new FallbackHop(
                correlationId: $this->str($row, 'correlation_id'),
                operation: $this->str($row, 'operation'),
                configurationIdentifier: $this->str($row, 'configuration_identifier'),
                provider: $this->str($row, 'provider'),
                model: $this->str($row, 'model'),
                servedConfigurationIdentifier: $this->str($row, 'served_configuration_identifier'),
                servedProvider: $this->str($row, 'served_provider'),
                servedModel: $this->str($row, 'served_model'),
                success: $this->int($row, 'success') === 1,
                fallbackAttempts: $this->int($row, 'fallback_attempts'),
                latencyMs: $this->int($row, 'latency_ms'),
                crdate: $this->int($row, 'crdate'),
            );
        }

        return $hops;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function str(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int)$value : 0;
    }
}
