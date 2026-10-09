<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

/**
 * @internal Only an expiring Vault reference leaves the exchange parser.
 */
final readonly class McpIssuedCredential
{
    public function __construct(public string $identifier, public int $expiresAtNanoseconds) {}
}
