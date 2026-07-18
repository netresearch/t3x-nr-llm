<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Writes and reads agent runs and their event streams (ADR-081).
 *
 * Uses the Doctrine ConnectionPool directly — both tables are UI-less logs with
 * no Extbase persistence needs, mirroring how {@see \Netresearch\NrLlm\Service\Telemetry\TelemetryRepository}
 * writes the telemetry table. A run row is inserted RUNNING, events are appended
 * as the loop progresses, and the row is updated once to its terminal state.
 */
final readonly class AgentRunRepository implements AgentRunRepositoryInterface, SingletonInterface
{
    private const TABLE_RUN = 'tx_nrllm_agentrun';

    private const TABLE_EVENT = 'tx_nrllm_agentrun_event';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function startRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser): int
    {
        $now        = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_RUN);
        $connection->insert(self::TABLE_RUN, [
            'pid'                      => 0,
            'uuid'                     => $uuid,
            'status'                   => AgentRunStatus::RUNNING->value,
            'configuration_uid'        => $configurationUid,
            'configuration_identifier' => $configurationIdentifier,
            'be_user'                  => $beUser,
            'iterations'               => 0,
            'truncated'                => 0,
            'total_prompt_tokens'      => 0,
            'total_completion_tokens'  => 0,
            'total_tokens'             => 0,
            'estimated_cost'           => 0.0,
            'error_class'              => '',
            'started_at'               => $now,
            'finished_at'              => 0,
            'tstamp'                   => $now,
            'crdate'                   => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    public function recordEvent(int $runUid, int $sequence, string $kind, int $round, float $durationMs, string $payloadJson): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_EVENT)->insert(self::TABLE_EVENT, [
            'pid'         => 0,
            'run'         => $runUid,
            'sequence'    => $sequence,
            'kind'        => $kind,
            'round'       => $round,
            'duration_ms' => $durationMs,
            'payload'     => $payloadJson,
            'crdate'      => time(),
        ]);
    }

    public function finishRun(
        int $runUid,
        string $status,
        int $iterations,
        bool $truncated,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        float $estimatedCost,
        string $errorClass,
    ): void {
        $this->connectionPool->getConnectionForTable(self::TABLE_RUN)->update(
            self::TABLE_RUN,
            [
                'status'                  => $status,
                'iterations'              => $iterations,
                'truncated'               => $truncated ? 1 : 0,
                'total_prompt_tokens'     => $promptTokens,
                'total_completion_tokens' => $completionTokens,
                'total_tokens'            => $totalTokens,
                'estimated_cost'          => $estimatedCost,
                'error_class'             => $errorClass,
                'finished_at'             => time(),
                'tstamp'                  => time(),
            ],
            ['uid' => $runUid],
        );
    }

    public function findByUuid(string $uuid): ?AgentRun
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_RUN);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE_RUN)
            ->where($queryBuilder->expr()->eq('uuid', $queryBuilder->createNamedParameter($uuid)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->hydrateRun($row);
    }

    public function findEvents(int $runUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_EVENT);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_EVENT)
            ->where($queryBuilder->expr()->eq('run', $queryBuilder->createNamedParameter($runUid, Connection::PARAM_INT)))
            ->orderBy('sequence', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrateEvent(...), $rows);
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $eventConnection = $this->connectionPool->getConnectionForTable(self::TABLE_EVENT);
        $eventBuilder    = $eventConnection->createQueryBuilder();
        $eventBuilder
            ->delete(self::TABLE_EVENT)
            ->where($eventBuilder->expr()->lt('crdate', $eventBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeStatement();

        $runConnection = $this->connectionPool->getConnectionForTable(self::TABLE_RUN);
        $runBuilder    = $runConnection->createQueryBuilder();

        return (int)$runBuilder
            ->delete(self::TABLE_RUN)
            ->where($runBuilder->expr()->lt('crdate', $runBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRun(array $row): AgentRun
    {
        return new AgentRun(
            uid: $this->intOf($row['uid'] ?? 0),
            uuid: $this->stringOf($row['uuid'] ?? ''),
            status: $this->stringOf($row['status'] ?? ''),
            configurationUid: $this->intOf($row['configuration_uid'] ?? 0),
            configurationIdentifier: $this->stringOf($row['configuration_identifier'] ?? ''),
            beUser: $this->intOf($row['be_user'] ?? 0),
            iterations: $this->intOf($row['iterations'] ?? 0),
            truncated: $this->intOf($row['truncated'] ?? 0) === 1,
            totalPromptTokens: $this->intOf($row['total_prompt_tokens'] ?? 0),
            totalCompletionTokens: $this->intOf($row['total_completion_tokens'] ?? 0),
            totalTokens: $this->intOf($row['total_tokens'] ?? 0),
            estimatedCost: $this->floatOf($row['estimated_cost'] ?? 0),
            errorClass: $this->stringOf($row['error_class'] ?? ''),
            startedAt: $this->intOf($row['started_at'] ?? 0),
            finishedAt: $this->intOf($row['finished_at'] ?? 0),
            crdate: $this->intOf($row['crdate'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateEvent(array $row): AgentRunEvent
    {
        $payload = [];
        $raw     = $row['payload'] ?? '';
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $payload = $decoded;
            }
        }

        return new AgentRunEvent(
            uid: $this->intOf($row['uid'] ?? 0),
            run: $this->intOf($row['run'] ?? 0),
            sequence: $this->intOf($row['sequence'] ?? 0),
            kind: $this->stringOf($row['kind'] ?? ''),
            round: $this->intOf($row['round'] ?? 0),
            durationMs: $this->floatOf($row['duration_ms'] ?? 0),
            payload: $payload,
            crdate: $this->intOf($row['crdate'] ?? 0),
        );
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private function floatOf(mixed $value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
