<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval\Fixtures;

use Netresearch\NrLlm\Service\Retrieval\SolrHttpClientInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Response;

/**
 * Plain interface double for the Solr select transport: records
 * requested URIs and replays a canned response. (A subclass of the
 * concrete RequestFactory cannot target 13.4 AND 14.x — readonly-ness
 * of the parent differs between the branches.).
 */
final class FakeSolrHttpClient implements SolrHttpClientInterface
{
    /** @var list<string> */
    private array $requestedUris = [];

    public function __construct(
        private readonly ResponseInterface $response,
    ) {}

    public function get(string $url): ResponseInterface
    {
        $this->requestedUris[] = $url;

        return $this->response;
    }

    /**
     * @return list<string>
     */
    public function requestedUris(): array
    {
        return $this->requestedUris;
    }

    public static function withJson(string $json, int $statusCode = 200): self
    {
        $response = new Response('php://temp', $statusCode);
        $response->getBody()->write($json);
        $response->getBody()->rewind();

        return new self($response);
    }
}
