<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AgentRunStatusTest extends TestCase
{
    #[Test]
    public function valuesListsAllBackingStrings(): void
    {
        self::assertSame(
            ['queued', 'running', 'waiting_for_approval', 'waiting_for_input', 'waiting_for_dependency', 'completed', 'failed', 'cancelled'],
            AgentRunStatus::values(),
        );
    }

    #[Test]
    public function awaitingValuesAreExactlyTheTwoHumanWaitingStatuses(): void
    {
        self::assertSame(
            ['waiting_for_approval', 'waiting_for_input'],
            AgentRunStatus::awaitingValues(),
        );
    }

    #[Test]
    public function isValidIsTrueForKnownAndFalseForUnknown(): void
    {
        self::assertTrue(AgentRunStatus::isValid('running'));
        self::assertTrue(AgentRunStatus::isValid('waiting_for_approval'));
        self::assertFalse(AgentRunStatus::isValid('bogus'));
    }

    #[Test]
    public function tryFromStringReturnsNullForUnknown(): void
    {
        self::assertNull(AgentRunStatus::tryFromString('bogus'));
        self::assertSame(AgentRunStatus::COMPLETED, AgentRunStatus::tryFromString('completed'));
    }

    #[Test]
    public function terminalStatesAreCompletedFailedCancelled(): void
    {
        self::assertTrue(AgentRunStatus::COMPLETED->isTerminal());
        self::assertTrue(AgentRunStatus::FAILED->isTerminal());
        self::assertTrue(AgentRunStatus::CANCELLED->isTerminal());
    }

    #[Test]
    public function nonTerminalStatesAreQueuedRunningAndWaiting(): void
    {
        self::assertFalse(AgentRunStatus::QUEUED->isTerminal());
        self::assertFalse(AgentRunStatus::RUNNING->isTerminal());
        self::assertFalse(AgentRunStatus::WAITING_FOR_APPROVAL->isTerminal());
        self::assertFalse(AgentRunStatus::WAITING_FOR_INPUT->isTerminal());
        self::assertFalse(AgentRunStatus::WAITING_FOR_DEPENDENCY->isTerminal());
    }

    /**
     * Spec 051 A2A-R1: a run waiting for a remote agent waits for nobody of the
     * installation. It is not terminal, so retention keeps it as work in
     * flight, and it is not in awaitingValues(), so the inbox never offers a
     * decision nobody can make.
     */
    #[Test]
    public function waitingForADependencyIsNeitherTerminalNorADecision(): void
    {
        self::assertSame('waiting_for_dependency', AgentRunStatus::WAITING_FOR_DEPENDENCY->value);
        self::assertContains('waiting_for_dependency', AgentRunStatus::nonTerminalValues());
        self::assertNotContains('waiting_for_dependency', AgentRunStatus::terminalValues());
        self::assertNotContains('waiting_for_dependency', AgentRunStatus::awaitingValues());
    }
}
