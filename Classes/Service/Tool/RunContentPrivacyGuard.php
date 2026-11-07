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
            static function (): void {},
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

                if ($provider !== null && $provider !== '') {
                    $providers = is_string($row['privacy_providers']) ? json_decode($row['privacy_providers'], true) : [];
                    $providers = is_array($providers) ? array_values(array_filter($providers, is_string(...))) : [];
                    if (!in_array($provider, $providers, true)) {
                        $providers[] = $provider;
                    }

                    $changes['privacy_providers'] = json_encode($providers, JSON_THROW_ON_ERROR);
                }

                $connection->update(self::RUN, $changes, ['uid' => $runUid]);
                $write($level, $answerLevel);
            },
        );
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
}
