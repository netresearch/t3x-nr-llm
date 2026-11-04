<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

/**
 * What discovery established about one OAuth-protected MCP server (ADR-259).
 *
 * Every field was validated before this object was built: endpoints are
 * `https` and passed the host gate, `issuer` equals the identifier the metadata
 * URL was built from, and the authorization server supports S256.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthMetadata
{
    /**
     * @param list<string> $authorizationServerScopes `scopes_supported` of the authorization server
     */
    public function __construct(
        // The MCP server's canonical URI, sent as `resource` (RFC 8707).
        public string $resource,
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        // '' when the authorization server offers no Dynamic Client Registration.
        public string $registrationEndpoint,
        // RFC 9207 section 2.3.
        public bool $issParameterSupported,
        // The scope the server suggested: the challenge's, else the protected
        // resource's `scopes_supported`, else ''.
        public string $suggestedScope,
        public array $authorizationServerScopes,
    ) {}
}
