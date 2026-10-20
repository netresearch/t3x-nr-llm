<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The old worker of a reclaimed run writes nothing into the new owner's
 * stream when the run is cancelled (ADR-103, ADR-104): a cancel clears the
 * claim, so the claim generation the reclaim raised tells the two apart.
 */
#[CoversClass(AgentRunExecutor::class)]
final class AgentRunExecutorReclaimedCancelTest extends AbstractFunctionalTestCase
{
    private AgentRunPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->persister = new AgentRunPersister(
            new AgentRunRepository($this->pool(), $this->get(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
    }

    /**
     * A segment of an older claim generation stops as lease-lost at the
     * cancel and writes no step; the segment that owns the cancelled run
     * records its step and stops as cancelled.
     */
    #[Test]
    public function theOldWorkerOfAReclaimedRunWritesNothingAtTheCancel(): void
    {
        foreach (['reclaimed' => true, 'still owned' => false] as $case => $reclaimed) {
            $handle = $this->persister->begin(null, 2, 'worker:a', time() + 600);
            self::assertInstanceOf(AgentRunHandle::class, $handle);
            self::assertSame(0, $handle->claimGeneration, $case);

            $eventsBefore = 0;
            $result       = $this->executor()->executeResume(
                $handle,
                null,
                AiActorContext::backendUser(2),
                function (ToolExecutionContext $context, RunTrace $trace) use ($handle, $reclaimed, &$eventsBefore): ToolLoopResult {
                    if ($reclaimed) {
                        // The reaper re-queued the run and worker B claimed it.
                        $this->pool()->getConnectionForTable('tx_nrllm_agentrun')->update(
                            'tx_nrllm_agentrun',
                            ['requeue_count' => 1, 'claimed_by' => 'worker:b'],
                            ['uuid' => $handle->uuid],
                        );
                    }

                    self::assertTrue($this->persister->cancel($handle->uuid));
                    $eventsBefore = count($this->persister->findEvents($handle->runUid));
                    $trace->recordRequest(1, [], []);

                    return new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1));
                },
                'worker:a',
            );

            $run = $this->persister->findRun($handle->uuid);
            self::assertInstanceOf(AgentRun::class, $run);
            if ($reclaimed) {
                self::assertSame(AgentRunOutcome::LEASE_LOST, $result->outcome, $case);
                self::assertCount($eventsBefore, $this->persister->findEvents($run->uid), $case . ": no step in the new owner's stream");
            } else {
                self::assertSame(AgentRunOutcome::CANCELLED, $result->outcome, $case);
                self::assertCount($eventsBefore + 1, $this->persister->findEvents($run->uid), $case . ': the step is recorded before the stop');
            }
        }
    }

    /**
     * A segment's handle carries the generation of the row it was claimed
     * from.
     */
    #[Test]
    public function aResumeHandleCarriesTheRunsClaimGeneration(): void
    {
        $handle = $this->persister->begin(null, 2);
        self::assertInstanceOf(AgentRunHandle::class, $handle);
        $this->pool()->getConnectionForTable('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['requeue_count' => 3], ['uuid' => $handle->uuid]);

        $run = $this->persister->findRun($handle->uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(3, $this->persister->resumeHandle($run)?->claimGeneration);
    }

    private function pool(): ConnectionPool
    {
        return GeneralUtility::makeInstance(ConnectionPool::class);
    }

    private function executor(): AgentRunExecutor
    {
        $loop     = self::createStub(ToolLoopServiceInterface::class);
        $resolver = self::createStub(ActingBackendUserResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->setUpBackendUser(1));

        return new AgentRunExecutor($loop, $this->persister, new NullLogger(), $resolver);
    }
}
