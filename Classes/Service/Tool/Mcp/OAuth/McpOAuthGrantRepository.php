<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use Doctrine\DBAL\ParameterType;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Reads and writes `tx_nrllm_mcp_oauth_grant`, one row per server (ADR-259).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthGrantRepository
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_mcp_oauth_grant';

    public function __construct(private ConnectionPool $connectionPool) {}

    public function findByServer(int $serverUid): ?McpOAuthGrant
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row          = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('server_uid', $queryBuilder->createNamedParameter($serverUid, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Insert or replace the server's row.
     */
    public function save(McpOAuthGrant $grant, int $timestamp): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $values     = [
            'server_uid'               => $grant->serverUid,
            'issuer'                   => $grant->issuer,
            'token_endpoint'           => $grant->tokenEndpoint,
            'resource'                 => $grant->resource,
            'redirect_uri'             => $grant->redirectUri,
            'client_id'                => $grant->clientId,
            'client_secret_credential' => $grant->clientSecretCredential,
            'client_source'            => $grant->clientSource,
            'access_credential'        => $grant->accessCredential,
            'refresh_credential'       => $grant->refreshCredential,
            'expires_at'               => $grant->expiresAt,
            'scope'                    => $grant->scope,
            'status'                   => $grant->status,
            'connected_by'             => $grant->connectedBy,
            'connected_at'             => $grant->connectedAt,
            'tstamp'                   => $timestamp,
        ];

        if ($this->findByServer($grant->serverUid) instanceof McpOAuthGrant) {
            $connection->update(self::TABLE, $values, ['server_uid' => $grant->serverUid]);

            return;
        }

        $connection->insert(self::TABLE, $values);
    }

    /**
     * Record a refresh: the new expiry and granted scope. The credential
     * references stay; their values were rotated in Vault.
     */
    public function recordRefresh(int $serverUid, int $expiresAt, string $scope, int $timestamp): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['expires_at' => $expiresAt, 'scope' => $scope, 'tstamp' => $timestamp],
            ['server_uid' => $serverUid],
        );
    }

    public function markReconnect(int $serverUid, int $timestamp): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['status' => McpOAuthGrant::STATUS_RECONNECT, 'tstamp' => $timestamp],
            ['server_uid' => $serverUid],
        );
    }

    public function delete(int $serverUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(
            self::TABLE,
            ['server_uid' => $serverUid],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): McpOAuthGrant
    {
        return new McpOAuthGrant(
            serverUid: self::toInt($row['server_uid'] ?? 0),
            issuer: self::toStr($row['issuer'] ?? ''),
            tokenEndpoint: self::toStr($row['token_endpoint'] ?? ''),
            resource: self::toStr($row['resource'] ?? ''),
            redirectUri: self::toStr($row['redirect_uri'] ?? ''),
            clientId: self::toStr($row['client_id'] ?? ''),
            clientSecretCredential: self::toStr($row['client_secret_credential'] ?? ''),
            clientSource: self::toStr($row['client_source'] ?? ''),
            accessCredential: self::toStr($row['access_credential'] ?? ''),
            refreshCredential: self::toStr($row['refresh_credential'] ?? ''),
            expiresAt: self::toInt($row['expires_at'] ?? 0),
            scope: self::toStr($row['scope'] ?? ''),
            status: self::toStr($row['status'] ?? ''),
            connectedBy: self::toInt($row['connected_by'] ?? 0),
            connectedAt: self::toInt($row['connected_at'] ?? 0),
        );
    }
}
