<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDependencyCanceller;
use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aDependencyPoller;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskProjector;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\DependencyResumeCoordinator;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The A2A event journal and its projector (spec 051 A2A-R14, A2A-R15;
 * ADR-282).
 */
#[CoversClass(A2aTaskProjector::class)]
#[CoversClass(A2aTaskStore::class)]
final class A2aProjectorTest extends AbstractFunctionalTestCase
{
    use A2aTaskStoreFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wireStore();
    }

    /**
     * No event exists before the completing transaction committed; after it,
     * the event carries the committed state and nothing else.
     */
    #[Test]
    public function noEventIsWrittenBeforeTheCommitAndTheEventHoldsOnlyWhatMayLeave(): void
    {
        $handle          = $this->boundRun();
        $insideTheCommit = null;

        $this->store->complete(
            $handle->runUid,
            'the answer',
            function () use ($handle, &$insideTheCommit): bool {
                $insideTheCommit = [$this->eventCount(), $this->connection()->isTransactionActive()];

                return $this->connection()->update('tx_nrllm_agentrun', ['status' => 'completed'], ['uid' => $handle->runUid]) === 1;
            },
            static fn (string $errorClass): bool => false,
        );

        self::assertSame([0, true], $insideTheCommit);
        $events = $this->projector->events((int) $this->taskRow()['uid']);
        self::assertCount(1, $events);
        self::assertSame(2, $events[0]['revision']);
        self::assertSame('TASK_STATE_COMPLETED', $events[0]['payload']['state']);
        self::assertSame([], array_diff(array_keys($events[0]['payload']), ['taskId', 'contextId', 'state', 'statusTimestamp', 'statusMessage', 'artifacts', 'metadata']));
        self::assertStringNotContainsString('alice@example.com', json_encode($events[0]['payload'], JSON_THROW_ON_ERROR));
        $payload = $this->connection()->select(['payload'], A2aTaskProjector::TABLE, [])->fetchOne();
        self::assertIsString($payload);
        self::assertStringNotContainsString('the answer', $payload);
    }

    /**
     * Projecting the same revision twice, also by two workers that both read
     * it as unprojected, yields its event once.
     */
    #[Test]
    public function projectingARevisionTwiceYieldsOneEvent(): void
    {
        $handle  = $this->boundRun();
        $taskUid = (int) $this->taskRow()['uid'];

        self::assertSame(1, $this->projector->project($taskUid));
        self::assertSame(0, $this->projector->project($taskUid));
        $this->connection()->update(A2aTaskStore::TABLE, ['projected_revision' => 0], ['uid' => $taskUid]);
        self::assertSame(0, $this->projector->project($taskUid));
        self::assertSame(1, $this->eventCount());

        $this->persister->settleCompleted($handle, $this->loopResult('done'), 'worker-a:1');
        self::assertSame([1, 2], array_column($this->projector->events($taskUid), 'revision'));
    }

    /**
     * A projection that fails after the commit leaves the completion as it is;
     * the worker's next pass writes the event from the committed state and
     * calls no model.
     */
    #[Test]
    public function aFailedProjectionIsRetriedByTheWorkerWithoutAModelCall(): void
    {
        $handle = $this->boundRun();
        $this->failInDatabase('a2a_force_event', A2aTaskProjector::TABLE, 'INSERT', '1 = 1');

        self::assertTrue($this->persister->settleCompleted($handle, $this->loopResult('the answer'), 'worker-a:1'));
        self::assertSame(['TASK_STATE_COMPLETED', 0], [$this->taskRow()['state'], $this->eventCount()]);
        $this->dropTrigger('a2a_force_event', A2aTaskProjector::TABLE);

        $report = $this->poller()->tick(time());

        self::assertSame(1, $report->projected);
        $events = $this->projector->events((int) $this->taskRow()['uid']);
        self::assertSame([2], array_column($events, 'revision'));
        self::assertSame('TASK_STATE_COMPLETED', $events[0]['payload']['state']);
    }

    #[Test]
    public function aTaskWhoseContentCannotBeReadDoesNotStopTheOthers(): void
    {
        $first  = $this->boundRun('task-1');
        $second = $this->boundRun('task-2');
        $this->failInDatabase('a2a_force_event', A2aTaskProjector::TABLE, 'INSERT', '1 = 1');
        self::assertTrue($this->persister->settleCompleted($first, $this->loopResult('first'), 'worker-a:1'));
        self::assertTrue($this->persister->settleCompleted($second, $this->loopResult('second'), 'worker-a:1'));
        $this->dropTrigger('a2a_force_event', A2aTaskProjector::TABLE);
        // A damaged envelope on the task that comes first in the selection:
        // the sealed artifacts with their last characters changed.
        $sealed = $this->taskRow('task-1')['artifacts'] ?? null;
        self::assertIsString($sealed);
        self::assertGreaterThan(16, strlen($sealed));
        $this->connection()->update(A2aTaskStore::TABLE, ['artifacts' => substr($sealed, 0, -8) . 'AAAAAAAA'], ['task_id' => 'task-1']);

        self::assertSame(1, $this->poller()->tick(time())->projected);
        self::assertSame(0, (int) $this->taskRow('task-1')['projected_revision']);
        self::assertSame([2], array_column($this->projector->events((int) $this->taskRow('task-2')['uid']), 'revision'));
    }

    /**
     * A projection takes the run-row lock a privacy scrub holds (ADR-270)
     * before it reads the task, so it cannot write content it read before a
     * scrub committed. Observed on MariaDB, where the lock is a row lock: a
     * projection that did not ask for it would read the task and write its
     * event past the held lock. SQLite locks the whole database for any
     * write, so there the event insert waits either way and the test would
     * prove nothing.
     */
    #[Test]
    public function aProjectionWaitsForTheRunRowLockOfAScrub(): void
    {
        $connection = $this->connection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite locks the whole database, so it cannot tell the run-row lock from the event write; the MariaDB leg runs this.');
        }

        $handle  = $this->boundRun();
        $taskUid = (int) $this->taskRow()['uid'];
        $second  = DriverManager::getConnection($connection->getParams());
        $second->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($second);
        $projector = new A2aTaskProjector($pool, $this->getService(AgentStateCodec::class));

        $connection->beginTransaction();
        $connection->executeStatement('UPDATE tx_nrllm_agentrun SET uid = uid WHERE uid = ?', [$handle->runUid]);
        try {
            $projector->project($taskUid);
            self::fail('A projection must not pass a held run-row lock.');
        } catch (DbalException $exception) {
            self::assertStringContainsStringIgnoringCase('lock wait timeout', $exception->getMessage());
        } finally {
            $connection->commit();
        }

        self::assertSame(0, $this->eventCount());
        self::assertSame(1, $projector->project($taskUid));
        $second->close();
    }

    /**
     * Under REPEATABLE READ a projection that runs in a transaction whose
     * snapshot predates a scrub still writes the scrubbed content, because it
     * reads the task with a locking read after taking the run lock. The
     * snapshot is pinned deterministically: the projector's connection opens
     * its transaction and reads before the change commits on the other
     * connection. MariaDB only; SQLite has no snapshot of that kind.
     */
    #[Test]
    public function aProjectionReadsTheLatestCommittedTaskNotItsSnapshot(): void
    {
        $connection = $this->connection();
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite has no REPEATABLE READ snapshot; the MariaDB leg runs this.');
        }

        $handle = $this->boundRun();
        $this->persister->settleCompleted($handle, $this->loopResult('done'), 'worker-a:1');
        $taskUid = (int) $this->taskRow()['uid'];
        $codec   = $this->getService(AgentStateCodec::class);
        $change  = static fn (string $text): array => [$codec->encode('[{"artifactId":"a","parts":[{"text":"' . $text . '"}]}]', AgentStateCodec::PURPOSE_A2A_TASK_CONTENT), $taskUid];
        // Revision 3, not yet projected, with content a scrub will remove.
        $connection->executeStatement('UPDATE tx_nrllm_a2a_task SET artifacts = ?, revision = revision + 1 WHERE uid = ?', $change('before the scrub'));
        $second = DriverManager::getConnection($connection->getParams());
        $pool   = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($second);
        $projector = new A2aTaskProjector($pool, $codec);

        // MariaDB 11.6+ refuses a write to a row changed after the snapshot
        // (innodb_snapshot_isolation), which would roll a stale projection
        // back on its own; MySQL and older MariaDB do not. Switched off, so
        // the test shows the locking reads, not the server, keep the stale
        // content out.
        try {
            $second->executeStatement('SET SESSION innodb_snapshot_isolation = OFF');
        } catch (DbalException) {
            // A server without the variable behaves as with it off.
        }

        $second->beginTransaction();
        $second->executeQuery('SELECT artifacts, revision FROM tx_nrllm_a2a_task WHERE uid = ?', [$taskUid])->fetchAssociative();
        // The scrub commits revision 4 after the projector's snapshot.
        $connection->executeStatement('UPDATE tx_nrllm_a2a_task SET artifacts = ?, revision = revision + 1 WHERE uid = ?', $change('after the scrub'));
        try {
            $projector->project($taskUid);
        } catch (DbalException) {
            // MariaDB 11.6+ (innodb_snapshot_isolation) refuses a locking read
            // of a row changed after the snapshot and rolls the transaction
            // back: no event is written, and the next pass writes it.
        } finally {
            if ($second->isTransactionActive()) {
                try {
                    $second->commit();
                } catch (DbalException) {
                    // Already rolled back by the server.
                }
            }

            $second->close();
        }

        // Whatever the server did, no event carries the content from before
        // the scrub: a plain read in the old snapshot would have written
        // revision 3 with it.
        self::assertStringNotContainsString('before the scrub', json_encode($this->projector->events($taskUid), JSON_THROW_ON_ERROR));

        $this->projector->projectPending();
        $latest = $this->projector->events($taskUid);
        $last   = json_encode(end($latest), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('after the scrub', $last);
        self::assertStringNotContainsString('before the scrub', $last);
    }

    private function poller(): A2aDependencyPoller
    {
        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager->expects(self::never())->method(self::anything());
        $loop          = new ToolLoopService($manager, new ToolRegistry(), self::createStub(ToolCallPolicyInterface::class));
        $configuration = self::createStub(LlmConfigurationRepository::class);
        $configuration->method('findByUid')->willReturn(new LlmConfiguration());
        $journal = new A2aSendJournal($this->getService(ConnectionPool::class));
        $client  = new A2aClient($journal, new ScriptedA2aTransport(), new A2aResponseParser());
        $scope   = new WorkerVaultScope(self::createStub(TechnicalActorContextInterface::class), self::createStub(ExtensionConfiguration::class), false);
        $agents  = $this->getService(A2aRemoteAgentRepository::class);

        return new A2aDependencyPoller(
            new DependencyResumeCoordinator($this->persister, $configuration, $loop, new AgentRunExecutor($loop, $this->persister)),
            $journal,
            $agents,
            $client,
            new A2aDependencyCanceller($this->persister, $journal, $agents, $client, $scope),
            $scope,
            $this->projector,
        );
    }
}
