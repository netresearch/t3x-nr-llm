<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

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
