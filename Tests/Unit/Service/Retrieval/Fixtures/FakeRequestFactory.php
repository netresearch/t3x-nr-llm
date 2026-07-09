<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval\Fixtures;

use ArrayObject;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;

/**
 * RequestFactory double (the real one is readonly and cannot be doubled
 * by PHPUnit): records requested URIs and replays a canned response.
 */
final readonly class FakeRequestFactory extends RequestFactory
{
    /**
     * @param ArrayObject<int, string> $requestedUris recorded select URIs
     */
    public function __construct(
        private ResponseInterface $response,
        private ArrayObject $requestedUris = new ArrayObject(),
    ) {
        // Deliberately no parent constructor: request() is overridden.
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $uri, string $method = 'GET', array $options = [], ?string $context = null): ResponseInterface
    {
        $this->requestedUris->append($uri);

        return $this->response;
    }

    /**
     * @return list<string>
     */
    public function requestedUris(): array
    {
        return array_values($this->requestedUris->getArrayCopy());
    }

    public static function withJson(string $json, int $statusCode = 200): self
    {
        $response = new Response('php://temp', $statusCode);
        $response->getBody()->write($json);
        $response->getBody()->rewind();

        return new self($response);
    }
}
