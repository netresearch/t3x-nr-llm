<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Closure;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aStreamOutcome;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aCallFailedException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aStreamEvent;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The streaming client (spec 051 A2A-SSE6): SendStreamingMessage and
 * SubscribeToTask over a scripted transport, with the journal of A2A-C4 to
 * A2A-C6.
 */
#[CoversClass(A2aClient::class)]
#[CoversClass(A2aStreamOutcome::class)]
final class A2aClientStreamTest extends AbstractFunctionalTestCase
{
    private const OWNER = 11;

    private ScriptedA2aTransport $transport;

    private A2aClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new ScriptedA2aTransport();
        $this->client    = new A2aClient($this->journal(), $this->transport, new A2aResponseParser());
    }

    /**
     * The remote task is persisted the moment the first task event is read,
     * before the stream goes on; the events reach the caller in order and the
     * last state is recorded on the handle.
     */
    #[Test]
    public function theTaskIsPersistedWithTheFirstEventAndTheStreamIsReadToItsEnd(): void
    {
        $seenAtSecond = null;
        $this->transport->thenStream(function (A2aHttpRequest $request, Closure $onBytes) use (&$seenAtSecond): A2aTransportResult {
            self::assertSame('text/event-stream, application/json', $request->headers['Accept'] ?? null);
            self::assertSame('SendStreamingMessage', ScriptedA2aTransport::envelopeOf($request)['method'] ?? null);
            $onBytes((ScriptedA2aTransport::event(['task' => ['id' => 'task-1', 'contextId' => 'ctx-1', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]))($request));
            $seenAtSecond = $this->journal()->findByMessageId(self::OWNER, 'm-1');
            $onBytes((ScriptedA2aTransport::event(['statusUpdate' => ['taskId' => 'task-1', 'contextId' => 'ctx-1', 'status' => ['state' => 'TASK_STATE_WORKING']]]))($request));
            $onBytes((ScriptedA2aTransport::event(['artifactUpdate' => ['taskId' => 'task-1', 'contextId' => 'ctx-1', 'artifact' => ['artifactId' => 'a-1', 'parts' => [['text' => 'done']]], 'lastChunk' => true]]))($request));
            $onBytes((ScriptedA2aTransport::event(['statusUpdate' => ['taskId' => 'task-1', 'contextId' => 'ctx-1', 'status' => ['state' => 'TASK_STATE_COMPLETED']]]))($request));

            return A2aTransportResult::response(200, 'text/event-stream', '', '', false);
        });

        $kinds   = [];
        $outcome = $this->client->sendStreamingMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'), static function (A2aStreamEvent $event) use (&$kinds): void {
            $kinds[] = $event->kind;
        });

        self::assertSame([A2aSendState::ACCEPTED, 'task-1'], [$seenAtSecond?->state, $seenAtSecond?->remoteTaskId], 'persisted before the second event');
        self::assertSame(['task', 'statusUpdate', 'artifactUpdate', 'statusUpdate'], $kinds);
        self::assertSame(4, $outcome->events);
        self::assertSame('', $outcome->endReason);
        self::assertSame('TASK_STATE_COMPLETED', $outcome->lastState);
        self::assertFalse($outcome->fallsBackToPolling());
        self::assertSame('TASK_STATE_COMPLETED', $this->journal()->findByMessageId(self::OWNER, 'm-1')?->lastRemoteState);
    }

    /**
     * A2A-C6: a stream that breaks before it names a task is an unknown
     * outcome; nothing is sent again.
     */
    #[Test]
    public function aStreamThatBreaksBeforeTheTaskIsAnUnknownOutcome(): void
    {
        $this->transport->thenEvents([': keep-alive' . "\n\n", 'data: {"jsonrpc":"2.0"'], A2aTransportResult::maybeSent('CONNECTION_LOST', 'reset'));

        $outcome = $this->client->sendStreamingMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'), static fn (): null => null);

        self::assertSame(A2aSendState::OUTCOME_UNKNOWN, $outcome->intent->state);
        self::assertSame('CONNECTION_LOST', $outcome->intent->errorReason);
        self::assertCount(1, $this->transport->requests, 'sent once');
    }

    /**
     * A2A-SSE6: a disconnect after the task is known falls back to polling.
     */
    #[Test]
    public function aDisconnectAfterTheTaskIsKnownFallsBackToPolling(): void
    {
        $this->transport->thenEvents([ScriptedA2aTransport::event(['task' => ['id' => 'task-1', 'status' => ['state' => 'TASK_STATE_WORKING']]])], A2aTransportResult::maybeSent('CONNECTION_LOST', 'reset'));

        $outcome = $this->client->sendStreamingMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'), static fn (): null => null);

        self::assertSame(A2aSendState::ACCEPTED, $outcome->intent->state);
        self::assertSame('CONNECTION_LOST', $outcome->endReason);
        self::assertTrue($outcome->fallsBackToPolling());
    }

    /**
     * A2A-SSE6: every event must answer the request's JSON-RPC id; one that
     * does not ends the stream unread, and before the task an unknown
     * outcome follows (the remote took the request).
     */
    #[Test]
    public function anEventWithAnotherIdEndsTheStream(): void
    {
        $this->transport->thenEvents(['data: ' . json_encode(['jsonrpc' => '2.0', 'id' => 'someone-else', 'result' => ['task' => ['id' => 'task-1', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]]) . "\n\n"]);

        $events  = 0;
        $outcome = $this->client->sendStreamingMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'), static function () use (&$events): void {
            ++$events;
        });

        self::assertSame(0, $events);
        self::assertSame(A2aSendState::OUTCOME_UNKNOWN, $outcome->intent->state);
        self::assertSame('INVALID_AGENT_RESPONSE', $outcome->intent->errorReason);
    }

    /**
     * A2A-SSE6: frames split across reads, inside a UTF-8 sequence and inside
     * a CRLF, are reassembled.
     */
    #[Test]
    public function framesSplitAcrossReadsAreReassembled(): void
    {
        $text  = 'Grüße ✓';
        $frame = static fn (A2aHttpRequest $request): string => 'data: ' . json_encode(['jsonrpc' => '2.0', 'id' => ScriptedA2aTransport::idOf($request), 'result' => ['message' => ['messageId' => 'r-1', 'role' => 'ROLE_AGENT', 'parts' => [['text' => $text]]]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\r\n\r\n";
        $this->transport->thenStream(static function (A2aHttpRequest $request, Closure $onBytes) use ($frame): A2aTransportResult {
            $bytes = $frame($request);
            $cut   = (int) strpos($bytes, 'ü') + 1;
            $onBytes(substr($bytes, 0, $cut));
            $onBytes(substr($bytes, $cut, -3));
            $onBytes(substr($bytes, -3, 1));
            $onBytes(substr($bytes, -2));

            return A2aTransportResult::response(200, 'text/event-stream', '', '', false);
        });

        $texts   = [];
        $outcome = $this->client->sendStreamingMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'), static function (A2aStreamEvent $event) use (&$texts): void {
            $texts[] = $event->message?->text();
        });

        self::assertSame([$text], $texts);
        self::assertSame(A2aSendState::ANSWERED, $outcome->intent->state);
    }

    /**
     * A task event and an invalid line in the same piece: the task is stored
     * before the stream ends as invalid.
     */
    #[Test]
    public function aTaskBeforeAnInvalidLineInTheSamePieceIsStored(): void
    {
        $this->transport->thenStream(static function (A2aHttpRequest $request, Closure $onBytes): A2aTransportResult {
            $onBytes((ScriptedA2aTransport::event(['task' => ['id' => 'task-9', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]))($request) . str_repeat('x', 4096));

            return A2aTransportResult::response(200, 'text/event-stream', '', '', false);
        });

        $outcome = $this->client->sendStreamingMessage($this->agent(maxResponseBytes: 1024), self::OWNER, A2aMessage::userText('m-1', 'hello'), static fn (): null => null);

        self::assertSame([A2aSendState::ACCEPTED, 'task-9'], [$outcome->intent->state, $outcome->intent->remoteTaskId]);
        self::assertSame('INVALID_AGENT_RESPONSE', $outcome->endReason);
    }

    /**
     * A JSON-RPC error answered as application/json before any stream is a
     * refusal with its code, as for SendMessage.
     */
    #[Test]
    public function aJsonErrorBeforeTheStreamIsARefusal(): void
    {
        $this->transport->thenStream(static fn (A2aHttpRequest $request): A2aTransportResult => A2aTransportResult::response(
            200,
            'application/json',
            '',
            json_encode(['jsonrpc' => '2.0', 'id' => ScriptedA2aTransport::idOf($request), 'error' => ['code' => -32004, 'message' => 'no']], JSON_THROW_ON_ERROR),
            false,
        ));

        $outcome = $this->client->sendStreamingMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'), static fn (): null => null);

        self::assertSame(A2aSendState::REFUSED, $outcome->intent->state);
        self::assertSame(-32004, $outcome->intent->errorCode);
    }

    /**
     * A record whose card declares no streaming sends nothing.
     */
    #[Test]
    public function aCardWithoutStreamingSendsNothing(): void
    {
        try {
            $this->client->sendStreamingMessage($this->agent('streaming=0;pushNotifications=-;extendedAgentCard=-'), self::OWNER, A2aMessage::userText('m-1', 'hello'), static fn (): null => null);
            self::fail('expected a refusal');
        } catch (A2aCallFailedException $e) {
            self::assertSame('STREAMING_NOT_DECLARED', $e->reason);
        }

        self::assertSame([], $this->transport->requests);
        self::assertNull($this->journal()->findByMessageId(self::OWNER, 'm-1'), 'no intent');
    }

    /**
     * SubscribeToTask reads only the owner's handle and only its task's
     * events; an event of another task ends the stream.
     */
    #[Test]
    public function subscribeAcceptsOnlyTheHandlesTask(): void
    {
        $agent = $this->agent();
        $this->transport->thenEvents([ScriptedA2aTransport::event(['task' => ['id' => 'task-1', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]])], A2aTransportResult::maybeSent('CONNECTION_LOST', 'x'));
        $this->client->sendStreamingMessage($agent, self::OWNER, A2aMessage::userText('m-1', 'hello'), static fn (): null => null);

        $this->transport->thenEvents([
            ScriptedA2aTransport::event(['task' => ['id' => 'task-1', 'status' => ['state' => 'TASK_STATE_WORKING']]]),
            ScriptedA2aTransport::event(['statusUpdate' => ['taskId' => 'task-2', 'contextId' => 'c', 'status' => ['state' => 'TASK_STATE_COMPLETED']]]),
        ]);

        $outcome = $this->client->subscribeToTask($agent, self::OWNER, 'task-1', static fn (): null => null);

        self::assertSame(1, $outcome->events);
        self::assertSame('INVALID_AGENT_RESPONSE', $outcome->endReason);
        self::assertSame('TASK_STATE_WORKING', $this->journal()->findByMessageId(self::OWNER, 'm-1')?->lastRemoteState, 'the other task was recorded nowhere');
        self::assertSame('SubscribeToTask', ScriptedA2aTransport::envelopeOf($this->transport->requests[1])['method'] ?? null);
        self::assertSame(['id' => 'task-1'], ScriptedA2aTransport::envelopeOf($this->transport->requests[1])['params'] ?? null);
    }

    private function agent(string $capabilities = 'streaming=1;pushNotifications=-;extendedAgentCard=-', int $maxResponseBytes = 0): A2aRemoteAgent
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent');
        $connection->insert('tx_nrllm_a2a_agent', [
            'identifier'        => 'agent' . random_int(1000, 9999),
            'title'             => 'Agent',
            'card_url'          => 'https://agent.example/.well-known/agent-card.json',
            'interface_url'     => 'https://agent.example/a2a/jsonrpc',
            'card_capabilities' => $capabilities,
            'credential'        => 'vault-credential-id',
            'data_class'        => ToolDataClass::PUBLIC_CONTENT->value,
            'enabled'           => 1,
        ] + ($maxResponseBytes > 0 ? ['max_response_bytes' => $maxResponseBytes] : []));

        $agent = $this->getService(A2aRemoteAgentRepository::class)->findByUid((int) $connection->lastInsertId());
        self::assertInstanceOf(A2aRemoteAgent::class, $agent);

        return $agent;
    }

    private function journal(): A2aSendJournal
    {
        return new A2aSendJournal($this->getService(ConnectionPool::class));
    }
}
