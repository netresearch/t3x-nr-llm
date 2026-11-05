<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A wire that answers by exact URL and records what was asked, in order.
 * Anything not routed is a 404, which is what a missing well-known document
 * looks like.
 */
final class RoutedWire implements ClientInterface
{
    /** @var array<string, ResponseInterface> */
    private array $routes = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function route(string $url, ResponseInterface $response): self
    {
        $this->routes[$url] = $response;

        return $this;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function json(string $url, array $payload, int $status = 200): self
    {
        return $this->route($url, new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response         = $this->routes[(string) $request->getUri()] ?? new Response(404);
        $response->getBody()->rewind();

        return $response;
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_map(static fn (RequestInterface $request): string => $request->getMethod() . ' ' . $request->getUri(), $this->requests);
    }
}
