<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Middleware;

use Closure;
use Netresearch\NrLlm\Middleware\McpOAuthCallbackBounce;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * The callback arrives without the strict session cookie and is loaded again
 * from this site (ADR-259).
 */
#[CoversClass(McpOAuthCallbackBounce::class)]
final class McpOAuthCallbackBounceTest extends AbstractUnitTestCase
{
    private const URL = 'https://typo3.example.com/typo3/nrllm/mcp/oauth/callback?code=c%2F1&state=s&iss=https%3A%2F%2Fauth.example.com';

    private bool $handled = false;

    #[Test]
    public function aCallbackWithoutTheCookieIsLoadedAgainFromThisSite(): void
    {
        $response = (new McpOAuthCallbackBounce())->process($this->request(), $this->handler());

        self::assertFalse($this->handled);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        $body = (string) $response->getBody();
        $next = 'https://typo3.example.com/typo3/nrllm/mcp/oauth/callback?code=c%2F1&amp;state=s&amp;iss=https%3A%2F%2Fauth.example.com&amp;nrllm_bounced=1';
        self::assertStringContainsString('<meta http-equiv="refresh" content="0;url=' . $next . '">', $body);
        self::assertStringContainsString('<a href="' . $next . '">', $body);
    }

    #[Test]
    public function aCallbackWithTheCookieOrTheMarkerPassesThrough(): void
    {
        $bounce = new McpOAuthCallbackBounce();

        $bounce->process($this->request()->withCookieParams(['be_typo_user' => 'session']), $this->handler());
        self::assertTrue($this->handled);

        $this->handled = false;
        $bounce->process($this->request()->withQueryParams(['nrllm_bounced' => '1']), $this->handler());
        self::assertTrue($this->handled, 'a browser that still sends no cookie reaches the login, not a loop');
    }

    #[Test]
    public function anyOtherRouteIsUntouched(): void
    {
        $request = $this->request()->withAttribute('route', new Route('/other', ['_identifier' => 'other']));

        (new McpOAuthCallbackBounce())->process($request, $this->handler());

        self::assertTrue($this->handled);
    }

    #[Test]
    public function markupInTheQueryCannotLeaveTheAttribute(): void
    {
        $request = $this->request()->withQueryParams(['state' => '"><script>alert(1)</script>']);

        $body = (string) (new McpOAuthCallbackBounce())->process($request, $this->handler())->getBody();

        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString('"><script', $body);
    }

    private function request(): ServerRequestInterface
    {
        $request = new ServerRequest(self::URL, 'GET');
        parse_str(parse_url(self::URL, PHP_URL_QUERY), $query);

        return $request
            ->withQueryParams($query)
            ->withAttribute('route', new Route('/nrllm/mcp/oauth/callback', ['_identifier' => McpOAuthCallbackBounce::ROUTE, 'access' => 'public']));
    }

    private function handler(): RequestHandlerInterface
    {
        $handled = function (): void {
            $this->handled = true;
        };

        return new class ($handled) implements RequestHandlerInterface {
            /**
             * @param Closure(): void $handled
             */
            public function __construct(private readonly Closure $handled) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->handled)();

                return new Response();
            }
        };
    }
}
