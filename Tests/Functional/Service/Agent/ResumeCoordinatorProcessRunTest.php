<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\ApprovalDenialReason;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessRunDecidedInChatException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessRunNeedsSecondApproverException;
use Netresearch\NrLlm\Service\Agent\Exception\ProcessVerdictUnavailableException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\SelfApprovalDeniedException;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Agent\Process\ProcessPinProbe;
use Netresearch\NrLlm\Service\Agent\ResumeCoordinator;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Builtin\AskChoiceTool;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Fixtures\Process\ProcessPinProbeStub;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * ADR-214 item 6 on the resume path: a run that holds a process pin is decided
 * only by its initiator, and stops on a four-eyes configuration — in the order
 * initiator, unreadable state, four-eyes stop, before ADR-172's gate.
 *
 * The run lives in the functional database and goes through the real guarded
 * transitions, so "still waiting" and "stopped" are asserted on the stored row.
 * BeUsers.csv: uid 1 is an administrator, uid 2 an editor; the runs here are
 * started by the editor.
 */
#[CoversClass(ResumeCoordinator::class)]
final class ResumeCoordinatorProcessRunTest extends AbstractFunctionalTestCase
{
    private const OWNER = 2;

    private AgentRunPersister $persister;

    private bool $resumed = false;

    private ?ApprovalDenialReason $reasonSeen = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        // ask_choice ships disabled; an administrator enables it where the
        // client answers an input pause.
        (new ToolStateRepository($this->connectionPool()))->setEnabled(AskChoiceTool::NAME, true);
        $this->persister = new AgentRunPersister(
            new AgentRunRepository($this->connectionPool(), $this->get(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
    }

    #[Test]
    public function nobodyButTheInitiatorDecidesAProcessRun(): void
    {
        foreach ([
            'an administrator'          => AiActorContext::backendUser(1, isAdmin: true),
            'a holder of the approve grant' => AiActorContext::backendUser(3, grants: [BackendUserGrant::AGENT_APPROVE]),
            'a service account'         => AiActorContext::serviceAccount('nightly-approver', [ServiceAccountScope::AGENT_APPROVE]),
        ] as $who => $actor) {
            $uuid = $this->suspendOn('touch_thing');

            try {
                $this->coordinator(false, $this->pinned())->approve($actor, $uuid, $this->decision(true, 'touch_thing'));
                self::fail('Expected ProcessRunDecidedInChatException for ' . $who);
            } catch (ProcessRunDecidedInChatException $exception) {
                self::assertStringContainsString('in the chat', $exception->getMessage(), $who);
                $this->assertStillWaiting($uuid, AgentRunStatus::WAITING_FOR_APPROVAL);
            }
        }
    }

    /**
     * The other direction: the initiator decides, and a reason reaches the
     * loop beside the denial.
     */
    #[Test]
    public function theInitiatorDecidesAndTheReasonReachesTheLoop(): void
    {
        $uuid = $this->suspendOn('touch_thing');
        $this->coordinator(false, $this->pinned())->approve($this->initiator(), $uuid, $this->decision(true, 'touch_thing'));
        self::assertTrue($this->resumed);

        $this->resumed = false;
        $uuid          = $this->suspendOn('touch_thing');
        $this->coordinator(false, $this->pinned())->approve(
            $this->initiator(),
            $uuid,
            new ApprovalDecision(false, self::OWNER, (new PendingTurnDigest())->forState($this->state('touch_thing')), ApprovalDenialReason::SKIP),
        );
        self::assertTrue($this->resumed);
        self::assertSame(ApprovalDenialReason::SKIP, $this->reasonSeen);
    }

    /**
     * A run without a pin keeps today's rules: an administrator may decide it.
     */
    #[Test]
    public function aRunWithoutAPinIsDecidedAsBefore(): void
    {
        $uuid = $this->suspendOn('touch_thing');

        $this->coordinator(false, new ProcessPinProbeStub(['another-run']))
            ->approve(AiActorContext::backendUser(1, isAdmin: true), $uuid, $this->decision(true, 'touch_thing'));

        self::assertTrue($this->resumed);
    }

    /**
     * Four-eyes would refuse the initiator and nobody else may decide, so the
     * run is stopped — on an approval and on a denial — with the setting named
     * and the refusal's class recorded.
     */
    #[Test]
    public function aProcessRunOnAFourEyesConfigurationIsStopped(): void
    {
        foreach (['approval' => true, 'denial' => false] as $case => $approved) {
            $uuid = $this->suspendOn('touch_thing');

            try {
                $this->coordinator(true, $this->pinned())->approve($this->initiator(), $uuid, $this->decision($approved, 'touch_thing'));
                self::fail('Expected ProcessRunNeedsSecondApproverException on the ' . $case);
            } catch (ProcessRunNeedsSecondApproverException $exception) {
                self::assertStringContainsString('require_second_approver', $exception->getMessage(), $case);
            }

            self::assertFalse($this->resumed, $case . ': nothing ran');
            $this->assertStopped($uuid);
        }
    }

    /**
     * The stop cannot be undone, so an answer that rests on a failed approval
     * lookup does not stop the run, and it is asked once per decision: the
     * probe here fails its first lookup and answers "no process" to every
     * later one, so a second lookup before the stop would end an ordinary
     * run. The decision is refused as retryable and the run keeps waiting —
     * on an approval, a denial and an answer.
     */
    #[Test]
    public function anAnswerThatCannotBeCheckedNeitherStopsTheRunNorIsAskedTwice(): void
    {
        foreach (['approval' => true, 'denial' => false] as $case => $approved) {
            $uuid = $this->suspendOn('touch_thing');
            try {
                $this->coordinator(true, $this->probeThatFailsOnce())->approve($this->initiator(), $uuid, $this->decision($approved, 'touch_thing'));
                self::fail('Expected ProcessVerdictUnavailableException on the ' . $case);
            } catch (ProcessVerdictUnavailableException) {
                $this->assertStillWaiting($uuid, AgentRunStatus::WAITING_FOR_APPROVAL);
            }
        }

        $uuid = $this->suspendForChoice();
        try {
            $this->coordinator(true, $this->probeThatFailsOnce())->submitInput($this->initiator(), $uuid, $this->answer('Home'));
            self::fail('Expected ProcessVerdictUnavailableException on the answer');
        } catch (ProcessVerdictUnavailableException) {
            $this->assertStillWaiting($uuid, AgentRunStatus::WAITING_FOR_INPUT);
        }

        self::assertFalse($this->resumed);
    }

    /**
     * A failed lookup turns nobody away as if the run were a guided process:
     * somebody other than the initiator is refused as retryable, not as
     * "decided in the chat".
     */
    #[Test]
    public function aDeciderIsNotTurnedAwayOnAnAnswerThatCannotBeChecked(): void
    {
        $probe = new ProcessPinProbeStub(everyRun: true, unknown: true);
        $admin = AiActorContext::backendUser(1, isAdmin: true);

        $uuid = $this->suspendOn('touch_thing');
        try {
            $this->coordinator(false, $probe)->approve($admin, $uuid, $this->decision(true, 'touch_thing'));
            self::fail('Expected ProcessVerdictUnavailableException on the approval');
        } catch (ProcessVerdictUnavailableException) {
            $this->assertStillWaiting($uuid, AgentRunStatus::WAITING_FOR_APPROVAL);
        }

        $uuid = $this->suspendForChoice();
        try {
            $this->coordinator(false, $probe)->submitInput($admin, $uuid, $this->answer('Home'));
            self::fail('Expected ProcessVerdictUnavailableException on the answer');
        } catch (ProcessVerdictUnavailableException) {
            $this->assertStillWaiting($uuid, AgentRunStatus::WAITING_FOR_INPUT);
        }
    }

    /**
     * Fails its first lookup, then answers that the run holds no process pin.
     */
    private function probeThatFailsOnce(): ProcessPinProbe
    {
        return new class implements ProcessPinProbe {
            private int $asked = 0;

            public function holdsProcessPin(AgentRun $run): bool
            {
                return $this->processPinOf($run) ?? true;
            }

            public function processPinOf(AgentRun $run): ?bool
            {
                return $this->asked++ === 0 ? null : false;
            }

            public function anyProcessPin(array $pins): bool
            {
                return false;
            }
        };
    }

    /**
     * The other direction: a run without a pin on the same configuration meets
     * ADR-172's gate and keeps waiting for a colleague.
     */
    #[Test]
    public function aRunWithoutAPinOnAFourEyesConfigurationMeetsTheFourEyesGate(): void
    {
        $uuid = $this->suspendOn('touch_thing');

        try {
            $this->coordinator(true, new ProcessPinProbeStub())->approve($this->initiator(), $uuid, $this->decision(true, 'touch_thing'));
            self::fail('Expected SelfApprovalDeniedException');
        } catch (SelfApprovalDeniedException) {
            $this->assertStillWaiting($uuid, AgentRunStatus::WAITING_FOR_APPROVAL);
        }
    }

    /**
     * The order: the initiator check comes first, then an unreadable state is
     * refused and left for the operator, and only then the four-eyes stop.
     */
    #[Test]
    public function theInitiatorCheckAndTheUnreadableStateComeBeforeTheStop(): void
    {
        $uuid = $this->suspendOn('touch_thing');
        $this->corrupt($uuid);

        try {
            $this->coordinator(true, $this->pinned())->approve(AiActorContext::backendUser(1, isAdmin: true), $uuid, $this->decision(true, 'touch_thing'));
            self::fail('Expected ProcessRunDecidedInChatException');
        } catch (ProcessRunDecidedInChatException) {
        }

        try {
            $this->coordinator(true, $this->pinned())->approve($this->initiator(), $uuid, $this->decision(true, 'touch_thing'));
            self::fail('Expected CorruptSuspendedStateException');
        } catch (CorruptSuspendedStateException) {
            $run = $this->persister->findRun($uuid);
            self::assertInstanceOf(AgentRun::class, $run);
            self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $run->statusEnum(), 'a corrupt run keeps its state for the operator');
        }
    }

    /**
     * A stop that loses the race to another transition does not report a stop:
     * the caller learns that the run no longer waits.
     */
    #[Test]
    public function aStopThatFindsTheRunGoneSaysItNoLongerWaits(): void
    {
        $uuid  = $this->suspendOn('touch_thing');
        $probe = new class (fn(): bool => $this->persister->cancel($uuid)) implements ProcessPinProbe {
            /**
             * @param Closure(): bool $meanwhile
             */
            public function __construct(private readonly Closure $meanwhile) {}

            public function holdsProcessPin(AgentRun $run): bool
            {
                return $this->processPinOf($run);
            }

            public function processPinOf(AgentRun $run): bool
            {
                ($this->meanwhile)();

                return true;
            }

            public function anyProcessPin(array $pins): bool
            {
                return true;
            }
        };

        $this->expectException(RunNotAwaitingApprovalException::class);
        $this->coordinator(true, $probe)->approve($this->initiator(), $uuid, $this->decision(true, 'touch_thing'));
    }

    #[Test]
    public function anAnswerToAProcessRunComesFromTheInitiatorOnly(): void
    {
        $uuid = $this->suspendForChoice();

        try {
            $this->coordinator(false, $this->pinned())->submitInput(AiActorContext::backendUser(1, isAdmin: true), $uuid, $this->answer('Home'));
            self::fail('Expected ProcessRunDecidedInChatException');
        } catch (ProcessRunDecidedInChatException) {
            $this->assertStillWaiting($uuid, AgentRunStatus::WAITING_FOR_INPUT);
        }
    }

    #[Test]
    public function anAnswerToAProcessRunOnAFourEyesConfigurationStopsIt(): void
    {
        $uuid = $this->suspendForChoice();

        $this->expectException(ProcessRunNeedsSecondApproverException::class);
        try {
            $this->coordinator(true, $this->pinned())->submitInput($this->initiator(), $uuid, $this->answer('Home'));
        } finally {
            $this->assertStopped($uuid);
        }
    }

    /**
     * The choice's enum holds at submission: an answer that is not one of the
     * options is refused and the run keeps waiting; one of them is accepted.
     */
    #[Test]
    public function anAnswerOutsideTheOptionsIsRefusedAndOneOfThemIsTaken(): void
    {
        $uuid = $this->suspendForChoice();

        try {
            $this->coordinator(false, $this->pinned())->submitInput($this->initiator(), $uuid, $this->answer('Contact'));
            self::fail('Expected InvalidInputSubmissionException');
        } catch (InvalidInputSubmissionException) {
            $this->assertStillWaiting($uuid, AgentRunStatus::WAITING_FOR_INPUT);
        }

        $this->coordinator(false, $this->pinned())->submitInput($this->initiator(), $uuid, $this->answer('Home'));
        self::assertTrue($this->resumed);
    }

    // --- assertions --------------------------------------------------------

    private function assertStillWaiting(string $uuid, AgentRunStatus $status): void
    {
        self::assertFalse($this->resumed, 'nothing was executed');

        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame($status, $run->statusEnum());
        self::assertNotNull($run->suspendedState);
    }

    private function assertStopped(string $uuid): void
    {
        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::FAILED, $run->statusEnum());
        self::assertSame(AgentRunTerminationReason::APPROVAL_DENIED, $run->terminationReasonEnum());
        self::assertSame(ProcessRunNeedsSecondApproverException::class, $run->errorClass);
        self::assertNull($run->suspendedState);
    }

