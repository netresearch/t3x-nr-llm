<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp\OAuth;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpAuthOperationGuard;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpCredentialSessionInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrVault\Http\CancellationSignalInterface;

/**
 * The credential of one MCP operation against an OAuth-connected server (ADR-259).
 *
 * Asked before every leg, like the delegated session of ADR-217, so a token
 * that expires during a long operation is refreshed before the next request
 * rather than sent and refused. Closing it deletes nothing: the token belongs
 * to the server's grant, not to the operation.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class McpOAuthCredentialSession implements McpCredentialSessionInterface
{
    private bool $closed = false;

    /** The grant generation of the token handed out last. */
    private ?int $handedOut = null;

    /** The generation the server refused, until the next hand-out. */
    private ?int $rejected = null;

    public function __construct(
        private readonly McpServerRecord $server,
        private readonly McpOAuthTokenStore $tokens,
        private readonly McpOperationDeadline $deadline,
        private readonly ?CancellationSignalInterface $cancellation,
    ) {}

    public function credentialIdentifier(): string
    {
        if ($this->closed) {
            throw McpTransportException::forOAuthFailure($this->server->identifier, 'session_closed');
        }

        McpAuthOperationGuard::assertAlive($this->server->identifier, $this->deadline, $this->cancellation);

        $rejected        = $this->rejected;
        $this->rejected  = null;
        $access          = $this->tokens->accessCredential($this->server, $this->deadline, $this->cancellation, $rejected);
        $this->handedOut = $access->generation;

        return $access->credential;
    }

    /**
     * The MCP server refused the token just sent (401). Returns whether a
     * retry can carry another one: the next {@see self::credentialIdentifier()}
     * then renews the refused generation first, unless another process has
     * already. Without a refresh token there is nothing to renew, and the
     * refusal stands.
     */
    public function tokenRejected(): bool
    {
        if ($this->closed || $this->handedOut === null || !$this->tokens->canRenew($this->server)) {
            return false;
        }

        $this->rejected = $this->handedOut;

        return true;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
