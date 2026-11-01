<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\WorkAgentQueueCommand;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
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

    /** @var list<int> backend users the budget refuses */
    private array $refused = [];

    /** @var list<int> configurations the budget refuses */
    private array $refusedConfigurations = [];

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
     * A refusal after the claim costs the run, so the budget is asked before it.
     * The refused run stays queued, and a run behind it is served.
     */
    #[Test]
    public function aRunTheBudgetWouldRefuseStaysQueuedAndTheNextOneRuns(): void
    {
        $this->repository->queued = [$this->queuedRun('a', beUser: 5), $this->queuedRun('b', beUser: 5), $this->queuedRun('c', beUser: 6)];
        $this->refused            = [5];

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        self::assertSame(['c'], $this->started);
        self::assertStringContainsString('held back by the budget 2', $tester->getDisplay());
    }

    #[Test]
    public function aRefusalIsOneOfThisConfigurationAndNotOfAllOfThem(): void
    {
        $this->repository->queued    = [$this->queuedRun('a', configuration: 1), $this->queuedRun('b', configuration: 2)];
        $this->refusedConfigurations = [1];

        (new CommandTester($this->command()))->execute([]);

        self::assertSame(['b'], $this->started);
    }

    /**
     * Five refused runs at the head and a limit of one: a fixed window would read
     * the same five every tick and never reach the sixth.
     */
    #[Test]
    public function heldRunsDoNotKeepThePlacesOfTheRunsBehindThem(): void
    {
        $this->repository->queued = [
            $this->queuedRun('a', beUser: 5),
            $this->queuedRun('b', beUser: 5),
            $this->queuedRun('c', beUser: 5),
            $this->queuedRun('d', beUser: 6),
            $this->queuedRun('e', beUser: 6),
        ];
        $this->refused = [5];

        (new CommandTester($this->command()))->execute(['--limit' => '1']);

        self::assertSame(['d'], $this->started);
    }

    #[Test]
    public function aTickTakesMoreThanOnePageWhenRunsWereHeld(): void
    {
        $this->repository->queued = [
            $this->queuedRun('a', beUser: 5),
            $this->queuedRun('b', beUser: 6),
            $this->queuedRun('c', beUser: 6),
        ];
        $this->refused = [5];

        (new CommandTester($this->command()))->execute(['--limit' => '2']);

        self::assertSame(['b', 'c'], $this->started);
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
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturnCallback(
            fn (int $beUser, float $planned, ?LlmConfiguration $configuration): BudgetCheckResult => in_array($beUser, $this->refused, true) || in_array($configuration?->getUid(), $this->refusedConfigurations, true)
                ? BudgetCheckResult::denied(BudgetCheckResult::LIMIT_DAILY_COST, 1.0, 1.0)
                : BudgetCheckResult::allowed(),
        );

        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findByUid')->willReturnCallback(static function (int $uid): LlmConfiguration {
            $configuration = new LlmConfiguration();
            $configuration->_setProperty('uid', $uid);

            return $configuration;
        });

        $runtime = self::createStub(AgentRuntimeInterface::class);
        $runtime->method('runQueued')->willReturnCallback(
            function (string $uuid) use ($sleepOn): ?AgentRunResult {
                $this->started[]            = $uuid;
                $this->repository->executed = $this->started;
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
            $budget,
            $configurations,
        );
    }

    private function queuedRun(string $uuid, int $beUser = 0, int $configuration = 1): AgentRun
    {
        return new AgentRun(
            uid: 1,
            uuid: $uuid,
            status: 'queued',
            configurationUid: $configuration,
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
