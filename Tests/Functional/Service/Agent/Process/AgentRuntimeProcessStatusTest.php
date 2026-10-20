<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\Process;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunLineage;
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunRequestCodec;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessInvocationRefusedException;
use Netresearch\NrLlm\Service\Agent\Process\ApprovedProcessPinProbe;
use Netresearch\NrLlm\Service\Agent\Process\ProcessRunPreparer;
use Netresearch\NrLlm\Service\Agent\Process\SubjectRecordAccess;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Skill\SkillApprovalRepository;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;
use Netresearch\NrLlm\Service\Skill\SkillRecordLookup;
use Netresearch\NrLlm\Service\Skill\SkillSourceLookup;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * What a run's initiator reads about a guided process (ADR-228): the status
 * says whether the run holds one and names its skill, and a queued start the
 * worker refuses ends as a failed run with a policy reason — not retried, not
 * dropped.
 */
#[CoversClass(AgentRuntime::class)]
#[CoversClass(AgentRunExecutor::class)]
final class AgentRuntimeProcessStatusTest extends AbstractFunctionalTestCase
{
    use ApprovedSkillsTrait;

    private const SOURCE = 10;

    private const OWNER = 2;

    private AgentRunPersister $persister;

    private bool $looped = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->seedSkillSource(self::SOURCE);
        $this->persister = new AgentRunPersister(
            new AgentRunRepository($this->pool(), $this->get(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
    }

    #[Test]
    public function theStatusNamesTheProcessARunHolds(): void
    {
        $handle = $this->begin();
        self::assertTrue($this->persister->recordLineage($handle, new RunLineage(30, $this->pin(), processSkill: '10:skills/tour-5/SKILL.md')));

        $status = $this->runtime()->status(AiActorContext::backendUser(self::OWNER), $handle->uuid);

        self::assertInstanceOf(AgentRun::class, $status);
        self::assertTrue($status->holdsProcess);
        self::assertSame('10:skills/tour-5/SKILL.md', $status->processSkill);
    }

    /**
     * A run without a process, and one whose process was released, say so;
     * a lineage that cannot be read leaves the field unknown, and the status
     * still answers.
     */
    #[Test]
    public function theStatusOfARunWithoutAProcessSaysSo(): void
    {
        $plain  = $this->begin();
        $status = $this->runtime()->status(AiActorContext::backendUser(self::OWNER), $plain->uuid);
        self::assertFalse($status?->holdsProcess);
        self::assertSame('', $status?->processSkill);

        $released = $this->begin();
        self::assertTrue($this->persister->recordLineage($released, (new RunLineage(30, $this->pin(), processSkill: 'tour'))->withReleasedProcess()));
        $status = $this->runtime()->status(AiActorContext::backendUser(self::OWNER), $released->uuid);
        self::assertFalse($status?->holdsProcess);
        self::assertSame('', $status?->processSkill);

        $broken = $this->begin();
        $this->pool()->getConnectionForTable('tx_nrllm_agentrun_event')->insert('tx_nrllm_agentrun_event', [
            'pid' => 0, 'run' => $broken->runUid, 'sequence' => 0, 'kind' => 'lineage', 'payload' => '{}', 'crdate' => time(),
        ]);
        $status = $this->runtime()->status(AiActorContext::backendUser(self::OWNER), $broken->uuid);
        self::assertInstanceOf(AgentRun::class, $status);
        self::assertNull($status->holdsProcess);
    }

    /**
     * The worker checks a queued invocation at pickup. A refusal settles the
     * run failed with its class and a policy reason, once: it is neither
     * requeued nor dead-lettered, and the loop never runs.
     */
    #[Test]
    public function aQueuedStartTheWorkerRefusesEndsAsAFailedRunWithAReason(): void
    {
        $configuration = $this->configuration($this->approvedSkill(5, self::SOURCE, process: true));
        $runtime       = $this->runtime($configuration);

        $uuid = $runtime->enqueue(new AgentRunRequest(
            configuration: $configuration,
            messages: [ChatMessage::user('go')],
            actor: AiActorContext::backendUser(self::OWNER),
            invocation: new SkillInvocation(99),
        ));
        $result = $runtime->runQueued($uuid);

        self::assertInstanceOf(ProcessInvocationRefusedException::class, $result?->error);
        self::assertFalse($this->looped);

        $status = $runtime->status(AiActorContext::backendUser(self::OWNER), $uuid);
        self::assertInstanceOf(AgentRun::class, $status);
        self::assertSame(AgentRunStatus::FAILED, $status->statusEnum());
        self::assertSame(ProcessInvocationRefusedException::class, $status->errorClass);
        self::assertSame(AgentRunTerminationReason::POLICY_DENIED, $status->terminationReasonEnum());
        self::assertSame(0, $status->requeueCount);
    }

    /**
     * The other direction: the same queued start with an attached process
     * runs, and its status names the process.
     */
    #[Test]
    public function aQueuedStartTheWorkerAcceptsRunsWithItsProcess(): void
    {
        $configuration = $this->configuration($this->approvedSkill(5, self::SOURCE, process: true));
        $runtime       = $this->runtime($configuration);

        $uuid = $runtime->enqueue(new AgentRunRequest(
            configuration: $configuration,
            messages: [ChatMessage::user('go')],
            actor: AiActorContext::backendUser(self::OWNER),
            invocation: new SkillInvocation(5),
        ));
        $runtime->runQueued($uuid);

        self::assertTrue($this->looped);
        $status = $runtime->status(AiActorContext::backendUser(self::OWNER), $uuid);
        self::assertSame(AgentRunStatus::COMPLETED, $status?->statusEnum());
        self::assertTrue($status?->holdsProcess);
        self::assertSame('10:skills/tour-5/SKILL.md', $status?->processSkill);
    }

    private function pin(): SkillPin
    {
        return new SkillPin(5, self::SOURCE, str_repeat('a', 64));
    }

    private function begin(): AgentRunHandle
    {
        $handle = $this->persister->begin(null, self::OWNER);
        self::assertInstanceOf(AgentRunHandle::class, $handle);

        return $handle;
    }

    private function configuration(Skill ...$skills): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', 30);
        $configuration->setIdentifier('cfg-process');
        $configuration->setIsActive(true);
        foreach ($skills as $skill) {
            $configuration->addSkill($skill);
        }

        return $configuration;
    }

    private function runtime(?LlmConfiguration $configuration = null): AgentRuntime
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findByUid')->willReturn($configuration);

        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(function (): ToolLoopResult {
            $this->looped = true;

            return new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1));
        });

        $admin    = $this->setUpBackendUser(1);
        $resolver = self::createStub(ActingBackendUserResolverInterface::class);
        $resolver->method('resolve')->willReturn($admin);

        $factory  = $this->composerFactory();
        $executor = new AgentRunExecutor($loop, $this->persister, new NullLogger(), $resolver, processPreparer: new ProcessRunPreparer(
            $this->persister,
            new ConfigurationResolver(),
            $factory->create(),
            new SkillPinCheck(new SkillApprovalRepository($this->pool()), new SkillSourceLookup($this->pool()), new SkillRecordLookup($this->pool()), $factory),
            new ApprovedProcessPinProbe(new SkillApprovalRepository($this->pool())),
            new SubjectRecordAccess($this->pool(), new TableReadAccessService()),
        ));

        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => $message instanceof Envelope ? $message : new Envelope($message));

        return new AgentRuntime(
            $loop,
            $this->persister,
            $repository,
            new NullLogger(),
            $bus,
            actingBackendUserResolver: $resolver,
            requestCodec: new AgentRunRequestCodec($repository),
            runExecutor: $executor,
        );
    }
}
