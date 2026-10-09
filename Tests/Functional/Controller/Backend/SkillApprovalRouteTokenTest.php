<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\SkillApprovalController;
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
 * Cross-site request forgery protection of the approve and revoke actions
 * (ADR-214 item 2) comes from the core: every backend route whose `access`
 * option is not `public` needs a request token bound to that route's
 * identifier, and the core's RouteDispatcher refuses the request before the
 * controller runs (typo3/cms-backend Classes/Http/RouteDispatcher.php,
 * assertRequestToken(), v14.3.7 lines 120-139, v13.4.35 from line 117).
 * Extbase module routes take the module's access value (ExtbaseModule.php
 * getDefaultRouteOptions(), lines 66 and 80), here `admin`.
 *
 * Dispatched through the real router and dispatcher, so a route that became
 * public — and thereby skipped the token check — fails here.
 */
#[CoversNothing]
final class SkillApprovalRouteTokenTest extends AbstractFunctionalTestCase
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
    public static function stateChangingActions(): iterable
    {
        yield 'approve' => ['approveVersion'];
        yield 'revoke' => ['revokeVersion'];
    }

    #[Test]
    #[DataProvider('stateChangingActions')]
    public function aPostWithoutATokenIsRefusedBeforeTheController(string $action): void
    {
        $this->expectException(MissingRequestTokenException::class);
        $this->dispatch($this->routePath($action), null);
    }

    #[Test]
    #[DataProvider('stateChangingActions')]
    public function aPostWithAForeignTokenIsRefusedBeforeTheController(string $action): void
    {
        // A valid token, but for another route: the review page's.
        $foreign = $this->tokenFor($this->matched($this->routePath('review'))->getOption('_identifier'));

        $this->expectException(InvalidRequestTokenException::class);
        $this->dispatch($this->routePath($action), $foreign);
    }

    /**
     * The other direction: the token the form's action URL carries passes the
     * check, so the refusals above are about the token and nothing else.
     */
    #[Test]
    #[DataProvider('stateChangingActions')]
    public function aPostWithTheRoutesOwnTokenPassesTheTokenCheck(string $action): void
    {
        $path  = $this->routePath($action);
        $token = $this->tokenFor($this->matched($path)->getOption('_identifier'));

        try {
            $this->dispatch($path, $token);
        } catch (MissingRequestTokenException|InvalidRequestTokenException $e) {
            self::fail('The route token was refused: ' . $e->getMessage());
        } catch (TypeError $e) {
            self::fail('The harness did not reach the token check: ' . $e->getMessage());
        } catch (Throwable) {
            // Anything after the token check (the Extbase bootstrap of this
            // minimal request) is not what this test is about.
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The path of the module route for one action of the approval controller,
     * asserted not to be public: a public route skips the token check.
     */
    private function routePath(string $action): string
    {
        $router = $this->getService(Router::class);
        self::assertInstanceOf(Router::class, $router);

        $seen = [];
        foreach ($router->getRoutes() as $identifier => $route) {
            if (!is_string($identifier) || !$route instanceof SymfonyRoute || !str_starts_with($identifier, 'nrllm_skills')) {
                continue;
            }

            $controller = $route->getOption('controller');
            $seen[]     = $identifier;
            if ($route->getOption('action') === $action && is_string($controller) && str_contains($controller, 'SkillApproval')) {
                self::assertNotSame('public', $route->getOption('access'));
                $path = $route->getPath();
                self::assertIsString($path);

                return $path;
            }
        }

        self::fail('No backend route for ' . SkillApprovalController::class . '::' . $action . 'Action; seen: ' . implode(', ', $seen));
    }

    /**
     * The route the core's router matches for the path, as a backend Route
     * carrying its identifier — what the production dispatcher receives.
     */
    private function matched(string $path): Route
    {
        $router = $this->getService(Router::class);
        self::assertInstanceOf(Router::class, $router);
        $route = $router->match($path);
        self::assertInstanceOf(Route::class, $route);

        return $route;
    }

    private function tokenFor(mixed $routeIdentifier): string
    {
        self::assertIsString($routeIdentifier);
        $factory = $this->getService(FormProtectionFactory::class);
        self::assertInstanceOf(FormProtectionFactory::class, $factory);

        return $factory->createFromRequest($this->request('/module/nrllm/skills', null))->generateToken('route', $routeIdentifier);
    }

    private function dispatch(string $path, ?string $token): void
    {
        $dispatcher = $this->getService(RouteDispatcher::class);
        self::assertInstanceOf(RouteDispatcher::class, $dispatcher);

        $request = $this->request($path, $token)->withAttribute('route', $this->matched($path));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $dispatcher->dispatch($request);
    }

    private function request(string $path, ?string $token): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3' . $path, 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withParsedBody(['skill' => '5', 'versionDigest' => '1:' . str_repeat('a', 64)] + ($token !== null ? ['token' => $token] : []));
        $request                  = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
