<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Mcp\Conformance;

use BadMethodCallException;
use Netresearch\NrVault\Http\OAuth\OAuthConfig;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A vault HTTP client that records how it was configured and never sends
 * anything.
 *
 * It exists for one claim the PSR-18 seam cannot make: the transport builds its
 * client through the vault and puts a finite timeout on it. That happens inside
 * {@see \Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport::clientFor()},
 * which `setHttpClient()` bypasses — so the conformance suite calls that method
 * and reads back what it configured, rather than asserting a constant nothing
 * proves is used.
 *
 * The `with*` methods return `$this` rather than a clone. The production client
 * is immutable and returns new instances; here the point is to accumulate what
 * was asked for, and a clone chain would hand the assertions the wrong object.
 */
final class RecordingVaultHttpClient implements VaultHttpClientInterface
{
    /** Seconds passed to withTimeout(), or null when it was never called. */
    public ?int $timeoutSeconds = null;

    /** The audit reason passed to withReason(), or null when it was never called. */
    public ?string $reason = null;

    /**
     * Accepted and dropped. The interface requires it and the transport calls
     * it for a server that has a credential, but no check here reads it back:
     * the credential is asserted where it is applied, not where it is declared.
     */
    public function withAuthentication(
        string $secretIdentifier,
        SecretPlacement $placement = SecretPlacement::Bearer,
        array $options = [],
    ): static {
        return $this;
    }

    public function withOAuth(OAuthConfig $config, string $reason = 'OAuth2 API call'): static
    {
        throw new BadMethodCallException('The MCP transport never configures OAuth', 1799990240);
    }

    public function withReason(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function withTimeout(int $seconds): static
    {
        $this->timeoutSeconds = $seconds;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new BadMethodCallException('This double configures a client; it never sends', 1799990241);
    }
}
