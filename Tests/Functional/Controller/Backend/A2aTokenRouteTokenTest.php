<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Throwable;
use TypeError;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * The three state-changing actions of the A2A token module (ADR-294) are
 * protected against cross-site request forgery by the core: every backend
 * route whose `access` option is not `public` needs a request token bound to
 * that route's identifier, and the RouteDispatcher refuses the request before
 * the controller runs. The same mechanism and the same harness as
 * {@see SkillApprovalRouteTokenTest}, dispatched through the real router and
 * dispatcher, so a route that became public — and skipped the check — fails.
 */
#[CoversNothing]
final class A2aTokenRouteTokenTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routes(): iterable
    {
        yield 'create' => ['ajax_nrllm_a2a_token_create'];
        yield 'issue' => ['ajax_nrllm_a2a_token_issue'];
        yield 'revoke' => ['ajax_nrllm_a2a_token_revoke'];
    }

    #[Test]
    #[DataProvider('routes')]
    public function aPostWithoutATokenIsRefusedBeforeTheController(string $identifier): void
    {
        $this->expectException(MissingRequestTokenException::class);
        $this->dispatch($this->path($identifier), null);
    }

    #[Test]
    #[DataProvider('routes')]
    public function aPostWithAnotherRoutesTokenIsRefusedBeforeTheController(string $identifier): void
    {
        $other   = $identifier === 'ajax_nrllm_a2a_token_create' ? 'ajax_nrllm_a2a_token_revoke' : 'ajax_nrllm_a2a_token_create';
        $foreign = $this->tokenFor($other);

        $this->expectException(InvalidRequestTokenException::class);
        $this->dispatch($this->path($identifier), $foreign);
    }

    /**
     * The other direction: the route's own token passes the check, so the
     * refusals above are about the token and nothing else.
     */
    #[Test]
    #[DataProvider('routes')]
    public function aPostWithTheRoutesOwnTokenPassesTheTokenCheck(string $identifier): void
    {
        $path  = $this->path($identifier);
        $token = $this->tokenFor($identifier);

        try {
            $this->dispatch($path, $token);
        } catch (MissingRequestTokenException|InvalidRequestTokenException $e) {
            self::fail('The route token was refused: ' . $e->getMessage());
        } catch (TypeError $e) {
            self::fail('The harness did not reach the token check: ' . $e->getMessage());
        } catch (Throwable) {
            // Anything after the token check is not what this test is about.
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The path of an AJAX route as the router registered it, asserted not to
     * be public: a public route skips the token check.
     */
    private function path(string $identifier): string
    {
        $router = $this->getService(Router::class);
        self::assertInstanceOf(Router::class, $router);

        foreach ($router->getRoutes() as $name => $route) {
            if ($name === $identifier && $route instanceof SymfonyRoute) {
                self::assertNotSame('public', $route->getOption('access'), $identifier);
                $path = $route->getPath();
                self::assertIsString($path);

                return $path;
            }
        }

        self::fail('No backend route ' . $identifier);
    }

    private function matched(string $path): Route
    {
        $router = $this->getService(Router::class);
        self::assertInstanceOf(Router::class, $router);
        $route = $router->match($path);
        self::assertInstanceOf(Route::class, $route);

        return $route;
    }

    private function tokenFor(string $identifier): string
    {
        $factory = $this->getService(FormProtectionFactory::class);
        self::assertInstanceOf(FormProtectionFactory::class, $factory);

        return $factory->createFromRequest($this->request('/ajax/nrllm/a2a-token/create', null))->generateToken('route', $identifier);
    }

    private function dispatch(string $path, ?string $token): void
    {
        $dispatcher = $this->getService(RouteDispatcher::class);
        self::assertInstanceOf(RouteDispatcher::class, $dispatcher);

        $request                  = $this->request($path, $token)->withAttribute('route', $this->matched($path));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $dispatcher->dispatch($request);
    }

    private function request(string $path, ?string $token): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3' . $path, 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withParsedBody(['uid' => '1', 'title' => 'x'] + ($token !== null ? ['token' => $token] : []));
        $request                  = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
