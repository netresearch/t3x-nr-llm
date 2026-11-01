<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\EnqueueTaskCommand;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Agent\TaskRunRequestFactory;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Task\TaskInputResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(EnqueueTaskCommand::class)]
final class EnqueueTaskCommandTest extends TestCase
{
    /** @var list<AgentRunRequest> */
    private array $deferred = [];

    private ?LlmConfiguration $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deferred      = [];
        $this->configuration = new LlmConfiguration();
    }

    #[Test]
    public function queuesTheTaskWithTheGivenInputAsAServiceAccount(): void
    {
        $tester = new CommandTester($this->command($this->task()));

        self::assertSame(Command::SUCCESS, $tester->execute(['task' => 'summarise', '--input' => 'the text']));

        self::assertCount(1, $this->deferred);
        $request = $this->deferred[0];
        self::assertSame('task:summarise', $request->origin);
        self::assertSame('Summarise the text', $request->messages[0]->content ?? null);

        // Never the _cli_ admin: a service account holding the one scope a
        // restricted configuration asks of it, and no backend privilege.
        self::assertTrue($request->actor->isServiceAccount());
        self::assertFalse($request->actor->isAdmin);
        self::assertSame(0, $request->actor->backendUserUid);
        self::assertSame([ServiceAccountScope::CONFIGURATION_USE], $request->actor->scopes);
        self::assertStringContainsString('Queued run uuid-1', $tester->getDisplay());
    }

    #[Test]
    public function aTaskWithASourceReadsItWhenItIsQueuedAndIgnoresTheOption(): void
    {
        $task = $this->task(inputType: Task::INPUT_SYSLOG, template: 'Read {{input}}');

        (new CommandTester($this->command($task, resolved: 'the log')))->execute(['task' => 'summarise', '--input' => 'ignored']);

        self::assertSame('Read the log', $this->deferred[0]->messages[0]->content ?? null);
    }

    #[Test]
    public function refusesAManualTaskWithoutInput(): void
    {
        $tester = new CommandTester($this->command($this->task()));

        self::assertSame(Command::INVALID, $tester->execute(['task' => 'summarise', '--input' => '   ']));
        self::assertSame([], $this->deferred);
        self::assertStringContainsString('--input', $tester->getDisplay());
    }

    #[Test]
    public function refusesAnUnknownTask(): void
    {
        $tester = new CommandTester($this->command(null));

        self::assertSame(Command::INVALID, $tester->execute(['task' => 'nope']));
        self::assertSame([], $this->deferred);
    }

    #[Test]
    public function refusesAnInactiveTask(): void
    {
        $task = $this->task();
        $task->setIsActive(false);

        $tester = new CommandTester($this->command($task));

        self::assertSame(Command::INVALID, $tester->execute(['task' => 'summarise', '--input' => 'x']));
        self::assertSame([], $this->deferred);
    }

    #[Test]
    public function failsWithoutAConfigurationToRunOn(): void
    {
        $this->configuration = null;

        $tester = new CommandTester($this->command($this->task()));

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'summarise', '--input' => 'x']));
        self::assertSame([], $this->deferred);
    }

    #[Test]
    public function failsWhenTheRunCannotBeStored(): void
    {
        $tester = new CommandTester($this->command($this->task(), storeFails: true));

        self::assertSame(Command::FAILURE, $tester->execute(['task' => 'summarise', '--input' => 'x']));
    }

    private function task(string $inputType = Task::INPUT_MANUAL, string $template = 'Summarise {{input}}'): Task
    {
        $task = new Task();
        $task->setIdentifier('summarise');
        $task->setPromptTemplate($template);
        $task->setInputType($inputType);
        $task->setIsActive(true);

        return $task;
    }

    private function command(?Task $task, string $resolved = '', bool $storeFails = false): EnqueueTaskCommand
    {
        $repository = self::createStub(TaskRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($task);

        $inputs = self::createStub(TaskInputResolverInterface::class);
        $inputs->method('resolve')->willReturn($resolved);

        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('resolveEffectiveConfiguration')->willReturnCallback(fn (): ?LlmConfiguration => $this->configuration);

        $runtime = self::createStub(AgentRuntimeInterface::class);
        $runtime->method('defer')->willReturnCallback(
            function (AgentRunRequest $request) use ($storeFails): string {
                if ($storeFails) {
                    throw RunEnqueueFailedException::forRun('');
                }

                $this->deferred[] = $request;

                return 'uuid-1';
            },
        );

        return new EnqueueTaskCommand($repository, $inputs, new TaskRunRequestFactory($manager), $runtime);
    }
}
