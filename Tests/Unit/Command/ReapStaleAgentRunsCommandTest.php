<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\ReapStaleAgentRunsCommand;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryAgentRunRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(ReapStaleAgentRunsCommand::class)]
final class ReapStaleAgentRunsCommandTest extends TestCase
{
    private InMemoryAgentRunRepository $repository;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new InMemoryAgentRunRepository();
        $this->dispatched = [];
    }

    #[Test]
    public function reclaimsAStaleRunUnderBudgetAndDispatchesAWakeUp(): void
    {
        $this->repository->staleRunning = [$this->staleRun('run-a', requeueCount: 0)];

        $tester = new CommandTester($this->command());
        $exit   = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertCount(1, $this->repository->staleRequeues);
        self::assertSame([], $this->repository->staleDeadLetters);
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(AgentRunQueuedMessage::class, $this->dispatched[0]);
        self::assertStringContainsString('1 requeued', $tester->getDisplay());
    }

    #[Test]
    public function deadLettersAStaleRunWhoseBudgetIsSpent(): void
    {
        $this->repository->staleRunning = [$this->staleRun('run-b', requeueCount: AgentRuntime::MAX_REQUEUES)];

        $tester = new CommandTester($this->command());
        $exit   = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame([], $this->repository->staleRequeues);
        self::assertCount(1, $this->repository->staleDeadLetters);
        self::assertSame(AgentRunTerminationReason::RETRIES_EXHAUSTED->value, $this->repository->staleDeadLetters[0]['reason']);
    }

    #[Test]
    public function deadLettersARunReapedMidNonIdempotentWriteEvenUnderBudget(): void
    {
        // ADR-111: the write may already have landed, so reclaiming it onto the
        // queue could double it — dead-letter regardless of the retry budget.
        $this->repository->staleRunning = [
            $this->staleRun('run-w', requeueCount: 0, pendingEffect: ToolEffect::NON_IDEMPOTENT_WRITE->value),
        ];

        $tester = new CommandTester($this->command());
        $exit   = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame([], $this->repository->staleRequeues, 'not reclaimed onto the queue');
        self::assertSame(AgentRunTerminationReason::NOT_RETRYABLE->value, $this->repository->staleDeadLetters[0]['reason']);
    }

    #[Test]
    public function reclaimsARunReapedMidIdempotentWrite(): void
    {
        // An idempotent write converges on repeat, so a stale reclaim is safe.
        $this->repository->staleRunning = [
            $this->staleRun('run-i', requeueCount: 0, pendingEffect: ToolEffect::IDEMPOTENT_WRITE->value),
        ];

        $tester = new CommandTester($this->command());
        $exit   = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame([], $this->repository->staleDeadLetters, 'reclaimed, not dead-lettered');
        self::assertCount(1, $this->repository->staleRequeues);
        // Reclaimed onto the queue -> a wake-up is dispatched.
        self::assertCount(1, $this->dispatched);
        self::assertStringContainsString('1 requeued', $tester->getDisplay());
    }

    #[Test]
    public function skipsARunRenewedBetweenSelectAndUpdate(): void
    {
        // The repository's staleness re-check fails (a heartbeat renewed the
        // lease after the reaper's SELECT), so requeueStale returns false.
        $this->repository->staleRunning     = [$this->staleRun('run-c', requeueCount: 0)];
        $this->repository->refuseRequeueStale = true;

        $tester = new CommandTester($this->command());
        $exit   = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertCount(0, $this->dispatched);
        self::assertStringContainsString('1 skipped', $tester->getDisplay());
    }

    #[Test]
    public function rejectsANonPositiveLimit(): void
    {
        $tester = new CommandTester($this->command());
        $exit   = $tester->execute(['--limit' => '0']);

        self::assertSame(Command::INVALID, $exit);
    }

    #[Test]
    public function failsWhenNoMessageBusIsAvailable(): void
    {
        $persister = new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));
        $tester    = new CommandTester(new ReapStaleAgentRunsCommand($persister, null));

        $exit = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
    }

    private function command(): ReapStaleAgentRunsCommand
    {
        $persister = new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));

        return new ReapStaleAgentRunsCommand($persister, $this->recordingBus());
    }

    #[Test]
    public function failsAReclaimedRunWhoseWakeUpCannotBeDispatchedToAvoidAnOrphan(): void
    {
        $this->repository->staleRunning = [$this->staleRun('run-d', requeueCount: 0)];

        $persister = new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));
        $tester    = new CommandTester(new ReapStaleAgentRunsCommand($persister, $this->throwingBus()));
        $exit      = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        // The run was reclaimed (RUNNING -> QUEUED) but the wake-up dispatch
        // failed. Fail-closed: settle it FAILED instead of leaving an orphaned
        // QUEUED row nothing would ever pick up (the reaper only re-finds stale
        // RUNNING rows, and no message will arrive).
        self::assertCount(1, $this->repository->staleRequeues);
        self::assertCount(1, $this->repository->finished);
        self::assertSame('failed', $this->repository->finished[0]['status']);
        self::assertCount(0, $this->dispatched);
        self::assertStringContainsString('0 requeued', $tester->getDisplay());
        self::assertStringContainsString('1 dead-lettered', $tester->getDisplay());
    }

    private function recordingBus(): MessageBusInterface
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message, array $stamps = []): Envelope {
                $this->dispatched[] = $message;

                return new Envelope($message, $stamps);
            },
        );

        return $bus;
    }

    private function throwingBus(): MessageBusInterface
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new RuntimeException('transport down', 1));

        return $bus;
    }

    private function staleRun(string $uuid, int $requeueCount, string $pendingEffect = ''): AgentRun
    {
        return new AgentRun(
            uid: 1,
            uuid: $uuid,
            status: 'running',
            configurationUid: 1,
            configurationIdentifier: 'cfg',
            beUser: 9,
            iterations: 1,
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
            suspendedState: null,
            queuedRequest: '{"messages":[]}',
            claimedBy: 'dead-worker:1',
            leaseExpires: 1,
            requeueCount: $requeueCount,
            pendingEffect: $pendingEffect,
        );
    }
}
