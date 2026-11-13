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
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
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
    public const TABLE = 'tx_nrllm_a2a_task';

    private const RUN_TABLE = 'tx_nrllm_agentrun';

    /** The messages a task's history keeps (spec 051 A2A-SEC6). */
    public const MAX_HISTORY = 100;

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

    /**
     * Add a client's later message to the task's history (spec 051 A2A-MT3),
     * at the run's answer level read under the run-row lock, like the first
     * message ({@see self::bind()}). A level that keeps no content keeps no
     * history at all. The history keeps the most recent
     * {@see self::MAX_HISTORY} messages.
     *
     * @param array<string, mixed> $entry the message, A2A JSON
     */
    public function appendHistory(int $runUid, array $entry): void
    {
        $this->privacy->withAnswerCeiling($runUid, function (PrivacyLevel $ceiling) use ($runUid, $entry): void {
            $connection = $this->connection();
            $history    = [];
            $stored     = $connection->select(['history'], self::TABLE, ['run_uid' => $runUid])->fetchOne();
            if ($stored === false) {
                throw new A2aResultNotRetainableException(sprintf('The A2A task of run %d does not exist.', $runUid), 1799400201);
            }

            if ($ceiling->persistsContent() && is_string($stored) && $stored !== '') {
                $decoded = json_decode($this->codec->decode($stored, AgentStateCodec::PURPOSE_A2A_TASK_CONTENT), true);
                $history = is_array($decoded) ? array_values($decoded) : [];
            }

            if ($ceiling->persistsContent()) {
                $history[] = $this->redact($entry, $ceiling);
                $history   = array_slice($history, -self::MAX_HISTORY);
            }

            // The row was read above; MariaDB counts changed rows only, so an
            // unchanged empty history is no failure.
            $connection->update(
                self::TABLE,
                ['history' => $history === [] ? '' : $this->seal($this->json($history)), 'tstamp' => time()],
                ['run_uid' => $runUid],
            );
        });
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
        $run        = $connection->select(['status', 'termination_reason', 'suspended_state'], self::RUN_TABLE, ['uid' => $runUid])->fetchAssociative();
        $state      = $connection->select(['state'], self::TABLE, ['run_uid' => $runUid])->fetchOne();
        $current    = is_string($state) ? A2aTaskState::tryFrom($state) : null;
        if (!is_array($run) || !$current instanceof A2aTaskState || $current->isTerminal()) {
            return;
        }

        $status    = AgentRunStatus::tryFrom(is_string($run['status'] ?? null) ? $run['status'] : '');
        $suspended = is_string($run['suspended_state'] ?? null) ? $run['suspended_state'] : '';
        $target    = self::stateOfRun(
            $status,
            is_string($run['termination_reason'] ?? null) ? $run['termination_reason'] : '',
            $current,
            // A QUEUED run that keeps a suspended state carries a stored
            // decision (ADR-281): the task was answered and goes on.
            $status === AgentRunStatus::QUEUED && $suspended !== '',
        );
        if (!$target instanceof A2aTaskState || $target === $current) {
            return;
        }

        $fields = ['state' => $target->value];
        if ($target->isTerminal()) {
            $fields['history'] = $ceiling->persistsContent() ? $this->historyAt($runUid, $ceiling) : '';
        }

        $statusText = match ($target) {
            A2aTaskState::FAILED         => 'The task failed.',
            A2aTaskState::REJECTED       => 'The task was refused before it started.',
            A2aTaskState::INPUT_REQUIRED => $this->question($suspended, $ceiling),
            default                      => '',
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
    public static function stateOfRun(?AgentRunStatus $status, string $terminationReason, A2aTaskState $current, bool $continuation = false): ?A2aTaskState
    {
        return match ($status) {
            AgentRunStatus::QUEUED => $continuation ? A2aTaskState::WORKING : A2aTaskState::SUBMITTED,
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
     * The status message of INPUT_REQUIRED (spec 051 A2A-MT1): the question
     * the input tool asks, and the answers its schema allows, kept at the
     * answer ceiling like every content the task view holds. A ceiling that
     * keeps no content, or a pause that cannot be read, leaves a fixed text.
     */
    private function question(string $sealedState, PrivacyLevel $ceiling): string
    {
        $fallback = 'The task needs an answer before it can go on.';
        if ($sealedState === '' || !$ceiling->persistsContent()) {
            return $fallback;
        }

        try {
            $decoded = json_decode($this->codec->decode($sealedState, AgentStateCodec::PURPOSE_SUSPENDED_STATE), true);
        } catch (Throwable) {
            return $fallback;
        }

        if (!is_array($decoded)) {
            return $fallback;
        }

        /** @var array<string, mixed> $decoded */
        $state    = SuspendedRunState::fromArray($decoded);
        $question = $this->questionOf($state);
        if ($question === '') {
            $question = A2aInputMapping::title($state->inputSchema);
        }

        $answers = A2aInputMapping::allowedAnswers($state->inputSchema);
        $fields  = A2aInputMapping::fieldHint($state->inputSchema);
        $text    = ($question !== '' ? $question : $fallback)
            . ($answers !== [] ? "\nAnswer with one of: " . implode(' | ', $answers) : '')
            . ($fields !== '' ? "\n" . $fields : '');

        return $this->privacy->keptAt($text, $ceiling);
    }

    /**
     * The question of the call the stored schema belongs to. One turn can hold
     * several calls of the input tool; the run suspended on the one whose id
     * the state names, and only a state stored before that id existed falls
     * back to the first call of the tool.
     */
    private function questionOf(SuspendedRunState $state): string
    {
        foreach ($state->pendingCalls as $raw) {
            $call = ToolCall::tryFromArray($raw);
            if (!$call instanceof ToolCall || $call->name !== $state->inputToolName || !is_string($call->arguments['question'] ?? null)) {
                continue;
            }

            if ($state->inputCallId === '' || $call->id === $state->inputCallId) {
                return trim($call->arguments['question']);
            }
        }

        return '';
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
        $writes = $this->writesArtifact($runUid, $this->profileMayWrite($runUid));
        if ($writes !== null) {
            $artifact[] = $writes;
        }

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

    private function profileMayWrite(int $runUid): bool
    {
        $mayWrite = $this->connection()->executeQuery(
            'SELECT p.may_write FROM ' . self::TABLE . ' t INNER JOIN tx_nrllm_a2a_profile p ON p.uid = t.profile_uid WHERE t.run_uid = ?',
            [$runUid],
            [Connection::PARAM_INT],
        )->fetchOne();

        return is_numeric($mayWrite) && (int) $mayWrite === 1;
    }

    /**
     * The artifact of a writing task that names the writes actually executed
     * (spec 051 A2A-MT6, B13): one line per write event the run's writes
     * announced, never what the model's answer says. A run that wrote nothing
     * says so. The run's own audit stream counts its write steps too; when the
     * two disagree, the list says it may be incomplete.
     *
     * The artifact is there when the profile may write now or when the run
     * wrote anything: "may write" can be cleared while a segment that already
     * runs goes on, and its writes are then still the client's to read.
     * Null for a read-only profile whose run wrote nothing.
     *
     * @return array<string, mixed>|null
     */
    private function writesArtifact(int $runUid, bool $profileMayWrite): ?array
    {
        $connection = $this->connection();
        $rows       = $connection->executeQuery(
            'SELECT w.record_table, w.record_uid, w.workspace, w.kind FROM ' . A2aWriteRecorder::TABLE . ' w'
                . ' INNER JOIN ' . self::TABLE . ' t ON t.uid = w.task_uid WHERE t.run_uid = ? ORDER BY w.uid',
            [$runUid],
            [Connection::PARAM_INT],
        )->fetchAllAssociative();
        $steps = $connection->count('uid', 'tx_nrllm_agentrun_event', ['run' => $runUid, 'kind' => 'tool_write']);
        if (!$profileMayWrite && $rows === [] && $steps === 0) {
            return null;
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '- %s:%d in workspace %d (%s)',
                is_string($row['record_table'] ?? null) ? $row['record_table'] : '',
                is_numeric($row['record_uid'] ?? null) ? (int) $row['record_uid'] : 0,
                is_numeric($row['workspace'] ?? null) ? (int) $row['workspace'] : 0,
                is_string($row['kind'] ?? null) ? $row['kind'] : '',
            );
        }

        $text = $lines === []
            ? 'No write was executed.'
            : sprintf("Writes executed (%d), each in the workspace named (0 is live):\n%s", count($lines), implode("\n", $lines));
        if ($steps !== count($lines)) {
            $text .= sprintf("\nThe run recorded %d write step(s); this list may be incomplete.", $steps);
        }

        return ['artifactId' => Uuid::v7()->toRfc4122(), 'name' => 'writes', 'parts' => [['text' => $text]]];
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
