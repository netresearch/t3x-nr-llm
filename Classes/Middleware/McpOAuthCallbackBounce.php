<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;

/**
 * Lets the OAuth callback of an MCP connection arrive with the backend
 * session (ADR-259).
 *
 * TYPO3 sets the backend session cookie with `SameSite=Strict` by default. The
 * authorization server sends the browser back with a cross-site navigation,
 * and a browser does not attach a strict cookie to that request — so the
 * callback would land on the login form, and the flow's state, which sits in
 * the session, would be out of reach.
 *
 * When that request arrives without the cookie, this middleware answers it
 * with a page that loads the same URL again. That second navigation starts on
 * this site, so the browser attaches the cookie. The page does nothing else:
 * it carries no data the URL did not, and the callback itself still requires
 * the logged-in administrator and checks the state. The marker parameter
 * keeps a browser that still sends no cookie from bouncing forever.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class McpOAuthCallbackBounce implements MiddlewareInterface
{
    public const ROUTE = 'nrllm_mcp_oauth_callback';

    public const MARKER = 'nrllm_bounced';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute('route');
        if (!$route instanceof Route || $route->getOption('_identifier') !== self::ROUTE) {
            return $handler->handle($request);
        }

        $query = $request->getQueryParams();
        if (isset($request->getCookieParams()[BackendUserAuthentication::getCookieName()]) || isset($query[self::MARKER])) {
            return $handler->handle($request);
        }

        $query[self::MARKER] = '1';
        $target              = (string) $request->getUri()->withQuery(http_build_query($query, '', '&', PHP_QUERY_RFC3986))->withFragment('');
        $escaped             = htmlspecialchars($target, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return new HtmlResponse(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="referrer" content="no-referrer">'
            . '<meta http-equiv="refresh" content="0;url=' . $escaped . '">'
            . '<title>Connecting</title></head><body>'
            . '<p><a href="' . $escaped . '">Continue</a></p>'
            . '</body></html>',
            200,
            [
                'Cache-Control'           => 'no-store',
                'Referrer-Policy'         => 'no-referrer',
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            ],
        );
    }
}
