<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Task;

use Closure;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\RunCompletionStoreInterface;
use Netresearch\NrLlm\Service\Tool\RunContentPrivacyGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The A2A task view and its result store (spec 051 A2A-R13, ADR-282).
 *
 * Stage 2 builds the store; the server role of stage 3 binds a task to the
 * run it creates with {@see self::bind()}.
 *
 * The result of a bound run is written in the transaction that completes the
 * run, under the run-row lock of ADR-270, with the answer ceiling read inside
 * that lock ({@see RunContentPrivacyGuard::withAnswerCeiling()}):
 *
 * - `full`: the answer is the task's one artifact, the task COMPLETED;
 * - `redacted`: the redacted answer, and the history redacted the same way;
 * - `metadata` or `none`: nothing is stored, the history is scrubbed, the run
 *   ends FAILED and the task FAILED with the reason RESULT_NOT_RETAINABLE.
 *
 * If the write fails, the transaction rolls back, so neither the result nor
 * the run's completion exists; the run and the task are then failed in a
 * transaction of their own. A COMPLETED task without its result cannot
 * exist. This holds for every path that completes a run, an approval's
 * continuation included.
 *
 * After the commit the projector writes the events ({@see A2aTaskProjector});
 * nothing here writes an event.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTaskStore implements RunCompletionStoreInterface
{
    public const TABLE = 'tx_nrllm_a2a_task';

    public function __construct(
        private ConnectionPool $connectionPool,
        private RunContentPrivacyGuard $privacy,
        private AgentStateCodec $codec,
        private A2aTaskProjector $projector,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Bind a new task to a run (stage 3 creates both in its acceptance
     * transaction, A2A-SV5). The history is kept at the run's answer level.
     *
     * @param list<array<string, mixed>> $history the client's messages, A2A JSON
     *
     * @return int the task row's uid
     */
    public function bind(int $runUid, string $taskId, string $contextId, int $ownerClientUid = 0, int $profileUid = 0, array $history = []): int
    {
        $connection = $this->connection();
        $now        = time();
        $connection->insert(self::TABLE, [
            'task_id'            => $taskId,
            'context_id'         => $contextId,
            'owner_client_uid'   => $ownerClientUid,
            'profile_uid'        => $profileUid,
            'run_uid'            => $runUid,
            'state'              => A2aTaskState::SUBMITTED->value,
            'status_timestamp'   => self::nowMillis(),
            'history'            => $this->seal($history === [] ? '' : $this->json($history)),
            'revision'           => 1,
            'projected_revision' => 0,
            'crdate'             => $now,
            'tstamp'             => $now,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function keeps(int $runUid): bool
    {
        return $runUid > 0 && $this->connection()->count('uid', self::TABLE, ['run_uid' => $runUid]) > 0;
    }

    public function complete(int $runUid, string $answer, Closure $completeRun, Closure $failRun): bool
    {
        try {
            $settled = $this->privacy->withAnswerCeiling(
                $runUid,
                fn (PrivacyLevel $ceiling): bool => $ceiling->persistsContent()
                    ? $this->completeWithResult($runUid, $answer, $ceiling, $completeRun)
                    : $this->failNotRetainable($runUid, A2aResultNotRetainableException::REASON, $failRun),
            );
        } catch (Throwable $e) {
            // Rolled back: neither the result nor the completion exists.
            $this->logger?->error('The result of an A2A task could not be stored; the run and the task are failed', ['run' => $runUid, 'exception' => $e]);
            $settled = $this->connection()->transactional(
                fn (): bool => $this->failNotRetainable($runUid, A2aResultNotRetainableException::WRITE_FAILED, $failRun),
            );
        }

        $this->project($runUid);

        return $settled;
    }

    /**
     * @param Closure(): bool $completeRun
     */
    private function completeWithResult(int $runUid, string $answer, PrivacyLevel $ceiling, Closure $completeRun): bool
    {
        if (!$completeRun()) {
            // Not this call's to settle (lease lost, already terminal): the
            // task is left as it is.
            return false;
        }

        $kept     = $this->privacy->keptAt($answer, $ceiling);
        $artifact = [['artifactId' => Uuid::v7()->toRfc4122(), 'parts' => [['text' => $kept]]]];

        $this->update($runUid, [
            'state'          => A2aTaskState::COMPLETED->value,
            'artifacts'      => $this->seal($this->json($artifact)),
            'history'        => $this->historyAt($runUid, $ceiling),
            'outcome_reason' => '',
        ]);

        return true;
    }

    /**
     * @param Closure(string): bool $failRun
     */
    private function failNotRetainable(int $runUid, string $reason, Closure $failRun): bool
    {
        if (!$failRun(A2aResultNotRetainableException::class)) {
            return false;
        }

        $this->update($runUid, [
            'state'          => A2aTaskState::FAILED->value,
            'artifacts'      => '',
            'history'        => '',
            'status_message' => $this->seal($this->json([
                'role'  => 'ROLE_AGENT',
                'parts' => [['text' => 'The result could not be kept under the privacy policy of this installation.']],
            ])),
            'outcome_reason' => $reason,
        ]);

        return true;
    }

    /**
     * The stored history at the completing ceiling: redacted with it, never
     * wider than when it was written.
     */
    private function historyAt(int $runUid, PrivacyLevel $ceiling): string
    {
        $stored = $this->connection()->select(['history'], self::TABLE, ['run_uid' => $runUid])->fetchOne();
        if (!is_string($stored) || $stored === '') {
            return '';
        }

        if ($ceiling === PrivacyLevel::FULL) {
            return $stored;
        }

        $decoded = json_decode($this->codec->decode($stored, AgentStateCodec::PURPOSE_A2A_TASK_CONTENT), true);

        return $this->seal($this->json($this->redact($decoded, $ceiling)));
    }

    private function redact(mixed $value, PrivacyLevel $ceiling): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $key === 'text' && is_string($item) ? $this->privacy->keptAt($item, $ceiling) : $this->redact($item, $ceiling);
        }

        return $value;
    }

    /**
     * One committed change of the task: the fields, a new status timestamp
     * and the next revision. The row must exist; anything else rolls the
     * completing transaction back.
     *
     * @param array<string, string> $fields
     */
    private function update(int $runUid, array $fields): void
    {
        $connection = $this->connection();
        $columns    = array_keys($fields);
        $affected   = $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier(self::TABLE) . ' SET '
                . implode(', ', array_map(static fn (string $column): string => $column . ' = ?', $columns))
                . ', status_timestamp = ?, tstamp = ?, revision = revision + 1 WHERE run_uid = ?',
            [...array_values($fields), self::nowMillis(), time(), $runUid],
            [...array_fill(0, count($columns), Connection::PARAM_STR), Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT],
        );
        if ($affected !== 1) {
            throw new A2aResultNotRetainableException(sprintf('The A2A task of run %d could not be updated.', $runUid), 1799200301);
        }
    }

    /**
     * After the commit only (A2A-R14): a failure here leaves the events to
     * the worker's next pass, from the committed state.
     */
    private function project(int $runUid): void
    {
        try {
            $uid = $this->connection()->select(['uid'], self::TABLE, ['run_uid' => $runUid])->fetchOne();
            if (is_numeric($uid)) {
                $this->projector->project((int) $uid);
            }
        } catch (Throwable $e) {
            $this->logger?->warning('The A2A task events could not be projected; the next pass retries', ['run' => $runUid, 'exception' => $e]);
        }
    }

    private function seal(string $plaintext): string
    {
        return $this->codec->encode($plaintext, AgentStateCodec::PURPOSE_A2A_TASK_CONTENT);
    }

    /**
     * @param array<array-key, mixed>|mixed $value
     */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function nowMillis(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
