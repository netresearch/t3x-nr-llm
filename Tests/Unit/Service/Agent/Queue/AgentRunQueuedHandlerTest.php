<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\Queue;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedHandler;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AgentRunQueuedHandler::class)]
final class AgentRunQueuedHandlerTest extends TestCase
{
    #[Test]
    public function executesTheQueuedRunThroughTheRuntime(): void
    {
        $runtime = $this->createMock(AgentRuntimeInterface::class);
        $runtime->expects(self::once())
            ->method('runQueued')
            ->with('run-uuid-1')
            ->willReturn(new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-uuid-1', []));

        (new AgentRunQueuedHandler($runtime, new NullLogger()))(new AgentRunQueuedMessage('run-uuid-1'));
    }

    #[Test]
    public function anUnclaimableRunIsANonEventNotAFailure(): void
    {
        // Another worker won the claim (or the run was cancelled while queued):
        // the handler must complete normally so the message is not redelivered.
        $runtime = self::createStub(AgentRuntimeInterface::class);
        $runtime->method('runQueued')->willReturn(null);

        (new AgentRunQueuedHandler($runtime, new NullLogger()))(new AgentRunQueuedMessage('run-uuid-1'));

        $this->addToAssertionCount(1);
    }
}
