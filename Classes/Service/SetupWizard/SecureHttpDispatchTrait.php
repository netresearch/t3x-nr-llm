<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\Exception\HostNotAllowedException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Shared SSRF-guarded HTTP dispatch for the setup-wizard services.
 *
 * Consumers must provide the nr-vault collaborators as promoted constructor
 * properties (`$vault`, `$httpClientFactory`) and declare a
 * `VAULT_DISPATCH_REASON` class constant — the human-readable reason the
 * vault secure client records in its audit log for every outbound request.
 */
trait SecureHttpDispatchTrait
{
    /**
     * Test seam: when set, requests go through this client instead of the
     * vault secure client. Production never sets it.
     */
    private ?ClientInterface $configuredHttpClient = null;

    /**
     * Inject a custom HTTP client, bypassing the vault secure client.
     *
     * @internal Test seam only — production always dispatches through the
     *           audited vault client (see `dispatch()`).
     */
    public function setHttpClient(ClientInterface $client): void
    {
        $this->configuredHttpClient = $client;
    }

    /**
     * Send a request through the SSRF-guarded vault secure client.
     *
     * The host is gated up front via `isHostAllowed()` so a disallowed /
     * private-range target is rejected before any secret-bearing header is
     * sent; the vault client re-checks the host and validates the scheme as
     * defence in depth.
     *
     * @throws HostNotAllowedException when the host is disallowed
     * @throws Throwable               when the request fails
     */
    private function dispatch(RequestInterface $request): ResponseInterface
    {
        // Test seam: an injected client bypasses the vault path entirely so
        // unit tests can assert on the request the wizard built without hitting
        // DNS or the host allowlist.
        if ($this->configuredHttpClient !== null) {
            return $this->configuredHttpClient->sendRequest($request);
        }

        // Reject a disallowed / private-range target up front, before any
        // secret-bearing header reaches the wire. The vault client re-checks
        // the host and validates the scheme inside sendRequest() as defence in
        // depth, but failing here yields a clear, typed rejection.
        $host = $request->getUri()->getHost();
        if (!$this->httpClientFactory->isHostAllowed($host)) {
            throw HostNotAllowedException::forHost($host);
        }

        return $this->vault->http()
            ->withReason(self::VAULT_DISPATCH_REASON)
            ->sendRequest($request);
    }
}