    // --- helpers -----------------------------------------------------------

    private function pinned(): ProcessPinProbe
    {
        return new ProcessPinProbeStub(everyRun: true);
    }

    private function initiator(): AiActorContext
    {
        return AiActorContext::backendUser(self::OWNER, grants: [BackendUserGrant::AGENT_APPROVE]);
    }

    private function decision(bool $approved, string $tool): ApprovalDecision
    {
        return new ApprovalDecision($approved, self::OWNER, (new PendingTurnDigest())->forState($this->state($tool)));
    }

    private function answer(string $choice): InputSubmission
    {
        return new InputSubmission([AskChoiceTool::ANSWER => $choice], self::OWNER, (new PendingTurnDigest())->forInputState($this->choiceState()));
    }

    /**
     * @return string the run uuid
     */
    private function suspendOn(string $tool): string
    {
        $handle = $this->persister->begin(null, self::OWNER);
        self::assertNotNull($handle);
        self::assertTrue($this->persister->suspend($handle, $this->state($tool)));

        return $handle->uuid;
    }

    /**
     * @return string the run uuid
     */
    private function suspendForChoice(): string
    {
        $handle = $this->persister->begin(null, self::OWNER);
        self::assertNotNull($handle);
        self::assertTrue($this->persister->suspendForInput($handle, $this->choiceState()));

        return $handle->uuid;
    }

