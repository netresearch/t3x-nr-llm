<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Mcp;

use BadMethodCallException;
use Netresearch\NrVault\Http\OAuth\OAuthConfig;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A vault HTTP client that answers, records the timeout each leg was given, and
 * costs the operation a scripted amount of time.
 *
 * It exists because the operation deadline can only be asserted where the
 * timeout is actually applied. `McpHttpTransport::setHttpClient()` — the seam
 * every other MCP test uses — bypasses the vault builder that calls
 * `withTimeout()`, so a test driven through it cannot see what a leg was
 * granted. This double takes the vault path instead: it accumulates every
 * timeout in order, and advances a {@see FakeMcpClock} before it answers, which
 * is how a leg "takes" time without the suite sleeping.
 *
 * It is not
 * {@see \Netresearch\NrLlm\Tests\Fixtures\Mcp\Conformance\RecordingVaultHttpClient}
 * widened: that one exists to prove a client is CONFIGURED and never sends, and
 * a double that both refuses to send and sends is a double that proves neither.
 *
 * The `with*` methods return `$this` rather than a clone, as the production
 * client would: the point is to accumulate what the transport asked for across
 * a whole operation.
 */
final class SlowVaultHttpClient implements VaultHttpClientInterface
{
    /**
     * The seconds passed to withTimeout(), one entry per leg, in order.
     *
     * @var list<int>
     */
    public array $grantedTimeouts = [];

    /**
     * @param McpTestServer $server       the scripted answers
     * @param FakeMcpClock  $clock        the operation's clock
     * @param list<float>   $legDurations how long each leg takes, in seconds;
     *                                    a leg beyond the list takes no time
     */
    public function __construct(
        private readonly McpTestServer $server,
        private readonly FakeMcpClock $clock,
        private array $legDurations = [],
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->clock->advanceSeconds(array_shift($this->legDurations) ?? 0.0);

        return $this->server->sendRequest($request);
    }

    public function withTimeout(int $seconds): static
    {
        $this->grantedTimeouts[] = $seconds;

        return $this;
    }

    public function withReason(string $reason): static
    {
        return $this;
    }

    /**
     * Accepted and dropped: no check here reads back how a credential was
     * placed, and the servers these tests use declare none.
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
        throw new BadMethodCallException('The MCP transport never configures OAuth', 1799990242);
    }
}
