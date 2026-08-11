<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Telemetry;

use Netresearch\NrLlm\Domain\ValueObject\RequestComplexity;
use Netresearch\NrLlm\Domain\ValueObject\RoutingSummary;
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
            ...$this->routingColumns($record->routingSummary),
            ...$this->complexityColumns($record->complexity),
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

    /**
     * The routing group (ADR-156). A run that chose nothing writes the
     * "no decision" shape — an empty mode — rather than zeros, which would read
     * as a decision that considered no candidates.
     *
     * @return array<string, int|string>
     */
    private function routingColumns(?RoutingSummary $routing): array
    {
        if (!$routing instanceof RoutingSummary) {
            return [
                'routing_policy_mode'    => '',
                'routing_candidates'     => 0,
                'routing_rejections'     => '',
                'routing_signal_quality' => 0,
                'routing_signal_health'  => 0,
                'routing_signal_cost'    => 0,
            ];
        }

        return [
            'routing_policy_mode' => $routing->policyMode,
            'routing_candidates'  => $routing->candidateCount,
            // Truncated to the column width rather than left to the database to
            // reject: a telemetry write must never be the thing that fails a
            // call, and a clipped reason list is still a usable one. The
            // reasons are sorted, so what is kept is stable across rows.
            'routing_rejections'     => substr(implode(',', $routing->rejectionReasons), 0, 255),
            'routing_signal_quality' => $routing->qualitySignalUsed ? 1 : 0,
            'routing_signal_health'  => $routing->healthSignalUsed ? 1 : 0,
            'routing_signal_cost'    => $routing->costSignalUsed ? 1 : 0,
        ];
    }

    /**
     * The complexity group (ADR-156). Observation only; an unmeasured run
     * writes an empty shape, and the two figures that need a context fit stay
     * SQL NULL rather than becoming a zero nobody measured.
     *
     * @return array<string, int|string|null>
     */
    private function complexityColumns(?RequestComplexity $complexity): array
    {
        if (!$complexity instanceof RequestComplexity) {
            return [
                'complexity_score'           => 0,
                'complexity_payload_bytes'   => 0,
                'complexity_tokens'          => null,
                'complexity_tools'           => 0,
                'complexity_context_percent' => null,
                'complexity_shape'           => '',
            ];
        }

        return [
            'complexity_score'           => $complexity->score,
            'complexity_payload_bytes'   => $complexity->payloadBytes,
            'complexity_tokens'          => $complexity->tokenEstimate,
            'complexity_tools'           => $complexity->toolCount,
            'complexity_context_percent' => $complexity->contextPercent,
            'complexity_shape'           => $complexity->shape,
        ];
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

    public function findByCorrelation(string $correlationId): array
    {
        // '' is the "no trace" marker every write point uses when it has none;
        // selecting on it would return unrelated calls as if they were one run.
        if ($correlationId === '') {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select(
                'operation',
                'provider',
                'model',
                'served_provider',
                'served_model',
                'success',
                'error_class',
                'latency_ms',
                'cache_hit',
                'fallback_attempts',
                'time_to_first_token_ms',
                'crdate',
            )
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'correlation_id',
                    $queryBuilder->createNamedParameter($correlationId, Connection::PARAM_STR),
                ),
            )
            ->orderBy('crdate', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $calls = [];
        foreach ($rows as $row) {
            $ttft = $row['time_to_first_token_ms'] ?? null;

            $calls[] = new TelemetryCall(
                operation: $this->str($row, 'operation'),
                provider: $this->str($row, 'provider'),
                model: $this->str($row, 'model'),
                servedProvider: $this->str($row, 'served_provider'),
                servedModel: $this->str($row, 'served_model'),
                success: $this->int($row, 'success') === 1,
                errorClass: $this->str($row, 'error_class'),
                latencyMs: $this->int($row, 'latency_ms'),
                cacheHit: $this->int($row, 'cache_hit') === 1,
                fallbackAttempts: $this->int($row, 'fallback_attempts'),
                // NULL is a non-streamed call, which is not the same as 0 ms.
                timeToFirstTokenMs: is_numeric($ttft) ? (int)$ttft : null,
                crdate: $this->int($row, 'crdate'),
            );
        }

        return $calls;
    }

    public function recentRoutedCalls(int $since, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select(
                'correlation_id',
                'operation',
                'configuration_identifier',
                'served_model',
                'success',
                'fallback_attempts',
                'latency_ms',
                'routing_policy_mode',
                'routing_candidates',
                'routing_rejections',
                'routing_signal_quality',
                'routing_signal_health',
                'routing_signal_cost',
                'complexity_score',
                'complexity_payload_bytes',
                'complexity_tokens',
                'complexity_tools',
                'complexity_context_percent',
                'complexity_shape',
                'crdate',
            )
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
                // The narrowing that makes this a ROUTED call: an empty mode is
                // a run that chose nothing — fixed mode, or a path with no
                // configuration — and also every row written before these
                // columns existed. Filtering in the query rather than in the
                // reader matters because $limit is applied here: an
                // installation whose traffic is mostly fixed-mode would
                // otherwise fill the window with rows the page then drops, and
                // report a period's real decisions as none. Same reasoning as
                // recentFallbackHops().
                $queryBuilder->expr()->neq(
                    'routing_policy_mode',
                    $queryBuilder->createNamedParameter('', Connection::PARAM_STR),
                ),
            )
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        $calls = [];
        foreach ($rows as $row) {
            $calls[] = new RoutedCall(
                correlationId: $this->str($row, 'correlation_id'),
                operation: $this->str($row, 'operation'),
                configurationIdentifier: $this->str($row, 'configuration_identifier'),
                servedModel: $this->str($row, 'served_model'),
                success: $this->int($row, 'success') === 1,
                fallbackAttempts: $this->int($row, 'fallback_attempts'),
                latencyMs: $this->int($row, 'latency_ms'),
                policyMode: $this->str($row, 'routing_policy_mode'),
                candidateCount: $this->int($row, 'routing_candidates'),
                rejectionReasons: $this->csv($row, 'routing_rejections'),
                qualitySignalUsed: $this->int($row, 'routing_signal_quality') === 1,
                healthSignalUsed: $this->int($row, 'routing_signal_health') === 1,
                costSignalUsed: $this->int($row, 'routing_signal_cost') === 1,
                complexityScore: $this->int($row, 'complexity_score'),
                payloadBytes: $this->int($row, 'complexity_payload_bytes'),
                complexityTokens: $this->nullableInt($row, 'complexity_tokens'),
                toolCount: $this->int($row, 'complexity_tools'),
                contextPercent: $this->nullableInt($row, 'complexity_context_percent'),
                shape: $this->str($row, 'complexity_shape'),
                crdate: $this->int($row, 'crdate'),
            );
        }

        return $calls;
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

    /**
     * A nullable integer column: SQL NULL stays null rather than becoming 0.
     * "No context fit ran" and "the fit estimated nothing" are different facts.
     *
     * @param array<string, mixed> $row
     */
    private function nullableInt(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function csv(array $row, string $column): array
    {
        $value = $this->str($row, $column);

        return $value === '' ? [] : explode(',', $value);
    }
}
