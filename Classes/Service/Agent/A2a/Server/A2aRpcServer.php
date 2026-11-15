<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use DateTimeImmutable;
use JsonException;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTimestamp;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The JSON-RPC 2.0 endpoint of the server role (spec 051 A2A-P1 to A2A-P7,
 * A2A-SV6, A2A-SEC1, A2A-SEC6).
 *
 * The order of the checks is the order of what a refusal may reveal: the
 * size of the body (413, before it is parsed), the credential (401, before
 * anything about the request is read), then the envelope, the protocol
 * version, the method, the capability, and the params. Every method reads
 * and writes only the authenticated client's tasks.
 *
 * Unrecognised members are ignored (A2A-P4). A proto field name is accepted
 * beside its JSON name ({@see A2aRequestFieldNames}). A non-empty `tenant` is refused
 * on every method: the card's interface declares none (A2A-SV6).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aRpcServer
{
    /** Methods of later stages and their refusal while undeclared (A2A-P7). */
    private const UNDECLARED = [
        'GetExtendedAgentCard'             => A2aErrorCode::UNSUPPORTED_OPERATION,
        'CreateTaskPushNotificationConfig' => A2aErrorCode::PUSH_NOTIFICATION_NOT_SUPPORTED,
        'GetTaskPushNotificationConfig'    => A2aErrorCode::PUSH_NOTIFICATION_NOT_SUPPORTED,
        'ListTaskPushNotificationConfigs'  => A2aErrorCode::PUSH_NOTIFICATION_NOT_SUPPORTED,
        'DeleteTaskPushNotificationConfig' => A2aErrorCode::PUSH_NOTIFICATION_NOT_SUPPORTED,
    ];

    private const PAGE_SIZE_DEFAULT = 50;

    private const PAGE_SIZE_MAX = 100;

    private LoggerInterface $logger;

    public function __construct(
        private A2aServerSettings $settings,
        private A2aAuthenticator $authenticator,
        private A2aSendMessageHandler $sendMessage,
        private A2aTaskView $view,
        private A2aListCursor $cursor,
        private AgentRuntimeInterface $runtime,
        private ConnectionPool $connectionPool,
        private A2aStreamSlots $streamSlots,
        private A2aTaskStream $taskStream,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function handle(ServerRequestInterface $request, int $now): A2aRpcReply
    {
        $id = null;
        try {
            $body   = $this->body($request);
            $client = $this->authenticator->authenticate($request, $now);
            $call   = $this->envelope($body, $id);
            $this->checkVersion($request);
            $result = $this->dispatch($client, $call['method'], $call['params'], $now, $id);

            return $result instanceof A2aRpcReply ? $result : A2aRpcReply::result($id, $result);
        } catch (A2aRpcError $error) {
            return A2aRpcReply::error($id, $error);
        } catch (Throwable $exception) {
            $this->logger->error('A2A request failed', ['exception' => $exception]);

            return A2aRpcReply::error($id, A2aRpcError::internal());
        }
    }

    /**
     * The body, read up to the limit and not one byte further (A2A-SEC6).
     */
    private function body(ServerRequestInterface $request): string
    {
        $limit  = $this->settings->maxRequestBytes();
        $length = $request->getHeaderLine('Content-Length');
        if (is_numeric($length) && (int) $length > $limit) {
            throw A2aRpcError::tooLarge();
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        while (!$stream->eof() && strlen($body) <= $limit) {
            $chunk = $stream->read(min(65536, $limit + 1 - strlen($body)));
            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        if (strlen($body) > $limit) {
            throw A2aRpcError::tooLarge();
        }

        return $body;
    }

    /**
     * @param int|string|null $id set to the request's id as soon as it is read
     *
     * @return array{method: string, params: array<string, mixed>}
     */
    private function envelope(string $body, int|string|null &$id): array
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw A2aRpcError::parseError();
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw A2aRpcError::invalidRequest('a single JSON-RPC request object is expected');
        }

        $requestId = $decoded['id'] ?? null;
        if (!is_string($requestId) && !is_int($requestId) && $requestId !== null) {
            throw A2aRpcError::invalidRequest('the id is neither a string nor a number');
        }

        $id = $requestId;
        if (($decoded['jsonrpc'] ?? null) !== '2.0' || !is_string($decoded['method'] ?? null)) {
            throw A2aRpcError::invalidRequest('"jsonrpc" must be "2.0" and "method" a string');
        }

        $params = $decoded['params'] ?? [];
        if (!is_array($params) || array_is_list($params) && $params !== []) {
            throw A2aRpcError::invalidParams('params', 'not an object');
        }

        $object = [];
        foreach ($params as $key => $value) {
            $object[(string) $key] = $value;
        }

        return ['method' => $decoded['method'], 'params' => $object];
    }

    /**
     * A2A-P2: the header, or the query parameter when the header is absent;
     * `1.0` and any `1.0.x`. Absent or empty means 0.3 and is refused.
     */
    private function checkVersion(ServerRequestInterface $request): void
    {
        $query   = $request->getQueryParams()[A2aProtocol::VERSION_HEADER] ?? null;
        $version = $request->hasHeader(A2aProtocol::VERSION_HEADER)
            ? trim($request->getHeaderLine(A2aProtocol::VERSION_HEADER))
            : trim(is_string($query) ? $query : '');

        if (!A2aProtocol::isSupportedVersion($version)) {
            throw A2aRpcError::a2a(A2aErrorCode::VERSION_NOT_SUPPORTED, 'Protocol version not supported; this agent speaks 1.0.');
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|A2aRpcReply a result, or a stream
     */
    private function dispatch(A2aInboundClient $client, string $method, array $params, int $now, int|string|null $id): array|A2aRpcReply
    {
        if (array_key_exists($method, self::UNDECLARED)) {
            throw A2aRpcError::a2a(self::UNDECLARED[$method], 'This agent does not offer ' . $method . '.');
        }

        $streaming = in_array($method, [A2aProtocol::METHOD_SEND_STREAMING_MESSAGE, A2aProtocol::METHOD_SUBSCRIBE_TO_TASK], true);
        if ($streaming && !$this->settings->streamingEnabled()) {
            // A2A-P7, A2A-OPS2: the card declares no streaming.
            throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'This agent does not offer ' . $method . '.');
        }

        if (!$streaming && !in_array($method, [A2aProtocol::METHOD_SEND_MESSAGE, A2aProtocol::METHOD_GET_TASK, A2aProtocol::METHOD_LIST_TASKS, A2aProtocol::METHOD_CANCEL_TASK], true)) {
            throw A2aRpcError::methodNotFound();
        }

        $params = A2aRequestFieldNames::normalise($method, $params);
        $tenant = $params['tenant'] ?? '';
        if (!is_string($tenant) || $tenant !== '') {
            throw A2aRpcError::invalidParams('tenant', 'this agent declares no tenant');
        }

        return match ($method) {
            A2aProtocol::METHOD_SEND_MESSAGE           => $this->sendMessage->handle($client, $params, $now),
            A2aProtocol::METHOD_SEND_STREAMING_MESSAGE => $this->sendStreamingMessage($client, $params, $now, $id),
            A2aProtocol::METHOD_SUBSCRIBE_TO_TASK      => $this->subscribeToTask($client, $params, $now, $id),
            A2aProtocol::METHOD_GET_TASK               => $this->getTask($client, $params),
            A2aProtocol::METHOD_LIST_TASKS             => $this->listTasks($client, $params),
            default                                    => $this->cancelTask($client, $params),
        };
    }

    /**
     * A2A-SSE1, A2A-SSE3: `SendMessage` without the wait, then a stream of the
     * task it created or found. The stream slot is taken before anything is
     * accepted, so a refusal for stream capacity stores nothing; the blocking
     * wait's slot is never taken. A message to an existing task (a
     * continuation, A2A-MT3) is counted against that task's streams before it
     * is taken in, so the answer is never stored and then refused. A message
     * answer is a stream of that one message.
     *
     * @param array<string, mixed> $params
     */
    private function sendStreamingMessage(A2aInboundClient $client, array $params, int $now, int|string|null $id): A2aRpcReply
    {
        $message       = is_array($params['message'] ?? null) ? $params['message'] : [];
        $configuration = is_array($params['configuration'] ?? null) ? $params['configuration'] : [];
        $named         = is_string($message['taskId'] ?? null) ? $message['taskId'] : '';
        $holder        = $this->takeStreamSlot($client, $named, $now);
        try {
            $result = $this->sendMessage->handle($client, $params, $now, true);
            if (is_array($result['message'] ?? null)) {
                $this->streamSlots->release($holder);

                return A2aRpcReply::stream(static function (A2aSseWriter $writer) use ($id, $result): void {
                    $writer->event(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['message' => $result['message']]]);
                }, $this->settings->maxFrameBytes());
            }

            $task   = is_array($result['task'] ?? null) ? $result['task'] : [];
            $taskId = is_string($task['id'] ?? null) ? $task['id'] : '';
            if ($named === '' && !$this->streamSlots->bind($holder, $client->uid, $taskId, $this->settings->maxStreamsPerTask(), $now)) {
                // Only a repeated messageId finds a task that already has
                // streams; nothing new was stored for it.
                throw A2aRpcError::unavailable(A2aRpcError::REASON_STREAM_CAPACITY_EXHAUSTED, 5);
            }
        } catch (Throwable $refused) {
            $this->streamSlots->release($holder);

            throw $refused;
        }

        $history = $configuration['historyLength'] ?? null;

        return $this->streamOf($client, $taskId, $holder, $id, is_int($history) && $history >= 0 ? $history : null);
    }

    /**
     * A2A-SSE4: a stream of a task the client owns; a terminal task is
     * `-32004` (proto comment, section 9.4.6).
     *
     * @param array<string, mixed> $params
     */
    private function subscribeToTask(A2aInboundClient $client, array $params, int $now, int|string|null $id): A2aRpcReply
    {
        $row = $this->ownTask($client, $params);
        if ($this->isTerminal($row)) {
            throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'The task is in a terminal state; read it with GetTask.');
        }

        $taskId = is_string($row['task_id'] ?? null) ? $row['task_id'] : '';

        return $this->streamOf($client, $taskId, $this->takeStreamSlot($client, $taskId, $now), $id);
    }

    private function takeStreamSlot(A2aInboundClient $client, string $taskId, int $now): string
    {
        return $this->streamSlots->take(
            $client->uid,
            $taskId,
            $this->settings->maxStreamsPerTask(),
            $this->settings->maxStreamsPerClient(),
            $this->settings->maxStreams(),
            $now,
        ) ?? throw A2aRpcError::unavailable(A2aRpcError::REASON_STREAM_CAPACITY_EXHAUSTED, 5);
    }

    /**
     * The stream itself. The task row is read when the stream starts: its
     * revision is the cursor of the snapshot (A2A-SSE2).
     */
    private function streamOf(A2aInboundClient $client, string $taskId, string $holder, int|string|null $id, ?int $historyLength = null): A2aRpcReply
    {
        return A2aRpcReply::stream(function (A2aSseWriter $writer) use ($client, $taskId, $holder, $id, $historyLength): void {
            try {
                $row = $this->view->find($client->uid, $taskId);
                if ($row !== null) {
                    $this->taskStream->stream($client, $row, $holder, $id, $writer, $historyLength);
                }
            } catch (Throwable $exception) {
                // The stream is open: a failure ends it, the task is untouched.
                $this->logger->error('An A2A stream failed', ['task' => $taskId, 'exception' => $exception]);
            } finally {
                $this->streamSlots->release($holder);
            }
        }, $this->settings->maxFrameBytes());
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function getTask(A2aInboundClient $client, array $params): array
    {
        return $this->view->render($this->ownTask($client, $params), $this->historyLength($params));
    }

    /**
     * A2A-SV6: CANCELED for a task that was not terminal, `-32002` for one
     * that was, and for one whose run could not be cancelled; another
     * client's task is not found.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function cancelTask(A2aInboundClient $client, array $params): array
    {
        $row = $this->ownTask($client, $params);
        if ($this->isTerminal($row)) {
            throw A2aRpcError::a2a(A2aErrorCode::TASK_NOT_CANCELABLE, 'The task is in a terminal state.');
        }

        $runUuid = $this->runUuid($row);
        $actor   = AiActorContext::backendUser($client->backendUserUid, false, [], [], $client->workspaceId);
        if ($runUuid === '' || !$this->runtime->cancel($actor, $runUuid)) {
            // The run was not cancelled here; the task may have been cancelled
            // meanwhile, or it ended. Anything but CANCELED is no success.
            $row = $this->ownTask($client, $params);
            if (($row['state'] ?? '') !== A2aTaskState::CANCELED->value) {
                throw A2aRpcError::a2a(A2aErrorCode::TASK_NOT_CANCELABLE, $this->isTerminal($row) ? 'The task is in a terminal state.' : 'The task could not be cancelled.');
            }
        }

        return $this->view->render($this->ownTask($client, $params), null);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function listTasks(A2aInboundClient $client, array $params): array
    {
        $filter   = $this->filter($params);
        $pageSize = $params['pageSize'] ?? null;
        if ($pageSize === null) {
            $pageSize = self::PAGE_SIZE_DEFAULT;
        } elseif (!is_int($pageSize) || $pageSize < 1 || $pageSize > self::PAGE_SIZE_MAX) {
            throw A2aRpcError::invalidParams('pageSize', sprintf('from 1 to %d', self::PAGE_SIZE_MAX));
        }

        $token = $params['pageToken'] ?? '';
        if (!is_string($token)) {
            throw A2aRpcError::invalidParams('pageToken', 'not a string');
        }

        $after = null;
        if ($token !== '') {
            $after = $this->cursor->read($token, $client->uid, $client->profileUids, $filter);
            if ($after === null) {
                throw A2aRpcError::invalidParams('pageToken', 'not a token issued for this client and this filter');
            }
        }

        $page = $this->view->page($client->uid, $filter, $after, $pageSize);
        $rows = $page['rows'];
        $next = '';
        if (count($rows) > $pageSize) {
            $rows = array_slice($rows, 0, $pageSize);
            $last = $rows[$pageSize - 1];
            $next = $this->cursor->issue(
                $client->uid,
                $client->profileUids,
                $filter,
                is_numeric($last['status_timestamp'] ?? null) ? (int) $last['status_timestamp'] : 0,
                is_string($last['task_id'] ?? null) ? $last['task_id'] : '',
            );
        }

        $history = $this->historyLength($params);

        return [
            'tasks'         => array_map(fn (array $row): array => $this->view->render($row, $history, $filter->includeArtifacts), $rows),
            'nextPageToken' => $next,
            'pageSize'      => $pageSize,
            'totalSize'     => $page['total'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function filter(array $params): A2aListFilter
    {
        $contextId = $params['contextId'] ?? '';
        if (!is_string($contextId)) {
            throw A2aRpcError::invalidParams('contextId', 'not a string');
        }

        $state  = null;
        $status = $params['status'] ?? '';
        if (!is_string($status)) {
            throw A2aRpcError::invalidParams('status', 'not a TaskState');
        }

        if ($status !== '' && $status !== A2aTaskState::UNSPECIFIED->value) {
            $state = A2aTaskState::tryFrom($status) ?? throw A2aRpcError::invalidParams('status', 'not a TaskState');
        }

        $after = null;
        if (($params['statusTimestampAfter'] ?? null) !== null) {
            $instant = is_string($params['statusTimestampAfter']) ? A2aTimestamp::parse($params['statusTimestampAfter']) : null;
            if (!$instant instanceof DateTimeImmutable) {
                throw A2aRpcError::invalidParams('statusTimestampAfter', 'not an ISO 8601 UTC timestamp');
            }

            $after = (int) $instant->format('Uv');
        }

        $include = $params['includeArtifacts'] ?? false;
        if (!is_bool($include)) {
            throw A2aRpcError::invalidParams('includeArtifacts', 'not a boolean');
        }

        return new A2aListFilter($contextId, $state, $after, $include);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function ownTask(A2aInboundClient $client, array $params): array
    {
        $id = $params['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw A2aRpcError::invalidParams('id', 'required');
        }

        return $this->view->find($client->uid, $id) ?? throw A2aRpcError::a2a(A2aErrorCode::TASK_NOT_FOUND, 'Task not found.');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function historyLength(array $params): ?int
    {
        $value = $params['historyLength'] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_int($value) || $value < 0) {
            throw A2aRpcError::invalidParams('historyLength', 'not a non-negative integer');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isTerminal(array $row): bool
    {
        $state = A2aTaskState::tryFrom(is_string($row['state'] ?? null) ? $row['state'] : '');

        return $state instanceof A2aTaskState && $state->isTerminal();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function runUuid(array $row): string
    {
        $runUid = is_numeric($row['run_uid'] ?? null) ? (int) $row['run_uid'] : 0;
        $uuid   = $this->connectionPool->getConnectionForTable('tx_nrllm_agentrun')
            ->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $runUid], [], [], 1)
            ->fetchOne();

        return is_string($uuid) ? $uuid : '';
    }
}
