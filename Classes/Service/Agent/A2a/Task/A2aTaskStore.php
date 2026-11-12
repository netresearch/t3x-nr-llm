<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Task;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Privacy\A2aContentRedaction;
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
    private const RUN_TABLE = 'tx_nrllm_agentrun';

    public const TABLE = 'tx_nrllm_a2a_task';

    public function __construct(
        private ConnectionPool $connectionPool,
        private RunContentPrivacyGuard $privacy,
        private AgentStateCodec $codec,
        private A2aTaskProjector $projector,
        private ?LoggerInterface $logger = null,
        private ?A2aArtifactShaperInterface $artifacts = null,
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
        // The history is content like the result: kept at the run's answer
        // level, read under the run-row lock (ADR-270, ADR-282). A later
        // tightening scrubs it, so it is never wider than the current level.
        return $this->privacy->withAnswerCeiling(
            $runUid,
            fn (PrivacyLevel $ceiling): int => $this->insertTask($runUid, $taskId, $contextId, $ownerClientUid, $profileUid, $history === [] || !$ceiling->persistsContent() ? '' : $this->json($this->redact($history, $ceiling))),
        );
    }

    private function insertTask(int $runUid, string $taskId, string $contextId, int $ownerClientUid, int $profileUid, string $history): int
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
            'status_timestamp'   => $this->nowMillis(),
            'history'            => $this->seal($history),
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
        if ($this->connection() !== $this->connectionPool->getConnectionForTable(self::RUN_TABLE)) {
            // The task and the run commit together only on one connection.
            throw new A2aException('An A2A task result requires the task and run tables on the same database connection.', 1799400420);
        }

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
     * Every other transition of a bound run reaches the task view here, in
     * the transition's transaction and under the run-row lock (A2A-SV10):
     * QUEUED is SUBMITTED, RUNNING and the waits a person or a dependency of
     * the installation decides are WORKING, a wait for the client's input is
     * INPUT_REQUIRED, FAILED is FAILED (REJECTED when a policy refused the
     * run before any worker took it, so the task never left SUBMITTED),
     * CANCELLED is CANCELED. A terminal task
     * never changes again, and COMPLETED is written by {@see self::complete()}
     * only, with its result.
     *
     * A terminal mirror keeps the history at the answer ceiling read under
     * the lock, as the completion does; a failure carries a fixed status text
     * that names no internal detail.
     */
    public function transition(int $runUid, Closure $transition): bool
    {
        if (!$this->keeps($runUid)) {
            return $transition();
        }

        $moved = $this->privacy->withAnswerCeiling(
            $runUid,
            function (PrivacyLevel $ceiling) use ($runUid, $transition): bool {
                if (!$transition()) {
                    return false;
                }

                $this->mirror($runUid, $ceiling);

                return true;
            },
        );

        if ($moved) {
            $this->project($runUid);
        }

        return $moved;
    }

    private function mirror(int $runUid, PrivacyLevel $ceiling): void
    {
        $connection = $this->connection();
        $run        = $connection->select(['status', 'termination_reason'], self::RUN_TABLE, ['uid' => $runUid])->fetchAssociative();
        $state      = $connection->select(['state'], self::TABLE, ['run_uid' => $runUid])->fetchOne();
        $current    = is_string($state) ? A2aTaskState::tryFrom($state) : null;
        if (!is_array($run) || !$current instanceof A2aTaskState || $current->isTerminal()) {
            return;
        }

        $target = self::stateOfRun(
            AgentRunStatus::tryFrom(is_string($run['status'] ?? null) ? $run['status'] : ''),
            is_string($run['termination_reason'] ?? null) ? $run['termination_reason'] : '',
            $current,
        );
        if (!$target instanceof A2aTaskState || $target === $current) {
            return;
        }

        $fields = ['state' => $target->value];
        if ($target->isTerminal()) {
            $fields['history'] = $ceiling->persistsContent() ? $this->historyAt($runUid, $ceiling) : '';
        }

        $statusText = match ($target) {
            A2aTaskState::FAILED   => 'The task failed.',
            A2aTaskState::REJECTED => 'The task was refused before it started.',
            default                => '',
        };
        $fields['status_message'] = $statusText === '' ? '' : $this->seal($this->json([
            'messageId' => Uuid::v7()->toRfc4122(),
            'role'      => 'ROLE_AGENT',
            'parts'     => [['text' => $statusText]],
        ]));

        $this->update($runUid, $fields);
    }

    /**
     * The task state that mirrors a run status; null where the task view is
     * not written by a mirror (COMPLETED, an unknown status).
     */
    public static function stateOfRun(?AgentRunStatus $status, string $terminationReason, A2aTaskState $current): ?A2aTaskState
    {
        return match ($status) {
            AgentRunStatus::QUEUED => A2aTaskState::SUBMITTED,
            AgentRunStatus::RUNNING,
            AgentRunStatus::WAITING_FOR_APPROVAL,
            AgentRunStatus::WAITING_FOR_DEPENDENCY => A2aTaskState::WORKING,
            AgentRunStatus::WAITING_FOR_INPUT      => A2aTaskState::INPUT_REQUIRED,
            AgentRunStatus::FAILED                 => $terminationReason === AgentRunTerminationReason::POLICY_DENIED->value && $current === A2aTaskState::SUBMITTED
                ? A2aTaskState::REJECTED
                : A2aTaskState::FAILED,
            AgentRunStatus::CANCELLED => A2aTaskState::CANCELED,
            default                   => null,
        };
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
        $artifact = $this->artifacts?->artifactsFor($this->profileOf($runUid), $kept)
            ?? [['artifactId' => Uuid::v7()->toRfc4122(), 'parts' => [['text' => $kept]]]];

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

    private function profileOf(int $runUid): int
    {
        $profile = $this->connection()->select(['profile_uid'], self::TABLE, ['run_uid' => $runUid])->fetchOne();

        return is_numeric($profile) ? (int) $profile : 0;
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

    /**
     * The content at the ceiling: as it is at `full`, by the allow-list of
     * {@see A2aContentRedaction} below it.
     */
    private function redact(mixed $value, PrivacyLevel $ceiling): mixed
    {
        if ($ceiling === PrivacyLevel::FULL) {
            return $value;
        }

        return A2aContentRedaction::redacted($value, fn (string $text): string => $this->privacy->keptAt($text, $ceiling));
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
            [...array_values($fields), $this->nowMillis(), time(), $runUid],
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

    private function nowMillis(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
