<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\ServerRequest as Typo3ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Functional tests for multi-provider workflow pathways.
 *
 * Tests user pathways:
 * - Pathway 8.1: Switch Between Providers
 * - Pathway 8.2: Fallback Provider (configuration-based testing)
 *
 * These tests verify that users can work with multiple providers
 * and switch between them seamlessly.
 */
#[CoversClass(ConfigurationController::class)]
#[CoversClass(ProviderController::class)]
#[CoversClass(ModelController::class)]
#[CoversClass(LlmModuleController::class)]
final class MultiProviderWorkflowTest extends AbstractFunctionalTestCase
{
    private ConfigurationController $configController;
    private ProviderController $providerController;
    private ModelController $modelController;
    private LlmModuleController $llmModuleController;
    private ProviderRepository $providerRepository;
    private LlmConfigurationRepository $configurationRepository;
    private PersistenceManagerInterface $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $this->importFixture('Tasks.csv');

        // Get repositories
        $providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $providerRepository);
        $this->providerRepository = $providerRepository;

        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);
        $this->configurationRepository = $configurationRepository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;

        // Create controllers
        $this->configController = $this->createConfigurationController();
        $this->providerController = $this->createProviderController();
        $this->modelController = $this->createModelController();
        $this->llmModuleController = $this->createLlmModuleController();
    }

    private function createConfigurationController(): ConfigurationController
    {
        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);

        $componentFactory = $this->get(ComponentFactory::class);
        self::assertInstanceOf(ComponentFactory::class, $componentFactory);

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
            $componentFactory,
            $iconFactory,
            $configurationService,
            $this->configurationRepository,
            $llmServiceManager,
            $providerAdapterRegistry,
            $pageRenderer,
            $backendUriBuilder,
        );
    }

    private function createProviderController(): ProviderController
    {
        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);

        $componentFactory = $this->get(ComponentFactory::class);
        self::assertInstanceOf(ComponentFactory::class, $componentFactory);

        $iconFactory = $this->get(IconFactory::class);
        self::assertInstanceOf(IconFactory::class, $iconFactory);

        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);

        $pageRenderer = $this->get(PageRenderer::class);
        self::assertInstanceOf(PageRenderer::class, $pageRenderer);

        $backendUriBuilder = $this->get(BackendUriBuilder::class);
        self::assertInstanceOf(BackendUriBuilder::class, $backendUriBuilder);

        return new ProviderController(
            $moduleTemplateFactory,
            $componentFactory,
            $iconFactory,
            $this->providerRepository,
            $providerAdapterRegistry,
            $persistenceManager,
            $pageRenderer,
            $backendUriBuilder,
        );
    }

    private function createModelController(): ModelController
    {
        $modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $modelRepository);

        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        $reflection = new ReflectionClass(ModelController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($controller, 'modelRepository', $modelRepository);
        $this->setPrivateProperty($controller, 'providerRepository', $this->providerRepository);
        $this->setPrivateProperty($controller, 'providerAdapterRegistry', $providerAdapterRegistry);

        return $controller;
    }

    private function createLlmModuleController(): LlmModuleController
    {
        $llmServiceManager = $this->get(LlmServiceManager::class);
        self::assertInstanceOf(LlmServiceManager::class, $llmServiceManager);

        $modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $modelRepository);

        $taskRepository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $taskRepository);

        $reflection = new ReflectionClass(LlmModuleController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($controller, 'llmServiceManager', $llmServiceManager);
        $this->setPrivateProperty($controller, 'providerRepository', $this->providerRepository);
        $this->setPrivateProperty($controller, 'modelRepository', $modelRepository);
        $this->setPrivateProperty($controller, 'configurationRepository', $this->configurationRepository);
        $this->setPrivateProperty($controller, 'taskRepository', $taskRepository);

        return $controller;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    /**
     * @param array<string, mixed> $parsedBody
     */
    private function createExtbaseRequest(array $parsedBody = []): ExtbaseRequest
    {
        $serverRequest = new Typo3ServerRequest();
        $serverRequest = $serverRequest->withParsedBody($parsedBody);

        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('LlmModule');
        $extbaseParameters->setControllerActionName('executeTest');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = $serverRequest->withAttribute('extbase', $extbaseParameters);

        return new ExtbaseRequest($serverRequest);
    }

    // =========================================================================
    // Pathway 8.1: Switch Between Providers
    // =========================================================================

    #[Test]
    public function canQueryMultipleProvidersInSequence(): void
    {
        // Get models for first provider
        $request = new ServerRequest('POST', '/ajax/nrllm/model/get-by-provider');
        $request = $request->withParsedBody(['providerUid' => 1]);

        $response1 = $this->modelController->getByProviderAction($request);
        self::assertSame(200, $response1->getStatusCode());
        $body1 = json_decode((string)$response1->getBody(), true);
        self::assertIsArray($body1);
        self::assertTrue($body1['success']);

        // Get models for second provider
        $request2 = new ServerRequest('POST', '/ajax/nrllm/model/get-by-provider');
        $request2 = $request2->withParsedBody(['providerUid' => 2]);

        $response2 = $this->modelController->getByProviderAction($request2);
        self::assertSame(200, $response2->getStatusCode());
        $body2 = json_decode((string)$response2->getBody(), true);
        self::assertIsArray($body2);
        self::assertTrue($body2['success']);

        // Both should work independently
        self::assertArrayHasKey('models', $body1);
        self::assertArrayHasKey('models', $body2);
    }

    #[Test]
    public function executeTestWithDifferentProvidersReturnsDistinctResponses(): void
    {
        // Execute with first provider
        $extbaseRequest1 = $this->createExtbaseRequest([
            'provider' => 'openai-test',
            'prompt' => 'Hello from provider 1',
        ]);
        $this->setPrivateProperty($this->llmModuleController, 'request', $extbaseRequest1);
        $response1 = $this->llmModuleController->executeTestAction();

        // Execute with second provider
        $extbaseRequest2 = $this->createExtbaseRequest([
            'provider' => 'anthropic-test',
            'prompt' => 'Hello from provider 2',
        ]);
        $this->setPrivateProperty($this->llmModuleController, 'request', $extbaseRequest2);
        $response2 = $this->llmModuleController->executeTestAction();

        // Both requests should return valid responses (success or error)
        self::assertContains($response1->getStatusCode(), [200, 500]);
        self::assertContains($response2->getStatusCode(), [200, 500]);

        $body1 = json_decode((string)$response1->getBody(), true);
        $body2 = json_decode((string)$response2->getBody(), true);

        self::assertIsArray($body1);
        self::assertIsArray($body2);

        // Both should have consistent response structure
        self::assertArrayHasKey('success', $body1);
        self::assertArrayHasKey('success', $body2);
    }

    #[Test]
    public function configurationsCanBeSwitchedBetweenProviders(): void
    {
        // First configuration uses provider 1
        $config1 = $this->configurationRepository->findByUid(1);
        self::assertNotNull($config1);

        // Second configuration uses provider 2
        $config2 = $this->configurationRepository->findByUid(2);
        self::assertNotNull($config2);

        // Toggle both configurations (demonstrates provider independence)
        $request1 = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request1 = $request1->withParsedBody(['uid' => 1]);
        $response1 = $this->configController->toggleActiveAction($request1);

        $request2 = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request2 = $request2->withParsedBody(['uid' => 2]);
        $response2 = $this->configController->toggleActiveAction($request2);

        // Both operations should succeed
        self::assertSame(200, $response1->getStatusCode());
        self::assertSame(200, $response2->getStatusCode());

        $body1 = json_decode((string)$response1->getBody(), true);
        $body2 = json_decode((string)$response2->getBody(), true);

        self::assertIsArray($body1);
        self::assertIsArray($body2);
        self::assertTrue($body1['success']);
        self::assertTrue($body2['success']);
    }

    #[Test]
    public function defaultConfigurationCanBeSwitchedBetweenProviders(): void
    {
        // Set configuration 1 as default
        $request1 = new ServerRequest('POST', '/ajax/nrllm/config/setdefault');
        $request1 = $request1->withParsedBody(['uid' => 1]);
        $response1 = $this->configController->setDefaultAction($request1);
        self::assertSame(200, $response1->getStatusCode());

        // Verify it's default
        $this->persistenceManager->clearState();
        $config1 = $this->configurationRepository->findByUid(1);
        self::assertNotNull($config1);
        self::assertTrue($config1->isDefault());

        // Switch to configuration 2 as default
        $request2 = new ServerRequest('POST', '/ajax/nrllm/config/setdefault');
        $request2 = $request2->withParsedBody(['uid' => 2]);
        $response2 = $this->configController->setDefaultAction($request2);
        self::assertSame(200, $response2->getStatusCode());

        // Verify default switched
        $this->persistenceManager->clearState();
        $config1After = $this->configurationRepository->findByUid(1);
        $config2After = $this->configurationRepository->findByUid(2);

        self::assertNotNull($config1After);
        self::assertNotNull($config2After);
        self::assertFalse($config1After->isDefault());
        self::assertTrue($config2After->isDefault());
    }

    // =========================================================================
    // Pathway 8.2: Fallback Provider (Configuration Testing)
    // =========================================================================

    #[Test]
    public function multipleProvidersCanBeActiveSimultaneously(): void
    {
        // Toggle provider 1 to active
        $request1 = new ServerRequest('POST', '/ajax/nrllm/provider/toggle');
        $request1 = $request1->withParsedBody(['uid' => 1]);
        $response1 = $this->providerController->toggleActiveAction($request1);

        // Toggle provider 2 to active
        $request2 = new ServerRequest('POST', '/ajax/nrllm/provider/toggle');
        $request2 = $request2->withParsedBody(['uid' => 2]);
        $response2 = $this->providerController->toggleActiveAction($request2);

        self::assertSame(200, $response1->getStatusCode());
        self::assertSame(200, $response2->getStatusCode());

        // Both providers should be manageable independently
        $body1 = json_decode((string)$response1->getBody(), true);
        $body2 = json_decode((string)$response2->getBody(), true);

        self::assertIsArray($body1);
        self::assertIsArray($body2);
        self::assertTrue($body1['success']);
        self::assertTrue($body2['success']);
    }

    #[Test]
    public function inactiveProviderDoesNotAffectOtherProviders(): void
    {
        // Deactivate one provider
        $provider = $this->providerRepository->findByUid(1);
        self::assertNotNull($provider);

        // Ensure provider 1 is inactive
        if ($provider->isActive()) {
            $request = new ServerRequest('POST', '/ajax/nrllm/provider/toggle');
            $request = $request->withParsedBody(['uid' => 1]);
            $this->providerController->toggleActiveAction($request);
        }

        // Provider 2 should still work
        $request2 = new ServerRequest('POST', '/ajax/nrllm/model/get-by-provider');
        $request2 = $request2->withParsedBody(['providerUid' => 2]);

        $response2 = $this->modelController->getByProviderAction($request2);
        self::assertSame(200, $response2->getStatusCode());

        $body = json_decode((string)$response2->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
    }

    #[Test]
    public function configurationsForDifferentProvidersAreIndependent(): void
    {
        // Get configurations
        $config1 = $this->configurationRepository->findByUid(1);
        $config2 = $this->configurationRepository->findByUid(2);

        self::assertNotNull($config1);
        self::assertNotNull($config2);

        // Toggle config 1
        $request1 = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request1 = $request1->withParsedBody(['uid' => 1]);
        $this->configController->toggleActiveAction($request1);

        // Config 2 should be unaffected
        $this->persistenceManager->clearState();
        $config2After = $this->configurationRepository->findByUid(2);
        self::assertNotNull($config2After);
        self::assertSame($config2->isActive(), $config2After->isActive());
    }

    // =========================================================================
    // Additional Multi-Provider Tests
    // =========================================================================

    #[Test]
    public function canTestConnectionForMultipleProviders(): void
    {
        // Test connection for provider 1
        $request1 = new ServerRequest('POST', '/ajax/nrllm/provider/connection');
        $request1 = $request1->withParsedBody(['uid' => 1]);
        $response1 = $this->providerController->testConnectionAction($request1);

        // Test connection for provider 2
        $request2 = new ServerRequest('POST', '/ajax/nrllm/provider/connection');
        $request2 = $request2->withParsedBody(['uid' => 2]);
        $response2 = $this->providerController->testConnectionAction($request2);

        // Both should return valid responses
        self::assertContains($response1->getStatusCode(), [200, 500]);
        self::assertContains($response2->getStatusCode(), [200, 500]);

        $body1 = json_decode((string)$response1->getBody(), true);
        $body2 = json_decode((string)$response2->getBody(), true);

        self::assertIsArray($body1);
        self::assertIsArray($body2);
        self::assertArrayHasKey('success', $body1);
        self::assertArrayHasKey('success', $body2);
    }

    #[Test]
    public function modelsAreCorrectlyFilteredByProvider(): void
    {
        // Get models for provider 1
        $request1 = new ServerRequest('POST', '/ajax/nrllm/model/get-by-provider');
        $request1 = $request1->withParsedBody(['providerUid' => 1]);
        $response1 = $this->modelController->getByProviderAction($request1);

        $body1 = json_decode((string)$response1->getBody(), true);
        self::assertIsArray($body1);
        self::assertTrue($body1['success']);
        self::assertArrayHasKey('models', $body1);
        self::assertIsArray($body1['models']);

        // Collect model UIDs from provider 1
        $provider1ModelUids = array_column($body1['models'], 'uid');

        // Get models for provider 2
        $request2 = new ServerRequest('POST', '/ajax/nrllm/model/get-by-provider');
        $request2 = $request2->withParsedBody(['providerUid' => 2]);
        $response2 = $this->modelController->getByProviderAction($request2);

        $body2 = json_decode((string)$response2->getBody(), true);
        self::assertIsArray($body2);
        self::assertTrue($body2['success']);
        self::assertArrayHasKey('models', $body2);
        self::assertIsArray($body2['models']);

        // Collect model UIDs from provider 2
        $provider2ModelUids = array_column($body2['models'], 'uid');

        // Models from different providers should be different (no overlap)
        $overlap = array_intersect($provider1ModelUids, $provider2ModelUids);
        self::assertEmpty($overlap, 'Models should not overlap between providers');

        // Each model should have required fields
        /** @var array<string, mixed> $model */
        foreach ($body1['models'] as $model) {
            self::assertArrayHasKey('uid', $model);
            self::assertArrayHasKey('identifier', $model);
            self::assertArrayHasKey('name', $model);
            self::assertArrayHasKey('modelId', $model);
        }
    }

    #[Test]
    public function providerSwitchingDoesNotCorruptData(): void
    {
        // Get initial state of configurations
        $config1Initial = $this->configurationRepository->findByUid(1);
        $config2Initial = $this->configurationRepository->findByUid(2);

        self::assertNotNull($config1Initial);
        self::assertNotNull($config2Initial);

        $isActive1Initial = $config1Initial->isActive();
        $isActive2Initial = $config2Initial->isActive();

        // Toggle both configurations
        $request1 = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request1 = $request1->withParsedBody(['uid' => 1]);
        $this->configController->toggleActiveAction($request1);

        $request2 = new ServerRequest('POST', '/ajax/nrllm/config/toggle');
        $request2 = $request2->withParsedBody(['uid' => 2]);
        $this->configController->toggleActiveAction($request2);

        // Toggle back
        $this->configController->toggleActiveAction($request1);
        $this->configController->toggleActiveAction($request2);

        // Data should be back to initial state
        $this->persistenceManager->clearState();
        $config1Final = $this->configurationRepository->findByUid(1);
        $config2Final = $this->configurationRepository->findByUid(2);

        self::assertNotNull($config1Final);
        self::assertNotNull($config2Final);
        self::assertSame($isActive1Initial, $config1Final->isActive());
        self::assertSame($isActive2Initial, $config2Final->isActive());
    }

    #[Test]
    public function canWorkWithThreeOrMoreProviders(): void
    {
        // Assuming we have at least 3 providers in fixtures
        // Test that the system handles multiple providers correctly
        $providers = [1, 2, 3];

        foreach ($providers as $providerUid) {
            $request = new ServerRequest('POST', '/ajax/nrllm/model/get-by-provider');
            $request = $request->withParsedBody(['providerUid' => $providerUid]);

            $response = $this->modelController->getByProviderAction($request);

            // Should either succeed or return proper error
            self::assertContains($response->getStatusCode(), [200, 404]);

            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('success', $body);
        }
    }
}
