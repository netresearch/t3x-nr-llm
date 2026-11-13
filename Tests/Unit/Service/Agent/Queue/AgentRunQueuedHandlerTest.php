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
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(AgentRunQueuedHandler::class)]
final class AgentRunQueuedHandlerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $globalsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->globalsBefore = array_intersect_key($GLOBALS, array_flip(['EXEC_TIME', 'SIM_EXEC_TIME', 'ACCESS_TIME', 'SIM_ACCESS_TIME']));
    }

    protected function tearDown(): void
    {
        foreach (['EXEC_TIME', 'SIM_EXEC_TIME', 'ACCESS_TIME', 'SIM_ACCESS_TIME'] as $global) {
            unset($GLOBALS[$global]);
            if (array_key_exists($global, $this->globalsBefore)) {
                $GLOBALS[$global] = $this->globalsBefore[$global];
            }
        }

        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    /**
     * What the process holds under a TYPO3 time global.
     */
    private function globalOf(string $name): mixed
    {
        return $GLOBALS[$name] ?? null;
    }

    /**
     * A Messenger consumer lives for hours; TYPO3 reads the time once at boot.
     * Every run it picks up starts on the current time (as the queue command's).
     */
    #[Test]
    public function aRunStartsOnTheCurrentTimeAndNotOnTheTimeTheConsumerBooted(): void
    {
        $stale = time() - 3600;
        foreach (['EXEC_TIME', 'SIM_EXEC_TIME', 'ACCESS_TIME', 'SIM_ACCESS_TIME'] as $global) {
            $GLOBALS[$global] = $stale;
        }

        $seen    = [];
        $runtime = self::createStub(AgentRuntimeInterface::class);
        $runtime->method('runQueued')->willReturnCallback(function () use (&$seen): AgentRunResult {
            $seen = [
                'exec' => $this->globalOf('EXEC_TIME'),
                'date' => GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp'),
            ];

            return new AgentRunResult(AgentRunOutcome::COMPLETED, 'run-uuid-1', []);
        });

        (new AgentRunQueuedHandler($runtime, new NullLogger()))(new AgentRunQueuedMessage('run-uuid-1'));

        self::assertIsInt($seen['exec'] ?? null);
        self::assertEqualsWithDelta(time(), $seen['exec'], 5);
        self::assertSame($seen['exec'], $seen['date']);
    }

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
