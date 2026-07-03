<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest as GuzzleServerRequest;
use Netresearch\NrLlm\Controller\Backend\ToolController;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Functional tests for the admin-only Tools management backend module.
 *
 * Renders listAction() through the real ModuleTemplate stack and asserts each
 * registered tool surfaces with its description and an enable/disable control.
 * The AJAX toggleToolAction is covered by the admin-guard, happy-path and
 * unknown-tool tests below.
 */
#[CoversClass(ToolController::class)]
final class ToolControllerTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function listActionRendersToolManagementList(): void
    {
        $this->importFixture('BeUsers.csv');
        $backendUser = $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $toolRegistry = new ToolRegistry([new FakeTool('fetch_logs')]);
        $controller   = $this->makeController($toolRegistry);
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $response = $controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();

        // The management list shows the tool name, its description and the toggle button.
        self::assertStringContainsString('fetch_logs', $body);
        self::assertStringContainsString('desc of fetch_logs', $body);
        self::assertStringContainsString('js-tool-toggle', $body);
        // The playground run form (config picker) does NOT live here anymore.
        self::assertStringNotContainsString('id="nrllm-tool-config"', $body);
    }

    #[Test]
    public function toggleToolActionDeniesNonAdmin(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2); // uid 2 is a non-admin editor (admin=0)

        $controller = $this->makeController(new ToolRegistry([new FakeTool('fetch_logs')]));

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))
            ->withParsedBody(['tool' => 'fetch_logs', 'enabled' => '0']);
        $response = $controller->toggleToolAction($request);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
    }

    #[Test]
    public function toggleToolActionRejectsUnknownTool(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // admin

        $controller = $this->makeController(new ToolRegistry([new FakeTool('fetch_logs')]));

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))
            ->withParsedBody(['tool' => 'no_such_tool', 'enabled' => '0']);
        $response = $controller->toggleToolAction($request);

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
    }

    #[Test]
    public function toggleToolActionPersistsDisabledState(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // admin

        $toolRegistry = new ToolRegistry([new FakeTool('fetch_logs')]);
        $controller   = $this->makeController($toolRegistry);

        $request = (new GuzzleServerRequest('POST', '/ajax/nrllm/tool/toggle'))
            ->withParsedBody(['tool' => 'fetch_logs', 'enabled' => '0']);
        $response = $controller->toggleToolAction($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);
        self::assertFalse($payload['enabled']);

        // The fail-closed gate now reports the tool as disabled on every read.
        self::assertNotContains('fetch_logs', $this->availabilityFor($toolRegistry)->enabledNames());
    }

    private function makeController(ToolRegistry $toolRegistry): ToolController
    {
        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);
        $pageRenderer = $this->get(PageRenderer::class);
        self::assertInstanceOf(PageRenderer::class, $pageRenderer);

        return new ToolController(
            $moduleTemplateFactory,
            $toolRegistry,
            $this->availabilityFor($toolRegistry),
            new ToolStateRepository($this->toolConnectionPool()),
            $pageRenderer,
        );
    }

    private function availabilityFor(ToolRegistry $toolRegistry): ToolAvailabilityService
    {
        return new ToolAvailabilityService($toolRegistry, new ToolStateRepository($this->toolConnectionPool()));
    }

    private function toolConnectionPool(): ConnectionPool
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        return $connectionPool;
    }

    /**
     * Build an Extbase backend request carrying the route package name so the
     * BackendViewFactory resolves the extension's template root path.
     */
    private function createBackendRequest(): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\Tool');
        $extbaseParameters->setControllerActionName('list');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/tools', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $reflection->getProperty($property)->setValue($object, $value);
    }
}
