<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

use GuzzleHttp\ClientInterface;
use Netresearch\NrLlm\Service\Tool\Web\Exception\ExternalFetchException;
use Netresearch\NrVault\Http\SecureHttpClientFactory;

/**
 * Builds the client from nr-vault's {@see SecureHttpClientFactory} (ADR-202).
 *
 * That factory is the second layer under {@see ExternalUrlGuard}: it applies
 * the instance's TYPO3 HTTP settings (proxy, TLS verification, certificates),
 * turns request logging off, and its middleware resolves and range-checks the
 * host of every request again before the socket opens.
 */
final readonly class ExternalFetchClientFactory implements ExternalFetchClientFactoryInterface
{
    public function __construct(
        private SecureHttpClientFactory $secureHttpClientFactory,
    ) {}

    public function create(int $timeoutSeconds): ClientInterface
    {
        $client = $this->secureHttpClientFactory->create($timeoutSeconds);

        // The pin, the bounded sink and on_headers are Guzzle request options;
        // a client that cannot take them must not be used without them.
        if (!$client instanceof ClientInterface) {
            throw new ExternalFetchException('The secure HTTP client is not a Guzzle client; external fetches are disabled.', 8609874314);
        }

        return $client;
    }
}
