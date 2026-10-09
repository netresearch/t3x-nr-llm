<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\AgentStatusCommand;
use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsReaderInterface;
use Netresearch\NrLlm\Service\Agent\Operations\WorkerOperationsSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AgentStatusCommand::class)]
final class AgentStatusCommandTest extends TestCase
{
    #[Test]
    public function healthyJsonContainsOnlyAggregatesAndMeasuredZero(): void
    {
        $repository = $this->createMock(WorkerOperationsReaderInterface::class);
        $repository
            ->expects(self::once())
            ->method('snapshot')
            ->with(
                self::callback(static fn(mixed $value): bool => is_int($value)),
                120,
                'doctrine',
            )
            ->willReturn(new WorkerOperationsSnapshot(1, 2, 0, 0, 0, 0, 3));
        $tester = new CommandTester(new AgentStatusCommand($repository));
        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            [
                'queued' => 1,
                'running' => 2,
                'dead_lettered' => 0,
                'expired_leases' => 0,
                'oldest_queue_wait_seconds' => 0,
                'unknown_queue_wait' => 0,
                'live_workers' => 3,
                'healthy' => true,
            ],
            $data,
        );
    }

    #[Test]
    public function eachHealthFailureProducesAnUnhealthyExit(): void
    {
        foreach ([
            new WorkerOperationsSnapshot(0, 0, 0, 1, null, 0, 1),
            new WorkerOperationsSnapshot(1, 0, 0, 0, 301, 0, 1),
            new WorkerOperationsSnapshot(1, 0, 0, 0, null, 1, 1),
            new WorkerOperationsSnapshot(0, 0, 0, 0, null, 0, 0),
        ] as $snapshot) {
            $repository = self::createStub(WorkerOperationsReaderInterface::class);
            $repository->method('snapshot')->willReturn($snapshot);
            $tester = new CommandTester(new AgentStatusCommand($repository));
            self::assertSame(
                Command::FAILURE,
                $tester->execute(['--json' => true]),
            );
            self::assertStringContainsString(
                '"healthy": false',
                $tester->getDisplay(),
            );
        }
    }

    #[Test]
    public function exactWaitThresholdAndExplicitZeroWorkerRequirementPass(): void
    {
        $repository = self::createStub(WorkerOperationsReaderInterface::class);
        $repository
            ->method('snapshot')
            ->willReturn(new WorkerOperationsSnapshot(1, 0, 0, 0, 300, 0, 0));
        $tester = new CommandTester(new AgentStatusCommand($repository));
        self::assertSame(
            Command::SUCCESS,
            $tester->execute(['--minimum-workers' => '0']),
        );
    }

    #[Test]
    public function malformedLimitsAndTransportNeverQueryTheDatabase(): void
    {
        foreach ([
            ['--max-queue-wait' => '-1'],
            ['--max-queue-wait' => '1.5'],
            ['--minimum-workers' => 'garbage'],
            ['--worker-max-age' => '0'],
            ['--transport' => '../private'],
            ['--minimum-workers' => '999999999999999999999999999'],
        ] as $options) {
            $repository = $this->createMock(WorkerOperationsReaderInterface::class);
            $repository->expects(self::never())->method('snapshot');
            $tester = new CommandTester(new AgentStatusCommand($repository));
            self::assertSame(Command::INVALID, $tester->execute($options));
        }
    }
}
