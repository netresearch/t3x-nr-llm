<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

/**
 * The OAuth connection of one MCP server, as `tx_nrllm_mcp_oauth_grant` holds
 * it (ADR-259). Every credential field is a Vault reference.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthGrant
{
    public const STATUS_CONNECTED = 'connected';

    public const STATUS_RECONNECT = 'reconnect';

    public const SOURCE_PRE = 'pre';

    public const SOURCE_DYNAMIC = 'dynamic';

    public function __construct(
        public int $serverUid,
        public string $issuer,
        public string $tokenEndpoint,
        public string $resource,
        public string $redirectUri,
        public string $clientId,
        public string $clientSecretCredential,
        public string $clientSource,
        public string $accessCredential,
        public string $refreshCredential,
        // Unix time; 0 when the authorization server named no lifetime.
        public int $expiresAt,
        public string $scope,
        public string $status,
        public int $connectedBy,
        public int $connectedAt,
        // Raised by every refresh; see McpOAuthTokenStore.
        public int $generation = 0,
    ) {}

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && $this->accessCredential !== '';
    }
}
