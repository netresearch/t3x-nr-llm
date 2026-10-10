<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RecordingAgentRunRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[CoversClass(AgentRunExecutor::class)]
#[CoversClass(AgentRuntime::class)]
final class AgentContextSettlementTest extends AbstractUnitTestCase
{
    #[Test]
    public function synchronousContextFailureSettlesTheClaimedRun(): void
    {
        $repository = new RecordingAgentRunRepository();
        $error = new RuntimeException('Backend user database unavailable', 1800100010);
        $runtime = $this->runtime($repository, $error);
        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertSame($error, $result->error);
        self::assertSame([], $result->steps);
        self::assertCount(1, $repository->startedRuns);
        self::assertStringStartsWith(
            'interactive:',
            $repository->startedRuns[0]['claimedBy'],
        );
        self::assertNotNull($repository->finished);
        self::assertSame(
            AgentRunStatus::FAILED->value,
            $repository->finished['status'],
        );
        self::assertSame(
            $repository->startedRuns[0]['claimedBy'],
            $repository->finished['ownedBy'],
        );
        self::assertSame($result->runUuid, $repository->startedRuns[0]['uuid']);
    }

    #[Test]
    public function anUnpersistedContextFailureStillReturnsTheFailedOutcome(): void
    {
        $repository = new RecordingAgentRunRepository();
        $repository->throwOnStart = true;

        $error = new RuntimeException('Backend user database unavailable', 1800100011);
        $result = $this->runtime($repository, $error)->run($this->request());

        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertSame($error, $result->error);
        self::assertSame('', $result->runUuid);
        self::assertNull($repository->finished);
    }

    #[Test]
    public function aClaimedResumeContextFailureSettlesWithoutCallingTheLoop(): void
    {
        $repository = new RecordingAgentRunRepository();
        $error = new RuntimeException('Backend user database unavailable', 1800100012);
        $executor = new AgentRunExecutor(
            $this->unusedLoop(),
            new AgentRunPersister(
                $repository,
                FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            ),
            actingBackendUserResolver: $this->throwingResolver($error),
        );
        $called = false;
        $result = $executor->executeResume(
            new AgentRunHandle(17, 'claimed-resume'),
            null,
            AiActorContext::backendUser(9),
            static function () use (&$called): ToolLoopResult {
                $called = true;
                throw new RuntimeException('The loop must not run', 1800100013);
            },
            'resume:worker:17',
        );

        self::assertFalse($called);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertSame($error, $result->error);
        self::assertSame('claimed-resume', $result->runUuid);
        self::assertNotNull($repository->finished);
        self::assertSame(17, $repository->finished['runUid']);
        self::assertSame(
            AgentRunStatus::FAILED->value,
            $repository->finished['status'],
        );
        self::assertSame('resume:worker:17', $repository->finished['ownedBy']);
    }

    #[Test]
    public function queuedContextFailureReachesTheExistingFailureRecovery(): void
    {
        $repository = new RecordingAgentRunRepository();
        $repository->findResult = new AgentRun(
            17,
            'queued-run',
            'queued',
            1,
            'fixture',
            9,
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
            queuedRequest: '{"messages":[]}',
        );
        $error = new RuntimeException('Backend user database unavailable', 1800100014);
        $result = $this->runtime($repository, $error)->runQueued('queued-run');

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertSame($error, $result->error);
        self::assertNotNull($repository->queuedClaim);
        self::assertNotNull($repository->finished);
        self::assertSame(
            AgentRunStatus::FAILED->value,
            $repository->finished['status'],
        );
        self::assertSame(
            AgentRunTerminationReason::NOT_RETRYABLE->value,
            $repository->finished['terminationReason'],
        );
        self::assertSame(
            $repository->queuedClaim['claimedBy'],
            $repository->finished['ownedBy'],
        );
        self::assertSame([], $repository->requeues);
    }

    private function runtime(
        RecordingAgentRunRepository $repository,
        RuntimeException $error,
    ): AgentRuntime {
        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findByUid')->willReturn(new LlmConfiguration());

        return new AgentRuntime(
            $this->unusedLoop(),
            new AgentRunPersister(
                $repository,
                FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            ),
            $configurations,
            actingBackendUserResolver: $this->throwingResolver($error),
        );
    }

    private function unusedLoop(): ToolLoopServiceInterface
    {
        $loop = $this->createMock(ToolLoopServiceInterface::class);
        $loop->expects(self::never())->method('runLoop');
        $loop->expects(self::never())->method('resume');
        $loop->expects(self::never())->method('resumeWithInput');
        return $loop;
    }

    private function throwingResolver(
        RuntimeException $error,
    ): ActingBackendUserResolverInterface {
        $resolver = $this->createMock(ActingBackendUserResolverInterface::class);
        $resolver
            ->expects(self::once())
            ->method('resolve')
            ->willThrowException($error);
        return $resolver;
    }

    private function request(): AgentRunRequest
    {
        return new AgentRunRequest(
            new LlmConfiguration(),
            [ChatMessage::user('go')],
            AiActorContext::backendUser(9),
        );
    }
}
