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
use Netresearch\NrLlm\Service\Privacy\A2aContentRedaction;
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
#[CoversClass(A2aContentRedaction::class)]
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
     * ADR-282: the events already journalled hold the same content, and a
     * resubscribe reads them; a tightening reaches them too.
     */
    #[Test]
    public function aTighteningScrubsTheJournalledEventsToo(): void
    {
        $handle = $this->boundRun();
        $this->persister->settleCompleted($handle, $this->loopResult('the answer'), 'worker-a:1');
        $taskUid = (int) $this->taskRow()['uid'];
        self::assertStringContainsString('the answer', json_encode($this->projector->events($taskUid), JSON_THROW_ON_ERROR));
        $this->connection()->insert('tx_nrllm_provider', ['identifier' => 'strict', 'name' => 'Strict', 'adapter_type' => 'openai', 'privacy_level' => 'none']);

        $this->guard->beforeProvider($handle->runUid, 'strict');

        $events = $this->projector->events($taskUid);
        self::assertStringNotContainsString('the answer', json_encode($events, JSON_THROW_ON_ERROR));
        self::assertSame('TASK_STATE_COMPLETED', $events[0]['payload']['state']);
        self::assertArrayNotHasKey('artifacts', $events[0]['payload']);
    }

    /**
     * The history a task is bound with is content: kept at the run's answer
     * level from the start, not only from the completion on.
     */
    #[Test]
    public function theBoundHistoryFollowsTheAnswerLevel(): void
    {
        $redacted = $this->persister->begin(null, 1, 'worker-a:1', time() + 300);
        $nothing  = $this->persister->begin(null, 1, 'worker-a:1', time() + 300);
        self::assertInstanceOf(AgentRunHandle::class, $redacted);
        self::assertInstanceOf(AgentRunHandle::class, $nothing);
        $this->connection()->update('tx_nrllm_agentrun', ['answer_privacy_level' => 'redacted'], ['uid' => $redacted->runUid]);
        $this->connection()->update('tx_nrllm_agentrun', ['answer_privacy_level' => 'metadata'], ['uid' => $nothing->runUid]);
        $history = [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['text' => 'ask alice@example.com']]]];

        $this->store->bind($redacted->runUid, 'task-r', 'ctx', 0, 0, $history);
        $this->store->bind($nothing->runUid, 'task-n', 'ctx', 0, 0, $history);

        self::assertStringContainsString('ask', $this->opened('history', 'task-r'));
        self::assertStringNotContainsString('alice@example.com', $this->opened('history', 'task-r'));
        self::assertSame('', $this->opened('history', 'task-n'));
    }

    /**
     * Every part type at every answer level, at bind and at a later
     * tightening: `full` keeps the content as it came, `redacted` keeps only
     * redacted text (a data part as its JSON, a file as a note) and drops
     * filenames and metadata, `metadata` and `none` keep nothing.
     */
    #[Test]
    public function everyPartTypeIsKeptAtTheAnswerLevelAndNothingBesideTheText(): void
    {
        $history = [[
            'messageId' => 'm',
            'role'      => 'ROLE_USER',
            'metadata'  => ['tracking' => 'secret-message-metadata'],
            'parts'     => [
                ['text' => 'ask alice@example.com', 'mediaType' => 'text/plain', 'filename' => 'secret-text-name.txt', 'metadata' => ['note' => 'secret-part-metadata']],
                ['raw' => base64_encode('secret-raw-bytes'), 'filename' => 'secret-raw-name.pdf', 'mediaType' => 'application/pdf'],
                ['url' => 'https://files.example/secret-url.pdf', 'filename' => 'secret-url-name.pdf'],
                ['data' => ['contact' => 'alice@example.com', 'nested' => ['metadata' => 'kept-as-text']], 'metadata' => ['note' => 'secret-data-metadata']],
            ],
        ]];
        $secrets = ['alice@example.com', 'secret-message-metadata', 'secret-text-name', 'secret-part-metadata', base64_encode('secret-raw-bytes'), 'secret-raw-name', 'secret-url', 'secret-data-metadata'];

        foreach (['full', 'redacted', 'metadata', 'none'] as $level) {
            $handle = $this->persister->begin(null, 1, 'worker-a:1', time() + 300);
            self::assertInstanceOf(AgentRunHandle::class, $handle);
            $this->connection()->update('tx_nrllm_agentrun', ['answer_privacy_level' => $level], ['uid' => $handle->runUid]);
            $this->store->bind($handle->runUid, 'task-' . $level, 'ctx', 0, 0, $history);
        }

        self::assertSame($history, json_decode($this->opened('history', 'task-full'), true));
        self::assertSame('', $this->opened('history', 'task-metadata'));
        self::assertSame('', $this->opened('history', 'task-none'));
        $this->assertRedactedHistory($this->opened('history', 'task-redacted'), $secrets);

        // A tightening from full to redacted applies the same allow-list.
        $this->connection()->insert('tx_nrllm_provider', ['identifier' => 'redacting', 'name' => 'Redacting', 'adapter_type' => 'openai', 'privacy_level' => 'redacted']);
        $full = $this->connection()->select(['run_uid'], A2aTaskStore::TABLE, ['task_id' => 'task-full'])->fetchOne();
        $this->guard->beforeProvider(is_numeric($full) ? (int) $full : 0, 'redacting');
        $this->assertRedactedHistory($this->opened('history', 'task-full'), $secrets);
    }

    /**
     * @param list<string> $secrets
     */
    private function assertRedactedHistory(string $stored, array $secrets): void
    {
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $stored);
        }

        $decoded = json_decode($stored, true);
        self::assertIsArray($decoded);
        $message = $decoded[0] ?? null;
        self::assertIsArray($message);
        self::assertArrayNotHasKey('metadata', $message);
        self::assertSame(['m', 'ROLE_USER'], [$message['messageId'] ?? null, $message['role'] ?? null]);
        $parts = $message['parts'] ?? [];
        self::assertIsArray($parts);
        self::assertCount(4, $parts);
        $texts = [];
        foreach ($parts as $part) {
            self::assertIsArray($part);
            self::assertSame([], array_diff(array_keys($part), ['text', 'mediaType']), json_encode($part, JSON_THROW_ON_ERROR));
            $texts[] = $this->text($part['text'] ?? null);
        }

        self::assertStringContainsString('ask', $texts[0]);
        self::assertSame([A2aContentRedaction::FILE_REMOVED, A2aContentRedaction::FILE_REMOVED], [$texts[1], $texts[2]]);
        self::assertStringContainsString('kept-as-text', $texts[3]);
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
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
