<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\Process;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ProcessInvocationRefusal;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunLineage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Exception\SkillInstructionWithdrawnException;
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
use Netresearch\NrLlm\Service\Tool\RunTrace;
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

    private int $rounds = 0;

    /** @var (Closure(RunTrace): void)|null */
    private ?Closure $loopBody = null;

    private ?SkillInstructionWithdrawnException $loopThrows = null;

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

    /**
     * A resume claims its run row a moment before the check reads it, so a
     * row the check cannot read is a store error: the resume is refused, not
     * let through as a run without a process.
     */
    #[Test]
    public function aResumeWhoseRunCannotBeReadIsRefused(): void
    {
        $result = $this->executor(true)->executeResume(
            new AgentRunHandle(999999, 'no-such-run'),
            null,
            AiActorContext::backendUser(2),
            function (): ToolLoopResult {
                $this->looped = true;

                return new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1));
            },
        );

        self::assertFalse($this->looped);
        self::assertInstanceOf(ProcessInvocationRefusedException::class, $result->error);
        self::assertSame(ProcessInvocationRefusal::LINEAGE_UNREADABLE, $result->error->reason);
    }

    /**
     * A turn that is already running when its process is ended stops at its
     * next step boundary and is settled failed as process_ended (ADR-228). A
     * turn whose process still holds, and one without a process, go on.
     */
    #[Test]
    public function aRunningTurnStopsAtTheStepAfterItsProcessWasEnded(): void
    {
        $pin = new SkillPin(5, self::SOURCE, str_repeat('a', 64));

        foreach (['process ended' => true, 'process holds' => false, 'no process' => null] as $case => $end) {
            $origin = $this->begin();
            self::assertTrue($this->persister->recordLineage($origin, new RunLineage(30, $pin, originRunUuid: $origin->uuid)));
            $turn = $this->begin();
            if ($end !== null) {
                self::assertTrue($this->persister->recordLineage($turn, new RunLineage(30, $pin, originRunUuid: $origin->uuid)));
            }

            $this->rounds = 0;
            $result = $this->executor(true)->executeResume(
                $turn,
                null,
                AiActorContext::backendUser(2),
                function (ToolExecutionContext $context, RunTrace $trace) use ($origin, $pin, $end): ToolLoopResult {
                    $trace->recordRequest(1, [], []);
                    $this->rounds = 1;
                    if ($end === true) {
                        self::assertTrue($this->persister->recordLineage($origin, (new RunLineage(30, $pin, originRunUuid: $origin->uuid))->withReleasedProcess()));
                    }

                    $trace->recordRequest(2, [], []);
                    $this->rounds = 2;

                    return new ToolLoopResult('done', [], 2, false, UsageStatistics::fromTokens(1, 1));
                },
            );

            if ($end === true) {
                self::assertSame(1, $this->rounds, $case);
                self::assertSame(AgentRunOutcome::FAILED, $result->outcome, $case);
                self::assertInstanceOf(ProcessInvocationRefusedException::class, $result->error, $case);
                self::assertSame(ProcessInvocationRefusal::PROCESS_ENDED, $result->error->reason, $case);
                self::assertSame(AgentRunStatus::FAILED, $this->persister->findRun($turn->uuid)?->statusEnum(), $case);
            } else {
                self::assertSame(2, $this->rounds, $case);
                self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, $case);
            }
        }
    }

    /**
     * The step-boundary probe fails closed: a turn of a process whose run row
     * can no longer be read stops. A run without a process does not depend
     * on that read and goes on.
     */
    #[Test]
    public function aRunningTurnOfAProcessStopsWhenItsRunCannotBeRead(): void
    {
        $pin = new SkillPin(5, self::SOURCE, str_repeat('a', 64));

        foreach (['process' => true, 'no process' => false] as $case => $withProcess) {
            $turn = $this->begin();
            if ($withProcess) {
                self::assertTrue($this->persister->recordLineage($turn, new RunLineage(30, $pin, originRunUuid: $turn->uuid)));
            }

            $this->rounds = 0;
            $result = $this->executor(true)->executeResume(
                $turn,
                null,
                AiActorContext::backendUser(2),
                function (ToolExecutionContext $context, RunTrace $trace) use ($turn): ToolLoopResult {
                    $this->pool()->getConnectionForTable('tx_nrllm_agentrun')->delete('tx_nrllm_agentrun', ['uuid' => $turn->uuid]);
                    $trace->recordRequest(1, [], []);
                    $this->rounds = 1;

                    return new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1));
                },
            );

            if ($withProcess) {
                self::assertSame(0, $this->rounds, $case);
                self::assertInstanceOf(ProcessInvocationRefusedException::class, $result->error, $case);
                self::assertSame(ProcessInvocationRefusal::LINEAGE_UNREADABLE, $result->error->reason, $case);
            } else {
                self::assertSame(1, $this->rounds, $case);
            }
        }
    }

    /**
     * A process whose approval goes away between the start checks and the
     * loop is refused like a start (ADR-228), so a queued run is settled, not
     * retried. A withdrawn pin of another skill keeps its own exception.
     */
    #[Test]
    public function aProcessWithdrawnBeforeTheLoopIsRefused(): void
    {
        $skill = $this->approvedSkill(5, self::SOURCE, process: true);
        $own   = new SkillPin(5, self::SOURCE, $skill->getVersionDigest());

        foreach (['the process pin' => [$own, true], 'another pin' => [new SkillPin(6, self::SOURCE, str_repeat('b', 64)), false]] as $case => [$pin, $refused]) {
            $this->loopThrows = new SkillInstructionWithdrawnException($pin, 'tour', 'its approval was revoked');

            $result = $this->executor(withPreparer: true)->executeRequest(
                $this->request($this->configuration($skill), new SkillInvocation(5)),
                $this->begin(),
                null,
            );

            if ($refused) {
                self::assertInstanceOf(ProcessInvocationRefusedException::class, $result->error, $case);
                self::assertSame(ProcessInvocationRefusal::PIN_WITHDRAWN, $result->error->reason, $case);
                $run = $this->persister->findRun($result->runUuid);
                self::assertInstanceOf(AgentRun::class, $run);
                self::assertSame(AgentRunStatus::FAILED, $run->statusEnum(), $case);
                $lineage = $this->persister->lineageOf($run);
                self::assertInstanceOf(RunLineage::class, $lineage, $case);
                self::assertNull($lineage->processPin, $case . ': the next turn goes on without the process');
            } else {
                self::assertSame($this->loopThrows, $result->error, $case);
                $run = $this->persister->findRun($result->runUuid);
                self::assertInstanceOf(AgentRun::class, $run);
                self::assertEquals($own, $this->persister->lineageOf($run)?->processPin, $case . ': the process stays recorded');
            }
        }
    }

    /**
     * A segment whose run was reclaimed by another worker stops as lease-lost
     * at the next step, even when the process was ended meanwhile: it writes
     * no step into the new owner's stream and settles nothing. A segment that
     * still owns the run stops as process_ended.
     */
    #[Test]
    public function aReclaimedSegmentOfAnEndedProcessWritesNothing(): void
    {
        $pin = new SkillPin(5, self::SOURCE, str_repeat('a', 64));

        foreach (['reclaimed' => true, 'still owned' => false] as $case => $reclaimed) {
            $handle = $this->persister->begin(null, 2, 'worker:a', time() + 600);
            self::assertInstanceOf(AgentRunHandle::class, $handle);
            self::assertTrue($this->persister->recordLineage($handle, new RunLineage(30, $pin, originRunUuid: $handle->uuid)));

            $eventsBefore = 0;
            $result       = $this->executor(true)->executeResume(
                $handle,
                null,
                AiActorContext::backendUser(2),
                function (ToolExecutionContext $context, RunTrace $trace) use ($handle, $pin, $reclaimed, &$eventsBefore): ToolLoopResult {
                    $run = $this->persister->findRun($handle->uuid);
                    self::assertInstanceOf(AgentRun::class, $run);
                    self::assertTrue($this->persister->recordLineage($handle, (new RunLineage(30, $pin, originRunUuid: $handle->uuid))->withReleasedProcess()));
                    if ($reclaimed) {
                        $this->pool()->getConnectionForTable('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['claimed_by' => 'worker:b'], ['uuid' => $handle->uuid]);
                    }

                    $eventsBefore = count($this->persister->findEvents($run->uid));
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
                self::assertSame(AgentRunStatus::RUNNING, $run->statusEnum(), $case);
            } else {
                self::assertInstanceOf(ProcessInvocationRefusedException::class, $result->error, $case);
                self::assertSame(ProcessInvocationRefusal::PROCESS_ENDED, $result->error->reason, $case);
                self::assertCount($eventsBefore + 1, $this->persister->findEvents($run->uid), $case . ': the step is recorded before the stop');
            }
        }
    }

    /**
     * A process ended while a write tool runs does not cut the write's audit
     * record short (ADR-182): the tool step and its write step are both
     * stored, and the turn stops before its next provider call.
     */
    #[Test]
    public function aProcessEndedDuringAWriteKeepsTheWriteRecord(): void
    {
        $pin    = new SkillPin(5, self::SOURCE, str_repeat('a', 64));
        $handle = $this->begin();
        self::assertTrue($this->persister->recordLineage($handle, new RunLineage(30, $pin, originRunUuid: $handle->uuid)));

        $this->rounds = 0;
        $result       = $this->executor(true)->executeResume(
            $handle,
            null,
            AiActorContext::backendUser(2),
            function (ToolExecutionContext $context, RunTrace $trace) use ($handle, $pin): ToolLoopResult {
                $trace->beforeToolExecution('update_record');
                self::assertTrue($this->persister->recordLineage($handle, (new RunLineage(30, $pin, originRunUuid: $handle->uuid))->withReleasedProcess()));
                $trace->recordToolResult(1, 0.0, 'update_record', [], ToolResult::text('saved')->withWriteTarget(new RecordReference('pages', 1), WriteKind::UPDATED));
                $this->rounds = 1;
                $trace->recordRequest(2, [], []);
                $this->rounds = 2;

                return new ToolLoopResult('done', [], 2, false, UsageStatistics::fromTokens(1, 1));
            },
        );

        self::assertSame(1, $this->rounds, 'stopped before the next provider call');
        self::assertInstanceOf(ProcessInvocationRefusedException::class, $result->error);
        self::assertSame(ProcessInvocationRefusal::PROCESS_ENDED, $result->error->reason);
        $run = $this->persister->findRun($handle->uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        $kinds = array_map(static fn(AgentRunEvent $event): string => $event->kind, $this->persister->findEvents($run->uid));
        self::assertContains(RunStep::KIND_TOOL, $kinds);
        self::assertContains(RunStep::KIND_WRITE, $kinds, 'the write keeps its audit step');
    }

    /**
     * No tool of a turn runs after its process was ended: the check before
     * each tool call stops it. While the process holds, the tool runs.
     */
    #[Test]
    public function noToolRunsAfterItsProcessWasEnded(): void
    {
        $pin = new SkillPin(5, self::SOURCE, str_repeat('a', 64));

        foreach (['process ended' => true, 'process holds' => false] as $case => $end) {
            $handle = $this->begin();
            self::assertTrue($this->persister->recordLineage($handle, new RunLineage(30, $pin, originRunUuid: $handle->uuid)));

            $this->rounds = 0;
            $result       = $this->executor(true)->executeResume(
                $handle,
                null,
                AiActorContext::backendUser(2),
                function (ToolExecutionContext $context, RunTrace $trace) use ($handle, $pin, $end): ToolLoopResult {
                    if ($end) {
                        self::assertTrue($this->persister->recordLineage($handle, (new RunLineage(30, $pin, originRunUuid: $handle->uuid))->withReleasedProcess()));
                    }

                    $trace->beforeToolExecution('read_page');
                    $this->rounds = 1;

                    return new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1));
                },
            );

            if ($end) {
                self::assertSame(0, $this->rounds, $case);
                self::assertInstanceOf(ProcessInvocationRefusedException::class, $result->error, $case);
                self::assertSame(ProcessInvocationRefusal::PROCESS_ENDED, $result->error->reason, $case);
            } else {
                self::assertSame(1, $this->rounds, $case);
                self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, $case);
            }
        }
    }

    /**
     * The step-boundary probe covers a turn that started its process too:
     * ended mid-segment, it stops at the next step; still held, it completes.
     */
    #[Test]
    public function aStartedProcessStopsAtTheStepAfterItWasEnded(): void
    {
        $skill = $this->approvedSkill(5, self::SOURCE, process: true);

        foreach (['process ended' => true, 'process holds' => false] as $case => $end) {
            $handle         = $this->begin();
            $this->rounds   = 0;
            $this->loopBody = function (RunTrace $trace) use ($handle, $end): void {
                $trace->recordRequest(1, [], []);
                $this->rounds = 1;
                if ($end) {
                    $run     = $this->persister->findRun($handle->uuid);
                    $lineage = $run instanceof AgentRun ? $this->persister->lineageOf($run) : null;
                    self::assertInstanceOf(RunLineage::class, $lineage);
                    self::assertTrue($this->persister->recordLineage($handle, $lineage->withReleasedProcess()));
                }

                $trace->recordRequest(2, [], []);
                $this->rounds = 2;
            };

            $result = $this->executor(withPreparer: true)->executeRequest(
                $this->request($this->configuration($skill), new SkillInvocation(5)),
                $handle,
                null,
            );

            if ($end) {
                self::assertSame(1, $this->rounds, $case);
                self::assertInstanceOf(ProcessInvocationRefusedException::class, $result->error, $case);
                self::assertSame(ProcessInvocationRefusal::PROCESS_ENDED, $result->error->reason, $case);
            } else {
                self::assertSame(2, $this->rounds, $case);
                self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome, $case);
            }
        }

        $this->loopBody = null;
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
            if ($this->loopThrows instanceof SkillInstructionWithdrawnException) {
                throw $this->loopThrows;
            }

            $trace = $arguments[6] ?? null;
            if ($this->loopBody instanceof Closure && $trace instanceof RunTrace) {
                ($this->loopBody)($trace);
            }

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
