<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\Process;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ProcessInvocationRefusal;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunLineage;
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
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
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * What the executor hands the loop for a run that invokes or continues a
 * process (ADR-228): the pin the runtime prepared and only that one, and the
 * subject record on the tool context. Without a preparer it starts no process
 * at all.
 */
#[CoversClass(AgentRunExecutor::class)]
final class AgentRunExecutorProcessTest extends AbstractFunctionalTestCase
{
    use ApprovedSkillsTrait;

    private const SOURCE = 10;

    private AgentRunPersister $persister;

    private ?RunAugmentation $augmentationSeen = null;

    private ?ToolExecutionContext $contextSeen = null;

    private bool $looped = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->seedSkillSource(self::SOURCE);
        $this->pool()->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Home']);
        $this->persister = new AgentRunPersister(
            new AgentRunRepository($this->pool(), $this->get(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
    }

    #[Test]
    public function anInvocationHandsTheLoopThePreparedPinAndTheSubject(): void
    {
        $skill = $this->approvedSkill(5, self::SOURCE, process: true);

        $this->executor(withPreparer: true)->executeRequest(
            $this->request($this->configuration($skill), new SkillInvocation(5, new SubjectRecord(new RecordReference('pages', 1)))),
            $this->begin(),
            null,
        );

        self::assertInstanceOf(RunAugmentation::class, $this->augmentationSeen);
        self::assertEquals(new SkillPin(5, self::SOURCE, $skill->getVersionDigest()), $this->augmentationSeen->processPin);
        self::assertFalse($this->augmentationSeen->bakesSystemPrompt, 'a carrier, not a playground bake');
        self::assertTrue($this->augmentationSeen->admitsProcess, 'a new start is admitted against the window');
        self::assertSame('pages:1', (string)$this->contextSeen?->subjectRecord?->record);
    }

    /**
     * No caller hands in a pin: one on the caller's augmentation is replaced
     * by what the runtime prepared — here nothing.
     */
    #[Test]
    public function aPinOnTheCallersAugmentationIsReplaced(): void
    {
        $skill  = $this->approvedSkill(5, self::SOURCE, process: true);
        $forged = new SkillPin(5, self::SOURCE, $skill->getVersionDigest());

        $this->executor(withPreparer: true)->executeRequest(
            $this->request($this->configuration($skill), null, new RunAugmentation(processPin: $forged)),
            $this->begin(),
            null,
        );

        self::assertTrue($this->looped);
        self::assertInstanceOf(RunAugmentation::class, $this->augmentationSeen);
        self::assertNull($this->augmentationSeen->processPin);
        self::assertTrue($this->augmentationSeen->bakesSystemPrompt, 'the caller augmentation keeps its own shape');
        self::assertNull($this->contextSeen?->subjectRecord);
    }

    #[Test]
    public function anOrdinaryRunReachesTheLoopUnchanged(): void
    {
        $this->executor(withPreparer: true)->executeRequest($this->request($this->configuration()), $this->begin(), null);

        self::assertTrue($this->looped);
        self::assertNull($this->augmentationSeen);

        $this->looped = false;
        $this->executor(withPreparer: false)->executeRequest($this->request($this->configuration()), $this->begin(), null);
        self::assertTrue($this->looped, 'without a preparer too');
    }

    /**
     * Fail closed: an executor built without the preparer refuses an
     * invocation and a continuation instead of running them as ordinary
     * turns. The refusal settles the run failed, before the loop is asked.
     */
    #[Test]
    public function withoutAPreparerNoProcessStarts(): void
    {
        foreach ([
            'an invocation'  => $this->request($this->configuration(), new SkillInvocation(5)),
            'a continuation' => new AgentRunRequest(configuration: $this->configuration(), messages: [ChatMessage::user('go')], actor: AiActorContext::backendUser(2), predecessorRunUuid: 'previous'),
        ] as $case => $request) {
            $result = $this->executor(withPreparer: false)->executeRequest($request, $this->begin(), null);

            self::assertSame(AgentRunOutcome::FAILED, $result->outcome, $case);
            self::assertInstanceOf(ProcessInvocationRefusedException::class, $result->error, $case);
            self::assertSame(ProcessInvocationRefusal::LINEAGE_NOT_STORED, $result->error->reason, $case);
            self::assertSame(AgentRunStatus::FAILED, $this->persister->findRun($result->runUuid)?->statusEnum(), $case);
        }

        self::assertFalse($this->looped);
    }

    /**
     * A resumed run carries its process's subject record only while the
     * acting user may still read it (ADR-228), and only through a preparer.
     */
    #[Test]
    public function aResumedRunCarriesItsSubjectOnlyWhileItIsReadable(): void
    {
        foreach ([
            'readable'          => [1, true, 'pages:1'],
            'no longer there'   => [999, true, ''],
            'without preparer'  => [1, false, ''],
        ] as $case => [$page, $withPreparer, $expected]) {
            $handle = $this->begin();
            self::assertTrue($this->persister->recordLineage($handle, new RunLineage(30, new SkillPin(5, self::SOURCE, str_repeat('a', 64)), new SubjectRecord(new RecordReference('pages', $page)))));

            $seen = 'unset';
            $this->executor($withPreparer)->executeResume(
                $handle,
                null,
                AiActorContext::backendUser(2),
                static function (ToolExecutionContext $context) use (&$seen): ToolLoopResult {
                    $seen = (string)$context->subjectRecord?->record;

                    return new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1));
                },
            );

            self::assertSame($expected, $seen, $case);
        }
    }

