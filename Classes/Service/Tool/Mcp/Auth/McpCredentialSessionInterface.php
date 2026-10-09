<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

/**
 * One operation receives Vault identifiers only.
 * Check before each HTTP leg; close owned credentials in finally (ADR-217).
 *
 * @api
 */
interface McpCredentialSessionInterface
{
    public function credentialIdentifier(): string;

    public function close(): void;
}
