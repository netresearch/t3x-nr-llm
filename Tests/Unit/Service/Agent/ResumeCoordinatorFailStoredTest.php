<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\ResumeCoordinator;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ending the run of a stored decision whose worker cannot act: the outcome
 * says FAILED only when the settlement landed.
 */
#[CoversClass(ResumeCoordinator::class)]
final class ResumeCoordinatorFailStoredTest extends TestCase
{
    #[Test]
    public function theRunIsReportedFailedWhenTheSettlementLanded(): void
    {
        $result = $this->coordinator(settles: true)->failStored('run-1', 'worker:1', new RuntimeException('cannot act', 1799400910));

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
    }

    #[Test]
    public function aSettlementThatDidNotLandIsReportedAsALostLease(): void
    {
        $result = $this->coordinator(settles: false)->failStored('run-1', 'worker:1', new RuntimeException('cannot act', 1799400910));

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::LEASE_LOST, $result->outcome);
    }

    #[Test]
    public function aRunThatIsNoQueuedDecisionOrWasWonByAnotherWorkerIsLeftAlone(): void
    {
        self::assertNull($this->coordinator(settles: true, claims: false)->failStored('run-1', 'worker:1', new RuntimeException('cannot act', 1799400910)));
    }

    private function coordinator(bool $settles, bool $claims = true): ResumeCoordinator
    {
        $run = new AgentRun(
            1,
            'run-1',
            AgentRunStatus::QUEUED->value,
            1,
            'cfg',
            2,
            0,
            false,
            0,
            0,
            0,
            0.0,
            '',
            '',
            0,
            0,
            0,
            suspendedState: '{"messages":[]}',
        );

        $repository = self::createStub(AgentRunRepositoryInterface::class);
        $repository->method('findByUuid')->willReturn($run);
        $repository->method('claimQueued')->willReturn($claims);
        $repository->method('finishRun')->willReturn($settles);

        $persister = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));
        $loop      = self::createStub(ToolLoopServiceInterface::class);

        return new ResumeCoordinator(
            $persister,
            self::createStub(LlmConfigurationRepository::class),
            $loop,
            new AgentRunExecutor($loop, $persister),
        );
    }
}
