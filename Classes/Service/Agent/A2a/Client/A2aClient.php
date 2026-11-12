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
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageConfiguration;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskList;
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
 *    | any other invalid response, id mismatch included | `refused`, `INVALID_AGENT_RESPONSE` (A2A-P5) |
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

        $intent = $begin->intent;
        $id     = Uuid::v7()->toRfc4122();
        $result = $this->transport->send($this->request($agent, A2aProtocol::METHOD_SEND_MESSAGE, $encoded, $id));

        return $this->settle($intent, $result, $id);
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

        $task = $this->parsed(A2aProtocol::METHOD_GET_TASK, fn (): A2aTask => $this->parser->parseTask($this->call($agent, A2aProtocol::METHOD_GET_TASK, $params), 'result'));
        $this->assertSameTask(A2aProtocol::METHOD_GET_TASK, $remoteTaskId, $task);
        $this->journal->recordRemoteState($handle, $task->status->rawState);

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
        $task   = $this->parsed(A2aProtocol::METHOD_CANCEL_TASK, fn (): A2aTask => $this->parser->parseTask($this->call($agent, A2aProtocol::METHOD_CANCEL_TASK, ['id' => $remoteTaskId]), 'result'));
        $this->assertSameTask(A2aProtocol::METHOD_CANCEL_TASK, $remoteTaskId, $task);
        $this->journal->recordRemoteState($handle, $task->status->rawState);

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
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws A2aCallFailedException
     */
    private function call(A2aRemoteAgent $agent, string $method, array $params): array
    {
        $id     = Uuid::v7()->toRfc4122();
        $result = $this->transport->send($this->request($agent, $method, $this->encode($this->withTenant($agent, $params)), $id));
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

    private function request(A2aRemoteAgent $agent, string $method, string $encodedParams, string $id): A2aHttpRequest
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
                'Accept'                    => A2aProtocol::CONTENT_TYPE,
                A2aProtocol::VERSION_HEADER => A2aProtocol::VERSION,
            ],
            $body,
            $agent->getCredential(),
            $agent->getConnectTimeoutSeconds(),
            $agent->getReadTimeoutSeconds(),
            $agent->getMaxResponseBytes(),
            sprintf('nr-llm A2A %s to "%s"', $method, $agent->getIdentifier()),
        );
    }
}
