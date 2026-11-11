<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aResultNotRetainableException;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentStateEnvelopeRotator;
use Netresearch\NrLlm\Service\Tool\RunContentPrivacyGuard;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The A2A result store (spec 051 A2A-R13, ADR-282).
 */
#[CoversClass(A2aTaskStore::class)]
#[CoversClass(RunContentPrivacyGuard::class)]
final class A2aResultStoreTest extends AbstractFunctionalTestCase
{
    use A2aTaskStoreFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wireStore();
    }

    /**
     * The result and the run's completion commit together; the result is
     * sealed at rest.
     */
    #[Test]
    public function theResultIsKeptWithTheRunsCompletion(): void
    {
        $handle = $this->boundRun();

        self::assertTrue($this->persister->settleCompleted($handle, $this->loopResult('the answer'), 'worker-a:1'));

        self::assertSame(AgentRunStatus::COMPLETED, $this->runOf($handle)->statusEnum());
        $task = $this->taskRow();
        self::assertSame(['TASK_STATE_COMPLETED', 2, ''], [$task['state'], (int) $task['revision'], $task['outcome_reason']]);
        self::assertNotNull($task['status_timestamp']);
        self::assertIsString($task['artifacts']);
        self::assertStringNotContainsString('the answer', $task['artifacts']);
        $artifacts = json_decode($this->opened('artifacts'), true);
        self::assertIsArray($artifacts);
        self::assertCount(1, $artifacts);
        self::assertIsArray($artifacts[0]);
        self::assertSame([['text' => 'the answer']], $artifacts[0]['parts'] ?? null);
        self::assertStringContainsString('alice@example.com', $this->opened('history'));
    }

    /**
     * A failure inside the completing transaction leaves neither the result
     * nor the completion; the run and the task end FAILED instead.
     */
    #[Test]
    public function aForcedFailureLeavesNeitherResultNorCompletion(): void
    {
        $handle = $this->boundRun();
        $this->failInDatabase('a2a_force_completion', A2aTaskStore::TABLE, 'UPDATE', "NEW.state = 'TASK_STATE_COMPLETED'");

        $this->persister->settleCompleted($handle, $this->loopResult('the answer'), 'worker-a:1');
        $this->dropTrigger('a2a_force_completion', A2aTaskStore::TABLE);

        $run = $this->runOf($handle);
        self::assertSame([AgentRunStatus::FAILED, A2aResultNotRetainableException::class], [$run->statusEnum(), $run->errorClass]);
        $task = $this->taskRow();
        self::assertSame(['TASK_STATE_FAILED', A2aResultNotRetainableException::WRITE_FAILED, ''], [$task['state'], $task['outcome_reason'], $this->opened('artifacts')]);
    }

    /**
     * An answer level of `metadata` or `none` at completion stores nothing and
     * ends the run and the task FAILED with RESULT_NOT_RETAINABLE.
     */
    #[Test]
    #[DataProvider('levelsThatKeepNoContent')]
    public function aCeilingThatKeepsNoContentFailsTheRunAndTheTask(string $level): void
    {
        $handle = $this->boundRun();
        $this->connection()->update('tx_nrllm_agentrun', ['answer_privacy_level' => $level], ['uid' => $handle->runUid]);

        $this->persister->settleCompleted($handle, $this->loopResult('the answer'), 'worker-a:1');

        self::assertSame(AgentRunStatus::FAILED, $this->runOf($handle)->statusEnum());
        $task = $this->taskRow();
        self::assertSame(['TASK_STATE_FAILED', A2aResultNotRetainableException::REASON], [$task['state'], $task['outcome_reason']]);
        self::assertSame(['', ''], [$this->opened('artifacts'), $this->opened('history')]);
        self::assertStringNotContainsString('the answer', $this->opened('status_message'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function levelsThatKeepNoContent(): iterable
    {
        yield 'metadata' => ['metadata'];
        yield 'none' => ['none'];
    }

    /**
     * `redacted` keeps the redacted text, in the result and in the history.
     */
    #[Test]
    public function aRedactedCeilingKeepsTheRedactedText(): void
    {
        $handle = $this->boundRun();
        $this->connection()->update('tx_nrllm_agentrun', ['answer_privacy_level' => 'redacted'], ['uid' => $handle->runUid]);

        $this->persister->settleCompleted($handle, $this->loopResult('mail bob@example.com now'), 'worker-a:1');

        self::assertSame('TASK_STATE_COMPLETED', $this->taskRow()['state']);
        self::assertStringContainsString('mail', $this->opened('artifacts'));
        self::assertStringNotContainsString('bob@example.com', $this->opened('artifacts'));
        self::assertStringNotContainsString('alice@example.com', $this->opened('history'));
    }

    /**
     * ADR-282: a run continued after an approval keeps its result, unlike a
     * background answer (ADR-234).
     */
    #[Test]
    public function aRunContinuedAfterAnApprovalKeepsItsResult(): void
    {
        $handle = $this->boundRun();
        self::assertTrue($this->persister->suspend($handle, new SuspendedRunState([['role' => 'user', 'content' => 'x']], [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'w', 'arguments' => '{}']]], 1, 0, 0)));
        $waiting = $this->runOf($handle);
        self::assertTrue($this->persister->claimResume($waiting, 'resume:1', time() + 300));
        $resumed = $this->persister->resumeHandle($this->runOf($handle));
        self::assertInstanceOf(AgentRunHandle::class, $resumed);

        self::assertTrue($this->persister->settleCompleted($resumed, $this->loopResult('approved and done'), 'resume:1'));

        self::assertSame('TASK_STATE_COMPLETED', $this->taskRow()['state']);
        self::assertStringContainsString('approved and done', $this->opened('artifacts'));
    }

    /**
     * ADR-282: a tightening that scrubs answers scrubs this store too, and the
     * change is a new revision for the projector.
     */
    #[Test]
    public function aTighteningScrubsTheKeptResult(): void
    {
        $handle = $this->boundRun();
        $this->persister->settleCompleted($handle, $this->loopResult('the answer'), 'worker-a:1');
        $this->connection()->insert('tx_nrllm_provider', ['identifier' => 'strict', 'name' => 'Strict', 'adapter_type' => 'openai', 'privacy_level' => 'none']);

        $this->guard->beforeProvider($handle->runUid, 'strict');

        self::assertSame(['', ''], [$this->opened('artifacts'), $this->opened('history')]);
        self::assertSame(3, (int) $this->taskRow()['revision']);
    }

    /**
     * A run that is not bound to a task settles as before, and the store is
     * purged with its run.
     */
    #[Test]
    public function anUnboundRunIsUntouchedAndABoundOneIsPurgedWithItsRun(): void
    {
        $plain = $this->persister->begin(null, 1, 'worker-a:1', time() + 300);
        self::assertInstanceOf(AgentRunHandle::class, $plain);
        self::assertTrue($this->persister->settleCompleted($plain, $this->loopResult('x'), 'worker-a:1'));
        self::assertSame(AgentRunStatus::COMPLETED, $this->runOf($plain)->statusEnum());

        $handle = $this->boundRun();
        $this->persister->settleCompleted($handle, $this->loopResult('the answer'), 'worker-a:1');
        self::assertSame(1, $this->eventCount());

        $this->connection()->update('tx_nrllm_agentrun', ['crdate' => 1], ['uid' => $handle->runUid]);
        $this->repository->purgeOlderThan(1000);

        self::assertSame(0, $this->connection()->count('uid', A2aTaskStore::TABLE, []));
        self::assertSame(0, $this->eventCount());
    }

    /**
     * The sealed columns of the store are rewrapped on a master-key rotation
     * like the run's own (ADR-114).
     */
    #[Test]
    public function theRotatorKnowsTheSealedColumnsOfTheStore(): void
    {
        $handle = $this->boundRun();
        $this->persister->settleCompleted($handle, $this->loopResult('the answer'), 'worker-a:1');
        $rotator = $this->getService(AgentStateEnvelopeRotator::class);

        self::assertContains(A2aTaskStore::TABLE, $rotator->getTables());
        self::assertContains('tx_nrllm_a2a_event', $rotator->getTables());
        // The run seals nothing here; the task's artifacts and history and
        // the event's payload are three envelopes.
        self::assertSame(3, $rotator->countEnvelopes());
    }

    private function runOf(AgentRunHandle $handle): AgentRun
    {
        $run = $this->persister->findRun($handle->uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }
}
