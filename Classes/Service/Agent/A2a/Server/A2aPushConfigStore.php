<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The push notification configs of the server role (spec 051 A2A-PUSH1,
 * A2A-PUSH2, A2A-SEC2, A2A-SEC5, ADR-285).
 *
 * - Every read and write has the owning client in its `WHERE` clause; a task
 *   of another client is not found, like a task that does not exist.
 * - The webhook URL is checked by the address guard when the config is
 *   created (DNS only, nothing is sent) and again before every delivery. It
 *   must be https; plain http is accepted only where the Testing context
 *   names test networks (A2A-SEC10).
 * - The one supported scheme is a Bearer credential in `authentication`. The
 *   credential and the optional `token` are sealed. `Get` and `List` return
 *   the credential masked; the token is returned, and is never sent as a
 *   header (A2A-D8).
 * - There is no update method. A `Create` with the id of an existing config
 *   of the same task replaces that config: the old one is deleted and a new
 *   one written, in one transaction, which is the "new config and the
 *   deletion of the old one" of A2A-PUSH1. Its pending deliveries end.
 * - Delete is idempotent.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPushConfigStore
{
    public const TABLE = 'tx_nrllm_a2a_push_config';

    public const OUTBOX_TABLE = 'tx_nrllm_a2a_push_outbox';

    public const STATE_ACTIVE = 'active';

    public const STATE_AUTH_FAILED = 'auth_failed';

    public const STATE_STOPPED = 'stopped';

    public const MASK = '********';

    private const MAX_ID_LENGTH = 191;

    private const MAX_URL_LENGTH = 2048;

    private const MAX_SECRET_LENGTH = 4096;

    public function __construct(
        private ConnectionPool $connectionPool,
        private AgentStateCodec $codec,
        private A2aUrlGuardFactory $guards,
        private A2aServerSettings $settings,
    ) {}

    /**
     * Check a `TaskPushNotificationConfig` the client sent and bring it into
     * the form {@see self::insert()} stores. Nothing is stored here.
     *
     * @param array<string, mixed> $config
     *
     * @return array{id: string, url: string, token: string, scheme: string, credentials: string}
     *
     * @throws A2aRpcError
     */
    public function validate(array $config, string $field): array
    {
        $url = $config['url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw A2aRpcError::invalidParams($field . '.url', 'required');
        }

        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw A2aRpcError::invalidParams($field . '.url', sprintf('at most %d characters', self::MAX_URL_LENGTH));
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https' && ($scheme !== 'http' || !$this->guards->permitsPlainHttp())) {
            throw A2aRpcError::invalidParams($field . '.url', 'a webhook must be an https URL');
        }

        // A2A-SEC5: an outside URL passes the guard at creation, and again
        // before every delivery.
        $target = $this->guards->createForPush()->check(A2aUrlGuardFactory::GROUP_EXTERNAL, $url);
        if (!$target->allowed) {
            throw A2aRpcError::invalidParams($field . '.url', 'this address is not allowed for a webhook');
        }

        $id = $config['id'] ?? '';
        if (!is_string($id) || strlen($id) > self::MAX_ID_LENGTH) {
            throw A2aRpcError::invalidParams($field . '.id', sprintf('a string of at most %d characters', self::MAX_ID_LENGTH));
        }

        $token = $config['token'] ?? '';
        if (!is_string($token) || strlen($token) > self::MAX_SECRET_LENGTH) {
            throw A2aRpcError::invalidParams($field . '.token', sprintf('a string of at most %d characters', self::MAX_SECRET_LENGTH));
        }

        $authScheme  = '';
        $credentials = '';
        $auth        = $config['authentication'] ?? null;
        if ($auth !== null) {
            if (!is_array($auth)) {
                throw A2aRpcError::invalidParams($field . '.authentication', 'not an object');
            }

            $authScheme = $auth['scheme'] ?? '';
            if (!is_string($authScheme) || strcasecmp($authScheme, 'Bearer') !== 0) {
                throw A2aRpcError::invalidParams($field . '.authentication.scheme', 'the one supported scheme is Bearer');
            }

            $credentials = $auth['credentials'] ?? '';
            if (!is_string($credentials) || $credentials === '' || strlen($credentials) > self::MAX_SECRET_LENGTH || preg_match('/[\x00-\x20\x7F]/', $credentials) === 1) {
                throw A2aRpcError::invalidParams($field . '.authentication.credentials', 'a Bearer credential without spaces or control characters');
            }

            $authScheme = 'Bearer';
        }

        return ['id' => $id !== '' ? $id : Uuid::v4()->toRfc4122(), 'url' => $url, 'token' => $token, 'scheme' => $authScheme, 'credentials' => $credentials];
    }

    /**
     * Store a validated config of a task the client owns, replacing a config
     * with the same id on the same task. Runs in the caller's transaction
     * when one is open.
     *
     * @param array{id: string, url: string, token: string, scheme: string, credentials: string} $config
     *
     * @throws A2aRpcError when the task or the client has as many configs as allowed
     */
    public function insert(Connection $connection, A2aInboundClient $client, int $taskUid, array $config, int $now): void
    {
        // The client's row lock serialises the configs of one client, so two
        // concurrent creates cannot both pass a count the other changes. The
        // acceptance of SendMessage holds the same lock already.
        $connection->executeStatement(
            'UPDATE ' . $connection->quoteIdentifier(A2aInboundClientRepository::TABLE) . ' SET uid = uid WHERE uid = ?',
            [$client->uid],
            [Connection::PARAM_INT],
        );

        $existing = $connection->select(['uid'], self::TABLE, ['task_uid' => $taskUid, 'config_id' => $config['id'], 'owner_client_uid' => $client->uid])->fetchOne();
        if (is_numeric($existing)) {
            $this->remove($connection, (int) $existing);
        } else {
            if ($connection->count('uid', self::TABLE, ['task_uid' => $taskUid]) >= $this->settings->maxPushConfigsPerTask()) {
                throw A2aRpcError::invalidParams('taskPushNotificationConfig', 'this task has as many push configs as allowed');
            }

            if ($connection->count('uid', self::TABLE, ['owner_client_uid' => $client->uid]) >= $this->settings->maxPushConfigsPerClient()) {
                throw A2aRpcError::invalidParams('taskPushNotificationConfig', 'this client has as many push configs as allowed');
            }
        }

        $connection->insert(self::TABLE, [
            'config_id'         => $config['id'],
            'task_uid'          => $taskUid,
            'owner_client_uid'  => $client->uid,
            'client_token_hash' => $client->tokenHash,
            'url'               => $config['url'],
            'token'             => $this->codec->encode($config['token'], AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL),
            'auth_scheme'       => $config['scheme'],
            'credentials'       => $this->codec->encode($config['credentials'], AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL),
            'state'             => self::STATE_ACTIVE,
            'crdate'            => $now,
            'tstamp'            => $now,
        ]);
    }

    /**
     * `CreateTaskPushNotificationConfig` on a task the client owns.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> the stored config, masked
     *
     * @throws A2aRpcError
     */
    public function create(A2aInboundClient $client, array $params, int $now): array
    {
        $task   = $this->ownTask($client, $params['taskId'] ?? null, 'taskId');
        $config = $this->validate($params, 'params');
        $this->connection()->transactional(function (Connection $connection) use ($client, $task, $config, $now): void {
            $this->insert($connection, $client, $task['uid'], $config, $now);
        });

        return $this->get($client, ['taskId' => $task['task_id'], 'id' => $config['id']]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws A2aRpcError
     */
    public function get(A2aInboundClient $client, array $params): array
    {
        $task = $this->ownTask($client, $params['taskId'] ?? null, 'taskId');
        $id   = $params['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw A2aRpcError::invalidParams('id', 'required');
        }

        $row = $this->connection()->select(['*'], self::TABLE, ['task_uid' => $task['uid'], 'config_id' => $id, 'owner_client_uid' => $client->uid])->fetchAssociative();
        if (!is_array($row)) {
            throw A2aRpcError::a2a(A2aErrorCode::TASK_NOT_FOUND, 'Push notification config not found.');
        }

        return $this->render($row, $task['task_id']);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> a `ListTaskPushNotificationConfigsResponse`
     *
     * @throws A2aRpcError
     */
    public function list(A2aInboundClient $client, array $params): array
    {
        $task     = $this->ownTask($client, $params['taskId'] ?? null, 'taskId');
        $pageSize = $params['pageSize'] ?? null;
        if ($pageSize !== null && (!is_int($pageSize) || $pageSize < 0 || $pageSize > 100)) {
            throw A2aRpcError::invalidParams('pageSize', 'from 0 to 100');
        }

        $token = $params['pageToken'] ?? '';
        if (!is_string($token) || $token !== '') {
            // A task holds at most maxPushConfigsPerTask configs, so every
            // list fits one page and no token is ever issued.
            throw A2aRpcError::invalidParams('pageToken', 'not a token issued by this agent');
        }

        $rows = $this->connection()->select(['*'], self::TABLE, ['task_uid' => $task['uid'], 'owner_client_uid' => $client->uid], [], ['uid' => 'ASC'])->fetchAllAssociative();

        return [
            'configs'       => array_map(fn (array $row): array => $this->render($row, $task['task_id']), $rows),
            'nextPageToken' => '',
        ];
    }

    /**
     * Idempotent: a config that does not exist (any more) is deleted too. The
     * task must be the client's.
     *
     * @param array<string, mixed> $params
     *
     * @throws A2aRpcError
     */
    public function delete(A2aInboundClient $client, array $params): void
    {
        $task = $this->ownTask($client, $params['taskId'] ?? null, 'taskId');
        $id   = $params['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw A2aRpcError::invalidParams('id', 'required');
        }

        $this->connection()->transactional(function (Connection $connection) use ($client, $task, $id): void {
            $uid = $connection->select(['uid'], self::TABLE, ['task_uid' => $task['uid'], 'config_id' => $id, 'owner_client_uid' => $client->uid])->fetchOne();
            if (is_numeric($uid)) {
                $this->remove($connection, (int) $uid);
            }
        });
    }

    /**
     * Stop every active config of a client and end their pending deliveries.
     */
    public static function stopAllOf(ConnectionPool $connectionPool, int $clientUid, string $reason): void
    {
        $connection = $connectionPool->getConnectionForTable(self::TABLE);
        $uids       = $connection->select(['uid'], self::TABLE, ['owner_client_uid' => $clientUid])->fetchFirstColumn();
        $connection->update(self::TABLE, ['state' => self::STATE_STOPPED, 'reason' => $reason, 'tstamp' => time()], ['owner_client_uid' => $clientUid, 'state' => self::STATE_ACTIVE]);
        foreach ($uids as $uid) {
            if (is_numeric($uid)) {
                $connection->update(self::OUTBOX_TABLE, ['state' => 'dropped', 'last_reason' => $reason, 'tstamp' => time()], ['config_uid' => (int) $uid, 'state' => 'pending']);
            }
        }
    }

    /**
     * A config and the deliveries it still has to make; one already in flight
     * may still arrive (A2A-PUSH5).
     */
    private function remove(Connection $connection, int $configUid): void
    {
        $connection->delete(self::OUTBOX_TABLE, ['config_uid' => $configUid, 'state' => 'pending']);
        $connection->delete(self::TABLE, ['uid' => $configUid]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function render(array $row, string $taskId): array
    {
        $config = [
            'id'     => is_string($row['config_id'] ?? null) ? $row['config_id'] : '',
            'taskId' => $taskId,
            'url'    => is_string($row['url'] ?? null) ? $row['url'] : '',
        ];
        $token = $this->codec->decode(is_string($row['token'] ?? null) ? $row['token'] : '', AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL);
        if ($token !== '') {
            $config['token'] = $token;
        }

        if (is_string($row['auth_scheme'] ?? null) && $row['auth_scheme'] !== '') {
            // A2A-PUSH2: never in clear.
            $config['authentication'] = ['scheme' => $row['auth_scheme'], 'credentials' => self::MASK];
        }

        return $config;
    }

    /**
     * @return array{uid: int, task_id: string}
     *
     * @throws A2aRpcError
     */
    private function ownTask(A2aInboundClient $client, mixed $taskId, string $field): array
    {
        if (!is_string($taskId) || $taskId === '') {
            throw A2aRpcError::invalidParams($field, 'required');
        }

        $uid = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)
            ->select(['uid'], A2aTaskStore::TABLE, ['owner_client_uid' => $client->uid, 'task_id' => $taskId])
            ->fetchOne();
        if (!is_numeric($uid)) {
            throw A2aRpcError::a2a(A2aErrorCode::TASK_NOT_FOUND, 'Task not found.');
        }

        return ['uid' => (int) $uid, 'task_id' => $taskId];
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
