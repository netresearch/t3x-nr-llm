<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Command;

use Netresearch\NrLlm\Command\EnqueueTaskCommand;
use Netresearch\NrLlm\Command\WorkAgentQueueCommand;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\TaskRunRequestFactory;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Task\TaskInputResolverInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AgentStateEnvelopeMarker;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The whole way (ADR-234): a task is queued from the command line, nothing runs
 * until the worker command comes along, and the run row carries the outcome, the
 * origin and the answer against a real database.
 */
#[CoversClass(EnqueueTaskCommand::class)]
#[CoversClass(WorkAgentQueueCommand::class)]
#[CoversClass(AgentRunRepository::class)]
final class BackgroundTaskQueueTest extends AbstractFunctionalTestCase
{
    private AgentRunRepository $repository;

    private int $loops = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');

        $this->repository = new AgentRunRepository($this->pool(), $this->getService(AgentStateCodec::class));
        $this->loops      = 0;

        $this->pool()->getConnectionForTable('tx_nrllm_task')->insert('tx_nrllm_task', [
            'pid'               => 0,
            'identifier'        => 'digest',
            'name'              => 'Digest',
            'prompt_template'   => 'Digest {{input}}',
            'input_type'        => 'manual',
            'output_format'     => 'markdown',
            'is_active'         => 1,
            'configuration_uid' => 0,
        ]);
    }

    #[Test]
    public function bothCommandsCanBeScheduled(): void
    {
        $names = array_keys(iterator_to_array($this->getService(CommandRegistry::class)->getSchedulableCommands()));

        self::assertContains('nrllm:agent:work', $names);
        self::assertContains('nrllm:task:enqueue', $names);
    }

    #[Test]
    public function aTaskQueuedOnTheCommandLineRunsInTheWorkerAndLeavesItsAnswer(): void
    {
        $runtime = $this->runtime();
        $enqueue = new CommandTester(new EnqueueTaskCommand(
            $this->getService(TaskRepository::class),
            $this->getService(TaskInputResolverInterface::class),
            new TaskRunRequestFactory($this->managerResolvingAConfiguration()),
            $runtime,
        ));

        self::assertSame(Command::SUCCESS, $enqueue->execute(['task' => 'digest', '--input' => 'the week']));

        // Queued, and nothing ran: the producer of a default installation does
        // not pay for the work.
        self::assertSame(0, $this->loops);
        $queued = $this->repository->findQueued(10);
        self::assertCount(1, $queued);
        self::assertSame('task:digest', $queued[0]->origin);
        self::assertNull($queued[0]->answer);

        $work = new CommandTester($this->worker($runtime));

        self::assertSame(Command::SUCCESS, $work->execute([]));
        self::assertSame(1, $this->loops);
        self::assertSame([], $this->repository->findQueued(10));

        $run = $this->repository->findByUuid($queued[0]->uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::COMPLETED, $run->statusEnum());
        self::assertSame('answer 1', $run->answer);
        self::assertSame(0, $run->beUser);

        // The status every reader goes through keeps both fields.
        $status = $runtime->status(AiActorContext::serviceAccount('test', [ServiceAccountScope::AGENT_READ]), $run->uuid);
        self::assertInstanceOf(AgentRun::class, $status);
        self::assertSame('answer 1', $status->answer);
        self::assertSame('task:digest', $status->origin);

        // The answer is sealed like the queued request, not stored in the clear.
        $stored = $this->pool()->getConnectionForTable('tx_nrllm_agentrun')->select(['answer'], 'tx_nrllm_agentrun', ['uid' => $run->uid])->fetchOne();
        self::assertIsString($stored);
        self::assertStringNotContainsString('answer 1', $stored);
    }

    #[Test]
    public function aSecondTickFindsNothingToDo(): void
    {
        $runtime = $this->runtime();
        $task    = $this->getService(TaskRepository::class)->findOneByIdentifier('digest');
        self::assertNotNull($task);
        $request = (new TaskRunRequestFactory($this->managerResolvingAConfiguration()))->build(
            $task,
            'x',
            AiActorContext::serviceAccount('test', [ServiceAccountScope::CONFIGURATION_USE]),
        );
        self::assertNotNull($request);
        $runtime->defer($request);

        (new CommandTester($this->worker($runtime)))->execute([]);
        (new CommandTester($this->worker($runtime)))->execute([]);

        self::assertSame(1, $this->loops);
    }

    #[Test]
    public function theQueueIsWorkedOldestFirst(): void
    {
        $older      = $this->repository->enqueueRun('older', 0, 'cfg', 0, '{}');
        $newer      = $this->repository->enqueueRun('newer', 0, 'cfg', 0, '{}');
        $oldest     = $this->repository->enqueueRun('oldest', 0, 'cfg', 0, '{}');
        $connection = $this->pool()->getConnectionForTable('tx_nrllm_agentrun');
        $connection->update('tx_nrllm_agentrun', ['queued_at' => 100], ['uid' => $older]);
        $connection->update('tx_nrllm_agentrun', ['queued_at' => 200], ['uid' => $newer]);
        $connection->update('tx_nrllm_agentrun', ['queued_at' => 50], ['uid' => $oldest]);

        self::assertSame(['oldest', 'older'], array_map(static fn (AgentRun $run): string => $run->uuid, $this->repository->findQueued(2)));
    }

    #[Test]
    public function theBackgroundHistoryHoldsOnlyRunsWithAnOriginAndFollowsTheUser(): void
    {
        $this->repository->enqueueRun('plain', 0, 'cfg', 3, '{}');
        $this->repository->enqueueRun('mine', 0, 'cfg', 3, '{}', 'task:a');
        $this->repository->enqueueRun('theirs', 0, 'cfg', 4, '{}', 'task:b');

        $all  = array_map(static fn (AgentRun $run): string => $run->uuid, $this->repository->findBackground());
        $mine = array_map(static fn (AgentRun $run): string => $run->uuid, $this->repository->findBackground(50, 3));

        self::assertSame(['theirs', 'mine'], $all);
        self::assertSame(['mine'], $mine);
    }

    /**
     * One value that cannot be decrypted is a problem of that row. A list that
     * read the sealed columns would throw for all of them.
     */
    #[Test]
    public function aSealedValueThatCannotBeReadTakesNoListDown(): void
    {
        $uid = $this->repository->enqueueRun('broken', 0, 'cfg', 0, '{}', 'task:a');
        $this->pool()->getConnectionForTable('tx_nrllm_agentrun')->update(
            'tx_nrllm_agentrun',
            ['queued_request' => AgentStateEnvelopeMarker::LEGACY_MARKER . 'broken', 'answer' => AgentStateEnvelopeMarker::LEGACY_MARKER . 'broken'],
            ['uid' => $uid],
        );

        self::assertCount(1, $this->repository->findQueued(10));
        self::assertCount(1, $this->repository->findBackground());

        $this->pool()->getConnectionForTable('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['status' => 'completed'], ['uid' => $uid]);
        self::assertCount(1, $this->repository->findRecentTerminal());
    }

    private function managerResolvingAConfiguration(): LlmServiceManagerInterface
    {
        $configuration = $this->getService(LlmConfigurationRepository::class)->findByUid(1);
        self::assertInstanceOf(LlmConfiguration::class, $configuration);

        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('resolveEffectiveConfiguration')->willReturn($configuration);

        return $manager;
    }

    private function worker(AgentRuntime $runtime): WorkAgentQueueCommand
    {
        return new WorkAgentQueueCommand(
            $this->persister(),
            $runtime,
            $this->getService(BudgetServiceInterface::class),
            $this->getService(LlmConfigurationRepository::class),
        );
    }

    private function persister(): AgentRunPersister
    {
        return new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
    }

    private function runtime(): AgentRuntime
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(function (): ToolLoopResult {
            ++$this->loops;

            return new ToolLoopResult('answer ' . $this->loops, [], 1, false, UsageStatistics::fromTokens(1, 1));
        });

        return new AgentRuntime(
            $loop,
            $this->persister(),
            $this->getService(LlmConfigurationRepository::class),
            new NullLogger(),
            self::createStub(MessageBusInterface::class),
            actingBackendUserResolver: self::createStub(ActingBackendUserResolverInterface::class),
            privacyPolicy: new FixedPrivacyPolicy(PrivacyLevel::METADATA),
        );
    }

    private function pool(): ConnectionPool
    {
        return $this->getService(ConnectionPool::class);
    }
}
