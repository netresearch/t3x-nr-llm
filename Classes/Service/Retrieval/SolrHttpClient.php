<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Production Solr select transport: TYPO3's RequestFactory with a tight
 * timeout and no redirect following (the endpoint host comes from
 * admin-controlled site configuration, never from model input).
 */
final readonly class SolrHttpClient implements SolrHttpClientInterface
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private RequestFactory $requestFactory,
    ) {}

    public function get(string $url): ResponseInterface
    {
        return $this->requestFactory->request($url, 'GET', [
            'timeout' => self::TIMEOUT_SECONDS,
            'allow_redirects' => false,
        ]);
    }
}
