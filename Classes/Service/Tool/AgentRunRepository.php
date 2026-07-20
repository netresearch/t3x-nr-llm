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
use Netresearch\NrLlm\Utility\SafeCastTrait;
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
    use SafeCastTrait;

    private const TABLE_RUN = 'tx_nrllm_agentrun';

    private const TABLE_EVENT = 'tx_nrllm_agentrun_event';

    /** Rows deleted per statement, so a neglected install purges in batches. */
    private const PURGE_CHUNK_SIZE = 500;

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
            'termination_reason'       => '',
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
        string $terminationReason = '',
    ): bool {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_RUN);
        $builder    = $connection->createQueryBuilder();
        $now        = time();

        // Guarded transition: only a non-terminal run may be settled. Without the
        // predicate a late callback could reopen or overwrite a finished run —
        // COMPLETED -> RUNNING -> COMPLETED with different totals (ADR-092).
        $affected = $builder
            ->update(self::TABLE_RUN)
            ->set('status', $status)
            ->set('iterations', $builder->createNamedParameter($iterations, Connection::PARAM_INT), false)
            ->set('truncated', $builder->createNamedParameter($truncated ? 1 : 0, Connection::PARAM_INT), false)
            ->set('total_prompt_tokens', $builder->createNamedParameter($promptTokens, Connection::PARAM_INT), false)
            ->set('total_completion_tokens', $builder->createNamedParameter($completionTokens, Connection::PARAM_INT), false)
            ->set('total_tokens', $builder->createNamedParameter($totalTokens, Connection::PARAM_INT), false)
            ->set('estimated_cost', $builder->createNamedParameter((string)$estimatedCost, Connection::PARAM_STR), false)
            ->set('error_class', $errorClass)
            ->set('termination_reason', $terminationReason)
            // A terminal run is no longer suspended.
            ->set('suspended_state', '')
            ->set('finished_at', $builder->createNamedParameter($now, Connection::PARAM_INT), false)
            ->set('tstamp', $builder->createNamedParameter($now, Connection::PARAM_INT), false)
            ->where(
                $builder->expr()->eq('uid', $builder->createNamedParameter($runUid, Connection::PARAM_INT)),
                $builder->expr()->in(
                    'status',
                    $builder->createNamedParameter(AgentRunStatus::nonTerminalValues(), Connection::PARAM_STR_ARRAY),
                ),
            )
            ->executeStatement();

        return $affected > 0;
    }

    public function suspendRun(int $runUid, string $stateJson): void
    {
        // Non-terminal transition (ADR-084): store the resumable state and move
        // the run to WAITING_FOR_APPROVAL without setting finished_at.
        $this->connectionPool->getConnectionForTable(self::TABLE_RUN)->update(
            self::TABLE_RUN,
            [
                'status'          => AgentRunStatus::WAITING_FOR_APPROVAL->value,
                'suspended_state' => $stateJson,
                'tstamp'          => time(),
            ],
            ['uid' => $runUid],
        );
    }

    /**
     * Atomically claim a suspended run for resume (ADR-084): move it off
     * WAITING_FOR_APPROVAL to RUNNING only if it is still awaiting approval, in a
     * single conditional UPDATE. Returns true for the caller that won the claim,
     * false if another resume already claimed it — so two concurrent Approve
     * requests cannot both execute the gated (destructive) tool.
     */
    public function claimForResume(int $runUid): bool
    {
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE_RUN)->update(
            self::TABLE_RUN,
            [
                'status' => AgentRunStatus::RUNNING->value,
                'tstamp' => time(),
            ],
            [
                'uid'    => $runUid,
                'status' => AgentRunStatus::WAITING_FOR_APPROVAL->value,
            ],
        );

        return $affected === 1;
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
        return $this->purgeRuns($timestamp, AgentRunStatus::terminalValues());
    }

    public function purgeUnfinishedOlderThan(int $timestamp): int
    {
        return $this->purgeRuns($timestamp, AgentRunStatus::nonTerminalValues());
    }

    /**
     * Delete runs created before the cutoff whose status is in the given set,
     * together with their events.
     *
     * Events go first and are addressed by run id: deleting each table
     * independently by crdate would orphan the events of a run that began before
     * the cutoff but recorded events after it. Both deletes are chunked so a
     * long-neglected installation does not build one unbounded IN() list.
     *
     * @param list<string> $statuses
     */
    private function purgeRuns(int $timestamp, array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }

        $runConnection = $this->connectionPool->getConnectionForTable(self::TABLE_RUN);
        $selectBuilder = $runConnection->createQueryBuilder();
        $rows          = $selectBuilder
            ->select('uid')
            ->from(self::TABLE_RUN)
            ->where(
                $selectBuilder->expr()->lt('crdate', $selectBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)),
                $selectBuilder->expr()->in('status', $selectBuilder->createNamedParameter($statuses, Connection::PARAM_STR_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $uids = array_map(fn(array $row): int => self::toInt($row['uid'] ?? 0), $rows);
        if ($uids === []) {
            return 0;
        }

        $eventConnection = $this->connectionPool->getConnectionForTable(self::TABLE_EVENT);
        $deleted         = 0;

        foreach (array_chunk($uids, self::PURGE_CHUNK_SIZE) as $chunk) {
            $eventBuilder = $eventConnection->createQueryBuilder();
            $eventBuilder
                ->delete(self::TABLE_EVENT)
                ->where($eventBuilder->expr()->in('run', $eventBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeStatement();

            $deleteBuilder = $runConnection->createQueryBuilder();
            $deleted += (int)$deleteBuilder
                ->delete(self::TABLE_RUN)
                ->where($deleteBuilder->expr()->in('uid', $deleteBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeStatement();
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRun(array $row): AgentRun
    {
        return new AgentRun(
            uid: self::toInt($row['uid'] ?? 0),
            uuid: self::toStr($row['uuid'] ?? ''),
            status: self::toStr($row['status'] ?? ''),
            configurationUid: self::toInt($row['configuration_uid'] ?? 0),
            configurationIdentifier: self::toStr($row['configuration_identifier'] ?? ''),
            beUser: self::toInt($row['be_user'] ?? 0),
            iterations: self::toInt($row['iterations'] ?? 0),
            truncated: self::toInt($row['truncated'] ?? 0) === 1,
            totalPromptTokens: self::toInt($row['total_prompt_tokens'] ?? 0),
            totalCompletionTokens: self::toInt($row['total_completion_tokens'] ?? 0),
            totalTokens: self::toInt($row['total_tokens'] ?? 0),
            estimatedCost: self::toFloat($row['estimated_cost'] ?? 0),
            errorClass: self::toStr($row['error_class'] ?? ''),
            terminationReason: self::toStr($row['termination_reason'] ?? ''),
            startedAt: self::toInt($row['started_at'] ?? 0),
            finishedAt: self::toInt($row['finished_at'] ?? 0),
            crdate: self::toInt($row['crdate'] ?? 0),
            suspendedState: $this->suspendedStateOf($row['suspended_state'] ?? null),
        );
    }

    /**
     * The stored suspended-state JSON, or null when the run is not suspended
     * (empty column) — distinct from a genuine payload.
     */
    private function suspendedStateOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
            uid: self::toInt($row['uid'] ?? 0),
            run: self::toInt($row['run'] ?? 0),
            sequence: self::toInt($row['sequence'] ?? 0),
            kind: self::toStr($row['kind'] ?? ''),
            round: self::toInt($row['round'] ?? 0),
            durationMs: self::toFloat($row['duration_ms'] ?? 0),
            payload: $payload,
            crdate: self::toInt($row['crdate'] ?? 0),
        );
    }

}
