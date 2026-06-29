<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\ToolPlaygroundController;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Functional tests for the admin-only Tool Playground backend module.
 *
 * Renders listAction() through the real ModuleTemplate stack (the playground
 * shell) and asserts the config picker plus the registered tools surface in
 * the markup. The AJAX runAction is covered by Task 2.
 */
#[CoversClass(ToolPlaygroundController::class)]
final class ToolPlaygroundControllerTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function listActionRendersPlaygroundShell(): void
    {
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('BeUsers.csv');
        $backendUser = $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);
        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);
        $pageRenderer = $this->get(PageRenderer::class);
        self::assertInstanceOf(PageRenderer::class, $pageRenderer);

        $controller = new ToolPlaygroundController(
            $moduleTemplateFactory,
            new ToolRegistry([new FakeTool('fetch_logs')]),
            $configurationRepository,
            $pageRenderer,
        );
        $this->setPrivateProperty($controller, 'request', $this->createBackendRequest());

        $response = $controller->listAction();

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();

        // Config picker over the persisted configurations.
        self::assertStringContainsString('id="nrllm-tool-config"', $body);
        self::assertStringContainsString('Default Configuration', $body);
        // Prompt box, run button and output pane.
        self::assertStringContainsString('id="nrllm-tool-prompt"', $body);
        self::assertStringContainsString('id="nrllm-tool-run"', $body);
        self::assertStringContainsString('id="nrllm-tool-output"', $body);
        // Tools panel lists the registered tool (name + description).
        self::assertStringContainsString('fetch_logs', $body);
        self::assertStringContainsString('desc of fetch_logs', $body);
    }

    /**
     * Build an Extbase backend request carrying the route package name so the
     * BackendViewFactory resolves the extension's template root path.
     */
    private function createBackendRequest(): ExtbaseRequest
    {
        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('ToolPlayground');
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