    /**
     * A waiting turn of a process that was ended from another turn since it
     * suspended does not resume: the run is refused as a policy stop before
     * the loop runs. While the process holds, and for a run without one, the
     * resume goes on.
     */
    #[Test]
    public function aWaitingTurnOfAnEndedProcessDoesNotResume(): void
    {
        $pin    = new SkillPin(5, self::SOURCE, str_repeat('a', 64));
        $origin = $this->begin();
        self::assertTrue($this->persister->recordLineage($origin, new RunLineage(30, $pin, originRunUuid: $origin->uuid)));

        foreach (['process holds' => true, 'no process' => null, 'process ended' => false] as $case => $holds) {
            $turn = $this->begin();
            if ($holds !== null) {
                self::assertTrue($this->persister->recordLineage($turn, new RunLineage(30, $pin, originRunUuid: $origin->uuid)));
            }

            if ($holds === false) {
                self::assertTrue($this->persister->recordLineage($origin, (new RunLineage(30, $pin, originRunUuid: $origin->uuid))->withReleasedProcess()));
            }

            $this->looped = false;
            $result       = $this->executor(true)->executeResume(
                $turn,
                null,
                AiActorContext::backendUser(2),
                function (): ToolLoopResult {
                    $this->looped = true;

                    return new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1));
                },
            );

            if ($holds === false) {
                self::assertFalse($this->looped, $case);
                self::assertInstanceOf(ProcessInvocationRefusedException::class, $result->error, $case);
                self::assertSame(ProcessInvocationRefusal::PROCESS_ENDED, $result->error->reason, $case);
            } else {
                self::assertTrue($this->looped, $case);
            }
        }
    }

    private function request(LlmConfiguration $configuration, ?SkillInvocation $invocation = null, ?RunAugmentation $augmentation = null): AgentRunRequest
    {
        return new AgentRunRequest(
            configuration: $configuration,
            messages: [ChatMessage::user('go')],
            actor: AiActorContext::backendUser(2),
            augmentation: $augmentation,
            invocation: $invocation,
        );
    }

    private function begin(): AgentRunHandle
    {
        $handle = $this->persister->begin(null, 2);
        self::assertInstanceOf(AgentRunHandle::class, $handle);

        return $handle;
    }

    private function configuration(Skill ...$skills): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', 30);
        $configuration->setIdentifier('cfg-process');
        foreach ($skills as $skill) {
            $configuration->addSkill($skill);
        }

        return $configuration;
    }

    private function executor(bool $withPreparer): AgentRunExecutor
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(function (mixed ...$arguments): ToolLoopResult {
            $this->looped           = true;
            $context                = $arguments[2] ?? null;
            $augmentation           = $arguments[7] ?? null;
            $this->contextSeen      = $context instanceof ToolExecutionContext ? $context : null;
            $this->augmentationSeen = $augmentation instanceof RunAugmentation ? $augmentation : null;

            return new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1));
        });

        $admin    = $this->setUpBackendUser(1);
        $resolver = self::createStub(ActingBackendUserResolverInterface::class);
        $resolver->method('resolve')->willReturn($admin);

        $preparer = null;
        if ($withPreparer) {
            $factory  = $this->composerFactory();
            $preparer = new ProcessRunPreparer(
                $this->persister,
                new ConfigurationResolver(),
                $factory->create(),
                new SkillPinCheck(new SkillApprovalRepository($this->pool()), new SkillSourceLookup($this->pool()), new SkillRecordLookup($this->pool()), $factory),
                new ApprovedProcessPinProbe(new SkillApprovalRepository($this->pool())),
                new SubjectRecordAccess($this->pool(), new TableReadAccessService()),
            );
        }

        return new AgentRunExecutor($loop, $this->persister, new NullLogger(), $resolver, processPreparer: $preparer);
    }
}
