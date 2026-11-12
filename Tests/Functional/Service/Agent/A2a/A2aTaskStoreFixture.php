<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskProjector;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelResolver;
use Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\RunContentPrivacyGuard;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The wiring of the result store tests (spec 051 A2A-R13 to A2A-R15): a
 * persister whose completion goes through the A2A task store, on the real
 * schema, with the real codec and privacy guard.
 */
trait A2aTaskStoreFixture
{
    private AgentRunPersister $persister;

    private A2aTaskStore $store;

    private A2aTaskProjector $projector;

    private RunContentPrivacyGuard $guard;

    private AgentRunRepository $repository;

    private function wireStore(): void
    {
        $pool             = $this->getService(ConnectionPool::class);
        $codec            = $this->getService(AgentStateCodec::class);
        $redactor         = new ContentRedactor();
        $policy           = new FixedPrivacyPolicy(PrivacyLevel::FULL, redactor: $redactor);
        $this->guard      = new RunContentPrivacyGuard($pool, new RunPrivacyLevelResolver($policy, $pool), new RunStepPrivacyFilter($policy, $redactor), $policy, $codec);
        $this->projector  = new A2aTaskProjector($pool, $codec);
        $this->store      = new A2aTaskStore($pool, $this->guard, $codec, $this->projector, new NullLogger());
        $this->repository = new AgentRunRepository($pool, $codec, $this->guard);
        $this->persister  = new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger(), completionStore: $this->store);
    }

    private function boundRun(string $taskId = 'task-1'): AgentRunHandle
    {
        $handle = $this->persister->begin(null, 1, 'worker-a:1', time() + 300);
        self::assertInstanceOf(AgentRunHandle::class, $handle);
        $this->store->bind($handle->runUid, $taskId, 'ctx-1', 0, 0, [['messageId' => 'm-1', 'role' => 'ROLE_USER', 'parts' => [['text' => 'client asks about alice@example.com']]]]);

        return $handle;
    }

    private function loopResult(string $answer): ToolLoopResult
    {
        return new ToolLoopResult($answer, [], 1, false, UsageStatistics::fromTokens(3, 2));
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(string $taskId = 'task-1'): array
    {
        $row = $this->connection()->select(['*'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function opened(string $column, string $taskId = 'task-1'): string
    {
        $stored = $this->taskRow($taskId)[$column] ?? '';

        return is_string($stored) && $stored !== '' ? $this->getService(AgentStateCodec::class)->decode($stored, AgentStateCodec::PURPOSE_A2A_TASK_CONTENT) : '';
    }

    private function eventCount(): int
    {
        return $this->connection()->count('uid', A2aTaskProjector::TABLE, []);
    }

    /**
     * Make every UPDATE of a task to $state, or every event insert, fail inside
     * the database: a failure in the completing transaction that no PHP
     * double can fake.
     */
    private function failInDatabase(string $name, string $table, string $event, string $condition): void
    {
        $connection = $this->connection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement(sprintf("CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW WHEN %s BEGIN SELECT RAISE(ABORT, 'forced'); END", $name, $event, $table, $condition));

            return;
        }

        $connection->executeStatement(sprintf("CREATE TRIGGER %s BEFORE %s ON %s FOR EACH ROW BEGIN IF %s THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced'; END IF; END", $name, $event, $table, $condition));
    }

    private function dropTrigger(string $name, string $table): void
    {
        $connection = $this->connection();
        $connection->executeStatement($connection->getDatabasePlatform() instanceof SQLitePlatform ? 'DROP TRIGGER ' . $name : 'DROP TRIGGER IF EXISTS ' . $name);
    }

    private function connection(): Connection
    {
        return $this->getService(ConnectionPool::class)->getConnectionForTable(A2aTaskStore::TABLE);
    }
}
