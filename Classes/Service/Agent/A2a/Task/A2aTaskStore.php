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
use Netresearch\NrLlm\Domain\Enum\ChangeCoverage;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChange;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChangeSet;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileStore;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aOutboundFileArtifacts;
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

    /** The status text of a WORKING task whose run waits for an approval (spec 059 WS1). */
    public const TEXT_WAITING_FOR_APPROVAL = 'The task is waiting for an approval in TYPO3.';

    /** The status text of a WORKING task whose run waits for a remote agent (spec 059 WS1). */
    public const TEXT_WAITING_FOR_REMOTE_AGENT = 'The task is waiting for a remote agent.';

    public function __construct(
        private ConnectionPool $connectionPool,
        private RunContentPrivacyGuard $privacy,
        private AgentStateCodec $codec,
        private A2aTaskProjector $projector,
        private ?LoggerInterface $logger = null,
        private ?A2aArtifactShaperInterface $artifacts = null,
        private ?A2aOutboundFileArtifacts $files = null,
        private ?A2aResultReporterInterface $reporter = null,
    ) {}

    /**
     * Bind a new task to a run (stage 3 creates both in its acceptance
     * transaction, A2A-SV5). The history is kept at the run's answer level.
     *
     * @param list<array<string, mixed>> $history  the client's messages, A2A JSON
     * @param string                     $endpoint the A2A endpoint the task was accepted at, the base of
     *                                             its file downloads (A2A-FILE5)
     *
     * @return int the task row's uid
     */
    public function bind(int $runUid, string $taskId, string $contextId, int $ownerClientUid = 0, int $profileUid = 0, array $history = [], string $endpoint = ''): int
    {
        // The history is content like the result: kept at the run's answer
        // level, read under the run-row lock (ADR-270, ADR-282). A later
        // tightening scrubs it, so it is never wider than the current level.
        return $this->privacy->withAnswerCeiling(
            $runUid,
            fn (PrivacyLevel $ceiling): int => $this->insertTask($runUid, $taskId, $contextId, $ownerClientUid, $profileUid, $history === [] || !$ceiling->persistsContent() ? '' : $this->json($this->redact($history, $ceiling)), $endpoint),
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

    private function insertTask(int $runUid, string $taskId, string $contextId, int $ownerClientUid, int $profileUid, string $history, string $endpoint): int
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
            'endpoint'           => mb_substr($endpoint, 0, 512),
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
     * only, with its result. A WORKING task whose run waits for an approval or
     * a remote agent says so in a fixed status text, and loses it when the run
     * goes on (spec 059).
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
        if (!$target instanceof A2aTaskState) {
            return;
        }

        // A stop by the end-of-run check names itself and carries the report
        // (ADR-301); the reason stays out of the text, it is in the report.
        // The current state is never terminal here, so a FAILED target is
        // always written and the report is built once.
        $report = $target === A2aTaskState::FAILED
            ? $this->reporter?->stopped($runUid, $this->profileOf($runUid), $ceiling, is_string($run['termination_reason'] ?? null) ? $run['termination_reason'] : '')
            : null;

        $statusText = match ($target) {
            A2aTaskState::FAILED         => $report !== null ? 'The editorial check stopped the text.' : 'The task failed.',
            A2aTaskState::REJECTED       => 'The task was refused before it started.',
            A2aTaskState::INPUT_REQUIRED => $this->question($suspended, $ceiling),
            default                      => $this->waitText($status),
        };
        // A wait the installation decides is WORKING like the run before it
        // (spec 059): only the text changes, and only a change is written.
        if ($target === $current && $statusText === $this->statusTextOf($runUid)) {
            return;
        }

        $fields = ['state' => $target->value];
        if ($target->isTerminal()) {
            $fields['history'] = $ceiling->persistsContent() ? $this->historyAt($runUid, $ceiling) : '';
            $this->releaseInputFiles($runUid);
        }

        if ($report !== null) {
            $fields['artifacts'] = $this->seal($this->json([$report]));
        }

        $fields['status_message'] = $statusText === '' ? '' : $this->seal($this->json([
            'messageId' => Uuid::v7()->toRfc4122(),
            'role'      => 'ROLE_AGENT',
            'parts'     => [['text' => $statusText]],
        ]));

        $this->update($runUid, $fields);
    }

    /**
     * The status text of a WORKING task (spec 059): what a run waits for when
     * a person of this installation or a remote agent decides when it goes
     * on, and nothing otherwise. A fixed text: no run, record, person, agent
     * or remote task is named.
     */
    private function waitText(?AgentRunStatus $status): string
    {
        return match ($status) {
            AgentRunStatus::WAITING_FOR_APPROVAL   => self::TEXT_WAITING_FOR_APPROVAL,
            AgentRunStatus::WAITING_FOR_DEPENDENCY => self::TEXT_WAITING_FOR_REMOTE_AGENT,
            default                                => '',
        };
    }

    /**
     * The text of the task's current status message; empty when it has none
     * or when a tightened answer level removed it, null when it cannot be
     * opened.
     */
    private function statusTextOf(int $runUid): ?string
    {
        $stored = $this->connection()->select(['status_message'], self::TABLE, ['run_uid' => $runUid])->fetchOne();
        if (!is_string($stored) || $stored === '') {
            return '';
        }

        try {
            $message = json_decode($this->codec->decode($stored, AgentStateCodec::PURPOSE_A2A_TASK_CONTENT), true);
        } catch (Throwable) {
            // An envelope that cannot be opened equals no text: the mirror
            // writes the message anew, which also repairs the row.
            return null;
        }

        $text = is_array($message) && is_array($message['parts'] ?? null) && is_array($message['parts'][0] ?? null) ? ($message['parts'][0]['text'] ?? '') : '';

        return is_string($text) ? $text : '';
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
        // The report goes after the content: the content artifact stays the
        // first and holds only the text (ADR-301).
        $report = $this->reporter?->completed($runUid, $this->profileOf($runUid), $artifact, $ceiling);
        // Beside a report only real writes are listed: a "No write was
        // executed." text part would be joined into the copied text, and an
        // editorial task offers no writing tool (ADR-302); writes that ran
        // anyway are still the client's to read.
        $writes = $this->writesArtifact($runUid, $report === null && $this->profileMayWrite($runUid));
        if ($report !== null) {
            $artifact[] = $report;
        }

        if ($writes !== null) {
            $artifact[] = $writes;
        }

        // The files the run sent (A2A-FILE5), at the same answer level.
        $task = $this->connection()->select(['uid', 'owner_client_uid', 'endpoint'], self::TABLE, ['run_uid' => $runUid])->fetchAssociative();
        if ($this->files instanceof A2aOutboundFileArtifacts && is_array($task)) {
            array_push($artifact, ...$this->files->artifactsFor(
                is_numeric($task['uid'] ?? null) ? (int) $task['uid'] : 0,
                is_numeric($task['owner_client_uid'] ?? null) ? (int) $task['owner_client_uid'] : 0,
                is_string($task['endpoint'] ?? null) ? $task['endpoint'] : '',
                $ceiling,
            ));
        }

        $this->update($runUid, [
            'state'          => A2aTaskState::COMPLETED->value,
            'artifacts'      => $this->seal($this->json($artifact)),
            'history'        => $this->historyAt($runUid, $ceiling),
            'outcome_reason' => '',
        ]);
        $this->releaseInputFiles($runUid);

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
        $this->releaseInputFiles($runUid);

        return true;
    }

    /**
     * The input files of a task that ended keep their record (id, size,
     * digest, states) but give up their bytes and URL, in the transaction
     * that ends the task, whatever the answer level (spec 051 A2A-FILE4):
     * only the worker reads them, before the run's first model call, and a
     * task that ended never runs again. The files the task sends stay until
     * they expire; they are its result.
     *
     * A url part still pending is refused as well, so a fetch in flight when
     * the task ends finds no pending file and records nothing.
     */
    private function releaseInputFiles(int $runUid): void
    {
        $taskUid = $this->connection()->select(['uid'], self::TABLE, ['run_uid' => $runUid])->fetchOne();
        if (!is_numeric($taskUid)) {
            return;
        }

        $files = $this->connectionPool->getConnectionForTable(A2aFileStore::TABLE);
        $now   = time();
        $files->update(
            A2aFileStore::TABLE,
            ['check_state' => A2aFileStore::CHECK_FAILED, 'approval_state' => A2aFileStore::APPROVAL_REFUSED, 'refusal' => 'task ended', 'tstamp' => $now],
            ['task_uid' => (int) $taskUid, 'direction' => A2aFileStore::DIRECTION_IN, 'check_state' => A2aFileStore::CHECK_PENDING],
        );
        $files->update(
            A2aFileStore::TABLE,
            ['content' => '', 'source_url' => '', 'tstamp' => $now],
            ['task_uid' => (int) $taskUid, 'direction' => A2aFileStore::DIRECTION_IN],
        );
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
     * A call that ended in an error announces no write, yet what it changed
     * can stand: a call the scope of the assignment refused a part of commits
     * the rest (ADR-295), and so does a tool that fails after it wrote. Those
     * records are listed apart, from the call's `tool_changes` step, which is
     * empty when the call was rolled back. A set whose capture missed writes
     * or was cut at its limit says so.
     *
     * A completed call's `tool_changes` step can name records beside its
     * announced write: what core wrote along with it, and what the listeners
     * of the write stored after the call, such as AI Label's metadata and the
     * versions core gave the translations in that run (#1432). Those are
     * listed in a block of their own; a list cut at its limit says so.
     *
     * What the scope of the assignment refused or rolled back is named last,
     * record by record with its rule, also for a call that was rolled back
     * whole: a listener of the call's write that wrote outside the scope rolls
     * back a call whose own write was in scope (#1438).
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
        $steps  = $connection->count('uid', 'tx_nrllm_agentrun_event', ['run' => $runUid, 'kind' => 'tool_write']);
        $failed = $this->changesOfFailedCalls($runUid);
        $beside = $this->changesBesideTheWrites($runUid, $rows);
        if (!$profileMayWrite && $rows === [] && $steps === 0 && $failed['lines'] === [] && !$failed['incomplete'] && $failed['refused'] === [] && !$failed['refusedIncomplete'] && $beside['lines'] === [] && !$beside['incomplete']) {
            return null;
        }

        $lines = array_map(
            static fn (array $row): string => sprintf(
                '- %s:%d in workspace %d (%s)',
                is_string($row['record_table'] ?? null) ? $row['record_table'] : '',
                is_numeric($row['record_uid'] ?? null) ? (int) $row['record_uid'] : 0,
                is_numeric($row['workspace'] ?? null) ? (int) $row['workspace'] : 0,
                is_string($row['kind'] ?? null) ? $row['kind'] : '',
            ),
            $rows,
        );

        $parts = [
            ...$this->block("Writes executed (%d), each in the workspace named (0 is live):\n%s", $lines),
            ...$this->block("Further records the run's completed tool calls wrote (%d), each in the workspace named (0 is live), for example a version core gave a translation of a record written in a workspace:\n%s", $beside['lines']),
            ...($beside['incomplete'] ? ['A completed tool call wrote more records than its list keeps; check the run.'] : []),
            ...$this->block("Records written by tool calls that did not complete (%d), each in the workspace named (0 is live); whether each change is stored has to be checked:\n%s", $failed['lines']),
            ...($failed['incomplete'] ? ['A tool call that did not complete may have written records this list does not name; check the run.'] : []),
        ];

        $text = $parts === [] ? 'No write was executed.' : implode("\n", $parts);
        if ($steps !== count($lines)) {
            $text .= sprintf("\nThe run recorded %d write step(s); this list may be incomplete.", $steps);
        }

        // A call whose rollback could not be confirmed (the database ended the
        // transaction itself, or the rollback failed) may have kept what the
        // scope found outside it: the heading does not say rolled back then.
        $heading = $failed['unconfirmed']
            ? "Writes the scope of the task's assignment refused or found outside it (%d), each with the rule it broke:\n%s"
            : "Writes the scope of the task's assignment refused or rolled back (%d), each with the rule it broke:\n%s";
        foreach ($this->block($heading, $failed['refused']) as $refused) {
            $text .= "\n" . $refused;
        }

        if ($failed['refusedIncomplete']) {
            $text .= "\nA tool call had more writes refused or found outside the scope than its list keeps; this list may be incomplete.";
        }

        if ($failed['unconfirmed']) {
            $text .= "\nThe rollback of a tool call that wrote outside the scope could not be confirmed: what it wrote may stand. Check the records.";
        }

        return ['artifactId' => Uuid::v7()->toRfc4122(), 'name' => 'writes', 'parts' => [['text' => $text]]];
    }

    /**
     * One block of the `writes` artifact: its heading with the count, and its
     * lines; nothing for no lines.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function block(string $format, array $lines): array
    {
        return $lines === [] ? [] : [sprintf($format, count($lines), implode("\n", $lines))];
    }

    /**
     * The records the run's failed tool calls wrote, as their `tool_changes`
     * steps name them, one line each, and whether a step says it is not the
     * whole list (a capture that missed writes, or a list cut at its limit).
     * The same for the refusals, and whether a call's rollback could not be
     * confirmed. A call's steps are recorded in order: its tool step, then its
     * changes.
     *
     * @return array{lines: list<string>, incomplete: bool, refused: list<string>, refusedIncomplete: bool, unconfirmed: bool}
     */
    private function changesOfFailedCalls(int $runUid): array
    {
        $lines             = [];
        $refused           = [];
        $incomplete        = false;
        $refusedIncomplete = false;
        $unconfirmed       = false;
        foreach ($this->changeSetsOfCalls($runUid, true) as $set) {
            $incomplete        = $incomplete || $set->coverage !== ChangeCoverage::DATAHANDLER || $set->omitted > 0;
            $refusedIncomplete = $refusedIncomplete || $set->refusedOmitted > 0;
            $unconfirmed       = $unconfirmed || $set->rollbackUnconfirmed && $set->refused !== [];
            foreach ($set->changes as $change) {
                // The line holds every part of its identity, so it is its own key.
                $lines[$this->changeLine($change)] = true;
            }

            foreach ($set->refused as $refusal) {
                $refused[sprintf('- %s of %s (%s)', $refusal->operation, $refusal->subject(), $refusal->reason)] = true;
            }
        }

        return ['lines' => array_keys($lines), 'incomplete' => $incomplete, 'refused' => array_keys($refused), 'refusedIncomplete' => $refusedIncomplete, 'unconfirmed' => $unconfirmed];
    }

    /**
     * The records the run's completed tool calls wrote beside the writes they
     * announced, as their `tool_changes` steps name them, one line each: what
     * a listener of a write stored with a DataHandler run of its own, and what
     * core added to such a run, such as the version of a translation (#1432).
     *
     * @param list<array<string, mixed>> $rows the announced writes
     *
     * @return array{lines: list<string>, incomplete: bool}
     */
    private function changesBesideTheWrites(int $runUid, array $rows): array
    {
        $announced = [];
        foreach ($rows as $row) {
            $announced[sprintf('%s:%d:%d', is_string($row['record_table'] ?? null) ? $row['record_table'] : '', is_numeric($row['record_uid'] ?? null) ? (int) $row['record_uid'] : 0, is_numeric($row['workspace'] ?? null) ? (int) $row['workspace'] : 0)] = true;
        }

        $lines      = [];
        $incomplete = false;
        foreach ($this->changeSetsOfCalls($runUid, false) as $set) {
            $incomplete = $incomplete || $set->omitted > 0;
            foreach ($set->changes as $change) {
                $uid = $change->liveUid ?? $change->record->uid;
                if (!isset($announced[sprintf('%s:%d:%d', $change->record->table, $uid, $change->workspace)])) {
                    $lines[$this->changeLine($change)] = true;
                }
            }
        }

        return ['lines' => array_keys($lines), 'incomplete' => $incomplete];
    }

    private function changeLine(ObservedChange $change): string
    {
        return sprintf('- %s:%d in workspace %d (%s)', $change->record->table, $change->liveUid ?? $change->record->uid, $change->workspace, $change->action->value);
    }

    /**
     * The `tool_changes` sets of the run's calls whose tool step was an error,
     * or of those whose tool step was none.
     *
     * @return list<ObservedChangeSet>
     */
    private function changeSetsOfCalls(int $runUid, bool $failedOnes): array
    {
        $events = $this->connection()->executeQuery(
            'SELECT kind, payload FROM tx_nrllm_agentrun_event WHERE run = ? AND kind IN (?, ?) ORDER BY sequence, uid',
            [$runUid, 'tool', 'tool_changes'],
            [Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_STR],
        )->fetchAllAssociative();

        $sets   = [];
        $failed = null;
        foreach ($events as $event) {
            $payload = is_string($event['payload'] ?? null) ? json_decode($event['payload'], true) : null;
            $payload = is_array($payload) ? $payload : [];
            $isTool  = ($event['kind'] ?? null) === 'tool';
            $set     = !$isTool && $failed !== null && $failed === $failedOnes ? ObservedChangeSet::tryFromEventPayload($payload) : null;
            if ($set instanceof ObservedChangeSet) {
                $sets[] = $set;
            }

            // A call's changes follow its tool step; anything else belongs to none.
            $failed = $isTool ? ($payload['toolIsError'] ?? null) === true : null;
        }

        return $sets;
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
