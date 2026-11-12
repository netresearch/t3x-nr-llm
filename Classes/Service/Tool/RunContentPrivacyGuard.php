<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Closure;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Service\Privacy\A2aContentRedaction;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelResolver;
use Netresearch\NrLlm\Service\Privacy\RunProviderPrivacyInterface;
use Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Serializes retention changes and content writes on the run row (ADR-270).
 * Functional queue/suspend state is not an audit payload and is unchanged.
 *
 * @internal
 */
final readonly class RunContentPrivacyGuard implements RunProviderPrivacyInterface
{
    private const RUN = 'tx_nrllm_agentrun';

    private const EVENT = 'tx_nrllm_agentrun_event';

    /** The A2A task view whose content follows the answer level (ADR-282). */
    private const A2A_TASK = 'tx_nrllm_a2a_task';

    /** The A2A event journal, whose payloads carry the same content (ADR-282). */
    private const A2A_EVENT = 'tx_nrllm_a2a_event';

    /** @var list<string> the sealed content columns of an A2A task */
    private const A2A_TASK_CONTENT = ['status_message', 'artifacts', 'history'];

    public function __construct(
        private ConnectionPool $connectionPool,
        private RunPrivacyLevelResolver $levels,
        private RunStepPrivacyFilter $filter,
        private PrivacyPolicyInterface $policy,
        private AgentStateCodec $stateCodec,
    ) {}

    public function beforeProvider(
        int $runUid,
        string $providerIdentifier,
    ): void {
        if ($this->connectionPool
            ->getConnectionForTable(self::RUN)
            ->isTransactionActive()) {
            throw new RuntimeException(
                'Provider privacy must commit before dispatch; an outer transaction is active.',
                1794046005,
            );
        }

        $this->withPolicy(
            $runUid,
            $providerIdentifier,
            static function (): void {
                /* Tightening commits policy without writing new content. */
            },
        );
    }

    /**
     * @param Closure(string): void $write
     */
    public function writeEvent(
        int $runUid,
        string $kind,
        string $payload,
        Closure $write,
    ): void {
        $this->withPolicy(
            $runUid,
            null,
            function (PrivacyLevel $level) use ($kind, $payload, $write): void {
                $write($this->eventPayload($kind, $payload, $level));
            },
        );
    }

    /**
     * @param Closure(string): void $write
     */
    public function writeAnswer(
        int $runUid,
        string $answer,
        Closure $write,
    ): void {
        $this->withPolicy(
            $runUid,
            null,
            function (
                PrivacyLevel $level,
                PrivacyLevel $answerLevel,
            ) use ($answer, $write): void {
                $write($this->answer($answer, $answerLevel));
            },
        );
    }

    /**
     * Run $write under the run-row lock with the run's answer ceiling read
     * inside it (ADR-270), in that lock's transaction: the completing write of
     * an A2A task's result and the run's terminal transition commit together,
     * or neither does (ADR-282). An exception from $write rolls both back and
     * is rethrown.
     *
     * @template T
     *
     * @param Closure(PrivacyLevel): T $write
     *
     * @return T
     */
    public function withAnswerCeiling(int $runUid, Closure $write): mixed
    {
        $result = null;
        $this->withPolicy(
            $runUid,
            null,
            static function (PrivacyLevel $level, PrivacyLevel $answerLevel) use ($write, &$result): void {
                $result = $write($answerLevel);
            },
        );

        /** @var T $result */
        return $result;
    }

    /**
     * What an answer level keeps of a text: all of it, the redacted text, or
     * nothing (ADR-234, ADR-282).
     */
    public function keptAt(string $text, PrivacyLevel $level): string
    {
        return $this->answer($text, $level);
    }

    /**
     * The no-op UPDATE takes a write lock on SQLite and a row lock on
     * InnoDB/PostgreSQL. Every event/answer writer takes this same lock before
     * reading the ceiling. A stale segment therefore cannot reinsert content
     * after another segment tightened it.
     *
     * @param Closure(PrivacyLevel, PrivacyLevel): void $write
     */
    private function withPolicy(
        int $runUid,
        ?string $provider,
        Closure $write,
    ): void {
        $connection = $this->connectionPool->getConnectionForTable(self::RUN);
        if ($connection !== $this->connectionPool->getConnectionForTable(self::EVENT)) {
            throw new RuntimeException(
                'Agent run privacy requires run and event tables on the same database connection.',
                1794046001,
            );
        }

        $connection->transactional(
            function (
                Connection $connection,
            ) use ($runUid, $provider, $write): void {
                $table = $connection->quoteIdentifier(self::RUN);
                $connection->executeStatement(
                    'UPDATE ' . $table . ' SET uid = uid WHERE uid = ?',
                    [$runUid],
                    [Connection::PARAM_INT],
                );
                $row = $connection
                    ->executeQuery(
                        'SELECT privacy_level, answer_privacy_level, privacy_providers, answer FROM ' . $table . ' WHERE uid = ?' . $this->lockingRead($connection),
                        [$runUid],
                        [Connection::PARAM_INT],
                    )
                    ->fetchAssociative();
                if ($row === false) {
                    throw new RuntimeException(
                        'Agent run privacy cannot be established for a missing run.',
                        1794046002,
                    );
                }

                $previous       = $this->storedLevel($row['privacy_level']);
                $previousAnswer = $this->storedLevel($row['answer_privacy_level']);
                $level          = PrivacyLevel::strictest(
                    $previous,
                    $this->levels->forRun($runUid),
                );
                $answerLevel = PrivacyLevel::strictest(
                    $previousAnswer,
                    $this->levels->forRunAnswer($runUid),
                );
                if ($provider !== null) {
                    $level = PrivacyLevel::strictest(
                        $level,
                        $this->attemptCeiling($connection, $provider),
                    );
                    $answerLevel = PrivacyLevel::strictest(
                        $answerLevel,
                        $this->attemptCeiling($connection, $provider),
                    );
                }

                if ($level !== $previous) {
                    $this->scrubEvents($connection, $runUid, $level);
                }

                $changes = [
                    'privacy_level'        => $level->value,
                    'answer_privacy_level' => $answerLevel->value,
                ];
                $changes += $this->answerChanges($row, $previousAnswer, $answerLevel);
                if ($answerLevel !== $previousAnswer) {
                    // A tightening scrubs the A2A result store as it scrubs
                    // the answer column (ADR-282).
                    $this->scrubA2aTask($connection, $runUid, $answerLevel);
                }

                if ($provider !== null && $provider !== '') {
                    $changes['privacy_providers'] = $this->recordProvider($row['privacy_providers'], $provider);
                }

                $connection->update(self::RUN, $changes, ['uid' => $runUid]);
                $write($level, $answerLevel);
            },
        );
    }

    /**
     * Re-apply an answer level to the sealed content of the run's A2A task:
     * nothing kept below `redacted`, the text parts redacted at `redacted`.
     */
    private function scrubA2aTask(Connection $connection, int $runUid, PrivacyLevel $level): void
    {
        $row = $connection
            ->executeQuery(
                'SELECT uid, ' . implode(', ', self::A2A_TASK_CONTENT) . ' FROM ' . $connection->quoteIdentifier(self::A2A_TASK) . ' WHERE run_uid = ?' . $this->lockingRead($connection),
                [$runUid],
                [Connection::PARAM_INT],
            )
            ->fetchAssociative();
        if ($row === false) {
            return;
        }

        $changes = [];
        foreach (self::A2A_TASK_CONTENT as $column) {
            $stored = is_string($row[$column] ?? null) ? $row[$column] : '';
            if ($stored === '') {
                continue;
            }

            $kept = '';
            if ($level->persistsContent()) {
                $decoded = json_decode($this->stateCodec->decode($stored, AgentStateCodec::PURPOSE_A2A_TASK_CONTENT), true);
                $kept    = json_encode($this->keptParts($decoded, $level), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            }

            $changes[$column] = $this->stateCodec->encode($kept, AgentStateCodec::PURPOSE_A2A_TASK_CONTENT);
        }

        if ($changes !== []) {
            $connection->executeStatement(
                'UPDATE ' . $connection->quoteIdentifier(self::A2A_TASK) . ' SET ' . implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($changes))) . ', revision = revision + 1 WHERE uid = ?',
                [...array_values($changes), $row['uid']],
            );
        }

        $this->scrubA2aEvents($connection, $row['uid'], $level);
    }

    /**
     * The events already journalled for the task carry the same content, and a
     * stream or a resubscribe reads them: the level applies to every one, in
     * the same transaction (ADR-282). Below `redacted` an event keeps its state
     * and timestamp and loses its status message and artifacts.
     */
    private function scrubA2aEvents(Connection $connection, mixed $taskUid, PrivacyLevel $level): void
    {
        $events = $connection
            ->executeQuery(
                'SELECT uid, payload FROM ' . $connection->quoteIdentifier(self::A2A_EVENT) . ' WHERE task_uid = ?' . $this->lockingRead($connection),
                [$taskUid],
            )
            ->fetchAllAssociative();
        foreach ($events as $event) {
            $stored = is_string($event['payload'] ?? null) ? $event['payload'] : '';
            if ($stored === '') {
                continue;
            }

            $payload = json_decode($this->stateCodec->decode($stored, AgentStateCodec::PURPOSE_A2A_EVENT), true);
            if (!is_array($payload)) {
                $payload = [];
            }

            if ($level->persistsContent()) {
                $payload = $this->keptParts($payload, $level);
            } else {
                unset($payload['statusMessage'], $payload['artifacts']);
            }

            $connection->update(
                self::A2A_EVENT,
                ['payload' => $this->stateCodec->encode(json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), AgentStateCodec::PURPOSE_A2A_EVENT)],
                ['uid' => $event['uid']],
            );
        }
    }

    /**
     * An A2A content structure (messages, artifacts, parts, an event payload)
     * at the level: as it is at `full`, by the allow-list of
     * {@see A2aContentRedaction} below it, the one rule the task store applies
     * too.
     */
    private function keptParts(mixed $value, PrivacyLevel $level): mixed
    {
        if ($level === PrivacyLevel::FULL) {
            return $value;
        }

        return A2aContentRedaction::redacted($value, fn (string $text): string => $this->answer($text, $level));
    }

    private function storedLevel(mixed $stored): PrivacyLevel
    {
        // Empty is an old/uninitialised row. Invalid nonempty policy is closed.
        if ($stored === '') {
            return PrivacyLevel::FULL;
        }

        return is_string($stored) ? PrivacyLevel::tryFrom($stored) ?? PrivacyLevel::NONE : PrivacyLevel::NONE;
    }

    private function scrubEvents(
        Connection $connection,
        int $runUid,
        PrivacyLevel $level,
    ): void {
        $rows = $connection
            ->executeQuery(
                'SELECT uid, kind, payload FROM ' . $connection->quoteIdentifier(self::EVENT) . ' WHERE run = ?' . $this->lockingRead($connection),
                [$runUid],
                [Connection::PARAM_INT],
            )
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $kind = is_string($row['kind']) ? $row['kind'] : '';
            if (!in_array($kind, RunStep::kinds(), true)) {
                continue;
            }

            $payload = is_string($row['payload']) ? $row['payload'] : '';
            $connection->update(
                self::EVENT,
                ['payload' => $this->eventPayload($kind, $payload, $level)],
                ['uid' => $row['uid']],
            );
        }
    }

    private function eventPayload(
        string $kind,
        string $payload,
        PrivacyLevel $level,
    ): string {
        if (!in_array($kind, RunStep::kinds(), true) || $level === PrivacyLevel::FULL) {
            return $payload;
        }

        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException(
                'A run step cannot be decoded for privacy filtering.',
                1794046003,
            );
        }

        /** @var array<string, mixed> $decoded */
        return json_encode(
            $this->filter->filter($decoded, $level),
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    private function answer(string $answer, PrivacyLevel $level): string
    {
        if (!$level->persistsContent()) {
            return '';
        }

        return $level->requiresRedaction() ? $this->policy->filterContentAt($answer, $level) ?? '' : $answer;
    }

    private function lockingRead(Connection $connection): string
    {
        return $connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }

    private function attemptCeiling(
        Connection $connection,
        string $provider,
    ): PrivacyLevel {
        if ($provider === '') {
            return PrivacyLevel::NONE;
        }

        $value = $connection
            ->executeQuery(
                'SELECT privacy_level FROM ' . $connection->quoteIdentifier('tx_nrllm_provider') . ' WHERE identifier = ?' . $this->lockingRead($connection),
                [$provider],
            )
            ->fetchOne();
        if ($value === '') {
            return PrivacyLevel::FULL;
        }

        return is_string($value) ? PrivacyLevel::tryFrom($value) ?? PrivacyLevel::NONE : PrivacyLevel::NONE;
    }

    /** @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function answerChanges(
        array $row,
        PrivacyLevel $previousAnswer,
        PrivacyLevel $answerLevel,
    ): array {
        $changes = [];
        if ($answerLevel !== $previousAnswer && is_string($row['answer']) && $row['answer'] !== '') {
            $kept = $answerLevel->persistsContent() ? $this->answer(
                $this->stateCodec->decode(
                    $row['answer'],
                    AgentStateCodec::PURPOSE_RUN_ANSWER,
                ),
                $answerLevel,
            ) : '';
            $changes['answer'] = $this->stateCodec->encode(
                $kept,
                AgentStateCodec::PURPOSE_RUN_ANSWER,
            );
        }

        return $changes;
    }

    private function recordProvider(mixed $stored, string $provider): string
    {
        $providers = is_string($stored) ? json_decode($stored, true) : [];
        $providers = is_array($providers) ? array_values(array_filter($providers, is_string(...))) : [];
        if (!in_array($provider, $providers, true)) {
            $providers[] = $provider;
        }

        return json_encode($providers, JSON_THROW_ON_ERROR);
    }
}
