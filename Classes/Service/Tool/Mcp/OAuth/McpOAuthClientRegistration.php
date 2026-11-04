<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

/**
 * The OAuth client this installation uses at one authorization server (ADR-259).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthClientRegistration
{
    public function __construct(
        public string $clientId,
        // Vault reference; '' for a public client.
        public string $clientSecretCredential,
        // McpOAuthGrant::SOURCE_PRE or McpOAuthGrant::SOURCE_DYNAMIC.
        public string $source,
        public string $issuer,
        public string $redirectUri,
        // True when this flow created the registration, so a flow that fails
        // before the grant is stored removes its secret again.
        public bool $created,
    ) {}
}
