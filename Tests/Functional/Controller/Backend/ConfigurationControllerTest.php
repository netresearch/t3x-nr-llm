<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Functional tests for ConfigurationController AJAX actions.
 *
 * Tests user pathways with real database fixtures:
 * - Pathway 4.2: Toggle Configuration Status
 * - Pathway 4.3: Set Default Configuration
 * - Pathway 4.4: Test Configuration
 *
 * Note: The controller is instantiated directly with real services from the container
 * rather than via DI. This bypasses the ActionController's request requirement during
 * instantiation while still testing against real database operations.
 */
#[CoversClass(ConfigurationController::class)]
final class ConfigurationControllerTest extends AbstractFunctionalTestCase
{
    private ConfigurationController $controller;
    private LlmConfigurationRepository $configurationRepository;
    private PersistenceManagerInterface $persistenceManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');

        // Get real services from container
        $repository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $repository);
        $this->configurationRepository = $repository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;

        // Create controller directly with real services, bypassing ActionController DI requirements
        $this->controller = $this->createController();
    }

    /**
     * Create ConfigurationController with real services from DI container.
     *
     * We instantiate the controller directly instead of via $this->get()
     * because ActionController requires a request during DI initialization.
     * The AJAX actions we test don't use the Extbase features that need the request.
     */
    private function createController(): ConfigurationController
    {
        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);

        $iconFactory = $this->get(IconFactory::class);
        self::assertInstanceOf(IconFactory::class, $iconFactory);

        $configurationService = $this->get(LlmConfigurationService::class);
        self::assertInstanceOf(LlmConfigurationService::class, $configurationService);

        $llmServiceManager = $this->get(LlmServiceManagerInterface::class);
        self::assertInstanceOf(LlmServiceManagerInterface::class, $llmServiceManager);

        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        $pageRenderer = $this->get(PageRenderer::class);
        self::assertInstanceOf(PageRenderer::class, $pageRenderer);

        $backendUriBuilder = $this->get(BackendUriBuilder::class);
        self::assertInstanceOf(BackendUriBuilder::class, $backendUriBuilder);

        return new ConfigurationController(
            $moduleTemplateFactory,
            $iconFactory,
            $configurationService,
            $this->configurationRepository,
            $llmServiceManager,
            $providerAdapterRegistry,
            $pageRenderer,
            $backendUriBuilder,
        );
    }

    // =========================================================================
    // Pathway 4.2: Toggle Configuration Status
    // =========================================================================

    #[Test]
    public function toggleActiveReturnsSuccessForActiveToInactive(): void
    {
        // Arrange: Get configuration that is currently active (uid=1)
        $configuration = $this->configurationRepository->findByUid(1);
        self::assertNotNull($configuration);
        self::assertTrue($configuration->isActive());

        $request = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request = $request->withParsedBody(['uid' => 1]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertFalse($body['isActive']);

        // Verify persistence
        $this->persistenceManager->clearState();
        $reloaded = $this->configurationRepository->findByUid(1);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());
    }

    #[Test]
    public function toggleActiveReturnsSuccessForInactiveToActive(): void
    {
        // Arrange: Use inactive configuration (uid=5)
        $configuration = $this->configurationRepository->findByUid(5);
        self::assertNotNull($configuration);
        self::assertFalse($configuration->isActive());

        $request = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request = $request->withParsedBody(['uid' => 5]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertTrue($body['isActive']);

        // Verify persistence
        $this->persistenceManager->clearState();
        $reloaded = $this->configurationRepository->findByUid(5);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isActive());
    }

    #[Test]
    public function toggleActiveReturnsErrorForMissingUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No configuration UID specified', $body['error']);
    }

    #[Test]
    public function toggleActiveReturnsNotFoundForNonExistentConfiguration(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request = $request->withParsedBody(['uid' => 999]);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Configuration not found', $body['error']);
    }

    #[Test]
    public function toggleActiveHandlesNumericStringUid(): void
    {
        // UID passed as string (common from form submissions)
        $request = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request = $request->withParsedBody(['uid' => '1']);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
    }

    // =========================================================================
    // Pathway 4.3: Set Default Configuration
    // =========================================================================

    #[Test]
    public function setDefaultReturnsSuccessAndUpdatesDefault(): void
    {
        // Arrange: Verify current default (uid=1) and non-default (uid=2)
        $currentDefault = $this->configurationRepository->findByUid(1);
        self::assertNotNull($currentDefault);
        self::assertTrue($currentDefault->isDefault());

        $newDefault = $this->configurationRepository->findByUid(2);
        self::assertNotNull($newDefault);
        self::assertFalse($newDefault->isDefault());

        $request = new ServerRequest('POST', '/ajax/nrllm/config/setdefault');
        $request = $request->withParsedBody(['uid' => 2]);

        // Act
        $response = $this->controller->setDefaultAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);

        // Verify persistence: new default is set, old default is cleared
        $this->persistenceManager->clearState();
        $reloadedNew = $this->configurationRepository->findByUid(2);
        self::assertNotNull($reloadedNew);
        self::assertTrue($reloadedNew->isDefault());

        $reloadedOld = $this->configurationRepository->findByUid(1);
        self::assertNotNull($reloadedOld);
        self::assertFalse($reloadedOld->isDefault());
    }

    #[Test]
    public function setDefaultReturnsErrorForMissingUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/config/setdefault');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->setDefaultAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No configuration UID specified', $body['error']);
    }

    #[Test]
    public function setDefaultReturnsNotFoundForNonExistentConfiguration(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/config/setdefault');
        $request = $request->withParsedBody(['uid' => 999]);

        // Act
        $response = $this->controller->setDefaultAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Configuration not found', $body['error']);
    }

    #[Test]
    public function setDefaultHandlesNumericStringUid(): void
    {
        // UID passed as string (common from form submissions)
        $request = new ServerRequest('POST', '/ajax/nrllm/config/setdefault');
        $request = $request->withParsedBody(['uid' => '2']);

        // Act
        $response = $this->controller->setDefaultAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
    }

    // =========================================================================
    // Pathway 4.4: Test Configuration
    // =========================================================================

    #[Test]
    public function testConfigurationReturnsResponse(): void
    {
        // Note: This test verifies the controller action flow.
        // Actual API connection is tested in integration tests.
        $request = new ServerRequest('POST', '/ajax/nrllm/config/test');
        $request = $request->withParsedBody(['uid' => 1]);

        // Act
        $response = $this->controller->testConfigurationAction($request);

        // Assert - response is either success or connection failure (expected without real API)
        self::assertContains($response->getStatusCode(), [200, 400, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function testConfigurationReturnsErrorForMissingUid(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/config/test');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->testConfigurationAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No configuration UID specified', $body['error']);
    }

    #[Test]
    public function testConfigurationReturnsNotFoundForNonExistentConfiguration(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/config/test');
        $request = $request->withParsedBody(['uid' => 999]);

        // Act
        $response = $this->controller->testConfigurationAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Configuration not found', $body['error']);
    }

    #[Test]
    public function testConfigurationReturnsErrorForConfigurationWithoutModel(): void
    {
        // Arrange: Use configuration without model assigned (uid=8)
        $configuration = $this->configurationRepository->findByUid(8);
        self::assertNotNull($configuration);
        self::assertNull($configuration->getLlmModel());

        $request = new ServerRequest('POST', '/ajax/nrllm/config/test');
        $request = $request->withParsedBody(['uid' => 8]);

        // Act
        $response = $this->controller->testConfigurationAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Configuration has no model assigned', $body['error']);
    }

    #[Test]
    public function toggleActiveHandlesNonNumericUidAsZero(): void
    {
        // Non-numeric string should be treated as 0
        $request = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request = $request->withParsedBody(['uid' => 'invalid']);

        // Act
        $response = $this->controller->toggleActiveAction($request);

        // Assert: treated as missing UID
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No configuration UID specified', $body['error']);
    }

    #[Test]
    public function getModelsHandlesNumericProviderValue(): void
    {
        // Numeric value should be converted to string
        $request = new ServerRequest('POST', '/ajax/nrllm/config/get-models');
        $request = $request->withParsedBody(['provider' => 12345]);

        // Act
        $response = $this->controller->getModelsAction($request);

        // Assert: numeric value converted to string but not found
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Provider not available', $body['error']);
    }

    // -------------------------------------------------------------------------
    // Get Models by Provider (getModelsAction)
    // -------------------------------------------------------------------------

    #[Test]
    public function getModelsReturnsErrorForMissingProvider(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/config/get-models');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->getModelsAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No provider specified', $body['error']);
    }

    #[Test]
    public function getModelsReturnsNotFoundForNonExistentProvider(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/config/get-models');
        $request = $request->withParsedBody(['provider' => 'non-existent-provider']);

        // Act
        $response = $this->controller->getModelsAction($request);

        // Assert
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Provider not available', $body['error']);
    }
}
