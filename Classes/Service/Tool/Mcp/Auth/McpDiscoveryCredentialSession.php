<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\Auth;

use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrVault\Http\CancellationSignalInterface;

/**
 * @internal A configured discovery reference is borrowed, never deleted.
 */
final class McpDiscoveryCredentialSession implements McpCredentialSessionInterface
{
    private bool $closed = false;

    public function __construct(
        private readonly string $server,
        private readonly string $identifier,
        private readonly McpOperationDeadline $deadline,
        private readonly ?CancellationSignalInterface $cancellation,
    ) {}

    public function credentialIdentifier(): string
    {
        if ($this->closed) {
            throw McpTransportException::forDelegatedAuthFailure($this->server, 'session_closed');
        }

        McpAuthOperationGuard::assertAlive($this->server, $this->deadline, $this->cancellation);
        return $this->identifier;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
