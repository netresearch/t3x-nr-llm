<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

/**
 * The access token handed to one request: its Vault reference, and the grant
 * generation it belongs to, so a refusal can name the token it refused
 * (ADR-259).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthAccess
{
    public function __construct(
        public string $credential,
        public int $generation,
    ) {}

    public static function of(McpOAuthGrant $grant): self
    {
        return new self($grant->accessCredential, $grant->generation);
    }
}
