<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The frontend entry point of the A2A server role (spec 051 A2A-SV1,
 * A2A-SV2, A2A-SV3, ADR-279, ADR-280).
 *
 * It answers two paths below a site's configured A2A base URL: the
 * JSON-RPC endpoint (the base itself, POST) and the agent card
 * (`/.well-known/agent-card.json`, GET). A request is matched against the
 * configured bases by its scheme, host, port and path; a request that
 * matches none, every other path and every other method pass through
 * untouched. With the server role switched off, everything passes through.
 *
 * It runs before the site is resolved, so an A2A request never reaches page
 * resolution, TypoScript or the frontend user.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aServerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private A2aServerSettings $settings,
        private SiteFinder $siteFinder,
        private A2aAgentCardBuilder $cards,
        private A2aRpcServer $rpc,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->settings->enabled()) {
            return $handler->handle($request);
        }

        $match = $this->match($request);
        if ($match === null) {
            return $handler->handle($request);
        }

        [$site, $base, $path] = $match;
        $method               = strtoupper($request->getMethod());

        if ($path === A2aBaseUrl::CARD_PATH && in_array($method, ['GET', 'HEAD'], true)) {
            return $this->card($request, $site, $base) ?? $handler->handle($request);
        }

        if ($path === '' && $method === 'POST') {
            return $this->rpc->handle($request, time())->toResponse();
        }

        return $handler->handle($request);
    }

    /**
     * The site, base and relative path of an A2A request; null for any other
     * request. The request's scheme, host and port are compared with the
     * configured base, never used to build anything.
     *
     * @return array{Site, A2aBaseUrl, string}|null
     */
    private function match(ServerRequestInterface $request): ?array
    {
        $params = $request->getAttribute('normalizedParams');
        $params = $params instanceof NormalizedParams ? $params : NormalizedParams::createFromRequest($request);

        $scheme = $params->isHttps() ? 'https' : 'http';
        $port   = $params->getRequestPort();
        $host   = $params->getRequestHostOnly();
        $path   = $request->getUri()->getPath();

        foreach ($this->siteFinder->getAllSites() as $site) {
            $base = $this->settings->baseUrlOf($site);
            if (!$base instanceof A2aBaseUrl || !$base->matchesOrigin($scheme, $host, $port > 0 ? $port : null)) {
                continue;
            }

            $relative = $base->relativePath($path);
            if ($relative !== null) {
                return [$site, $base, $relative];
            }
        }

        return null;
    }

    /**
     * The card with `Cache-Control: max-age=300` and an `ETag` of its body;
     * 304 for a matching `If-None-Match` (A2A-SV3, §8.6.1). Null when the
     * site publishes no skill: a card needs at least one (A2A-P5).
     */
    private function card(ServerRequestInterface $request, Site $site, A2aBaseUrl $base): ?ResponseInterface
    {
        $card = $this->cards->build($site, $base);
        if ($card['skills'] === []) {
            return null;
        }

        $body    = json_encode($card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $etag    = '"' . hash('sha256', $body) . '"';
        $headers = ['Cache-Control' => 'max-age=300', 'ETag' => $etag, 'Content-Type' => 'application/json'];

        $match = array_map(trim(...), explode(',', $request->getHeaderLine('If-None-Match')));
        if (in_array($etag, $match, true) || in_array('W/' . $etag, $match, true) || in_array('*', $match, true)) {
            return new Response(null, 304, ['Cache-Control' => 'max-age=300', 'ETag' => $etag]);
        }

        $response = new Response('php://temp', 200, $headers);
        $response->getBody()->write($body);

        return $response;
    }
}
