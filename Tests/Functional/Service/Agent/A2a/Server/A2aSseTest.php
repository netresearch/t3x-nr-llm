<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Closure;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aAgentCardBuilder;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aAuthenticator;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aListCursor;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aPushConfigStore;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcReply;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcServer;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSendMessageHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerSettings;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSseWriter;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aStreamSlots;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aTaskStream;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aTaskView;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskProjector;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * SendStreamingMessage and SubscribeToTask of the server role (spec 051
 * A2A-SSE1 to A2A-SSE5, A2A-D5, A2A-OPS2).
 *
 * A stream is driven through the real RPC server with a stream whose sleep
 * plays the worker and whose clock steps a quarter second per poll, so time
 * limits and keep-alives are tested without waiting for them.
 */
#[CoversClass(A2aTaskStream::class)]
#[CoversClass(A2aStreamSlots::class)]
#[CoversClass(A2aSseWriter::class)]
#[CoversClass(A2aRpcReply::class)]
final class A2aSseTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;

    private const STREAM_SETTINGS = ['streaming' => '1', 'streamMaxSeconds' => '20', 'maxStreamsPerTask' => '2', 'maxStreamsPerClient' => '3', 'maxStreams' => '4'];

    private float $clock = 1_000_000.0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServer();
        $this->streamSettings([]);
    }

    protected function tearDown(): void
    {
        $this->serverSettings(null);
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
        parent::tearDown();
    }

    /**
     * A2A-SSE1, A2A-SSE2, A2A-SSE5, A2A-D5: the snapshot comes first, then
     * only newer journal events in order; the artifact arrives whole, with the
     * event that completes the task and not before; the stream closes after
     * COMPLETED. The task was accepted without a blocking wait.
     */
    #[Test]
    public function aStreamSendsTheSnapshotThenTheEventsAndClosesAtCompletion(): void
    {
        $persister = $this->getService(AgentRunPersister::class);
        $run       = null;
        $events    = $this->stream('SendStreamingMessage', ['message' => $this->message()], 1, function (int $tick, string $taskId) use ($persister, &$run): void {
            $run ??= $this->runOf($taskId);
            if ($tick === 1) {
                self::assertTrue($persister->claimQueued($run, 'worker-t', time() + 300));
            }

            if ($tick === 3) {
                self::assertTrue($persister->settleCompleted(new AgentRunHandle($run->uid, $run->uuid), new ToolLoopResult('the summary', [], 1, false, UsageStatistics::fromTokens(1, 1)), 'worker-t'));
            }
        });

        self::assertSame(['task', 'statusUpdate', 'artifactUpdate', 'statusUpdate'], $this->kinds($events['frames']));
        self::assertSame('TASK_STATE_SUBMITTED', self::j($events['frames'][0], 'result.task.status.state'));
        self::assertSame('TASK_STATE_WORKING', self::j($events['frames'][1], 'result.statusUpdate.status.state'));
        self::assertArrayNotHasKey('artifacts', self::objectOf(self::j($events['frames'][0], 'result.task')), 'no content before the check passed');
        self::assertSame('the summary', self::j($events['frames'][2], 'result.artifactUpdate.artifact.parts.0.text'));
        self::assertFalse(self::j($events['frames'][2], 'result.artifactUpdate.append'));
        self::assertTrue(self::j($events['frames'][2], 'result.artifactUpdate.lastChunk'));
        self::assertSame('TASK_STATE_COMPLETED', self::j($events['frames'][3], 'result.statusUpdate.status.state'));
        foreach ($events['frames'] as $frame) {
            self::assertSame('2.0', $frame['jsonrpc'] ?? null);
            self::assertSame('s-1', $frame['id'] ?? null, 'every event answers the request id');
        }

        $taskId = self::text(self::j($events['frames'][0], 'result.task.id'));
        self::assertSame($taskId, self::j($events['frames'][3], 'result.statusUpdate.taskId'));
        self::assertSame(self::j($events['frames'][0], 'result.task.contextId'), self::j($events['frames'][3], 'result.statusUpdate.contextId'));
        self::assertSame(3, $events['ticks'], 'closed right after the completing event');
        self::assertSame(0, $this->heldWaitSlots(), 'a stream takes no waiting slot');
        self::assertSame(0, $this->heldStreamSlots(), 'the slot is released when the stream ends');
    }

    /**
     * Spec 059 WS1, WS2, WS6: the stream says when the run starts to wait for
     * a remote agent, in a WORKING update with the fixed text, and says it no
     * longer waits in the next WORKING update, without a text.
     */
    #[Test]
    public function aStreamSaysWhatAWorkingTaskWaitsFor(): void
    {
        $persister = $this->getService(AgentRunPersister::class);
        $run       = null;
        $events    = $this->stream('SendStreamingMessage', ['message' => $this->message()], 1, function (int $tick, string $taskId) use ($persister, &$run): void {
            $run ??= $this->runOf($taskId);
            match ($tick) {
                1       => self::assertTrue($persister->claimQueued($run, 'worker-t', time() + 300)),
                2       => self::assertTrue($persister->suspendForDependency(new AgentRunHandle($run->uid, $run->uuid), $this->suspended(), new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'c', 'w', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512))),
                3       => self::assertTrue($persister->claimResumeFromDependency($this->runOf($taskId), 'resume:1', time() + 300)),
                4       => self::assertTrue($persister->settleCompleted(new AgentRunHandle($run->uid, $run->uuid), new ToolLoopResult('the summary', [], 1, false, UsageStatistics::fromTokens(1, 1)), 'resume:1')),
                default => null,
            };
        });

        self::assertSame(['task', 'statusUpdate', 'statusUpdate', 'statusUpdate', 'artifactUpdate', 'statusUpdate'], $this->kinds($events['frames']));
        $updates = array_map(static fn (array $frame): array => [self::j($frame, 'result.statusUpdate.status.state'), self::j($frame, 'result.statusUpdate.status.message.parts.0.text')], [$events['frames'][1], $events['frames'][2], $events['frames'][3]]);
        self::assertSame([['TASK_STATE_WORKING', null], ['TASK_STATE_WORKING', A2aTaskStore::TEXT_WAITING_FOR_REMOTE_AGENT], ['TASK_STATE_WORKING', null]], $updates);
        self::assertStringNotContainsString('remote-1', $events['raw'], 'the remote task id stays inside');
    }

    /**
     * Spec 059 WS6: a subscription that starts during the wait gets the text
     * in its snapshot.
     */
    #[Test]
    public function aSubscriptionDuringAWaitStartsWithTheText(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $run  = $this->runOf($task['id']);
        self::assertTrue($this->getService(AgentRunPersister::class)->claimQueued($run, 'worker-t', time() + 300));
        self::assertTrue($this->getService(AgentRunPersister::class)->suspend(new AgentRunHandle($run->uid, $run->uuid), $this->suspended()));

        $events = $this->stream('SubscribeToTask', ['id' => $task['id']], 1, function (int $tick, string $taskId) use ($task): void {
            if ($tick === 1) {
                $this->getService(AgentRunPersister::class)->cancel($this->runOf($task['id'])->uuid);
            }
        });

        self::assertSame('TASK_STATE_WORKING', self::j($events['frames'][0], 'result.task.status.state'));
        self::assertSame(A2aTaskStore::TEXT_WAITING_FOR_APPROVAL, self::j($events['frames'][0], 'result.task.status.message.parts.0.text'));
        $last = $events['frames'][count($events['frames']) - 1];
        self::assertSame('TASK_STATE_CANCELED', self::j($last, 'result.statusUpdate.status.state'));
        self::assertStringNotContainsString('waiting', (string) json_encode($last), 'the cancel ends the wait text');
    }

    /**
     * ADR-282: a completed task's content goes out at the run's current
     * answer level. A provider tightened to `metadata` after the completion
     * was journalled sends the completion without its artifact.
     */
    #[Test]
    public function aStreamSendsSettledContentAtTheCurrentAnswerLevel(): void
    {
        $persister = $this->getService(AgentRunPersister::class);
        $events    = $this->stream('SendStreamingMessage', ['message' => $this->message()], 1, function (int $tick, string $taskId) use ($persister): void {
            if ($tick === 1) {
                $run = $this->runOf($taskId);
                self::assertTrue($persister->claimQueued($run, 'worker-t', time() + 300));
                self::assertTrue($persister->settleCompleted(new AgentRunHandle($run->uid, $run->uuid), new ToolLoopResult('the secret summary', [], 1, false, UsageStatistics::fromTokens(1, 1)), 'worker-t'));
                $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['privacy_level' => 'metadata'], []);
            }
        });

        self::assertSame('TASK_STATE_COMPLETED', self::j($events['frames'][count($events['frames']) - 1], 'result.statusUpdate.status.state'));
        self::assertNotContains('artifactUpdate', $this->kinds($events['frames']));
        self::assertStringNotContainsString('the secret summary', $events['raw']);
    }

    /**
     * A JSON-RPC id longer than 255 bytes is refused before anything is
     * accepted: every event of a stream repeats it.
     */
    #[Test]
    public function aVeryLongRequestIdIsRefused(): void
    {
        $tasks = $this->rows(A2aTaskStore::TABLE);
        $reply = $this->post(json_encode(['jsonrpc' => '2.0', 'id' => str_repeat('i', 256), 'method' => 'SendStreamingMessage', 'params' => ['message' => $this->message()]], JSON_THROW_ON_ERROR));

        self::assertSame(-32600, self::j(json_decode((string) $reply->getBody(), true), 'error.code'));
        self::assertSame($tasks, $this->rows(A2aTaskStore::TABLE));
    }

    /**
     * A2A-SSE2: a stream sends only events newer than its snapshot's
     * revision; an event already contained in the snapshot is not repeated.
     */
    #[Test]
    public function subscribingSendsOnlyEventsNewerThanTheSnapshot(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $run  = $this->runOf($task['id']);
        self::assertTrue($this->getService(AgentRunPersister::class)->claimQueued($run, 'worker-t', time() + 300));
        $this->getService(A2aTaskProjector::class)->projectPending();
        self::assertGreaterThanOrEqual(1, count($this->getService(A2aTaskProjector::class)->events($this->taskUid($task['id']))), 'the WORKING event is journalled');

        $events = $this->stream('SubscribeToTask', ['id' => $task['id']], 1, function (int $tick) use ($run): void {
            if ($tick === 2) {
                self::assertTrue($this->getService(AgentRunPersister::class)->settleCompleted(new AgentRunHandle($run->uid, $run->uuid), new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1)), 'worker-t'));
            }
        });

        self::assertSame(['task', 'artifactUpdate', 'statusUpdate'], $this->kinds($events['frames']), 'WORKING is in the snapshot, not sent again');
        self::assertSame('TASK_STATE_WORKING', self::j($events['frames'][0], 'result.task.status.state'));
        self::assertSame('TASK_STATE_COMPLETED', self::j($events['frames'][2], 'result.statusUpdate.status.state'));
    }

    /**
     * A2A-SSE4: SubscribeToTask on a terminal task is -32004, on an unknown
     * task and on another client's task the same -32001; each an ordinary
     * JSON response, no stream.
     */
    #[Test]
    public function subscribeRefusesATerminalTaskAndAnotherClientsTask(): void
    {
        $done  = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-complete-task-s1')], 3));
        $other = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]], 4));

        $terminal = $this->reply('SubscribeToTask', ['id' => $done['id']], 3);
        self::assertFalse($terminal->isStream());
        self::assertSame(-32004, $this->errorOf($this->asArray($terminal))['code']);

        $foreign = $this->asArray($this->reply('SubscribeToTask', ['id' => $other['id']], 1));
        $unknown = $this->asArray($this->reply('SubscribeToTask', ['id' => '00000000-0000-4000-8000-000000000000'], 1));
        self::assertSame(-32001, $this->errorOf($foreign)['code']);
        self::assertSame(json_encode($foreign), json_encode($unknown), 'indistinguishable (A2A-SEC2)');
        self::assertSame(0, $this->heldStreamSlots(), 'a refusal holds no slot');
    }

    /**
     * A2A-SSE4, A2A-D5: on a task in INPUT_REQUIRED the stream sends the task
     * and closes.
     */
    #[Test]
    public function subscribeToATaskWaitingForInputSendsTheTaskAndCloses(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-input-required-s2')], 3));
        self::assertSame('TASK_STATE_INPUT_REQUIRED', self::j($task, 'status.state'));

        $events = $this->stream('SubscribeToTask', ['id' => $task['id']], 3);

        self::assertSame(['task'], $this->kinds($events['frames']));
        self::assertSame('TASK_STATE_INPUT_REQUIRED', self::j($events['frames'][0], 'result.task.status.state'));
        self::assertSame(0, $events['ticks']);
    }

    /**
     * A2A-D5, A2A-SSE3: after AUTH_REQUIRED the stream stays open, sends a
     * keep-alive comment at least every 15 seconds and ends at
     * streamMaxSeconds; ending it leaves the task as it was (A2A-SSE1).
     */
    #[Test]
    public function aStreamStaysOpenAfterAuthRequiredUntilItsTimeLimit(): void
    {
        $task   = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        $events = $this->stream('SubscribeToTask', ['id' => $task['id']], 1, function (int $tick) use ($task): void {
            if ($tick === 1) {
                $this->forceState($task['id'], 'TASK_STATE_AUTH_REQUIRED');
            }
        });

        self::assertSame(['task', 'statusUpdate'], $this->kinds($events['frames']));
        self::assertSame('TASK_STATE_AUTH_REQUIRED', self::j($events['frames'][1], 'result.statusUpdate.status.state'));
        self::assertSame(80, $events['ticks'], '20 s at a quarter second per poll: ended by the time limit');
        self::assertGreaterThanOrEqual(1, substr_count($events['raw'], ": keep-alive\n"));
        self::assertLessThan(15.0, $this->longestGap($events['times']), 'a write within every 15 s, a poll interval included');
        self::assertSame('TASK_STATE_AUTH_REQUIRED', self::j($this->taskOf($this->call('GetTask', ['id' => $task['id']])), 'status.state'), 'the task is untouched');
    }

    /**
     * A2A-SSE1: a stream that ends, by its time limit here, never cancels the
     * task or its run.
     */
    #[Test]
    public function closingAStreamLeavesTheTaskRunning(): void
    {
        $events = $this->stream('SendStreamingMessage', ['message' => $this->message()], 1);
        $taskId = self::text(self::j($events['frames'][0], 'result.task.id'));

        self::assertSame(80, $events['ticks']);
        self::assertSame('TASK_STATE_SUBMITTED', self::j($this->taskOf($this->call('GetTask', ['id' => $taskId])), 'status.state'));
        self::assertSame('queued', $this->db('tx_nrllm_agentrun')->select(['status'], 'tx_nrllm_agentrun', ['uid' => $this->runOf($taskId)->uid])->fetchOne());
    }

    /**
     * CORE-STREAM-002: a message answer is a stream of exactly that message.
     */
    #[Test]
    public function aMessageAnswerIsAStreamOfThatOneMessage(): void
    {
        $events = $this->stream('SendStreamingMessage', ['message' => $this->message('hi', 'tck-message-response-s3')], 3);

        self::assertSame(['message'], $this->kinds($events['frames']));
        self::assertSame('ROLE_AGENT', self::j($events['frames'][0], 'result.message.role'));
        self::assertSame(0, $this->heldStreamSlots());
    }

    /**
     * A2A-SSE3: the per-client, per-task and installation limits refuse with
     * HTTP 503, STREAM_CAPACITY_EXHAUSTED and Retry-After, before anything
     * is stored.
     */
    #[Test]
    public function streamCapacityIsLimitedPerClientPerTaskAndInTotal(): void
    {
        $this->streamSettings(['maxStreamsPerClient' => '1', 'maxStreamsPerTask' => '1', 'maxStreams' => '2']);
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true], 'metadata' => [A2aSendMessageHandler::PROFILE_EXTENSION => 'summary']], 2));

        $held = $this->reply('SubscribeToTask', ['id' => $task['id']], 2);
        self::assertTrue($held->isStream());
        $tasks = $this->rows(A2aTaskStore::TABLE);

        $perClient = $this->reply('SendStreamingMessage', ['message' => $this->message('another'), 'metadata' => [A2aSendMessageHandler::PROFILE_EXTENSION => 'summary']], 2);
        self::assertSame(['code' => -32000, 'reason' => 'STREAM_CAPACITY_EXHAUSTED', 'http' => 503], $this->errorOf($this->asArray($perClient)));
        self::assertSame('5', $perClient->headers['Retry-After'] ?? null);
        self::assertSame($tasks, $this->rows(A2aTaskStore::TABLE), 'a refused stream stored no task');

        $this->streamSettings(['maxStreamsPerClient' => '5', 'maxStreamsPerTask' => '1', 'maxStreams' => '2']);
        self::assertSame('STREAM_CAPACITY_EXHAUSTED', $this->errorOf($this->asArray($this->reply('SubscribeToTask', ['id' => $task['id']], 2)))['reason'], 'per task');

        self::assertTrue($this->reply('SendStreamingMessage', ['message' => $this->message()], 1)->isStream(), 'the second installation slot');
        self::assertSame('STREAM_CAPACITY_EXHAUSTED', $this->errorOf($this->asArray($this->reply('SendStreamingMessage', ['message' => $this->message()], 4)))['reason'], 'installation');

        $this->streamSettings(['maxStreams' => '0']);
        $this->db(A2aStreamSlots::TABLE)->update(A2aStreamSlots::TABLE, ['holder' => '', 'expires' => 0], []);
        self::assertSame('STREAM_CAPACITY_EXHAUSTED', $this->errorOf($this->asArray($this->reply('SubscribeToTask', ['id' => $task['id']], 2)))['reason'], 'maxStreams 0 refuses every stream');
    }

    /**
     * A2A-SSE3, A2A-MT2: an answer to a waiting task sent as a stream is
     * counted against that task's streams before it is taken in; refused, it
     * stored nothing and the task still waits.
     */
    #[Test]
    public function aContinuationOverTheTasksStreamLimitIsRefusedBeforeItIsTakenIn(): void
    {
        $this->streamSettings(['maxStreamsPerTask' => '1']);
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-input-required-c1')], 3));
        self::assertTrue($this->reply('SubscribeToTask', ['id' => $task['id']], 3)->isStream(), 'the one stream the task may have');
        $history = $this->db(A2aTaskStore::TABLE)->select(['history', 'revision'], A2aTaskStore::TABLE, ['task_id' => $task['id']])->fetchAssociative();

        $answer = $this->reply('SendStreamingMessage', ['message' => $this->message('the answer', 'tck-complete-task-c2', ['taskId' => $task['id'], 'contextId' => $task['contextId']])], 3);

        self::assertSame('STREAM_CAPACITY_EXHAUSTED', $this->errorOf($this->asArray($answer))['reason']);
        self::assertSame($history, $this->db(A2aTaskStore::TABLE)->select(['history', 'revision'], A2aTaskStore::TABLE, ['task_id' => $task['id']])->fetchAssociative(), 'nothing was taken in');
        self::assertSame(0, $this->rows('tx_nrllm_a2a_dedup', ['message_id' => 'tck-complete-task-c2']));
        self::assertSame('TASK_STATE_INPUT_REQUIRED', self::j($this->taskOf($this->call('GetTask', ['id' => $task['id']], 3)), 'status.state'));
    }

    /**
     * A2A-SSE2, A2A-SSE3: the first event names the task even when the
     * snapshot is larger than a frame: it goes without its history.
     */
    #[Test]
    public function aSnapshotLargerThanAFrameStillNamesTheTask(): void
    {
        $this->streamSettings(['maxFrameBytes' => '4096', 'maxRequestBytes' => '65536']);

        $events = $this->stream('SendStreamingMessage', ['message' => $this->message(str_repeat('a long question ', 400))], 1);

        self::assertSame('task', $this->kinds($events['frames'])[0] ?? null);
        $task = self::objectOf(self::j($events['frames'][0], 'result.task'));
        self::assertNotSame('', $task['id'] ?? '');
        self::assertSame('TASK_STATE_SUBMITTED', self::j($task, 'status.state'));
        self::assertArrayNotHasKey('history', $task);
    }

    /**
     * SendStreamingMessage honours configuration.historyLength in its
     * snapshot, as SendMessage does (A2A-SV6).
     */
    #[Test]
    public function theSnapshotHonoursTheHistoryLength(): void
    {
        $events = $this->stream('SendStreamingMessage', ['message' => $this->message(), 'configuration' => ['historyLength' => 0]], 1);

        self::assertArrayNotHasKey('history', self::objectOf(self::j($events['frames'][0], 'result.task')));
    }

    /**
     * A2A-SSE3: a slot is a lease; one whose stream died without releasing
     * it is free again once the lease ran out.
     */
    #[Test]
    public function aSlotWhoseLeaseRanOutIsFreeAgain(): void
    {
        $slots  = $this->getService(A2aStreamSlots::class);
        $holder = $slots->take(1, 'task-a', 1, 1, 1, 1000);
        self::assertIsString($holder);
        self::assertNull($slots->take(1, 'task-b', 1, 1, 1, 1000 + A2aStreamSlots::LEASE_SECONDS), 'held until the lease ends');
        self::assertIsString($slots->take(2, 'task-b', 1, 1, 1, 1001 + A2aStreamSlots::LEASE_SECONDS), 'free once it ran out');
        self::assertFalse($slots->renew($holder, 1002 + A2aStreamSlots::LEASE_SECONDS), 'the old holder lost it');

        $lapsed = $slots->take(3, 'task-c', 1, 1, 2, 2000);
        self::assertIsString($lapsed);
        self::assertFalse($slots->renew($lapsed, 2001 + A2aStreamSlots::LEASE_SECONDS), 'a lease that ran out is not renewed, even while nobody took the slot');
    }

    /**
     * A2A-SSE3: the installation's total counts every live slot, so a free
     * low slot does not admit one more after maxStreams was lowered; and a
     * slot taken over by another stream cannot be bound any more.
     */
    #[Test]
    public function theTotalCountsEveryLiveSlotAndALostSlotCannotBeBound(): void
    {
        $slots = $this->getService(A2aStreamSlots::class);
        $first = $slots->take(1, 'task-a', 2, 2, 2, 1000);
        self::assertIsString($first);
        self::assertIsString($slots->take(2, 'task-b', 2, 2, 2, 1000));
        $slots->release($first);

        self::assertNull($slots->take(3, 'task-c', 2, 2, 1, 1000), 'slot 2 is still held: one stream already fills a total of 1');

        $lapsed = $slots->take(3, '', 2, 2, 2, 2000);
        self::assertIsString($lapsed);
        self::assertIsString($slots->take(4, '', 2, 2, 2, 2001 + A2aStreamSlots::LEASE_SECONDS), 'another stream takes the lapsed slot');
        self::assertFalse($slots->bind($lapsed, 'task-d', 2, 2002 + A2aStreamSlots::LEASE_SECONDS));
    }

    /**
     * A2A-SSE3: a slot whose lease ran out cannot be bound back to life: a
     * stream of the same client admitted meanwhile counted it out, and
     * reviving it would put the client over its limit.
     */
    #[Test]
    public function aLapsedSlotCannotBeBoundBackOverTheLimit(): void
    {
        $slots = $this->getService(A2aStreamSlots::class);
        $lower = $slots->take(2, '', 2, 1, 3, 1000);
        self::assertIsString($lower);
        $lapsed = $slots->take(1, '', 2, 1, 3, 1000);
        self::assertIsString($lapsed, 'slot 2');
        $slots->release($lower);
        $now = 1001 + A2aStreamSlots::LEASE_SECONDS;
        self::assertIsString($slots->take(1, 'task-x', 2, 1, 3, $now), 'admitted into slot 1: the lapsed slot 2 no longer counts');

        self::assertFalse($slots->bind($lapsed, 'task-y', 2, $now));
        self::assertSame(1, (int) $this->db(A2aStreamSlots::TABLE)->executeQuery('SELECT COUNT(*) FROM ' . A2aStreamSlots::TABLE . " WHERE client_uid = 1 AND holder <> '' AND expires >= " . $now)->fetchOne(), 'the client stays at its limit of one');
    }

    /**
     * A2A-SSE3: the client is checked again before events are sent that a
     * poll returned after the renewal was due, a terminal one included.
     */
    #[Test]
    public function eventsAfterADueRenewalAreSentOnlyToAStillValidClient(): void
    {
        $persister = $this->getService(AgentRunPersister::class);
        $events    = $this->stream('SendStreamingMessage', ['message' => $this->message()], 1, function (int $tick, string $taskId) use ($persister): void {
            if ($tick === A2aTaskStream::RENEW_SECONDS * 4) {
                $run = $this->runOf($taskId);
                self::assertTrue($persister->claimQueued($run, 'worker-t', time() + 300));
                self::assertTrue($persister->settleCompleted(new AgentRunHandle($run->uid, $run->uuid), new ToolLoopResult('for the disabled client', [], 1, false, UsageStatistics::fromTokens(1, 1)), 'worker-t'));
                $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['enabled' => 0], ['uid' => 1]);
            }
        });

        self::assertSame(['task'], $this->kinds($events['frames']));
        self::assertStringNotContainsString('for the disabled client', $events['raw']);
    }

    /**
     * A2A-SSE3: a frame larger than maxFrameBytes is not sent; the stream
     * ends, and the task is read with GetTask.
     */
    #[Test]
    public function aFrameAboveTheLimitEndsTheStreamWithoutSendingIt(): void
    {
        $this->streamSettings(['maxFrameBytes' => '4096']);
        $persister = $this->getService(AgentRunPersister::class);
        $events    = $this->stream('SendStreamingMessage', ['message' => $this->message()], 1, function (int $tick, string $taskId) use ($persister): void {
            if ($tick === 1) {
                $run = $this->runOf($taskId);
                self::assertTrue($persister->claimQueued($run, 'worker-t', time() + 300));
                self::assertTrue($persister->settleCompleted(new AgentRunHandle($run->uid, $run->uuid), new ToolLoopResult(str_repeat('long answer ', 600), [], 1, false, UsageStatistics::fromTokens(1, 1)), 'worker-t'));
            }
        });

        self::assertSame(['task', 'statusUpdate'], $this->kinds($events['frames']), 'the oversized artifact frame was not written, nor anything after it');
        self::assertSame('TASK_STATE_WORKING', self::j($events['frames'][1], 'result.statusUpdate.status.state'));
        self::assertSame(1, $events['ticks']);
        self::assertSame('TASK_STATE_COMPLETED', self::j($this->taskOf($this->call('GetTask', ['id' => self::text(self::j($events['frames'][0], 'result.task.id'))])), 'status.state'));
    }

    /**
     * A2A-SSE3: a client disabled while its stream is open is cut at the next
     * lease renewal, at most RENEW_SECONDS later.
     */
    #[Test]
    public function aStreamOfAClientDisabledMeanwhileEndsAtTheNextRenewal(): void
    {
        $events = $this->stream('SendStreamingMessage', ['message' => $this->message()], 1, function (int $tick): void {
            if ($tick === 1) {
                $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['enabled' => 0], ['uid' => 1]);
            }
        });

        self::assertSame(A2aTaskStream::RENEW_SECONDS * 4, $events['ticks']);
    }

    /**
     * A2A-OPS2, A2A-P7: with streaming off, the card declares none and both
     * methods are -32004; GetTask keeps working. On, the card declares it.
     */
    #[Test]
    public function theStreamingSwitchDecidesTheCardAndTheMethods(): void
    {
        self::assertTrue(self::j($this->card(), 'capabilities.streaming'));

        $this->streamSettings(['streaming' => '0']);
        self::assertFalse(self::j($this->card(), 'capabilities.streaming'));
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]]));
        foreach (['SendStreamingMessage' => ['message' => $this->message()], 'SubscribeToTask' => ['id' => $task['id']]] as $method => $params) {
            $reply = $this->call($method, $params);
            self::assertSame(-32004, $this->errorOf($reply)['code'], $method);
        }

        self::assertSame('TASK_STATE_SUBMITTED', self::j($this->taskOf($this->call('GetTask', ['id' => $task['id']])), 'status.state'));
    }

    /**
     * A2A-SSE3: a repeated messageId finds the task it created; a stream of
     * it counts against that task's limit, and a refusal creates nothing.
     */
    #[Test]
    public function aRepeatedStreamedMessageCountsAgainstItsTasksLimit(): void
    {
        $this->streamSettings(['maxStreamsPerTask' => '1']);
        $message = $this->message('again', 'm-repeat-1');
        self::assertTrue($this->reply('SendStreamingMessage', ['message' => $message], 1)->isStream());
        $tasks = $this->rows(A2aTaskStore::TABLE);

        $again = $this->reply('SendStreamingMessage', ['message' => $message], 1);

        self::assertSame('STREAM_CAPACITY_EXHAUSTED', $this->errorOf($this->asArray($again))['reason']);
        self::assertSame($tasks, $this->rows(A2aTaskStore::TABLE));
        self::assertSame(1, $this->heldStreamSlots(), 'the refused stream gave its slot back');
    }

    /**
     * A2A-OPS2: streaming is on by default; a setting TYPO3 has not written
     * yet does not switch it off.
     */
    #[Test]
    public function streamingIsOnWhenTheSettingIsMissing(): void
    {
        $settings = self::SERVER_SETTINGS;
        unset($settings['streaming']);
        $this->serverSettings($settings);

        self::assertTrue(self::j($this->card(), 'capabilities.streaming'));
    }

    /**
     * A2A-SEC2: a streamed message naming another client's task is the same
     * -32001 as an unknown task, before any stream of that task is counted.
     */
    #[Test]
    public function aStreamedMessageToAnotherClientsTaskIsNotFound(): void
    {
        $this->streamSettings(['maxStreamsPerTask' => '1']);
        $theirs = $this->taskOf($this->call('SendMessage', ['message' => $this->message('hi', 'tck-input-required-o1')], 3));
        self::assertTrue($this->reply('SubscribeToTask', ['id' => $theirs['id']], 3)->isStream(), 'their task is at its stream limit');

        $foreign = $this->asArray($this->reply('SendStreamingMessage', ['message' => $this->message('x', '', ['taskId' => $theirs['id']])], 1));
        $unknown = $this->asArray($this->reply('SendStreamingMessage', ['message' => $this->message('x', '', ['taskId' => '00000000-0000-4000-8000-000000000000'])], 1));

        self::assertSame(-32001, $this->errorOf($foreign)['code']);
        self::assertSame(json_encode($foreign), json_encode($unknown));
    }

    /**
     * Through the middleware, a refusal of a streaming method is an ordinary
     * application/json response.
     */
    #[Test]
    public function aRefusedStreamIsAnOrdinaryJsonResponse(): void
    {
        $response = $this->post(json_encode(['jsonrpc' => '2.0', 'id' => 'r-1', 'method' => 'SubscribeToTask', 'params' => ['id' => 'nope']], JSON_THROW_ON_ERROR));

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(-32001, self::j(json_decode((string) $response->getBody(), true), 'error.code'));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function streamSettings(array $overrides): void
    {
        $this->serverSettings($overrides + self::STREAM_SETTINGS + self::SERVER_SETTINGS);
    }

    /**
     * Run one streaming call to its end.
     *
     * @param array<string, mixed>              $params
     * @param (Closure(int, string): void)|null $tick   called on every poll with its number and the task id
     *
     * @return array{frames: list<array<string, mixed>>, raw: string, ticks: int, times: list<float>}
     */
    private function stream(string $method, array $params, int $client, ?Closure $tick = null): array
    {
        $raw    = '';
        $times  = [$this->clock];
        $ticks  = 0;
        $taskId = '';
        $reply  = $this->reply($method, $params, $client, function () use (&$ticks, &$taskId, &$raw, $tick): void {
            ++$ticks;
            $this->clock += 0.25;
            if ($taskId === '' && preg_match('/"task":\{"id":"([^"]+)"/', $raw, $match) === 1) {
                $taskId = $match[1];
            }

            if ($tick instanceof Closure) {
                $tick($ticks, $taskId);
            }
        });
        self::assertTrue($reply->isStream(), 'expected a stream: ' . json_encode($reply->body));

        $writer = new A2aSseWriter($this->getService(A2aServerSettings::class)->maxFrameBytes(), function (string $bytes) use (&$raw, &$times): void {
            $raw .= $bytes;
            $times[] = $this->clock;
        }, fn (): float => $this->clock);
        $reply->streamTo($writer);

        $frames = [];
        foreach (explode("\n\n", $raw) as $block) {
            if (str_starts_with($block, 'data: ')) {
                $frames[] = self::objectOf(json_decode(substr($block, 6), true, 512, JSON_THROW_ON_ERROR));
            } else {
                self::assertStringStartsWith(':', $block === '' ? ':' : $block, 'anything else is a comment');
            }
        }

        $times[] = $this->clock;

        return ['frames' => $frames, 'raw' => $raw, 'ticks' => $ticks, 'times' => $times];
    }

    /**
     * @param array<string, mixed>      $params
     * @param (Closure(int): void)|null $sleep
     */
    private function reply(string $method, array $params, int $client, ?Closure $sleep = null): A2aRpcReply
    {
        $stream = new A2aTaskStream(
            $this->getService(A2aTaskView::class),
            $this->getService(A2aTaskProjector::class),
            $this->getService(A2aStreamSlots::class),
            $this->getService(A2aAuthenticator::class),
            $this->getService(A2aServerSettings::class),
            null,
            $sleep ?? function (): void {
                $this->clock += 0.25;
            },
            fn (): float => $this->clock,
        );
        $server = new A2aRpcServer(
            $this->getService(A2aServerSettings::class),
            $this->getService(A2aAuthenticator::class),
            $this->getService(A2aSendMessageHandler::class),
            $this->getService(A2aTaskView::class),
            $this->getService(A2aListCursor::class),
            $this->getService(AgentRuntimeInterface::class),
            $this->getService(ConnectionPool::class),
            $this->getService(A2aStreamSlots::class),
            $stream,
            $this->getService(A2aPushConfigStore::class),
        );

        $body = json_encode(['jsonrpc' => '2.0', 'id' => 's-1', 'method' => $method, 'params' => $params === [] ? new stdClass() : $params], JSON_THROW_ON_ERROR);

        return $server->handle($this->request('POST', self::BASE, ['Content-Type' => 'application/json', 'A2A-Version' => '1.0', 'Authorization' => 'Bearer ' . $this->tokens[$client]], $body), time());
    }

    /**
     * @param list<float> $times
     */
    private function longestGap(array $times): float
    {
        $gap = 0.0;
        for ($i = 1, $n = count($times); $i < $n; ++$i) {
            $gap = max($gap, $times[$i] - $times[$i - 1]);
        }

        return $gap;
    }

    /**
     * @return array<string, mixed>
     */
    private function asArray(A2aRpcReply $reply): array
    {
        return $reply->body + ['_status' => $reply->status];
    }

    /**
     * @param list<array<string, mixed>> $frames
     *
     * @return list<string>
     */
    private function kinds(array $frames): array
    {
        return array_map(static fn (array $frame): string => (string) array_key_first(self::objectOf($frame['result'] ?? [])), $frames);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(): array
    {
        $site = new Site('agent', 1, ['base' => 'https://agent.example.org/', 'websiteTitle' => 'Agent', 'settings' => ['nrllm' => ['a2a' => ['baseUrl' => self::BASE]]]]);
        $base = (new A2aServerSettings($this->getService(ExtensionConfiguration::class), new A2aOneSiteFinder($site)))->baseUrlOf($site);
        self::assertNotNull($base);

        return $this->getService(A2aAgentCardBuilder::class)->build($site, $base);
    }

    /**
     * A state nr-llm never sets itself (AUTH_REQUIRED), committed and
     * journalled as the store would.
     */
    private function forceState(string $taskId, string $state): void
    {
        $uid = $this->taskUid($taskId);
        $this->db(A2aTaskStore::TABLE)->executeStatement('UPDATE ' . A2aTaskStore::TABLE . ' SET state = ?, revision = revision + 1, status_timestamp = ? WHERE uid = ?', [$state, (int) (microtime(true) * 1000), $uid]);
        $this->getService(A2aTaskProjector::class)->project($uid);
    }

    private function taskUid(string $taskId): int
    {
        $uid = $this->db(A2aTaskStore::TABLE)->select(['uid'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchOne();
        self::assertIsNumeric($uid);

        return (int) $uid;
    }

    private function runOf(string $taskId): AgentRun
    {
        $runUid = $this->db(A2aTaskStore::TABLE)->select(['run_uid'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchOne();
        $uuid   = $this->db('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $runUid])->fetchOne();
        self::assertIsString($uuid);
        $run = $this->getService(AgentRunPersister::class)->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    private function suspended(): SuspendedRunState
    {
        return new SuspendedRunState([['role' => 'user', 'content' => 'x']], [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'w', 'arguments' => '{}']]], 1, 0, 0);
    }

    private function heldStreamSlots(): int
    {
        return (int) $this->db(A2aStreamSlots::TABLE)->executeQuery('SELECT COUNT(*) FROM ' . A2aStreamSlots::TABLE . " WHERE holder <> ''")->fetchOne();
    }

    private function heldWaitSlots(): int
    {
        return (int) $this->db('tx_nrllm_a2a_wait_slot')->executeQuery("SELECT COUNT(*) FROM tx_nrllm_a2a_wait_slot WHERE holder <> ''")->fetchOne();
    }
}
