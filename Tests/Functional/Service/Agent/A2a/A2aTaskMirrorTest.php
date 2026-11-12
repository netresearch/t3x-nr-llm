<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Closure;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Every status transition of a bound run reaches the A2A task view, in the
 * transition's transaction (spec 051 A2A-SV10; the stage-2 gap: only the
 * completion wrote the view). One case per writer of the persister.
 */
#[CoversClass(A2aTaskStore::class)]
#[CoversClass(AgentRunPersister::class)]
final class A2aTaskMirrorTest extends AbstractFunctionalTestCase
{
    use A2aTaskStoreFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wireStore();
    }

    /**
     * @param Closure(self, AgentRunHandle): bool $settle
     */
    #[Test]
    #[DataProvider('terminalWriters')]
    public function aTerminalTransitionOfABoundRunReachesTheTaskView(Closure $settle, string $state, bool $failureText): void
    {
        $handle = $this->claimedBoundRun(time() + 300);

        self::assertTrue($settle($this, $handle));

        $task = $this->taskRow();
        self::assertSame($state, $task['state']);
        self::assertSame(3, (int) $task['revision']);
        self::assertSame($failureText, str_contains($this->opened('status_message'), 'The task failed.'));
        // The history is kept at the answer level, as at the completion.
        self::assertStringContainsString('alice@example.com', $this->opened('history'));
        // The view is journalled after the commit: the claim, then this.
        self::assertSame(2, $this->eventCount());
        self::assertSame($state, $this->projector->events((int) $task['uid'])[1]['payload']['state']);
    }

    /**
     * @return iterable<string, array{Closure(self, AgentRunHandle): bool, string, bool}>
     */
    public static function terminalWriters(): iterable
    {
        yield 'settleFailed' => [static fn (self $t, AgentRunHandle $h): bool => $t->persister->settleFailed($h, new RuntimeException('x'), 'worker-a:1'), 'TASK_STATE_FAILED', true];
        yield 'settlePolicyStopped on a running run' => [static fn (self $t, AgentRunHandle $h): bool => $t->persister->settlePolicyStopped($h, new RuntimeException('x'), AgentRunTerminationReason::POLICY_DENIED, 'worker-a:1'), 'TASK_STATE_FAILED', true];
        yield 'settleDeadLettered' => [static fn (self $t, AgentRunHandle $h): bool => $t->persister->settleDeadLettered($h, new RuntimeException('x'), AgentRunTerminationReason::RETRIES_EXHAUSTED, 'worker-a:1'), 'TASK_STATE_FAILED', true];
        yield 'settleCancelled' => [static fn (self $t, AgentRunHandle $h): bool => $t->persister->settleCancelled($h, 'worker-a:1'), 'TASK_STATE_CANCELED', false];
        yield 'cancel' => [static fn (self $t, AgentRunHandle $h): bool => $t->persister->cancel($h->uuid), 'TASK_STATE_CANCELED', false];
        yield 'settleDeadLetteredStale' => [static function (self $t, AgentRunHandle $h): bool {
            $t->connection()->update('tx_nrllm_agentrun', ['lease_expires' => 10], ['uid' => $h->runUid]);

            return $t->persister->settleDeadLetteredStale($t->runOf($h), 100, AgentRunTerminationReason::RETRIES_EXHAUSTED);
        }, 'TASK_STATE_FAILED', true];
    }

    /**
     * A run that ends while it waits (cancelled, timed out) reaches the view
     * as well, from the waiting state.
     */
    #[Test]
    public function aRunThatEndsWhileWaitingReachesTheTaskView(): void
    {
        $handle = $this->boundRun();
        self::assertTrue($this->persister->suspendForInput($handle, $this->state()));
        self::assertSame('TASK_STATE_INPUT_REQUIRED', $this->taskRow()['state']);

        self::assertTrue($this->persister->settleIfWaiting($this->runOf($handle), [AgentRunStatus::WAITING_FOR_INPUT], AgentRunStatus::FAILED, AgentRunTerminationReason::PROVIDER_FAILED));

        self::assertSame('TASK_STATE_FAILED', $this->taskRow()['state']);
    }

    #[Test]
    public function cancellingADependencyWaitReachesTheTaskView(): void
    {
        $handle = $this->boundRun();
        self::assertTrue($this->persister->suspendForDependency($handle, $this->state(), $this->checkpoint()));
        self::assertSame('TASK_STATE_WORKING', $this->taskRow()['state']);

        self::assertTrue($this->persister->cancelWaitingForDependency($this->runOf($handle)));

        self::assertSame('TASK_STATE_CANCELED', $this->taskRow()['state']);
    }

    /**
     * QUEUED is SUBMITTED, the worker's claim makes it WORKING, a requeue
     * SUBMITTED again.
     */
    #[Test]
    public function theWorkersClaimAndARequeueReachTheTaskView(): void
    {
        $handle = $this->queuedBoundRun();
        self::assertSame('TASK_STATE_SUBMITTED', $this->taskRow()['state']);

        self::assertTrue($this->persister->claimQueued($this->runOf($handle), 'worker-a:1', time() + 300));
        self::assertSame('TASK_STATE_WORKING', $this->taskRow()['state']);

        self::assertTrue($this->persister->requeue($handle, 'worker-a:1'));
        self::assertSame('TASK_STATE_SUBMITTED', $this->taskRow()['state']);
    }

    #[Test]
    public function theReapersRequeueReachesTheTaskView(): void
    {
        $handle = $this->claimedBoundRun(10);
        self::assertSame('TASK_STATE_WORKING', $this->taskRow()['state']);

        self::assertTrue($this->persister->requeueStale($this->runOf($handle), 100));

        self::assertSame('TASK_STATE_SUBMITTED', $this->taskRow()['state']);
    }

    /**
     * A wait the installation decides (an approval, a dependency) stays
     * WORKING; the client's input is INPUT_REQUIRED; each resume is WORKING.
     */
    #[Test]
    public function suspensionsAndResumesReachTheTaskView(): void
    {
        $approval = $this->boundRun('task-a');
        self::assertTrue($this->persister->suspend($approval, $this->state()));
        self::assertSame('TASK_STATE_WORKING', $this->taskRow('task-a')['state']);
        self::assertTrue($this->persister->claimResume($this->runOf($approval), 'resume:1', time() + 300));
        self::assertSame('TASK_STATE_WORKING', $this->taskRow('task-a')['state']);

        $input = $this->boundRun('task-i');
        self::assertTrue($this->persister->suspendForInput($input, $this->state()));
        self::assertSame('TASK_STATE_INPUT_REQUIRED', $this->taskRow('task-i')['state']);
        self::assertTrue($this->persister->claimResumeFromInput($this->runOf($input), 'resume:1', time() + 300));
        self::assertSame('TASK_STATE_WORKING', $this->taskRow('task-i')['state']);

        $dependency = $this->boundRun('task-d');
        self::assertTrue($this->persister->suspendForDependency($dependency, $this->state(), $this->checkpoint()));
        self::assertTrue($this->persister->claimResumeFromDependency($this->runOf($dependency), 'resume:1', time() + 300));
        self::assertSame('TASK_STATE_WORKING', $this->taskRow('task-d')['state']);
    }

    /**
     * A run a policy refused before any worker took it: the task never left
     * SUBMITTED, so it is REJECTED (A2A-SV10), not FAILED.
     */
    #[Test]
    public function aRunRefusedBeforeAWorkerTookItIsRejected(): void
    {
        $handle = $this->queuedBoundRun();

        self::assertTrue($this->persister->settlePolicyStopped($handle, new RuntimeException('x'), AgentRunTerminationReason::POLICY_DENIED));

        self::assertSame('TASK_STATE_REJECTED', $this->taskRow()['state']);
        self::assertStringContainsString('refused before it started', $this->opened('status_message'));
    }

    /**
     * A terminal task never changes again.
     */
    #[Test]
    public function aTerminalTaskIsNotChangedByALaterTransition(): void
    {
        $handle = $this->boundRun();
        self::assertTrue($this->persister->settleCompleted($handle, $this->loopResult('done'), 'worker-a:1'));

        self::assertFalse($this->persister->cancel($handle->uuid));

        self::assertSame(['TASK_STATE_COMPLETED', 2], [$this->taskRow()['state'], (int) $this->taskRow()['revision']]);
    }

    /**
     * The history at a terminal mirror follows the answer ceiling read under
     * the lock: nothing is kept at `metadata`.
     */
    #[Test]
    public function aTerminalMirrorKeepsTheHistoryOnlyAtTheAnswerLevel(): void
    {
        $handle = $this->claimedBoundRun(time() + 300);
        $this->connection()->update('tx_nrllm_agentrun', ['answer_privacy_level' => 'metadata'], ['uid' => $handle->runUid]);

        self::assertTrue($this->persister->settleFailed($handle, new RuntimeException('x'), 'worker-a:1'));

        self::assertSame(['TASK_STATE_FAILED', ''], [$this->taskRow()['state'], $this->opened('history')]);
    }

    /**
     * The mirror and the transition commit together: a failure writing the
     * task leaves the run as it was.
     */
    #[Test]
    public function aFailedMirrorRollsTheTransitionBack(): void
    {
        $handle = $this->claimedBoundRun(time() + 300);
        $this->failInDatabase('a2a_force_mirror', A2aTaskStore::TABLE, 'UPDATE', "NEW.state = 'TASK_STATE_FAILED'");

        $settled = $this->persister->settleFailed($handle, new RuntimeException('x'), 'worker-a:1');
        $this->dropTrigger('a2a_force_mirror', A2aTaskStore::TABLE);

        self::assertFalse($settled);
        self::assertSame(AgentRunStatus::RUNNING, $this->runOf($handle)->statusEnum());
        self::assertSame('TASK_STATE_WORKING', $this->taskRow()['state']);
    }

    /**
     * A run that no task is bound to settles as before and writes nothing
     * into the view.
     */
    #[Test]
    public function anUnboundRunIsNotMirrored(): void
    {
        $plain = $this->persister->begin(null, 1, 'worker-a:1', time() + 300);
        self::assertInstanceOf(AgentRunHandle::class, $plain);

        self::assertTrue($this->persister->settleFailed($plain, new RuntimeException('x'), 'worker-a:1'));

        self::assertSame(0, $this->connection()->count('uid', A2aTaskStore::TABLE, []));
    }

    private function claimedBoundRun(int $leaseExpires): AgentRunHandle
    {
        $handle = $this->queuedBoundRun();
        self::assertTrue($this->persister->claimQueued($this->runOf($handle), 'worker-a:1', $leaseExpires));

        return $handle;
    }

    private function queuedBoundRun(): AgentRunHandle
    {
        $handle = $this->persister->enqueue(null, 1, '{}', 'a2a');
        self::assertInstanceOf(AgentRunHandle::class, $handle);
        $this->store->bind($handle->runUid, 'task-1', 'ctx-1', 0, 0, [['messageId' => 'm-1', 'role' => 'ROLE_USER', 'parts' => [['text' => 'client asks about alice@example.com']]]]);

        return $handle;
    }

    private function state(): SuspendedRunState
    {
        return new SuspendedRunState([['role' => 'user', 'content' => 'x']], [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'w', 'arguments' => '{}']]], 1, 0, 0);
    }

    private function checkpoint(): DependencyCheckpoint
    {
        return new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512);
    }

    private function runOf(AgentRunHandle $handle): AgentRun
    {
        $run = $this->persister->findRun($handle->uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }
}
