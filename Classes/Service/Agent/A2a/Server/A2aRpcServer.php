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
 * Unrecognised members are ignored (A2A-P4). A non-empty `tenant` is refused
 * on every method: the card's interface declares none (A2A-SV6).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aRpcServer
{
    /** Methods of later stages and their refusal while undeclared (A2A-P7). */
    private const UNDECLARED = [
        'SendStreamingMessage'             => A2aErrorCode::UNSUPPORTED_OPERATION,
        'SubscribeToTask'                  => A2aErrorCode::UNSUPPORTED_OPERATION,
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
            $result = $this->dispatch($client, $call['method'], $call['params'], $now);

            return A2aRpcReply::result($id, $result);
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
        $version = $request->hasHeader(A2aProtocol::VERSION_HEADER)
            ? trim($request->getHeaderLine(A2aProtocol::VERSION_HEADER))
            : (is_string($request->getQueryParams()[A2aProtocol::VERSION_HEADER] ?? null) ? trim($request->getQueryParams()[A2aProtocol::VERSION_HEADER]) : '');

        if (!A2aProtocol::isSupportedVersion($version)) {
            throw A2aRpcError::a2a(A2aErrorCode::VERSION_NOT_SUPPORTED, 'Protocol version not supported; this agent speaks 1.0.');
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function dispatch(A2aInboundClient $client, string $method, array $params, int $now): array
    {
        if (array_key_exists($method, self::UNDECLARED)) {
            throw A2aRpcError::a2a(self::UNDECLARED[$method], 'This agent does not offer ' . $method . '.');
        }

        if (!in_array($method, [A2aProtocol::METHOD_SEND_MESSAGE, A2aProtocol::METHOD_GET_TASK, A2aProtocol::METHOD_LIST_TASKS, A2aProtocol::METHOD_CANCEL_TASK], true)) {
            throw A2aRpcError::methodNotFound();
        }

        $tenant = $params['tenant'] ?? '';
        if (!is_string($tenant) || $tenant !== '') {
            throw A2aRpcError::invalidParams('tenant', 'this agent declares no tenant');
        }

        return match ($method) {
            A2aProtocol::METHOD_SEND_MESSAGE => $this->sendMessage->handle($client, $params, $now),
            A2aProtocol::METHOD_GET_TASK     => $this->getTask($client, $params),
            A2aProtocol::METHOD_LIST_TASKS   => $this->listTasks($client, $params),
            default                          => $this->cancelTask($client, $params),
        };
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
     * that was; another client's task is not found.
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
            $row = $this->ownTask($client, $params);
            if ($this->isTerminal($row) && ($row['state'] ?? '') !== A2aTaskState::CANCELED->value) {
                throw A2aRpcError::a2a(A2aErrorCode::TASK_NOT_CANCELABLE, 'The task is in a terminal state.');
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