    private function state(string $tool): SuspendedRunState
    {
        return new SuspendedRunState([], [ToolCall::function('c1', $tool, ['uid' => 42])->toArray()], 1, 0, 0);
    }

    private function choiceState(): SuspendedRunState
    {
        $arguments = ['question' => 'Which page?', 'options' => ['Home', 'About']];

        return new SuspendedRunState(
            [],
            [ToolCall::function('c1', AskChoiceTool::NAME, $arguments)->toArray()],
            1,
            0,
            0,
            inputToolName: AskChoiceTool::NAME,
            inputSchema: (new AskChoiceTool())->inputSchemaFor($arguments),
        );
    }

    private function corrupt(string $uuid): void
    {
        $this->connectionPool()->getConnectionForTable('tx_nrllm_agentrun')
            ->update('tx_nrllm_agentrun', ['suspended_state' => 'not-json{'], ['uuid' => $uuid]);
    }

    private function coordinator(bool $fourEyes, ProcessPinProbe $probe): ResumeCoordinator
    {
        $registry = new ToolRegistry([
            new FakeTool('touch_thing', effect: ToolEffect::NON_IDEMPOTENT_WRITE),
            new AskChoiceTool(),
        ]);

        $policy = new ToolCallPolicy(
            $registry,
            new ToolAvailabilityService($registry, new ToolStateRepository($this->connectionPool()), new ToolGroupStateRepository($this->connectionPool())),
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($this->localConfiguration($fourEyes));

        $loop = $this->toolLoop();

        return new ResumeCoordinator(
            $this->persister,
            $configurationRepository,
            $loop,
            new AgentRunExecutor($loop, $this->persister),
            null,
            new ToolEffectResolver($registry),
            new PendingTurnDigest(),
            $policy,
            new ActingBackendUserResolver(),
            new NullLogger(),
            $probe,
        );
    }

    private function toolLoop(): ToolLoopServiceInterface
    {
        $done = fn(): ToolLoopResult => new ToolLoopResult('continued', [], 1, false, UsageStatistics::fromTokens(1, 1));

        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('resume')->willReturnCallback(function (mixed ...$arguments) use ($done): ToolLoopResult {
            $this->resumed    = true;
            $reason           = $arguments[7] ?? null;
            $this->reasonSeen = $reason instanceof ApprovalDenialReason ? $reason : null;

            return $done();
        });
        $loop->method('resumeWithInput')->willReturnCallback(function () use ($done): ToolLoopResult {
            $this->resumed = true;

            return $done();
        });

        return $loop;
    }

    /**
     * A LOCAL-trust-zone configuration, so the trust-zone axis of the real gate
     * permits the tools and the four-eyes switch is the variable.
     */
    private function localConfiguration(bool $fourEyes): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-process');
        $configuration->setLlmModel($model);
        $configuration->setRequireSecondApprover($fourEyes);

        return $configuration;
    }

    private function connectionPool(): ConnectionPool
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        return $connectionPool;
    }
}
