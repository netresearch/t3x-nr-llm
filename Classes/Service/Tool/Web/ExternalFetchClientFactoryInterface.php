<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

use GuzzleHttp\ClientInterface;

/**
 * Supplies the HTTP client `fetch_external_url` sends through (ADR-202).
 *
 * A Guzzle client rather than a PSR-18 one, because the tool needs per-request
 * options PSR-18 cannot carry: the `CURLOPT_RESOLVE` pin, the bounded sink,
 * the `on_headers` check and the connect timeout.
 */
interface ExternalFetchClientFactoryInterface
{
    public function create(int $timeoutSeconds): ClientInterface;
}
