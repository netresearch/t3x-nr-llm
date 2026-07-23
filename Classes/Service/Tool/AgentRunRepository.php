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
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
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

    public function enqueueRun(string $uuid, int $configurationUid, string $configurationIdentifier, int $beUser, string $requestJson): int
    {
        $now        = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_RUN);
        $connection->insert(self::TABLE_RUN, [
            'pid'                      => 0,
            'uuid'                     => $uuid,
            // QUEUED, not RUNNING: the run waits for a worker's atomic claim
            // (ADR-102). started_at stays 0 until the claim.
            'status'                   => AgentRunStatus::QUEUED->value,
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
            'queued_request'           => $requestJson,
            'started_at'               => 0,
            'finished_at'              => 0,
            'tstamp'                   => $now,
            'crdate'                   => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * Atomically claim a queued run for execution (ADR-102): move it off QUEUED
     * to RUNNING only if it is still queued, in a single conditional UPDATE —
     * the same optimistic idiom as {@see claimForResume()}. Returns true for
     * the worker that won; false when another worker already claimed it or the
     * run was cancelled while waiting.
     */
    public function claimQueued(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE_RUN)->update(
            self::TABLE_RUN,
            [
                'status'        => AgentRunStatus::RUNNING->value,
                'claimed_by'    => $claimedBy,
                'lease_expires' => $leaseExpires,
                'started_at'    => time(),
                'tstamp'        => time(),
            ],
            [
                'uid'    => $runUid,
                'status' => AgentRunStatus::QUEUED->value,
            ],
        );

        return $affected === 1;
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
            // A terminal run is no longer suspended, queued or leased.
            ->set('suspended_state', '')
            ->set('queued_request', '')
            ->set('claimed_by', '')
            ->set('lease_expires', $builder->createNamedParameter(0, Connection::PARAM_INT), false)
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

    public function suspendRun(int $runUid, string $stateJson): bool
    {
        return $this->conditionalSuspend($runUid, $stateJson, AgentRunStatus::WAITING_FOR_APPROVAL);
    }

    public function suspendRunForInput(int $runUid, string $stateJson): bool
    {
        return $this->conditionalSuspend($runUid, $stateJson, AgentRunStatus::WAITING_FOR_INPUT);
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
        return $this->conditionalClaim($runUid, AgentRunStatus::WAITING_FOR_APPROVAL);
    }

    public function claimForResumeFromInput(int $runUid): bool
    {
        return $this->conditionalClaim($runUid, AgentRunStatus::WAITING_FOR_INPUT);
    }

    /**
     * Shared body for the suspend transitions (ADR-084 approval / ADR-105 input):
     * store the resumable state and move the run RUNNING -> $target without
     * setting finished_at. Guarded on the run still being RUNNING (ADR-101): a
     * concurrent cancel wins the terminal transition first, and an unguarded
     * suspend would resurrect the CANCELLED row into a waiting state — offering a
     * resume for a run the operator was told was stopped. The lease is cleared:
     * a run waiting on a human is not "presumed executing" (ADR-102), so the
     * reaper must not see it.
     */
    private function conditionalSuspend(int $runUid, string $stateJson, AgentRunStatus $target): bool
    {
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE_RUN)->update(
            self::TABLE_RUN,
            [
                'status'          => $target->value,
                'suspended_state' => $stateJson,
                'claimed_by'      => '',
                'lease_expires'   => 0,
                'tstamp'          => time(),
            ],
            [
                'uid'    => $runUid,
                'status' => AgentRunStatus::RUNNING->value,
            ],
        );

        return $affected === 1;
    }

    /**
     * Shared body for the resume claims (ADR-084 approval / ADR-105 input): move
     * the run $from -> RUNNING only if it is still in $from, in a single
     * conditional UPDATE — the atomic mutual-exclusion gate so two concurrent
     * resume requests cannot both execute the pending turn. The lease is left
     * clear: the resume runs in the acting process, not under a worker lease.
     */
    private function conditionalClaim(int $runUid, AgentRunStatus $from): bool
    {
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE_RUN)->update(
            self::TABLE_RUN,
            [
                'status'        => AgentRunStatus::RUNNING->value,
                'claimed_by'    => '',
                'lease_expires' => 0,
                'tstamp'        => time(),
            ],
            [
                'uid'    => $runUid,
                'status' => $from->value,
            ],
        );

        return $affected === 1;
    }

    /**
     * Extend the lease on a running run this worker owns (ADR-104 heartbeat).
     * Guarded on both status = running AND claimed_by = the caller: a run the
     * reaper reclaimed (claimed_by cleared, or another worker's identity) or a
     * cancel/settle terminated updates zero rows, which is the worker's signal
     * that it has lost ownership and must stop before executing further.
     */
    public function renewLease(int $runUid, string $claimedBy, int $leaseExpires): bool
    {
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE_RUN)->update(
            self::TABLE_RUN,
            [
                'lease_expires' => $leaseExpires,
                'tstamp'        => time(),
            ],
            [
                'uid'        => $runUid,
                'status'     => AgentRunStatus::RUNNING->value,
                'claimed_by' => $claimedBy,
            ],
        );

        if ($affected >= 1) {
            return true;
        }

        // MySQL/MariaDB report zero *changed* rows for a no-op UPDATE — two
        // heartbeats in the same wall-clock second write an identical
        // lease_expires and tstamp, so the second changes nothing even though
        // the row still matches and the worker still owns it. Zero affected
        // rows therefore does NOT prove lost ownership; confirm with an explicit
        // ownership re-check so a healthy heartbeat is never misread as a lost
        // lease (which would abandon the run RUNNING until the reaper requeues).
        return $this->ownsRunningRun($runUid, $claimedBy);
    }

    /**
     * Whether the run still exists RUNNING under this worker's claim — the
     * ownership predicate {@see renewLease()} uses to tell a no-op UPDATE
     * (nothing changed) apart from a lost lease (nothing matched). The reaper
     * clears claimed_by on requeue, so a reclaimed run fails this check.
     */
    private function ownsRunningRun(int $runUid, string $claimedBy): bool
    {
        $builder = $this->connectionPool->getConnectionForTable(self::TABLE_RUN)->createQueryBuilder();

        $count = $builder
            ->count('uid')
            ->from(self::TABLE_RUN)
            ->where(
                $builder->expr()->eq('uid', $builder->createNamedParameter($runUid, Connection::PARAM_INT)),
                $builder->expr()->eq('status', $builder->createNamedParameter(AgentRunStatus::RUNNING->value)),
                $builder->expr()->eq('claimed_by', $builder->createNamedParameter($claimedBy)),
            )
            ->executeQuery()
            ->fetchOne();

        return self::toInt($count) === 1;
    }

    /**
     * Put a running run this worker owns back on the queue for a retry (ADR-104
     * failure retry). Ownership-guarded (status = running AND claimed_by = the
     * caller) so a zombie worker cannot requeue — and thereby double-execute — a
     * run another worker already owns. Increments requeue_count, clears the
     * claim and lease; queued_request stays on the row for re-execution. Returns
     * true when this worker still owned the run and the requeue took effect.
     */
    public function requeue(int $runUid, string $claimedBy): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_RUN);
        $builder    = $connection->createQueryBuilder();

        $affected = $this
            ->applyRequeueSet($builder)
            ->where(
                $builder->expr()->eq('uid', $builder->createNamedParameter($runUid, Connection::PARAM_INT)),
                $builder->expr()->eq('status', $builder->createNamedParameter(AgentRunStatus::RUNNING->value)),
                $builder->expr()->eq('claimed_by', $builder->createNamedParameter($claimedBy)),
            )
            ->executeStatement();

        return $affected > 0;
    }

    /**
     * Running runs whose lease has expired (ADR-104 stale-run reaper). The
     * lease_expires > 0 predicate excludes interactive run()/approve() runs,
     * which never take a lease (claimed_by/lease stay empty) — the reaper only
     * reclaims abandoned queue workers, never a live foreground call.
     *
     * @return list<AgentRun>
     */
    public function findStaleRunning(int $now, int $limit = 50): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_RUN);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_RUN)
            ->where(
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(AgentRunStatus::RUNNING->value)),
                $queryBuilder->expr()->gt('lease_expires', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('lease_expires', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
            )
            ->orderBy('lease_expires', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrateRun(...), $rows);
    }

    /**
     * Reclaim a stale running run back onto the queue (ADR-104 reaper). Unlike
     * {@see requeue()} this is not ownership-guarded — the reaper reclaims on
     * behalf of no worker — but it re-checks staleness inside the UPDATE
     * (lease_expires still > 0 and < now): a heartbeat renewal that lands
     * between the reaper's SELECT and this UPDATE moves lease_expires forward,
     * the predicate no longer matches, zero rows change and the reaper skips it.
     * Increments requeue_count, clears the claim and lease.
     */
    public function requeueStale(int $runUid, int $now): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_RUN);
        $builder    = $connection->createQueryBuilder();

        $affected = $this
            ->applyRequeueSet($builder)
            ->where(...$this->stalePredicates($builder, $runUid, $now))
            ->executeStatement();

        return $affected > 0;
    }

    /**
     * Dead-letter a stale running run whose requeue budget is spent (ADR-104
     * reaper): a terminal FAILED with the given reason. Staleness-guarded like
     * {@see requeueStale()} — the same lease_expires re-check inside the UPDATE,
     * so a heartbeat renewal that lands between the reaper's SELECT and this
     * write moves the lease forward, the predicate no longer matches, and a
     * still-live run is never flipped to FAILED. Totals are zeroed, matching the
     * failure convention of {@see finishRun()}.
     */
    public function deadLetterStale(int $runUid, int $now, string $terminationReason): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_RUN);
        $builder    = $connection->createQueryBuilder();
        $timestamp  = time();

        $affected = $builder
            ->update(self::TABLE_RUN)
            ->set('status', $builder->createNamedParameter(AgentRunStatus::FAILED->value), false)
            ->set('termination_reason', $builder->createNamedParameter($terminationReason), false)
            ->set('error_class', $builder->createNamedParameter(''), false)
            ->set('suspended_state', $builder->createNamedParameter(''), false)
            ->set('queued_request', $builder->createNamedParameter(''), false)
            ->set('claimed_by', $builder->createNamedParameter(''), false)
            ->set('lease_expires', $builder->createNamedParameter(0, Connection::PARAM_INT), false)
            ->set('finished_at', $builder->createNamedParameter($timestamp, Connection::PARAM_INT), false)
            ->set('tstamp', $builder->createNamedParameter($timestamp, Connection::PARAM_INT), false)
            ->where(...$this->stalePredicates($builder, $runUid, $now))
            ->executeStatement();

        return $affected > 0;
    }

    /**
     * The WHERE guard both reaper mutations share (ADR-104): a specific run that
     * is RUNNING and whose lease has expired (lease_expires > 0 excludes
     * interactive runs, < now is the staleness re-check). Returned as an
     * expression list so the caller spreads it into ->where().
     *
     * @return list<string>
     */
    private function stalePredicates(QueryBuilder $builder, int $runUid, int $now): array
    {
        return [
            $builder->expr()->eq('uid', $builder->createNamedParameter($runUid, Connection::PARAM_INT)),
            $builder->expr()->eq('status', $builder->createNamedParameter(AgentRunStatus::RUNNING->value)),
            $builder->expr()->gt('lease_expires', $builder->createNamedParameter(0, Connection::PARAM_INT)),
            $builder->expr()->lt('lease_expires', $builder->createNamedParameter($now, Connection::PARAM_INT)),
        ];
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

    public function findAwaiting(int $limit = 100): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_RUN);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_RUN)
            ->where(
                $queryBuilder->expr()->in(
                    'status',
                    $queryBuilder->createNamedParameter(AgentRunStatus::awaitingValues(), Connection::PARAM_STR_ARRAY),
                ),
            )
            // Oldest first: act on the longest-waiting run first. The
            // status_lookup(status, crdate) index serves the status filter;
            // ordering across the two waiting values is a cheap filesort at this
            // limit.
            ->orderBy('crdate', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrateRun(...), $rows);
    }

    public function findRecentTerminal(int $limit = 20): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_RUN);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_RUN)
            ->where(
                $queryBuilder->expr()->in(
                    'status',
                    $queryBuilder->createNamedParameter(AgentRunStatus::terminalValues(), Connection::PARAM_STR_ARRAY),
                ),
            )
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrateRun(...), $rows);
    }

    public function findEvents(int $runUid, int $afterSequence = -1): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_EVENT);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_EVENT)
            ->where(
                $queryBuilder->expr()->eq('run', $queryBuilder->createNamedParameter($runUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('sequence', $queryBuilder->createNamedParameter($afterSequence, Connection::PARAM_INT)),
            )
            ->orderBy('sequence', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrateEvent(...), $rows);
    }

    public function maxEventSequence(int $runUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_EVENT);
        $queryBuilder->getRestrictions()->removeAll();

        $max = $queryBuilder
            ->selectLiteral('MAX(sequence) AS max_sequence')
            ->from(self::TABLE_EVENT)
            ->where($queryBuilder->expr()->eq('run', $queryBuilder->createNamedParameter($runUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        // MAX() over zero rows is NULL — a run with no events yet.
        return is_numeric($max) ? (int)$max : -1;
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

        $runConnection   = $this->connectionPool->getConnectionForTable(self::TABLE_RUN);
        $eventConnection = $this->connectionPool->getConnectionForTable(self::TABLE_EVENT);
        $deleted         = 0;

        // Select AND delete in bounded chunks. Materialising every matching uid
        // up front would exhaust memory on exactly the long-neglected install
        // the chunking exists to protect (millions of rows accrued because the
        // purge never ran). Each pass reads at most one chunk of ids; the just
        // deleted rows drop out of the next pass's WHERE, so no OFFSET is needed
        // and the loop terminates when a short (or empty) chunk comes back.
        do {
            $selectBuilder = $runConnection->createQueryBuilder();
            $rows          = $selectBuilder
                ->select('uid')
                ->from(self::TABLE_RUN)
                ->where(
                    $selectBuilder->expr()->lt('crdate', $selectBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)),
                    $selectBuilder->expr()->in('status', $selectBuilder->createNamedParameter($statuses, Connection::PARAM_STR_ARRAY)),
                )
                ->orderBy('uid')
                ->setMaxResults(self::PURGE_CHUNK_SIZE)
                ->executeQuery()
                ->fetchAllAssociative();

            $chunk = array_map(fn(array $row): int => self::toInt($row['uid'] ?? 0), $rows);
            if ($chunk === []) {
                break;
            }

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
        } while (count($chunk) === self::PURGE_CHUNK_SIZE);

        return $deleted;
    }

    /**
     * The SET clause shared by both requeue paths (ADR-104): back to QUEUED,
     * requeue_count incremented, claim and lease cleared, tstamp bumped. The
     * caller adds the WHERE guard that distinguishes an ownership requeue
     * ({@see requeue()}) from a staleness reclaim ({@see requeueStale()}).
     * queued_request is deliberately left untouched — the stored request is what
     * the re-dispatched worker re-executes.
     */
    private function applyRequeueSet(QueryBuilder $builder): QueryBuilder
    {
        $now = time();

        return $builder
            ->update(self::TABLE_RUN)
            ->set('status', $builder->createNamedParameter(AgentRunStatus::QUEUED->value), false)
            ->set('requeue_count', 'requeue_count + 1', false)
            ->set('claimed_by', $builder->createNamedParameter(''), false)
            ->set('lease_expires', $builder->createNamedParameter(0, Connection::PARAM_INT), false)
            ->set('tstamp', $builder->createNamedParameter($now, Connection::PARAM_INT), false);
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
            queuedRequest: $this->suspendedStateOf($row['queued_request'] ?? null),
            claimedBy: self::toStr($row['claimed_by'] ?? ''),
            leaseExpires: self::toInt($row['lease_expires'] ?? 0),
            requeueCount: self::toInt($row['requeue_count'] ?? 0),
        );
    }

    /**
     * The stored payload JSON (suspended state / queued request), or null when
     * the column is empty — distinct from a genuine payload.
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
