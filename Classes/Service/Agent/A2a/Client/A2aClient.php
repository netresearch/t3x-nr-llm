<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Client;

use Closure;
use JsonException;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aAgentUnavailableException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aCallFailedException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aIntentConflictException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aJournalException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aTaskHandleNotFoundException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aUnknownPayloadException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aJsonRpcCodec;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aJsonRpcResponse;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aListTasksQuery;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRemoteError;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageConfiguration;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aStreamEvent;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskList;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskStatus;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportOutcome;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Symfony\Component\Uid\Uuid;

/**
 * The A2A 1.0 client of nr-llm (spec 051 stage 1, A2A-C3 to A2A-C8).
 *
 * `SendMessage`:
 *
 * 1. The record must be usable ({@see A2aRemoteAgent::useRefusal()}).
 * 2. The request params are serialised once; their sha256 and the message id
 *    go into the send-intent journal, committed before the network call
 *    (A2A-C4). A message id the same owner used for the same request names
 *    the same operation and sends nothing; for another request it is refused
 *    (A2A-C5).
 * 3. The transport sends to the guard-checked addresses (ADR-279), and the
 *    intent settles exactly once (A2A-C6):
 *
 *    | What happened | Journal state, reason |
 *    | --- | --- |
 *    | guard, vault or connect refused it; nothing left | `refused`, the transport's reason |
 *    | timeout after send, connection lost | `outcome_unknown` |
 *    | response above the record's size limit | `outcome_unknown`, `RESPONSE_TOO_LARGE` (A2A-C8) |
 *    | 3xx | `refused`, `REDIRECT_NOT_FOLLOWED` |
 *    | JSON-RPC error | `refused`, code and reason |
 *    | 5xx without a JSON-RPC body | `outcome_unknown`, `SERVER_ERROR_WITHOUT_RESPONSE` |
 *    | 2xx that is not a valid response, id mismatch included | `outcome_unknown`, `INVALID_AGENT_RESPONSE` (A2A-P5): the remote took the request |
 *    | any other invalid response | `refused`, `INVALID_AGENT_RESPONSE` (A2A-P5) |
 *    | a response member of an unknown kind only | `outcome_unknown`, `UNKNOWN_RESPONSE_KIND` |
 *    | task | `accepted`, remote task and context id |
 *    | message | `answered` |
 *
 *    Nothing is ever resent: an unknown outcome stays unknown, and resending
 *    is a new intent under a new message id.
 *
 * `GetTask` and `CancelTask` work only on a handle the calling owner holds
 * (A2A-C7) and record the remote state on it; a response about another task
 * than the one asked for is refused (`INVALID_AGENT_RESPONSE`) and recorded
 * nowhere. `ListTasks` returns only tasks the owner holds a handle for and
 * creates none.
 *
 * Every request carries `A2A-Version: 1.0` (A2A-P2) and, when the selected
 * interface names one, the `tenant` (A2A-C2).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aClient
{
    public function __construct(
        private A2aSendJournal $journal,
        private A2aTransportInterface $transport,
        private A2aResponseParser $parser,
    ) {}

    /**
     * A new message id (UUIDv7, A2A-C4).
     */
    public static function newMessageId(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    /**
     * @throws A2aAgentUnavailableException when the record may not be used
     * @throws A2aIntentConflictException   when the message id was used for another request
     * @throws A2aJournalException          when the intent cannot be committed
     */
    public function sendMessage(
        A2aRemoteAgent $agent,
        int $ownerUid,
        A2aMessage $message,
        A2aSendMessageConfiguration $configuration = new A2aSendMessageConfiguration(),
        int $runUid = 0,
    ): A2aSendOutcome {
        $this->assertUsable($agent);
        $message->assertSendable();

        $params = $this->withTenant($agent, ['message' => $message->toArray()]);
        if ($configuration->toArray() !== []) {
            $params['configuration'] = $configuration->toArray();
        }

        $encoded = $this->encode($params);
        $begin   = $this->journal->begin($ownerUid, (int) $agent->getUid(), $runUid, $message->messageId, A2aJsonRpcCodec::digest($encoded));
        if (!$begin->created) {
            return new A2aSendOutcome($begin->intent, false);
        }

        $intent  = $begin->intent;
        $id      = Uuid::v7()->toRfc4122();
        $request = $this->request($agent, A2aProtocol::METHOD_SEND_MESSAGE, $encoded, $id);
        $result  = $this->transport->send($request);
        // The outcome settles first, so a failed accounting write cannot cost
        // the handle of a task the remote accepted; what left and came back
        // is counted after it either way (spec 051 A2A-R7).
        $outcome = $this->settle($intent, $result, $id);
        $this->journal->addTransferredBytes($intent, strlen($request->body) + strlen($result->body));

        return $outcome;
    }

    /**
     * `SendStreamingMessage` (spec 051 A2A-SSE6): the send of
     * {@see self::sendMessage()}, with the same journal and the same rule of
     * no automatic resend, answered as an event stream.
     *
     * - The record's card must declare `streaming`; otherwise nothing is sent
     *   (`STREAMING_NOT_DECLARED`) and the caller uses `SendMessage`.
     * - The stream is read as it arrives and parsed by the WHATWG rules; every
     *   event must answer this request's JSON-RPC id.
     * - The first event that names a task settles the intent `accepted` with
     *   the remote task and context id at once; a message settles it
     *   `answered`. A stream that ends or breaks before either is an unknown
     *   outcome (A2A-C6), never refused and never resent. An `application/json`
     *   answer, an error before the stream, settles as for `SendMessage`.
     * - After the acknowledgement an event about another task ends the stream
     *   (`INVALID_AGENT_RESPONSE`); the intent keeps the task it named first.
     * - A disconnect after the acknowledgement leaves a task to poll:
     *   {@see A2aStreamOutcome::fallsBackToPolling()}.
     *
     * The stream lasts at most the record's maximum wait (at least its read
     * timeout); an event may be at most the record's maximum response bytes.
     *
     * @param Closure(A2aStreamEvent): void $onEvent
     *
     * @throws A2aAgentUnavailableException when the record may not be used
     * @throws A2aIntentConflictException   when the message id was used for another request
     * @throws A2aJournalException          when the intent cannot be committed
     * @throws A2aCallFailedException       when the card declares no streaming
     */
    public function sendStreamingMessage(
        A2aRemoteAgent $agent,
        int $ownerUid,
        A2aMessage $message,
        Closure $onEvent,
        A2aSendMessageConfiguration $configuration = new A2aSendMessageConfiguration(),
        int $runUid = 0,
    ): A2aStreamOutcome {
        $this->assertUsable($agent);
        $this->assertStreaming($agent, A2aProtocol::METHOD_SEND_STREAMING_MESSAGE);
        $message->assertSendable();

        $params = $this->withTenant($agent, ['message' => $message->toArray()]);
        if ($configuration->toArray() !== []) {
            $params['configuration'] = $configuration->toArray();
        }

        $encoded = $this->encode($params);
        $begin   = $this->journal->begin($ownerUid, (int) $agent->getUid(), $runUid, $message->messageId, A2aJsonRpcCodec::digest($encoded));
        if (!$begin->created) {
            return new A2aStreamOutcome($begin->intent, false);
        }

        $id      = Uuid::v7()->toRfc4122();
        $request = $this->request($agent, A2aProtocol::METHOD_SEND_STREAMING_MESSAGE, $encoded, $id, true);
        $read    = $this->readStream($agent, $request, $id, $begin->intent, $onEvent, true);
        $intent  = $read['intent'];
        $result  = $read['result'];

        if ($result->outcome === A2aTransportOutcome::RESPONSE && $result->mediaType() !== A2aProtocol::EVENT_STREAM_CONTENT_TYPE) {
            // Not a stream: an error (or an answer) as SendMessage gets it.
            $settled = $this->settle($intent, $result, $id);
            $this->journal->addTransferredBytes($intent, strlen($request->body) + strlen($result->body));

            return new A2aStreamOutcome($settled->intent, true, 0, '', $settled->intent->lastRemoteState, $settled->remoteError);
        }

        if (!$read['acknowledged']) {
            $intent = match (true) {
                $result->outcome === A2aTransportOutcome::NOT_SENT => $this->journal->refuse($intent, null, $result->reason, $result->detail),
                $read['error'] instanceof A2aRemoteError           => $this->journal->refuse($intent, $read['error']->code, $read['error']->effectiveReason(), $read['error']->message),
                default                                            => $this->journal->markOutcomeUnknown(
                    $intent,
                    $read['failure'][0] ?? ($result->reason !== '' ? $result->reason : 'STREAM_ENDED_UNACKNOWLEDGED'),
                    $read['failure'][1] ?? ($result->detail !== '' ? $result->detail : 'the stream ended before it named a task or a message'),
                ),
            };
        }

        $this->journal->addTransferredBytes($intent, strlen($request->body) + $read['bytes']);

        return new A2aStreamOutcome($intent, true, $read['events'], $read['end'], $read['state'], $read['error']);
    }

    /**
     * `SubscribeToTask` (A2A-SSE6) for a handle the owner holds: the remote's
     * snapshot of the task, then its updates, until the remote closes the
     * stream or it breaks. Only events about that task are accepted; the last
     * state is recorded on the handle.
     *
     * @param Closure(A2aStreamEvent): void $onEvent
     *
     * @throws A2aAgentUnavailableException
     * @throws A2aTaskHandleNotFoundException
     * @throws A2aCallFailedException         when the card declares no streaming, or the remote refused
     */
    public function subscribeToTask(A2aRemoteAgent $agent, int $ownerUid, string $remoteTaskId, Closure $onEvent): A2aStreamOutcome
    {
        $handle = $this->handle($agent, $ownerUid, $remoteTaskId);
        $this->assertStreaming($agent, A2aProtocol::METHOD_SUBSCRIBE_TO_TASK);

        $id      = Uuid::v7()->toRfc4122();
        $request = $this->request($agent, A2aProtocol::METHOD_SUBSCRIBE_TO_TASK, $this->encode($this->withTenant($agent, ['id' => $remoteTaskId])), $id, true);
        $read    = $this->readStream($agent, $request, $id, $handle, $onEvent, false);
        $result  = $read['result'];
        $this->journal->addTransferredBytes($handle, strlen($request->body) + $read['bytes'] + strlen($result->body));

        if ($result->outcome === A2aTransportOutcome::RESPONSE && $result->mediaType() !== A2aProtocol::EVENT_STREAM_CONTENT_TYPE) {
            $refusal = $this->responseRefusal($result);
            if ($refusal !== null) {
                throw A2aCallFailedException::local(A2aProtocol::METHOD_SUBSCRIBE_TO_TASK, $refusal[0], $refusal[1]);
            }

            try {
                $response = A2aJsonRpcCodec::decodeResponse($result->body, $id);
            } catch (A2aInvalidAgentResponseException $e) {
                throw A2aCallFailedException::local(A2aProtocol::METHOD_SUBSCRIBE_TO_TASK, 'INVALID_AGENT_RESPONSE', $e->getMessage());
            }

            $this->resultOf(A2aProtocol::METHOD_SUBSCRIBE_TO_TASK, $response);

            throw A2aCallFailedException::local(A2aProtocol::METHOD_SUBSCRIBE_TO_TASK, 'INVALID_AGENT_RESPONSE', 'the agent answered SubscribeToTask without a stream');
        }

        if ($result->outcome === A2aTransportOutcome::NOT_SENT) {
            throw A2aCallFailedException::local(A2aProtocol::METHOD_SUBSCRIBE_TO_TASK, $result->reason, $result->detail);
        }

        if ($read['error'] instanceof A2aRemoteError && $read['events'] === 0) {
            throw A2aCallFailedException::remote(A2aProtocol::METHOD_SUBSCRIBE_TO_TASK, $read['error']);
        }

        return new A2aStreamOutcome($read['intent'], true, $read['events'], $read['end'], $read['state'], $read['error']);
    }

    /**
     * Reads one stream through the transport and hands each event to the
     * caller. For a send, the first event naming a task or a message settles
     * the intent at once; for a subscription every event must name the
     * handle's task.
     *
     * @param Closure(A2aStreamEvent): void $onEvent
     *
     * @return array{intent: A2aSendIntent, result: A2aTransportResult, acknowledged: bool, events: int, bytes: int, state: string, end: string, error: ?A2aRemoteError, failure: array{0: string, 1: string}|null}
     */
    private function readStream(A2aRemoteAgent $agent, A2aHttpRequest $request, string $id, A2aSendIntent $intent, Closure $onEvent, bool $isSend): array
    {
        $sse   = new A2aSseParser(max(1024, $agent->getMaxResponseBytes()));
        $state = [
            'intent'       => $intent,
            'acknowledged' => !$isSend,
            'events'       => 0,
            'bytes'        => 0,
            'state'        => '',
            'error'        => null,
            'failure'      => null,
        ];

        $result = $this->transport->stream($request, function (string $bytes) use ($sse, $id, $onEvent, $isSend, &$state): bool {
            $state['bytes'] += strlen($bytes);
            try {
                $frames = $sse->feed($bytes);
            } catch (A2aInvalidAgentResponseException $e) {
                $state['failure'] = ['INVALID_AGENT_RESPONSE', $e->getMessage()];

                return false;
            }

            foreach ($frames as $frame) {
                try {
                    $response = A2aJsonRpcCodec::decodeResponse($frame['data'], $id);
                    if ($response->isError()) {
                        $state['error'] = $response->error;

                        return false;
                    }

                    $event = $this->parser->parseStreamResponse($response->result);
                } catch (A2aUnknownPayloadException) {
                    // A2A-P4: a later protocol's event kind is skipped.
                    continue;
                } catch (A2aInvalidAgentResponseException $e) {
                    $state['failure'] = ['INVALID_AGENT_RESPONSE', $e->getMessage()];

                    return false;
                }

                if (!$state['acknowledged']) {
                    if ($event->kind === A2aStreamEvent::KIND_MESSAGE && !$event->namesTask()) {
                        $state['intent'] = $this->journal->answer($state['intent'], $event->contextId);
                    } elseif ($event->namesTask()) {
                        // Persisted the moment it is read (A2A-SSE6).
                        $state['intent'] = $this->journal->accept($state['intent'], $event->taskId, $event->contextId, $event->status instanceof A2aTaskStatus ? $event->status->rawState : '');
                    }

                    $state['acknowledged'] = true;
                } elseif ($event->namesTask() && $event->taskId !== $state['intent']->remoteTaskId && ($isSend || $state['intent']->remoteTaskId !== '')) {
                    $state['failure'] = ['INVALID_AGENT_RESPONSE', 'the stream carries an event of another task'];

                    return false;
                }

                if ($event->status instanceof A2aTaskStatus) {
                    $state['state'] = $event->status->rawState;
                    if ($state['intent']->remoteTaskId !== '') {
                        $this->journal->recordRemoteState($state['intent'], $event->status->rawState);
                    }
                }

                ++$state['events'];
                $onEvent($event);
            }

            return true;
        });

        $end = match (true) {
            $state['failure'] !== null                         => $state['failure'][0],
            $state['error'] instanceof A2aRemoteError          => 'REMOTE_ERROR',
            $result->outcome !== A2aTransportOutcome::RESPONSE => $result->reason,
            $sse->hasPartialEvent()                            => 'STREAM_ENDED_MID_EVENT',
            default                                            => '',
        };

        return ['result' => $result, 'end' => $end] + $state;
    }

    /**
     * @throws A2aCallFailedException
     */
    private function assertStreaming(A2aRemoteAgent $agent, string $method): void
    {
        if (!$agent->declaresStreaming()) {
            throw A2aCallFailedException::local($method, 'STREAMING_NOT_DECLARED', "the agent's card does not declare streaming");
        }
    }

    /**
     * The remote task of a handle the owner holds.
     *
     * @throws A2aAgentUnavailableException
     * @throws A2aTaskHandleNotFoundException
     * @throws A2aCallFailedException
     */
    public function getTask(A2aRemoteAgent $agent, int $ownerUid, string $remoteTaskId, ?int $historyLength = null): A2aTask
    {
        $handle = $this->handle($agent, $ownerUid, $remoteTaskId);
        $params = ['id' => $remoteTaskId];
        if ($historyLength !== null) {
            $params['historyLength'] = $historyLength;
        }

        $moved = 0;
        try {
            $task = $this->parsed(A2aProtocol::METHOD_GET_TASK, function () use ($agent, $params, &$moved): A2aTask {
                return $this->parser->parseTask($this->call($agent, A2aProtocol::METHOD_GET_TASK, $params, $moved), 'result');
            });
            $this->assertSameTask(A2aProtocol::METHOD_GET_TASK, $remoteTaskId, $task);
            $this->journal->recordRemoteState($handle, $task->status->rawState);
        } finally {
            // After the answer is handled, and for a failed call too (A2A-R7).
            $this->journal->addTransferredBytes($handle, $moved);
        }

        return $task;
    }

    /**
     * Cancel the remote task of a handle the owner holds. The remote's answer,
     * a task or an error, is what comes back; nothing is retried.
     *
     * @throws A2aAgentUnavailableException
     * @throws A2aTaskHandleNotFoundException
     * @throws A2aCallFailedException
     */
    public function cancelTask(A2aRemoteAgent $agent, int $ownerUid, string $remoteTaskId): A2aTask
    {
        $handle = $this->handle($agent, $ownerUid, $remoteTaskId);
        $moved  = 0;
        try {
            $task = $this->parsed(A2aProtocol::METHOD_CANCEL_TASK, function () use ($agent, $remoteTaskId, &$moved): A2aTask {
                return $this->parser->parseTask($this->call($agent, A2aProtocol::METHOD_CANCEL_TASK, ['id' => $remoteTaskId], $moved), 'result');
            });
            $this->assertSameTask(A2aProtocol::METHOD_CANCEL_TASK, $remoteTaskId, $task);
            $this->journal->recordRemoteState($handle, $task->status->rawState);
        } finally {
            $this->journal->addTransferredBytes($handle, $moved);
        }

        return $task;
    }

    /**
     * A remote that answers about another task than the one asked for would
     * hand this owner that task's content and file its state on this handle.
     * The ids are compared byte for byte.
     *
     * @throws A2aCallFailedException
     */
    private function assertSameTask(string $method, string $remoteTaskId, A2aTask $task): void
    {
        if ($task->id !== $remoteTaskId) {
            throw A2aCallFailedException::local($method, 'INVALID_AGENT_RESPONSE', 'the agent answered with another task than the one asked for');
        }
    }

    /**
     * The remote's task list, reduced to the tasks the owner holds a handle for
     * (A2A-C7). With a credential several local users share, the remote lists
     * everybody's tasks; only this owner's come back, `totalSize` counts only
     * those of this page, and no handle is created for any of them.
     *
     * @throws A2aAgentUnavailableException
     * @throws A2aCallFailedException
     */
    public function listTasks(A2aRemoteAgent $agent, int $ownerUid, A2aListTasksQuery $query = new A2aListTasksQuery()): A2aTaskList
    {
        $this->assertUsable($agent);
        $list = $this->parsed(A2aProtocol::METHOD_LIST_TASKS, fn (): A2aTaskList => $this->parser->parseTaskList($this->call($agent, A2aProtocol::METHOD_LIST_TASKS, $query->toArray())));

        $owned = $this->journal->ownedRemoteTaskIds(
            $ownerUid,
            (int) $agent->getUid(),
            array_map(static fn (A2aTask $task): string => $task->id, $list->tasks),
        );
        $tasks = array_values(array_filter($list->tasks, static fn (A2aTask $task): bool => in_array($task->id, $owned, true)));

        return new A2aTaskList($tasks, $list->nextPageToken, $list->pageSize, count($tasks));
    }

    /**
     * @template T
     *
     * @param Closure(): T $parse
     *
     * @return T
     *
     * @throws A2aCallFailedException
     */
    private function parsed(string $method, Closure $parse): mixed
    {
        try {
            return $parse();
        } catch (A2aInvalidAgentResponseException $e) {
            throw A2aCallFailedException::local($method, 'INVALID_AGENT_RESPONSE', $e->getMessage());
        }
    }

    /**
     * @throws A2aJournalException
     */
    private function settle(A2aSendIntent $intent, A2aTransportResult $result, string $id): A2aSendOutcome
    {
        if ($result->outcome === A2aTransportOutcome::NOT_SENT) {
            return new A2aSendOutcome($this->journal->refuse($intent, null, $result->reason, $result->detail), true);
        }

        if ($result->outcome === A2aTransportOutcome::MAYBE_SENT) {
            return new A2aSendOutcome($this->journal->markOutcomeUnknown($intent, $result->reason, $result->detail), true);
        }

        if ($result->oversized) {
            // A2A-C8: the remote may have created a task whose acknowledgement
            // is too large to read; never refused, never resent.
            return new A2aSendOutcome($this->journal->markOutcomeUnknown($intent, 'RESPONSE_TOO_LARGE', "the response is larger than the record's maximum response size and was not parsed"), true);
        }

        $refusal = $this->responseRefusal($result);
        if ($refusal !== null && $result->isSuccess()) {
            // A 2xx came from the remote that took the request; what it
            // created cannot be read, so the outcome is unknown, not refused.
            return new A2aSendOutcome($this->journal->markOutcomeUnknown($intent, $refusal[0], $refusal[1]), true);
        }

        if ($refusal !== null) {
            return new A2aSendOutcome($this->journal->refuse($intent, null, $refusal[0], $refusal[1]), true);
        }

        return $this->settleResponse($intent, $result, $id);
    }

    /**
     * Settles an intent on a response the client reads: an error, a task, a
     * message, or something it cannot use.
     *
     * The result goes back only when the intent settled as `accepted` or
     * `answered`: an id the journal cannot hold settles it as unknown, and
     * then there is no result to act on.
     *
     * @throws A2aJournalException
     */
    private function settleResponse(A2aSendIntent $intent, A2aTransportResult $result, string $id): A2aSendOutcome
    {
        try {
            $response = A2aJsonRpcCodec::decodeResponse($result->body, $id);
            if ($response->isError()) {
                $error = $response->error;

                return new A2aSendOutcome($this->journal->refuse($intent, $error->code, $error->effectiveReason(), $error->message), true, remoteError: $error);
            }

            $sent = $this->parser->parseSendMessageResponse($response->result);
        } catch (A2aUnknownPayloadException $e) {
            return new A2aSendOutcome($this->journal->markOutcomeUnknown($intent, 'UNKNOWN_RESPONSE_KIND', $e->getMessage()), true);
        } catch (A2aInvalidAgentResponseException $e) {
            if ($result->status >= 500) {
                return new A2aSendOutcome($this->journal->markOutcomeUnknown($intent, 'SERVER_ERROR_WITHOUT_RESPONSE', sprintf('HTTP %d: %s', $result->status, $e->getMessage())), true);
            }

            if ($result->isSuccess()) {
                return new A2aSendOutcome($this->journal->markOutcomeUnknown($intent, 'INVALID_AGENT_RESPONSE', $e->getMessage()), true);
            }

            return new A2aSendOutcome($this->journal->refuse($intent, null, 'INVALID_AGENT_RESPONSE', $e->getMessage()), true);
        }

        $settled = $sent->isTask()
            ? $this->journal->accept($intent, $sent->task->id, $sent->task->contextId, $sent->task->status->rawState)
            : $this->journal->answer($intent, $sent->message->contextId);

        return new A2aSendOutcome($settled, true, in_array($settled->state, [A2aSendState::ACCEPTED, A2aSendState::ANSWERED], true) ? $sent : null);
    }

    /**
     * A response this client does not parse: too large, or a redirect.
     *
     * @return array{string, string}|null reason and detail
     */
    private function responseRefusal(A2aTransportResult $result): ?array
    {
        if ($result->oversized) {
            return ['RESPONSE_TOO_LARGE', "the response is larger than the record's maximum response size and was not parsed"];
        }

        if ($result->status >= 300 && $result->status < 400) {
            return ['REDIRECT_NOT_FOLLOWED', sprintf('the agent answered HTTP %d; redirects are not followed', $result->status)];
        }

        if ($result->mediaType() !== '' && $result->mediaType() !== A2aProtocol::CONTENT_TYPE && $result->status < 500) {
            return ['INVALID_AGENT_RESPONSE', sprintf('the response has the content type "%s", not application/json', $result->mediaType())];
        }

        return null;
    }

    /**
     * One unary call that creates no remote state: its `result` or an exception.
     * $moved receives the bytes it sent and read, for the caller to count once
     * the answer is handled.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws A2aCallFailedException
     */
    private function call(A2aRemoteAgent $agent, string $method, array $params, int &$moved = 0): array
    {
        $id      = Uuid::v7()->toRfc4122();
        $request = $this->request($agent, $method, $this->encode($this->withTenant($agent, $params)), $id);
        $result  = $this->transport->send($request);
        $moved   = strlen($request->body) + strlen($result->body);

        if ($result->outcome !== A2aTransportOutcome::RESPONSE) {
            throw A2aCallFailedException::local($method, $result->reason, $result->detail);
        }

        $refusal = $this->responseRefusal($result);
        if ($refusal !== null) {
            throw A2aCallFailedException::local($method, $refusal[0], $refusal[1]);
        }

        try {
            $response = A2aJsonRpcCodec::decodeResponse($result->body, $id);
        } catch (A2aInvalidAgentResponseException $e) {
            throw A2aCallFailedException::local($method, 'INVALID_AGENT_RESPONSE', $e->getMessage());
        }

        return $this->resultOf($method, $response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws A2aCallFailedException
     */
    private function resultOf(string $method, A2aJsonRpcResponse $response): array
    {
        if ($response->isError()) {
            throw A2aCallFailedException::remote($method, $response->error);
        }

        return $response->result;
    }

    /**
     * @throws A2aAgentUnavailableException
     * @throws A2aTaskHandleNotFoundException
     */
    private function handle(A2aRemoteAgent $agent, int $ownerUid, string $remoteTaskId): A2aSendIntent
    {
        $this->assertUsable($agent);
        $handle = $this->journal->findByRemoteTask($ownerUid, (int) $agent->getUid(), $remoteTaskId);
        if (!$handle instanceof A2aSendIntent) {
            throw A2aTaskHandleNotFoundException::forTask($agent->getIdentifier(), $remoteTaskId);
        }

        return $handle;
    }

    /**
     * @throws A2aAgentUnavailableException
     */
    private function assertUsable(A2aRemoteAgent $agent): void
    {
        $refusal = $agent->useRefusal();
        if ($refusal !== null) {
            throw A2aAgentUnavailableException::because($refusal);
        }
    }

    /**
     * The `tenant` of the selected interface first (proto field 1), when the
     * card named one; otherwise no `tenant` member at all (A2A-C2).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function withTenant(A2aRemoteAgent $agent, array $params): array
    {
        $tenant = $agent->getInterfaceTenant();

        return $tenant === '' ? $params : ['tenant' => $tenant] + $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function encode(array $params): string
    {
        try {
            return A2aJsonRpcCodec::encodeParams($params);
        } catch (JsonException $e) {
            throw new A2aJournalException('The request cannot be serialised: ' . $e->getMessage(), 1799100430, $e);
        }
    }

    private function request(A2aRemoteAgent $agent, string $method, string $encodedParams, string $id, bool $stream = false): A2aHttpRequest
    {
        try {
            $body = A2aJsonRpcCodec::encodeRequest($method, $encodedParams, $id);
        } catch (JsonException $e) {
            throw new A2aJournalException('The request cannot be serialised: ' . $e->getMessage(), 1799100431, $e);
        }

        return new A2aHttpRequest(
            'POST',
            $agent->getInterfaceUrl(),
            [
                'Content-Type'              => A2aProtocol::CONTENT_TYPE,
                'Accept'                    => $stream ? A2aProtocol::EVENT_STREAM_CONTENT_TYPE . ', ' . A2aProtocol::CONTENT_TYPE : A2aProtocol::CONTENT_TYPE,
                A2aProtocol::VERSION_HEADER => A2aProtocol::VERSION,
            ],
            $body,
            $agent->getCredential(),
            $agent->getConnectTimeoutSeconds(),
            $stream ? max($agent->getReadTimeoutSeconds(), $agent->getMaxWaitSeconds()) : $agent->getReadTimeoutSeconds(),
            $agent->getMaxResponseBytes(),
            sprintf('nr-llm A2A %s to "%s"', $method, $agent->getIdentifier()),
        );
    }
}
