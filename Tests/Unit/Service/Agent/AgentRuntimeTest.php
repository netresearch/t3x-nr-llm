<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAccessDeniedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingInputException;
use Netresearch\NrLlm\Service\Agent\Exception\RunStateUnavailableException;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
use Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RecordingAgentRunRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

#[CoversClass(AgentRuntime::class)]
final class AgentRuntimeTest extends AbstractUnitTestCase
{
    private RecordingAgentRunRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new RecordingAgentRunRepository();
    }

    #[Test]
    public function aCompletedRunSettlesCompletedAndCarriesTheLoopResult(): void
    {
        $loopResult = $this->loopResult('done');
        $runtime    = $this->runtime($this->loopReturning($loopResult));

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertSame($loopResult, $result->loopResult);
        self::assertNotSame('', $result->runUuid);
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunStatus::COMPLETED->value, $this->repository->finished['status']);
    }

    #[Test]
    public function aSuspendedRunIsPersistedAndReturnedAsAwaitingApproval(): void
    {
        $state   = $this->suspendedState();
        $runtime = $this->runtime($this->loopThrowing(ToolApprovalRequiredException::fromState($state)));

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::AWAITING_APPROVAL, $result->outcome);
        self::assertSame($state, $result->suspendedState);
        self::assertNotNull($this->repository->suspended);
        // The run stays WAITING_FOR_APPROVAL: the finally-guard must NOT settle
        // a successfully-suspended run into FAILED — that would destroy the
        // resumable state (the run-destroying case the design review flagged).
        self::assertNull($this->repository->finished);
    }

    #[Test]
    public function aFailedSuspensionFailsClosedAsSuspendFailed(): void
    {
        $this->repository->throwOnSuspend = true;
        $runtime = $this->runtime($this->loopThrowing(ToolApprovalRequiredException::fromState($this->suspendedState())));

        $result = $runtime->run($this->request());

        // ADR-092: no stored state means no resume — the run is failed, never
        // announced as awaiting approval.
        self::assertSame(AgentRunOutcome::SUSPEND_FAILED, $result->outcome);
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunStatus::FAILED->value, $this->repository->finished['status']);
    }

    #[Test]
    public function aSuspensionArrivingAfterACancelIsDiscardedAndFailsClosed(): void
    {
        // The guarded suspendRun refuses because the run is no longer RUNNING
        // (a concurrent cancel won the row): the suspension must not resurrect
        // the cancelled run into an approval queue (ADR-101).
        $this->repository->refuseSuspend = true;
        $runtime = $this->runtime($this->loopThrowing(ToolApprovalRequiredException::fromState($this->suspendedState())));

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::SUSPEND_FAILED, $result->outcome);
        self::assertNull($this->repository->suspended);
    }

    #[Test]
    public function anUnpersistedRunThatSuspendsFailsClosed(): void
    {
        // No handle (persistence down at begin) means no stored state and no
        // resume — announcing awaiting-approval would strand the client behind
        // an approve() that can only ever 400 (ADR-092).
        $this->repository->throwOnStart = true;
        $runtime = $this->runtime($this->loopThrowing(ToolApprovalRequiredException::fromState($this->suspendedState())));

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::SUSPEND_FAILED, $result->outcome);
        self::assertSame('', $result->runUuid);
        self::assertNull($this->repository->finished);
    }

    #[Test]
    public function aCancelledRunStopsCooperativelyAtTheNextStepBoundary(): void
    {
        // ADR-103: the operator cancelled while the loop was executing (the row
        // is already terminal CANCELLED). The probe at the step boundary stops
        // the loop BEFORE any further provider call or tool execution.
        $this->repository->findResult = $this->suspendedRun(status: 'cancelled');

        $reachedSecondRound = false;
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max, ?RunTrace $trace) use (&$reachedSecondRound): ToolLoopResult {
                // First step boundary: the probe fires here and aborts.
                $trace?->recordRequest(1, [], []);
                // Anything after the boundary must never run for a cancelled run.
                $reachedSecondRound = true;

                return $this->loopResult('must not complete');
            },
        );

        $result = $this->runtime($loop)->run($this->request());

        self::assertSame(AgentRunOutcome::CANCELLED, $result->outcome);
        self::assertFalse($reachedSecondRound);
        // The boundary step itself is still on the audit stream…
        self::assertCount(1, $this->repository->events);
        // …and the already-terminal row is NOT settled again (no discarded
        // double settle, no FAILED overwrite attempt).
        self::assertNull($this->repository->finished);
    }

    #[Test]
    public function aRunningRunIsNotDisturbedByTheCancellationProbe(): void
    {
        // The probe reads the row at every step boundary; a run that is simply
        // RUNNING must complete normally.
        $this->repository->findResult = $this->suspendedRun(status: 'running');

        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max, ?RunTrace $trace): ToolLoopResult {
                $trace?->recordRequest(1, [], []);

                return $this->loopResult('done');
            },
        );

        $result = $this->runtime($loop)->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
    }

    #[Test]
    public function aGuardrailDenialSettlesPolicyStopped(): void
    {
        $runtime = $this->runtime($this->loopThrowing(new GuardrailViolationException('GuardrailFqcn', 'blocked')));

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::GUARDRAIL_BLOCKED, $result->outcome);
        self::assertSame('GuardrailFqcn', $result->guardrailClass);
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunTerminationReason::POLICY_DENIED->value, $this->repository->finished['terminationReason']);
    }

    #[Test]
    public function aGuardrailApprovalRequirementIsDistinctFromADenial(): void
    {
        $runtime = $this->runtime($this->loopThrowing(new GuardrailApprovalRequiredException('GuardrailFqcn', 'needs a human')));

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED, $result->outcome);
        self::assertNotNull($this->repository->finished);
        // ADR-092: required-but-never-obtained is not recorded as a denial.
        self::assertSame(AgentRunTerminationReason::APPROVAL_DENIED->value, $this->repository->finished['terminationReason']);
    }

    #[Test]
    public function anUnexpectedThrowableSettlesFailedAndIsReturnedNotRethrown(): void
    {
        $error   = new RuntimeException('provider exploded', 1784700001);
        $runtime = $this->runtime($this->loopThrowing($error));

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertSame($error, $result->error);
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunStatus::FAILED->value, $this->repository->finished['status']);
    }

    #[Test]
    public function stepsReachTheObserverBeforePersistenceAndTheResult(): void
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max, ?RunTrace $trace): ToolLoopResult {
                $trace?->recordRequest(1, [], []);

                return $this->loopResult('ok');
            },
        );

        $observed = [];
        $runtime  = $this->runtime($loop);
        $result   = $runtime->run($this->request(), function (RunStep $s) use (&$observed): void {
            $observed[] = $s->kind;
        });

        self::assertSame([RunStep::KIND_REQUEST], $observed);
        self::assertCount(1, $result->steps);
        // The same step also reached the persisted event stream.
        self::assertCount(1, $this->repository->events);
        self::assertSame(RunStep::KIND_REQUEST, $this->repository->events[0]['kind']);
    }

    #[Test]
    public function anObserverThrowMidRunSettlesTheRunFailed(): void
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max, ?RunTrace $trace): ToolLoopResult {
                // A recorded step reaches the observer, which dies (client
                // disconnect on the stream) — the throw propagates through the
                // loop into the ladder.
                $trace?->recordRequest(1, [], []);

                return $this->loopResult('never reached');
            },
        );

        $runtime = $this->runtime($loop);
        $result  = $runtime->run($this->request(), static function (): void {
            throw new RuntimeException('client gone', 1784700002);
        });

        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunStatus::FAILED->value, $this->repository->finished['status']);
    }

    #[Test]
    public function anExplicitIterationCapIsClampedToTheCeilingButNullPassesThrough(): void
    {
        $seen = [];
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max) use (&$seen): ToolLoopResult {
                $seen[] = $max;

                return $this->loopResult('ok');
            },
        );
        $runtime = $this->runtime($loop);

        $runtime->run($this->request(maxIterations: 50));
        $runtime->run($this->request(maxIterations: 3));
        // null must NOT be coerced to the ceiling: the loop's own (lower)
        // default applies — a naive clamp would quadruple the default cost.
        $runtime->run($this->request());

        self::assertSame([AgentRuntime::MAX_ITERATIONS, 3, null], $seen);
    }

    #[Test]
    public function approveClaimsRecordsTheDecisionAndResumes(): void
    {
        $this->repository->findResult  = $this->suspendedRun();
        $this->repository->maxSequence = 4;
        $loopResult = $this->loopResult('continued');

        $seenBeUser = null;
        $loop       = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('resume')->willReturnCallback(
            function (SuspendedRunState $state, bool $approved, LlmConfiguration $config, ToolExecutionContext $context, ?int $max, ?RunTrace $trace, ?int $beUserUid) use (&$seenBeUser, $loopResult): ToolLoopResult {
                $seenBeUser = $beUserUid;

                return $loopResult;
            },
        );

        $result = $this->runtime($loop)->approve($this->actor(), 'run-uuid-1', new ApprovalDecision(true, 42));

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertSame($loopResult, $result->loopResult);
        // The approver's uid budget-checks the continuation (ADR-084).
        self::assertSame(42, $seenBeUser);
        // The decision is the first event of the resumed segment, at the next
        // sequence after the stored stream (MAX 4 -> 5).
        self::assertNotSame([], $this->repository->events);
        $approval = $this->repository->events[0];
        self::assertSame('approval', $approval['kind']);
        self::assertSame(5, $approval['sequence']);
        $payload = json_decode($approval['payloadJson'], true);
        self::assertSame(['approved' => true, 'decidedBy' => 42], $payload);
    }

    #[Test]
    public function approveResolvesTheEventPositionAgainAfterWinningTheClaim(): void
    {
        // A request that stalled between its pre-claim probe and the claim may
        // hold a stale position from before another approval's continuation
        // appended events; the post-claim resolve must win so sequences are
        // never duplicated (ADR-101). Probe sees MAX 4; after the claim the
        // stream has grown to MAX 9 — the approval event lands at 10.
        $this->repository->findResult         = $this->suspendedRun();
        $this->repository->maxSequenceReturns = [4, 9];

        $this->runtime($this->loopReturning($this->loopResult('continued')))
            ->approve($this->actor(), 'run-uuid-1', new ApprovalDecision(true, 1));

        self::assertNotSame([], $this->repository->events);
        self::assertSame(10, $this->repository->events[0]['sequence']);
    }

    #[Test]
    public function approveThrowsWhenNoRunIsAwaitingApproval(): void
    {
        $this->repository->findResult = null;

        $this->expectException(RunNotAwaitingApprovalException::class);
        $this->runtime($this->loopReturning($this->loopResult('x')))->approve($this->actor(), 'unknown', new ApprovalDecision(true, 1));
    }

    #[Test]
    public function approveThrowsWhenTheRunIsNotSuspended(): void
    {
        $this->repository->findResult = $this->suspendedRun(status: 'completed');

        $this->expectException(RunNotAwaitingApprovalException::class);
        $this->runtime($this->loopReturning($this->loopResult('x')))->approve($this->actor(), 'run-uuid-1', new ApprovalDecision(true, 1));
    }

    #[Test]
    public function approveThrowsWhenTheConfigurationIsGone(): void
    {
        $this->repository->findResult = $this->suspendedRun();

        $this->expectException(RunConfigurationGoneException::class);
        $this->runtime($this->loopReturning($this->loopResult('x')), configuration: null)->approve($this->actor(), 'run-uuid-1', new ApprovalDecision(true, 1));
    }

    #[Test]
    public function approveThrowsOnCorruptSuspendedState(): void
    {
        $this->repository->findResult = $this->suspendedRun(stateJson: 'not-json{');

        $this->expectException(CorruptSuspendedStateException::class);
        $this->runtime($this->loopReturning($this->loopResult('x')))->approve($this->actor(), 'run-uuid-1', new ApprovalDecision(true, 1));
    }

    #[Test]
    public function approveRefusesBeforeClaimingWhenTheEventPositionIsUnavailable(): void
    {
        $this->repository->findResult         = $this->suspendedRun();
        $this->repository->throwOnMaxSequence = true;

        try {
            $this->runtime($this->loopReturning($this->loopResult('x')))->approve($this->actor(), 'run-uuid-1', new ApprovalDecision(true, 1));
            self::fail('Expected RunStateUnavailableException');
        } catch (RunStateUnavailableException) {
            // Fail-closed BEFORE the claim: the run is still suspended, so the
            // approval can simply be retried — nothing was claimed or executed.
            self::assertSame(0, $this->repository->claimsGranted);
        }
    }

    #[Test]
    public function approveThrowsWhenTheClaimIsLostToAConcurrentApproval(): void
    {
        $this->repository->findResult    = $this->suspendedRun();
        $this->repository->claimsGranted = 1; // the next claim loses

        $loop = self::createStub(ToolLoopServiceInterface::class);
        // The gated tool must never execute on the losing request.
        $loop->method('resume')->willReturnCallback(static function (): ToolLoopResult {
            throw new RuntimeException('resume must not be reached', 1784700003);
        });

        $this->expectException(RunAlreadyResumingException::class);
        $this->runtime($loop)->approve($this->actor(), 'run-uuid-1', new ApprovalDecision(true, 1));
    }

    #[Test]
    public function aDeniedApprovalStillResumesWithApprovedFalse(): void
    {
        $this->repository->findResult = $this->suspendedRun();

        $seenApproved = null;
        $loop         = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('resume')->willReturnCallback(
            function (SuspendedRunState $state, bool $approved) use (&$seenApproved): ToolLoopResult {
                $seenApproved = $approved;

                return $this->loopResult('refused and continued');
            },
        );

        $result = $this->runtime($loop)->approve($this->actor(), 'run-uuid-1', new ApprovalDecision(false, 7));

        // A denial does not terminate the run: the loop continues from the
        // refusal message (ADR-084) — and the denial is on the audit stream.
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertFalse($seenApproved);
        $payload = json_decode($this->repository->events[0]['payloadJson'], true);
        self::assertSame(['approved' => false, 'decidedBy' => 7], $payload);
    }

    #[Test]
    public function aResumedRunMaySuspendAgain(): void
    {
        $this->repository->findResult = $this->suspendedRun();
        $again = $this->suspendedState();

        $runtime = $this->runtime($this->loopThrowing(ToolApprovalRequiredException::fromState($again), onResume: true));
        $result  = $runtime->approve($this->actor(), 'run-uuid-1', new ApprovalDecision(true, 1));

        self::assertSame(AgentRunOutcome::AWAITING_APPROVAL, $result->outcome);
        self::assertSame($again, $result->suspendedState);
        self::assertNotNull($this->repository->suspended);
        self::assertNull($this->repository->finished);
    }

    #[Test]
    public function enqueuePersistsAQueuedRowAndDispatchesTheWakeUpMessage(): void
    {
        $dispatched = [];
        $runtime    = $this->runtime($this->loopReturning($this->loopResult('x')), bus: $this->recordingBus($dispatched));

        $uuid = $runtime->enqueue($this->request(maxIterations: 7));

        self::assertNotSame('', $uuid);
        self::assertCount(1, $this->repository->enqueuedRuns);
        $row = $this->repository->enqueuedRuns[0];
        self::assertSame($uuid, $row['uuid']);
        self::assertSame(9, $row['beUser']);
        $payload = json_decode($row['requestJson'], true);
        self::assertIsArray($payload);
        self::assertSame(7, $payload['maxIterations']);
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(AgentRunQueuedMessage::class, $dispatched[0]);
        self::assertSame($uuid, $dispatched[0]->runUuid);
    }

    #[Test]
    public function enqueueFailsClosedWhenTheRowCannotBeStored(): void
    {
        $this->repository->throwOnEnqueue = true;
        $dispatched = [];
        $runtime    = $this->runtime($this->loopReturning($this->loopResult('x')), bus: $this->recordingBus($dispatched));

        try {
            $runtime->enqueue($this->request());
            self::fail('Expected RunEnqueueFailedException');
        } catch (RunEnqueueFailedException) {
            // No row, and crucially no wake-up for a run that does not exist.
            self::assertSame([], $dispatched);
        }
    }

    #[Test]
    public function enqueueSettlesTheRowFailedWhenTheDispatchFails(): void
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new RuntimeException('transport down', 1784700020));
        $runtime = $this->runtime($this->loopReturning($this->loopResult('x')), bus: $bus);

        try {
            $runtime->enqueue($this->request());
            self::fail('Expected RunEnqueueFailedException');
        } catch (RunEnqueueFailedException) {
            // The stored row must not stay QUEUED forever with no message ever
            // arriving — it is settled failed (fail-closed, no orphan).
            self::assertNotNull($this->repository->finished);
            self::assertSame(AgentRunStatus::FAILED->value, $this->repository->finished['status']);
        }
    }

    #[Test]
    public function enqueueFailsClosedWithoutAMessageBus(): void
    {
        $this->expectException(RunEnqueueFailedException::class);
        $this->runtime($this->loopReturning($this->loopResult('x')))->enqueue($this->request());
    }

    #[Test]
    public function runQueuedClaimsRehydratesAndExecutesTheStoredRequest(): void
    {
        // Full round-trip: enqueue() serialises the request, runQueued() claims
        // the row and rehydrates — the loop must receive equivalent inputs.
        $dispatched = [];
        $runtime0   = $this->runtime($this->loopReturning($this->loopResult('x')), bus: $this->recordingBus($dispatched));
        $uuid       = $runtime0->enqueue(new AgentRunRequest(
            configuration: new LlmConfiguration(),
            messages: [ChatMessage::user('do the queued thing')],
            allowedToolNames: ['fetch_logs'],
            options: (new ToolOptions(temperature: 0.5, plannedCost: 1.25))->withIdempotencyKey('idem-1'),
            maxIterations: 50,
            captureRaw: true,
            actor: AiActorContext::backendUser(9),
        ));

        $this->repository->findResult = $this->queuedRun($uuid, $this->repository->enqueuedRuns[0]['requestJson']);

        $seen = [];
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max, mixed $trace, mixed $augmentation) use (&$seen): ToolLoopResult {
                $seen = ['messages' => $messages, 'allowed' => $allowed, 'options' => $options, 'max' => $max, 'augmentation' => $augmentation];

                return $this->loopResult('queued done');
            },
        );

        $result = $this->runtime($loop)->runQueued($uuid);

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        // The claim was atomic and stamped the worker lease.
        self::assertNotNull($this->repository->queuedClaim);
        self::assertNotSame('', $this->repository->queuedClaim['claimedBy']);
        self::assertGreaterThan(time(), $this->repository->queuedClaim['leaseExpires']);
        // Rehydrated inputs reached the loop: the message content, the
        // allow-list, the ceiling-clamped round cap, the initiator's budget uid.
        self::assertSame('do the queued thing', $seen['messages'][0]['content'] ?? null);
        self::assertSame(['fetch_logs'], $seen['allowed']);
        self::assertSame(AgentRuntime::MAX_ITERATIONS, $seen['max']);
        self::assertInstanceOf(ToolOptions::class, $seen['options']);
        self::assertSame(0.5, $seen['options']->getTemperature());
        self::assertSame(9, $seen['options']->getBeUserUid());
        // The out-of-band budget/idempotency fields survive the round-trip: a
        // queued run's budget pre-flight is as strict as the direct path's.
        self::assertSame(1.25, $seen['options']->getPlannedCost());
        self::assertSame('idem-1', $seen['options']->getIdempotencyKey());
        // A null augmentation stays null — a fabricated empty RunAugmentation
        // would flip the loop into its prompt-baking assembly branch and
        // silently change the prompt composition vs. the identical run().
        self::assertNull($seen['augmentation']);
        // And the run settled completed.
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunStatus::COMPLETED->value, $this->repository->finished['status']);
    }

    #[Test]
    public function runQueuedReturnsNullForAnUnknownOrNonQueuedRun(): void
    {
        $runtime = $this->runtime($this->loopReturning($this->loopResult('x')));

        self::assertNull($runtime->runQueued('unknown'));

        $this->repository->findResult = $this->suspendedRun();
        self::assertNull($runtime->runQueued('run-uuid-1'));
    }

    #[Test]
    public function runQueuedReturnsNullWhenTheClaimIsLost(): void
    {
        $this->repository->findResult        = $this->queuedRun('run-uuid-q', '{"messages":[]}');
        $this->repository->refuseClaimQueued = true;

        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(static function (): ToolLoopResult {
            throw new RuntimeException('the loop must not run on the losing worker', 1784700021);
        });

        self::assertNull($this->runtime($loop)->runQueued('run-uuid-q'));
    }

    #[Test]
    public function runQueuedSettlesTheRunFailedWhenTheStoredRequestIsCorrupt(): void
    {
        $this->repository->findResult = $this->queuedRun('run-uuid-q', 'not-json{');

        $result = $this->runtime($this->loopReturning($this->loopResult('x')))->runQueued('run-uuid-q');

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunStatus::FAILED->value, $this->repository->finished['status']);
    }

    #[Test]
    public function runQueuedSettlesTheRunFailedWhenTheConfigurationIsGone(): void
    {
        $this->repository->findResult = $this->queuedRun('run-uuid-q', '{"messages":[]}');

        $result = $this->runtime($this->loopReturning($this->loopResult('x')), configuration: null)->runQueued('run-uuid-q');

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertNotNull($this->repository->finished);
    }

    #[Test]
    public function aQueuedRunRenewsItsLeaseAtEachStepBoundary(): void
    {
        // ADR-104 heartbeat: a worker-claimed run renews its lease at every step
        // boundary, under its own worker identity.
        $this->repository->findResult = $this->queuedRun('run-uuid-q', '{"messages":[]}');

        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max, ?RunTrace $trace): ToolLoopResult {
                $trace?->recordRequest(1, [], []);

                return $this->loopResult('done');
            },
        );

        $result = $this->runtime($loop)->runQueued('run-uuid-q');

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertCount(1, $this->repository->leaseRenewals);
        self::assertSame($this->repository->queuedClaim['claimedBy'] ?? null, $this->repository->leaseRenewals[0]['claimedBy']);
        self::assertGreaterThan(time(), $this->repository->leaseRenewals[0]['leaseExpires']);
    }

    #[Test]
    public function aQueuedRunThatLosesItsLeaseStopsWithoutSettling(): void
    {
        // ADR-104: the reaper reclaimed the run (renewLease affects no row). The
        // worker stops at the step boundary WITHOUT recording the step and
        // WITHOUT settling — the row belongs to its new owner.
        $this->repository->findResult        = $this->queuedRun('run-uuid-q', '{"messages":[]}');
        $this->repository->refuseRenewLease = true;

        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max, ?RunTrace $trace): ToolLoopResult {
                $trace?->recordRequest(1, [], []);

                return $this->loopResult('must not complete');
            },
        );

        $result = $this->runtime($loop)->runQueued('run-uuid-q');

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::LEASE_LOST, $result->outcome);
        // No settle: the run is not this worker's to terminate.
        self::assertNull($this->repository->finished);
        // The step was NOT persisted — the abort precedes the recordStep.
        self::assertCount(0, $this->repository->events);
    }

    #[Test]
    public function aQueuedRunFailingRetryablyIsRequeuedAndReDispatchedWithBackoff(): void
    {
        // ADR-104 failure retry: a retryable provider error requeues the run
        // (ownership-guarded) and re-dispatches it with an exponential backoff.
        $this->repository->findResult = $this->queuedRun('run-uuid-q', '{"messages":[]}');

        $dispatched = [];
        $runtime    = $this->runtime(
            $this->loopRecordingThenThrowing(new ProviderConnectionException('provider unreachable', 1784900010)),
            bus: $this->stampRecordingBus($dispatched),
        );

        $result = $runtime->runQueued('run-uuid-q');

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::REQUEUED, $result->outcome);
        // Requeued under this worker's identity, not settled.
        self::assertCount(1, $this->repository->requeues);
        self::assertSame($this->repository->queuedClaim['claimedBy'] ?? null, $this->repository->requeues[0]['claimedBy']);
        self::assertNull($this->repository->finished);
        // Re-dispatched with a DelayStamp of the base backoff (2^0).
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(AgentRunQueuedMessage::class, $dispatched[0]['message']);
        self::assertCount(1, $dispatched[0]['stamps']);
        self::assertInstanceOf(DelayStamp::class, $dispatched[0]['stamps'][0]);
        self::assertSame(AgentRuntime::REQUEUE_BACKOFF_MS, $dispatched[0]['stamps'][0]->getDelay());
    }

    #[Test]
    public function aQueuedRunFailingNonRetryablyIsDeadLetteredAsNotRetryable(): void
    {
        // ADR-104: a failure class retrying cannot fix (here UNKNOWN, from a
        // plain error) dead-letters immediately with NOT_RETRYABLE — never the
        // retryable PROVIDER_FAILED reason.
        $this->repository->findResult = $this->queuedRun('run-uuid-q', '{"messages":[]}');

        $result = $this->runtime(
            $this->loopRecordingThenThrowing(new RuntimeException('deterministic bug', 1784900011)),
            bus: $this->discardingBus(),
        )->runQueued('run-uuid-q');

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertNull($this->repository->requeues[0] ?? null);
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunStatus::FAILED->value, $this->repository->finished['status']);
        self::assertSame(AgentRunTerminationReason::NOT_RETRYABLE->value, $this->repository->finished['terminationReason']);
    }

    #[Test]
    public function aQueuedRunOutOfRetryBudgetIsDeadLetteredAsRetriesExhausted(): void
    {
        // ADR-104: retryable in principle, but the requeue budget is spent — the
        // run dead-letters with RETRIES_EXHAUSTED and is NOT requeued again.
        $this->repository->findResult = $this->queuedRun('run-uuid-q', '{"messages":[]}', AgentRuntime::MAX_REQUEUES);

        $result = $this->runtime(
            $this->loopRecordingThenThrowing(new ProviderConnectionException('still failing', 1784900012)),
            bus: $this->discardingBus(),
        )->runQueued('run-uuid-q');

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::FAILED, $result->outcome);
        self::assertNull($this->repository->requeues[0] ?? null);
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunTerminationReason::RETRIES_EXHAUSTED->value, $this->repository->finished['terminationReason']);
    }

    #[Test]
    public function aQueuedRunLosingOwnershipDuringRequeueIsNotSettled(): void
    {
        // ADR-104 (review MAJOR): requeue returns false — a concurrent reaper
        // reclaim or cancel won. The worker must NOT settle the run: it may
        // belong to another worker now.
        $this->repository->findResult   = $this->queuedRun('run-uuid-q', '{"messages":[]}');
        $this->repository->refuseRequeue = true;

        $result = $this->runtime(
            $this->loopRecordingThenThrowing(new ProviderConnectionException('provider unreachable', 1784900013)),
            bus: $this->discardingBus(),
        )->runQueued('run-uuid-q');

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::LEASE_LOST, $result->outcome);
        self::assertNull($this->repository->finished);
    }

    #[Test]
    public function aRequeuedRunResumesItsEventStreamAtMaxSequencePlusOne(): void
    {
        // ADR-104 D3: a requeued run carries the prior attempt's events, so the
        // claim resolves the stream position to MAX(sequence) + 1 rather than 0.
        $this->repository->findResult = $this->queuedRun('run-uuid-q', '{"messages":[]}', 1);
        $this->repository->maxSequence = 5;

        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max, ?RunTrace $trace): ToolLoopResult {
                $trace?->recordRequest(1, [], []);

                return $this->loopResult('done');
            },
        );

        $result = $this->runtime($loop)->runQueued('run-uuid-q');

        self::assertNotNull($result);
        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertCount(1, $this->repository->events);
        self::assertSame(6, $this->repository->events[0]['sequence']);
    }

    #[Test]
    public function anInteractiveRunNeverRenewsALease(): void
    {
        // ADR-104: run()/approve() hold no lease (leaseOwner is null), so the
        // heartbeat never fires — only queue workers renew.
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max, ?RunTrace $trace): ToolLoopResult {
                $trace?->recordRequest(1, [], []);

                return $this->loopResult('done');
            },
        );

        $result = $this->runtime($loop)->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertCount(0, $this->repository->leaseRenewals);
    }

    #[Test]
    public function aToolRequiringInputSuspendsTheRunAsAwaitingInput(): void
    {
        // ADR-105: a called tool raised ToolInputRequiredException — the run
        // suspends WAITING_FOR_INPUT carrying the declared schema, not FAILED.
        $runtime = $this->runtime($this->loopThrowing(ToolInputRequiredException::fromState($this->inputState())));

        $result = $runtime->run($this->request());

        self::assertSame(AgentRunOutcome::AWAITING_INPUT, $result->outcome);
        self::assertSame('ask_user', $result->suspendedState?->inputToolName);
        self::assertNotNull($this->repository->suspendedForInput);
        // A successful input suspension is non-terminal: the finally-guard must
        // NOT flip it to FAILED.
        self::assertNull($this->repository->finished);
    }

    #[Test]
    public function aFailedInputSuspensionFailsClosedAsSuspendFailed(): void
    {
        $this->repository->refuseSuspendForInput = true;
        $runtime = $this->runtime($this->loopThrowing(ToolInputRequiredException::fromState($this->inputState())));

        $result = $runtime->run($this->request());

        // No stored state means no resume — fail closed, never announce awaiting.
        self::assertSame(AgentRunOutcome::SUSPEND_FAILED, $result->outcome);
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunStatus::FAILED->value, $this->repository->finished['status']);
    }

    #[Test]
    public function submitInputValidatesClaimsRecordsAndResumes(): void
    {
        $this->repository->findResult = $this->inputRun();

        $result = $this->runtime($this->loopReturning($this->loopResult('answered')))
            ->submitInput($this->actor(), 'run-uuid-i', new InputSubmission(['city' => 'Berlin'], 7));

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        // The claim was consumed exactly once and an INPUT audit event recorded.
        self::assertSame(1, $this->repository->inputClaimsGranted);
        self::assertSame('input', $this->repository->events[0]['kind'] ?? null);
        self::assertNotNull($this->repository->finished);
    }

    #[Test]
    public function submitInputRejectsInvalidInputWithoutConsumingTheClaim(): void
    {
        // The required 'city' is missing → schema validation fails BEFORE the
        // claim, so the run stays WAITING_FOR_INPUT and can be resubmitted.
        $this->repository->findResult = $this->inputRun();

        $runtime = $this->runtime($this->loopReturning($this->loopResult('x')));

        try {
            $runtime->submitInput($this->actor(), 'run-uuid-i', new InputSubmission([], 7));
            self::fail('Expected InvalidInputSubmissionException');
        } catch (InvalidInputSubmissionException) {
            // expected
        }

        self::assertSame(0, $this->repository->inputClaimsGranted);
        self::assertSame([], $this->repository->events);
        self::assertNull($this->repository->finished);
    }

    #[Test]
    public function submitInputThrowsWhenTheRunIsNotAwaitingInput(): void
    {
        $runtime = $this->runtime($this->loopReturning($this->loopResult('x')));

        // Unknown run.
        $this->expectException(RunNotAwaitingInputException::class);
        $runtime->submitInput($this->actor(), 'unknown', new InputSubmission(['city' => 'Berlin'], 7));
    }

    #[Test]
    public function submitInputThrowsCorruptStateForADegenerateSchema(): void
    {
        // A rehydrated input state with an empty schema must NOT validate against
        // [] (accept-all) — it is corruption, fail closed (ADR-105 M2).
        $state = new SuspendedRunState([['role' => 'user', 'content' => 'x']], [], 1, 0, 0, ['ask_user'], [], 'ask_user', []);
        $encoded = json_encode($state->toArray());
        \assert(is_string($encoded));
        $this->repository->findResult = $this->inputRun($encoded);

        $runtime = $this->runtime($this->loopReturning($this->loopResult('x')));

        $this->expectException(CorruptSuspendedStateException::class);
        $runtime->submitInput($this->actor(), 'run-uuid-i', new InputSubmission(['city' => 'Berlin'], 7));
    }

    #[Test]
    public function aSecondConcurrentSubmitInputLosesTheClaim(): void
    {
        $this->repository->findResult = $this->inputRun();
        $runtime = $this->runtime($this->loopReturning($this->loopResult('answered')));

        // First submission wins the atomic claim and resumes.
        $first = $runtime->submitInput($this->actor(), 'run-uuid-i', new InputSubmission(['city' => 'Berlin'], 7));
        self::assertSame(AgentRunOutcome::COMPLETED, $first->outcome);

        // A second submission (the fixture grants only the first claim) is refused.
        $this->expectException(RunAlreadyResumingException::class);
        $runtime->submitInput($this->actor(), 'run-uuid-i', new InputSubmission(['city' => 'Hamburg'], 7));
    }

    #[Test]
    public function cancelDelegatesToTheGuardedTransition(): void
    {
        $this->repository->findResult = $this->suspendedRun();

        self::assertTrue($this->runtime($this->loopReturning($this->loopResult('x')))->cancel($this->actor(), 'run-uuid-1'));
        self::assertNotNull($this->repository->finished);
        self::assertSame(AgentRunStatus::CANCELLED->value, $this->repository->finished['status']);
    }

    #[Test]
    public function eventsFiltersBySequenceAndIsEmptyForAnUnknownRun(): void
    {
        $runtime = $this->runtime($this->loopReturning($this->loopResult('x')));

        self::assertSame([], $runtime->events($this->actor(), 'unknown'));

        $this->repository->findResult     = $this->suspendedRun();
        $this->repository->eventsToReturn = [
            new AgentRunEvent(1, 1, 0, 'request', 1, 0.0, [], 0),
            new AgentRunEvent(2, 1, 1, 'llm', 1, 0.0, [], 0),
            new AgentRunEvent(3, 1, 2, 'tool', 1, 0.0, [], 0),
        ];

        self::assertCount(3, $runtime->events($this->actor(), 'run-uuid-1'));
        $paged = $runtime->events($this->actor(), 'run-uuid-1', 0);
        self::assertCount(2, $paged);
        self::assertSame(1, $paged[0]->sequence);
    }

    #[Test]
    public function statusStripsTheSuspendedStateTranscript(): void
    {
        $this->repository->findResult = $this->suspendedRun();
        $runtime                      = $this->runtime($this->loopReturning($this->loopResult('x')));

        $status = $runtime->status($this->actor(), 'run-uuid-1');

        self::assertNotNull($status);
        self::assertSame('run-uuid-1', $status->uuid);
        // The raw transcript bypasses the privacy filter — never on the status
        // surface (design-review MAJOR 1).
        self::assertNull($status->suspendedState);
    }

    #[Test]
    public function anUnpersistedRunStillExecutesWithAnEmptyRunUuid(): void
    {
        $this->repository->throwOnStart = true;
        $loopResult = $this->loopResult('ran without persistence');

        $result = $this->runtime($this->loopReturning($loopResult))->run($this->request());

        self::assertSame(AgentRunOutcome::COMPLETED, $result->outcome);
        self::assertSame('', $result->runUuid);
        self::assertSame($loopResult, $result->loopResult);
        self::assertNull($this->repository->finished);
    }

    // ------------------------------------------------------------------ //

    private function runtime(
        ToolLoopServiceInterface $loop,
        ?LlmConfiguration $configuration = new LlmConfiguration(),
        ?MessageBusInterface $bus = null,
    ): AgentRuntime {
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($configuration);

        return new AgentRuntime(
            $loop,
            new AgentRunPersister($this->repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL)),
            $configurationRepository,
            null,
            $bus,
            // A stub resolver (returns null for every actor) keeps this a pure
            // unit test — the real resolver would hit the database via
            // setBeUserByUid(). Tool authorization from a live user is covered by
            // the functional ActingBackendUserResolver and tool tests.
            actingBackendUserResolver: self::createStub(ActingBackendUserResolverInterface::class),
        );
    }

    /**
     * A bus double recording every dispatched message into $sink.
     *
     * @param list<object> $sink
     */
    private function recordingBus(array &$sink): MessageBusInterface
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$sink): Envelope {
                $sink[] = $message;

                return new Envelope($message);
            },
        );

        return $bus;
    }

    private function queuedRun(string $uuid, string $requestJson, int $requeueCount = 0): AgentRun
    {
        return new AgentRun(
            uid: 1,
            uuid: $uuid,
            status: 'queued',
            configurationUid: 1,
            configurationIdentifier: 'cfg',
            beUser: 9,
            iterations: 0,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 0,
            suspendedState: null,
            queuedRequest: $requestJson,
            requeueCount: $requeueCount,
        );
    }

    /**
     * A bus double recording each dispatched message and its stamps into $sink.
     *
     * @param list<array{message: object, stamps: list<object>}> $sink
     */
    private function stampRecordingBus(array &$sink): MessageBusInterface
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message, array $stamps = []) use (&$sink): Envelope {
                $sink[] = ['message' => $message, 'stamps' => array_values($stamps)];

                return new Envelope($message, $stamps);
            },
        );

        return $bus;
    }

    /**
     * A bus double that accepts any dispatch and records nothing.
     */
    private function discardingBus(): MessageBusInterface
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn(object $message, array $stamps = []): Envelope => new Envelope($message, $stamps),
        );

        return $bus;
    }

    /**
     * A loop that drives one step boundary (so the heartbeat/cancellation probe
     * fires) and then throws — the queued-run failure path.
     */
    private function loopRecordingThenThrowing(Throwable $throwable): ToolLoopServiceInterface
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturnCallback(
            static function (array $messages, LlmConfiguration $config, ToolExecutionContext $context, ?array $allowed, mixed $options, ?int $max, ?RunTrace $trace) use ($throwable): ToolLoopResult {
                $trace?->recordRequest(1, [], []);

                throw $throwable;
            },
        );

        return $loop;
    }

    #[Test]
    public function approveIsDeniedForANonOwnerNonAdmin(): void
    {
        // The run is owned by uid 9; a different, non-admin backend user must not
        // approve it — a guessed uuid is never authorization (ADR-083).
        $this->repository->findResult = $this->suspendedRun();

        $this->expectException(RunAccessDeniedException::class);
        $this->runtime($this->loopReturning($this->loopResult('x')))
            ->approve(AiActorContext::backendUser(5), 'run-uuid-1', new ApprovalDecision(true, 5));
    }

    #[Test]
    public function cancelIsDeniedForANonOwnerNonAdmin(): void
    {
        $this->repository->findResult = $this->suspendedRun();

        // A stranger cannot cancel someone else's run: reported not-cancelled.
        self::assertFalse(
            $this->runtime($this->loopReturning($this->loopResult('x')))->cancel(AiActorContext::backendUser(5), 'run-uuid-1'),
        );
    }

    #[Test]
    public function statusHidesARunFromANonOwnerNonAdmin(): void
    {
        $this->repository->findResult = $this->suspendedRun();

        // Reading is scoped too: a stranger sees null, not another user's run.
        self::assertNull(
            $this->runtime($this->loopReturning($this->loopResult('x')))->status(AiActorContext::backendUser(5), 'run-uuid-1'),
        );
    }

    #[Test]
    public function aServiceAccountActsOnlyWithinItsDeclaredScopes(): void
    {
        // ADR-110: a service account owns nothing and is authorised solely by the
        // scope each operation requires. One granted for cancellation may cancel
        // but neither read nor approve — scopes do not leak across operations.
        $cancelOnly = AiActorContext::serviceAccount('sweep', [ServiceAccountScope::AGENT_CANCEL]);

        $this->repository->findResult = $this->suspendedRun();
        self::assertTrue(
            $this->runtime($this->loopReturning($this->loopResult('x')))->cancel($cancelOnly, 'run-uuid-1'),
            'cancel scope grants cancel',
        );

        $this->repository->findResult = $this->suspendedRun();
        self::assertNull(
            $this->runtime($this->loopReturning($this->loopResult('x')))->status($cancelOnly, 'run-uuid-1'),
            'cancel scope does NOT grant read',
        );

        $this->repository->findResult = $this->suspendedRun();
        $this->expectException(RunAccessDeniedException::class);
        $this->runtime($this->loopReturning($this->loopResult('x')))
            ->approve($cancelOnly, 'run-uuid-1', new ApprovalDecision(true, 5));
    }

    #[Test]
    public function aScopelessServiceAccountMayDoNothing(): void
    {
        // Fail-closed: a service account that declares no scopes is as powerless
        // as a stranger — it cannot cancel or read another principal's run.
        $powerless = AiActorContext::serviceAccount('no-grants');

        $this->repository->findResult = $this->suspendedRun();
        self::assertFalse(
            $this->runtime($this->loopReturning($this->loopResult('x')))->cancel($powerless, 'run-uuid-1'),
        );

        $this->repository->findResult = $this->suspendedRun();
        self::assertNull(
            $this->runtime($this->loopReturning($this->loopResult('x')))->status($powerless, 'run-uuid-1'),
        );
    }

    private function actor(): AiActorContext
    {
        // Owner (uid 9, matching request()) AND admin -> always authorised, so the
        // lifecycle assertions are not gated by mayActOnRun. Authorization itself
        // is covered by dedicated cases.
        return AiActorContext::backendUser(9, isAdmin: true);
    }

    private function request(?int $maxIterations = null): AgentRunRequest
    {
        return new AgentRunRequest(
            configuration: new LlmConfiguration(),
            messages: [ChatMessage::user('go')],
            maxIterations: $maxIterations,
            actor: AiActorContext::backendUser(9),
        );
    }

    private function loopResult(string $content): ToolLoopResult
    {
        return new ToolLoopResult($content, [], 1, false, UsageStatistics::fromTokens(3, 2));
    }

    private function loopReturning(ToolLoopResult $result): ToolLoopServiceInterface
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('runLoop')->willReturn($result);
        $loop->method('resume')->willReturn($result);
        $loop->method('resumeWithInput')->willReturn($result);

        return $loop;
    }

    private function loopThrowing(Throwable $throwable, bool $onResume = false): ToolLoopServiceInterface
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method($onResume ? 'resume' : 'runLoop')->willThrowException($throwable);

        return $loop;
    }

    private function suspendedState(): SuspendedRunState
    {
        return new SuspendedRunState(
            [['role' => 'user', 'content' => 'delete it']],
            [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_thing', 'arguments' => '{}']]],
            1,
            5,
            2,
        );
    }

    /**
     * @return array<string, mixed> the input schema used by the input-pause fixtures
     */
    private function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']];
    }

    private function inputState(): SuspendedRunState
    {
        return new SuspendedRunState(
            [['role' => 'user', 'content' => 'weather?']],
            [['id' => 'call_1', 'name' => 'ask_user', 'arguments' => []]],
            1,
            5,
            2,
            ['ask_user'],
            [],
            'ask_user',
            $this->inputSchema(),
        );
    }

    private function inputRun(?string $stateJson = null): AgentRun
    {
        $encoded = $stateJson ?? json_encode($this->inputState()->toArray());
        \assert(is_string($encoded));

        return new AgentRun(
            uid: 1,
            uuid: 'run-uuid-i',
            status: 'waiting_for_input',
            configurationUid: 1,
            configurationIdentifier: 'cfg',
            beUser: 9,
            iterations: 1,
            truncated: false,
            totalPromptTokens: 5,
            totalCompletionTokens: 2,
            totalTokens: 7,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 0,
            suspendedState: $encoded,
        );
    }

    private function suspendedRun(string $status = 'waiting_for_approval', ?string $stateJson = null): AgentRun
    {
        $encoded = $stateJson ?? json_encode($this->suspendedState()->toArray());
        \assert(is_string($encoded));

        return new AgentRun(
            uid: 1,
            uuid: 'run-uuid-1',
            status: $status,
            configurationUid: 1,
            configurationIdentifier: 'cfg',
            beUser: 9,
            iterations: 1,
            truncated: false,
            totalPromptTokens: 5,
            totalCompletionTokens: 2,
            totalTokens: 7,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 0,
            suspendedState: $status === 'waiting_for_approval' ? $encoded : null,
        );
    }
}
