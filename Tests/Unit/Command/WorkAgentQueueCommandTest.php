<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\WorkAgentQueueCommand;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryAgentRunRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(WorkAgentQueueCommand::class)]
final class WorkAgentQueueCommandTest extends TestCase
{
    private InMemoryAgentRunRepository $repository;

    /** @var list<string> */
    private array $started = [];

    /** @var array<string, AgentRunResult|null> keyed by uuid */
    private array $results = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new InMemoryAgentRunRepository();
        $this->started    = [];
        $this->results    = [];
    }

    #[Test]
    public function executesTheQueuedRunsInTheOrderTheQueueReturnsThem(): void
    {
        $this->repository->queued = [$this->queuedRun('a'), $this->queuedRun('b')];

        $tester = new CommandTester($this->command());
        $exit   = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(['a', 'b'], $this->started);
        self::assertStringContainsString('Executed 2,', $tester->getDisplay());
        self::assertStringContainsString('completed: 2', $tester->getDisplay());
    }

    #[Test]
    public function theLimitBoundsOneTick(): void
    {
        $this->repository->queued = [$this->queuedRun('a'), $this->queuedRun('b'), $this->queuedRun('c')];

        (new CommandTester($this->command()))->execute(['--limit' => '2']);

        self::assertSame(['a', 'b'], $this->started);
    }

    #[Test]
    public function aRunAnotherWorkerClaimedIsCountedAndNotAnError(): void
    {
        $this->repository->queued = [$this->queuedRun('a')];
        $this->results['a']       = null;

        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('claimed by another worker 1', $tester->getDisplay());
        self::assertStringContainsString('Executed 0,', $tester->getDisplay());
    }

    #[Test]
    public function aFailedRunIsItsOwnOutcomeAndTheTickGoesOn(): void
    {
        $this->repository->queued = [$this->queuedRun('a'), $this->queuedRun('b')];
        $this->results['a']       = new AgentRunResult(AgentRunOutcome::FAILED, 'a', [], error: new RuntimeException('provider down', 1798000001));

        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(['a', 'b'], $this->started);
        self::assertStringContainsString('failed: 1', $tester->getDisplay());
    }

    /**
     * ADR-162: every later run of that user meets the same empty bucket, so it
     * stays queued. Another user's budget is not exhausted by this one.
     */
    #[Test]
    public function aBudgetRefusalHoldsBackThatUsersRunsAndNotOthers(): void
    {
        $this->repository->queued = [$this->queuedRun('a', beUser: 5), $this->queuedRun('b', beUser: 5), $this->queuedRun('c', beUser: 6)];
        $this->results['a']       = new AgentRunResult(
            AgentRunOutcome::COMPLETED,
            'a',
            [],
            new ToolLoopResult('', [], 1, true, UsageStatistics::fromTokens(0, 0), AgentRunTerminationReason::BUDGET_EXHAUSTED),
        );

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertSame(['a', 'c'], $this->started);
        self::assertStringContainsString('held back after a budget refusal 1', $tester->getDisplay());
    }

    #[Test]
    public function aPassedDeadlineStartsNothingMoreAndSaysWhatIsLeft(): void
    {
        $this->repository->queued = [$this->queuedRun('slow'), $this->queuedRun('b')];

        $tester = new CommandTester($this->command(sleepOn: 'slow'));
        $tester->execute(['--time-limit' => '1']);

        self::assertSame(['slow'], $this->started);
        self::assertStringContainsString('left for the next tick 1', $tester->getDisplay());
    }

    #[Test]
    public function aQueueThatCannotBeReadFailsTheCommand(): void
    {
        $this->repository->throwOnFindQueued = true;

        $tester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame([], $this->started);
    }

    #[Test]
    public function anEmptyQueueSucceeds(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Executed 0,', $tester->getDisplay());
    }

    #[Test]
    public function rejectsANonPositiveLimitOrTimeLimit(): void
    {
        self::assertSame(Command::INVALID, (new CommandTester($this->command()))->execute(['--limit' => '0']));
        self::assertSame(Command::INVALID, (new CommandTester($this->command()))->execute(['--time-limit' => 'soon']));
    }

    private function command(string $sleepOn = ''): WorkAgentQueueCommand
    {
        $runtime = self::createStub(AgentRuntimeInterface::class);
        $runtime->method('runQueued')->willReturnCallback(
            function (string $uuid) use ($sleepOn): ?AgentRunResult {
                $this->started[] = $uuid;
                if ($uuid === $sleepOn) {
                    sleep(2);
                }

                return array_key_exists($uuid, $this->results)
                    ? $this->results[$uuid]
                    : new AgentRunResult(AgentRunOutcome::COMPLETED, $uuid, []);
            },
        );

        return new WorkAgentQueueCommand(
            new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL)),
            $runtime,
        );
    }

    private function queuedRun(string $uuid, int $beUser = 0): AgentRun
    {
        return new AgentRun(
            uid: 1,
            uuid: $uuid,
            status: 'queued',
            configurationUid: 1,
            configurationIdentifier: 'cfg',
            beUser: $beUser,
            iterations: 0,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 0,
        );
    }
}
