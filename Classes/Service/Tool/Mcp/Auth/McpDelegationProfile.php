<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

/**
 * One explicitly configured RFC-8693 exchange profile (ADR-217).
 * Holds public request fields and Vault identifiers with canonical grants.
 *
 * @api
 */
final readonly class McpDelegationProfile
{
    /** @var list<string> */
    public array $allowedAudiences;

    /** @var list<string> */
    public array $allowedScopes;

    /**
     * @param string|null  $clientSecretIdentifier Canonical Vault UUIDv7 or ASCII alias (3–255 characters).
     * @param list<string> $allowedAudiences
     * @param list<string> $allowedScopes
     */
    public function __construct(
        public string $identifier,
        public string $tokenEndpoint,
        public string $clientId,
        public ?string $clientSecretIdentifier,
        array $allowedAudiences,
        array $allowedScopes,
    ) {
        McpAuthValidation::profileIdentifier($identifier);
        McpAuthValidation::endpoint($tokenEndpoint);
        McpAuthValidation::clientId($clientId);
        if ($clientSecretIdentifier !== null) {
            McpAuthValidation::credentialIdentifier($clientSecretIdentifier);
        }

        $this->allowedAudiences = McpAuthValidation::audiences($allowedAudiences);
        $this->allowedScopes = McpAuthValidation::scopes($allowedScopes);
    }
}
