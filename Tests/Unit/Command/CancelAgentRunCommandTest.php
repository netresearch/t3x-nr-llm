<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\CancelAgentRunCommand;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CancelAgentRunCommand::class)]
final class CancelAgentRunCommandTest extends TestCase
{
    #[Test]
    public function cancelsARunThroughTheRuntimeAndReportsSuccess(): void
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::once())->method('cancel')->with(self::isInstanceOf(AiActorContext::class), 'run-uuid-1')->willReturn(true);

        $tester = new CommandTester(new CancelAgentRunCommand($runtime));
        $exit   = $tester->execute(['uuid' => 'run-uuid-1']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('was cancelled', $tester->getDisplay());
    }

    #[Test]
    public function reportsFailureForAnUnknownOrAlreadyFinishedRun(): void
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->method('cancel')->willReturn(false);

        $tester = new CommandTester(new CancelAgentRunCommand($runtime));
        $exit   = $tester->execute(['uuid' => 'gone']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('unknown or already finished', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAMissingUuidWithoutTouchingTheRuntime(): void
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::never())->method('cancel');

        $tester = new CommandTester(new CancelAgentRunCommand($runtime));
        $exit   = $tester->execute(['uuid' => '']);

        self::assertSame(Command::INVALID, $exit);
    }
}
