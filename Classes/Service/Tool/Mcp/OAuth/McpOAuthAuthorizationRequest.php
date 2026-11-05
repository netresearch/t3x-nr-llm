<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

/**
 * Where to send the administrator, and what to keep until they come back (ADR-259).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class McpOAuthAuthorizationRequest
{
    public function __construct(
        public string $authorizationUrl,
        public McpOAuthPendingAuthorization $pending,
    ) {}
}
