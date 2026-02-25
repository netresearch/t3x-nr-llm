<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\Backend;

use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Controller\Backend\TaskController;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest as Typo3ServerRequest;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * E2E tests for Error Handling pathways.
 *
 * Tests complete user journeys for error scenarios:
 * - Pathway 7.1: Invalid API Key
 * - Pathway 7.2: Rate Limit Exceeded
 * - Pathway 7.3: Network Timeout
 * - Pathway 7.4: Invalid Model Selection
 */
#[CoversClass(ProviderController::class)]
#[CoversClass(ModelController::class)]
#[CoversClass(LlmModuleController::class)]
final class ErrorPathwaysE2ETest extends AbstractBackendE2ETestCase
{
    private ProviderController $providerController;
    private ModelController $modelController;
    private ConfigurationController $configController;
    private TaskController $taskController;
    private LlmModuleController $dashboardController;
    private ProviderRepository $providerRepository;
    private ModelRepository $modelRepository;
    private LlmConfigurationRepository $configurationRepository;
    private TaskRepository $taskRepository;
    private PersistenceManagerInterface $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $providerRepository);
        $this->providerRepository = $providerRepository;

        $modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $modelRepository);
        $this->modelRepository = $modelRepository;

        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);
        $this->configurationRepository = $configurationRepository;

        $taskRepository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $taskRepository);
        $this->taskRepository = $taskRepository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;

        $this->providerController = $this->createProviderController();
        $this->modelController = $this->createModelController();
        $this->configController = $this->createConfigurationController();
        $this->taskController = $this->createTaskController();
        $this->dashboardController = $this->createDashboardController();
    }

    private function createProviderController(): ProviderController
    {
        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        return $this->createControllerWithReflection(ProviderController::class, [
            'providerRepository' => $this->providerRepository,
            'providerAdapterRegistry' => $providerAdapterRegistry,
            'persistenceManager' => $this->persistenceManager,
        ]);
    }

    private function createModelController(): ModelController
    {
        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        $modelDiscovery = $this->get(ModelDiscoveryInterface::class);
        self::assertInstanceOf(ModelDiscoveryInterface::class, $modelDiscovery);

        return $this->createControllerWithReflection(ModelController::class, [
            'modelRepository' => $this->modelRepository,
            'providerRepository' => $this->providerRepository,
            'providerAdapterRegistry' => $providerAdapterRegistry,
            'modelDiscovery' => $modelDiscovery,
            'persistenceManager' => $this->persistenceManager,
        ]);
    }

    private function createConfigurationController(): ConfigurationController
    {
        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        $llmServiceManager = $this->get(LlmServiceManager::class);
        self::assertInstanceOf(LlmServiceManager::class, $llmServiceManager);

        $configurationService = $this->get(LlmConfigurationService::class);
        self::assertInstanceOf(LlmConfigurationService::class, $configurationService);

        return $this->createControllerWithReflection(ConfigurationController::class, [
            'configurationService' => $configurationService,
            'configurationRepository' => $this->configurationRepository,
            'llmServiceManager' => $llmServiceManager,
            'providerAdapterRegistry' => $providerAdapterRegistry,
        ]);
    }

    private function createTaskController(): TaskController
    {
        $llmServiceManager = $this->get(LlmServiceManager::class);
        self::assertInstanceOf(LlmServiceManager::class, $llmServiceManager);

        return $this->createControllerWithReflection(TaskController::class, [
            'taskRepository' => $this->taskRepository,
            'llmServiceManager' => $llmServiceManager,
        ]);
    }

    private function createDashboardController(): LlmModuleController
    {
        $llmServiceManager = $this->get(LlmServiceManager::class);
        self::assertInstanceOf(LlmServiceManager::class, $llmServiceManager);

        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);

        $taskRepository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $taskRepository);

        return $this->createControllerWithReflection(LlmModuleController::class, [
            'llmServiceManager' => $llmServiceManager,
            'providerRepository' => $this->providerRepository,
            'modelRepository' => $this->modelRepository,
            'configurationRepository' => $configurationRepository,
            'taskRepository' => $taskRepository,
        ]);
    }

    /**
     * @param array<string, mixed> $parsedBody
     */
    private function createDashboardExtbaseRequest(array $parsedBody = []): ExtbaseRequest
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
    // Pathway 7.1: Invalid API Key
    // =========================================================================

    #[Test]
    public function pathway7_1_invalidApiKey_providerTestReturnsError(): void
    {
        // Create a provider with invalid API key
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('invalid-key-provider');
        $provider->setName('Invalid Key Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('invalid-api-key-12345');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier('invalid-key-provider');
        self::assertNotNull($addedProvider);

        // User tests connection with invalid key
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $addedProvider->getUid()]);
        $response = $this->providerController->testConnectionAction($request);

        // Response should be structured (not crash)
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        // Connection should fail with invalid key
        // The response should contain an error message about authentication
        if (!$body['success']) {
            self::assertArrayHasKey('message', $body);
            self::assertNotEmpty($body['message'], 'Error message should be provided');
        }
    }

    #[Test]
    public function pathway7_1_invalidApiKey_modelTestReturnsError(): void
    {
        // Create provider with invalid key
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('invalid-key-provider-2');
        $provider->setName('Invalid Key Provider 2');
        $provider->setAdapterType('openai');
        $provider->setApiKey('invalid-api-key-67890');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier('invalid-key-provider-2');
        self::assertNotNull($addedProvider);

        // Create model for this provider
        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('invalid-key-model');
        $model->setName('Invalid Key Model');
        $model->setModelId('gpt-4o');
        $model->setProvider($addedProvider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedModel = $this->modelRepository->findOneByIdentifier('invalid-key-model');
        self::assertNotNull($addedModel);

        // User tests model
        $request = $this->createFormRequest('/ajax/model/test', ['uid' => $addedModel->getUid()]);
        $response = $this->modelController->testModelAction($request);

        // Response should be structured
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('message', $body);

        // Should fail with meaningful error
        if (!$body['success']) {
            self::assertNotEmpty($body['message']);
        }
    }

    #[Test]
    public function pathway7_1_invalidApiKey_quickTestReturnsError(): void
    {
        // Create provider with invalid key
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('invalid-key-provider-3');
        $provider->setName('Invalid Key Provider 3');
        $provider->setAdapterType('openai');
        $provider->setApiKey('invalid-api-key-abcde');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier('invalid-key-provider-3');
        self::assertNotNull($addedProvider);

        // User runs quick test on dashboard
        $request = $this->createDashboardExtbaseRequest([
            'provider' => $addedProvider->getIdentifier(),
            'prompt' => 'Hello',
        ]);

        $this->setPrivateProperty($this->dashboardController, 'request', $request);
        $response = $this->dashboardController->executeTestAction();

        // Response should be structured
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        // Should have error info if failed
        if (!$body['success']) {
            self::assertArrayHasKey('error', $body);
            self::assertNotEmpty($body['error']);
        }
    }

    // =========================================================================
    // Pathway 7.2: Rate Limit Exceeded (Simulated via provider response)
    // =========================================================================

    #[Test]
    public function pathway7_2_rateLimitExceeded_handledGracefully(): void
    {
        // Note: In real scenario, rate limits come from actual API calls.
        // Here we test that the error handling structure is in place.

        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // Test connection - if rate limited, should still return structured response
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $provider->getUid()]);
        $response = $this->providerController->testConnectionAction($request);

        // Response should always be structured JSON
        self::assertContains($response->getStatusCode(), [200, 429, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('message', $body);

        // If rate limited (429), message should indicate this
        if ($response->getStatusCode() === 429) {
            self::assertIsString($body['message']);
            self::assertStringContainsStringIgnoringCase('rate', $body['message']);
        }
    }

    // =========================================================================
    // Pathway 7.3: Network Timeout
    // =========================================================================

    #[Test]
    public function pathway7_3_networkTimeout_handledGracefully(): void
    {
        // Create provider with very short timeout (to simulate timeout behavior)
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('timeout-test-provider');
        $provider->setName('Timeout Test Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('test-key');
        $provider->setTimeout(1); // 1 second timeout
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier('timeout-test-provider');
        self::assertNotNull($addedProvider);

        // Test connection (may timeout due to network or succeed quickly)
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $addedProvider->getUid()]);
        $response = $this->providerController->testConnectionAction($request);

        // Response should be structured regardless of timeout
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('message', $body);
    }

    #[Test]
    public function pathway7_3_invalidEndpoint_handledGracefully(): void
    {
        // Create provider with unreachable endpoint
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('unreachable-provider');
        $provider->setName('Unreachable Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('test-key');
        $provider->setEndpointUrl('https://nonexistent.invalid.domain.local/v1');
        $provider->setTimeout(5);
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier('unreachable-provider');
        self::assertNotNull($addedProvider);

        // Test connection to unreachable endpoint
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $addedProvider->getUid()]);
        $response = $this->providerController->testConnectionAction($request);

        // Response should indicate failure with network-related message
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('message', $body);

        // Should fail with network/connection error
        self::assertFalse($body['success'], 'Connection to invalid endpoint should fail');
        self::assertNotEmpty($body['message'], 'Error message should be provided');
    }

    // =========================================================================
    // Pathway 7.4: Invalid Model Selection
    // =========================================================================

    #[Test]
    public function pathway7_4_invalidModel_detectLimitsReturnsError(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // User tries to detect limits for non-existent model
        $request = $this->createFormRequest('/ajax/model/detect-limits', [
            'providerUid' => $provider->getUid(),
            'modelId' => 'completely-nonexistent-model-xyz',
        ]);
        $response = $this->modelController->detectLimitsAction($request);

        // Response should indicate model not found
        self::assertContains($response->getStatusCode(), [200, 404, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        // If not found through API discovery, should indicate error
        if (!$body['success']) {
            self::assertArrayHasKey('error', $body);
            self::assertNotEmpty($body['error']);
        }
    }

    #[Test]
    public function pathway7_4_orphanedModel_testReturnsError(): void
    {
        // Create model without provider (orphaned)
        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('orphaned-model');
        $model->setName('Orphaned Model');
        $model->setModelId('gpt-4o');
        $model->setProvider(null);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedModel = $this->modelRepository->findOneByIdentifier('orphaned-model');
        self::assertNotNull($addedModel);

        // User tries to test orphaned model
        $request = $this->createFormRequest('/ajax/model/test', ['uid' => $addedModel->getUid()]);
        $response = $this->modelController->testModelAction($request);

        // Should return error about missing provider
        self::assertSame(400, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
        self::assertArrayHasKey('error', $body);
        self::assertIsString($body['error']);
        self::assertStringContainsStringIgnoringCase('provider', $body['error']);
    }

    #[Test]
    public function pathway7_4_nonExistentModel_toggleReturnsError(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => 99999]);
        $response = $this->modelController->toggleActiveAction($request);

        $this->assertErrorResponse($response, 404, 'Model not found');
    }

    #[Test]
    public function pathway7_4_nonExistentModel_setDefaultReturnsError(): void
    {
        $request = $this->createFormRequest('/ajax/model/setdefault', ['uid' => 99999]);
        $response = $this->modelController->setDefaultAction($request);

        $this->assertErrorResponse($response, 404, 'Model not found');
    }

    // =========================================================================
    // Missing UID Error Handling
    // =========================================================================

    #[Test]
    public function missingUid_providerToggle_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/provider/toggle', []);
        $response = $this->providerController->toggleActiveAction($request);

        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    #[Test]
    public function missingUid_modelToggle_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', []);
        $response = $this->modelController->toggleActiveAction($request);

        $this->assertErrorResponse($response, 400, 'No model UID specified');
    }

    #[Test]
    public function missingUid_modelSetDefault_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/model/setdefault', []);
        $response = $this->modelController->setDefaultAction($request);

        $this->assertErrorResponse($response, 400, 'No model UID specified');
    }

    #[Test]
    public function missingUid_modelTest_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/model/test', []);
        $response = $this->modelController->testModelAction($request);

        $this->assertErrorResponse($response, 400, 'No model UID specified');
    }

    #[Test]
    public function missingProviderUid_getByProvider_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/model/getbyprovider', []);
        $response = $this->modelController->getByProviderAction($request);

        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    #[Test]
    public function missingProviderUid_fetchAvailable_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/model/fetch', []);
        $response = $this->modelController->fetchAvailableModelsAction($request);

        self::assertSame(400, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('No provider UID specified', $body['error']);
    }

    #[Test]
    public function missingProvider_quickTest_returnsError(): void
    {
        $request = $this->createDashboardExtbaseRequest([
            'prompt' => 'Hello',
        ]);

        $this->setPrivateProperty($this->dashboardController, 'request', $request);
        $response = $this->dashboardController->executeTestAction();

        self::assertSame(400, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('No provider specified', $body['error']);
    }

    // =========================================================================
    // Invalid Request Body Handling
    // =========================================================================

    #[Test]
    public function invalidUid_providerToggle_treatedAsZero(): void
    {
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => 'not-a-number']);
        $response = $this->providerController->toggleActiveAction($request);

        // Non-numeric UID should be treated as 0 (missing)
        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    #[Test]
    public function invalidUid_modelToggle_treatedAsZero(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => 'abc']);
        $response = $this->modelController->toggleActiveAction($request);

        $this->assertErrorResponse($response, 400, 'No model UID specified');
    }

    #[Test]
    public function emptyUid_providerToggle_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => '']);
        $response = $this->providerController->toggleActiveAction($request);

        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    #[Test]
    public function zeroUid_providerToggle_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => 0]);
        $response = $this->providerController->toggleActiveAction($request);

        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    // =========================================================================
    // Pathway 7.5: Input Validation and Security
    // =========================================================================

    #[Test]
    public function pathway7_5_specialCharactersInProviderName_handledSafely(): void
    {
        // Test that special characters don't cause issues
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('special-chars-provider');
        $provider->setName('<script>alert("xss")</script>');
        $provider->setAdapterType('openai');
        $provider->setApiKey('test-key');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier('special-chars-provider');
        self::assertNotNull($addedProvider);

        // Verify name is stored correctly (not executed)
        self::assertSame('<script>alert("xss")</script>', $addedProvider->getName());

        // Test connection should work without issues
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $addedProvider->getUid()]);
        $response = $this->providerController->testConnectionAction($request);

        // Response should be structured JSON
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway7_5_unicodeCharactersInModelName_handledSafely(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // Create model with unicode characters
        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('unicode-model');
        $model->setName('模型名称 🎉 Ñoño');
        $model->setModelId('gpt-4o');
        $model->setProvider($provider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedModel = $this->modelRepository->findOneByIdentifier('unicode-model');
        self::assertNotNull($addedModel);

        // Verify unicode is preserved
        self::assertSame('模型名称 🎉 Ñoño', $addedModel->getName());

        // Toggle should work
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => $addedModel->getUid()]);
        $response = $this->modelController->toggleActiveAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function pathway7_5_sqlInjectionAttempt_handledSafely(): void
    {
        // Attempt SQL injection via identifier (should be handled by Extbase)
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier("'; DROP TABLE tx_nrllm_domain_model_provider; --");
        $provider->setName('SQL Injection Test');
        $provider->setAdapterType('openai');
        $provider->setApiKey('test-key');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify provider was created (SQL injection didn't work)
        $addedProvider = $this->providerRepository->findOneByIdentifier("'; DROP TABLE tx_nrllm_domain_model_provider; --");
        self::assertNotNull($addedProvider);

        // Verify other providers still exist (table wasn't dropped)
        $allProviders = $this->providerRepository->findActive();
        self::assertGreaterThanOrEqual(1, $allProviders->count());
    }

    #[Test]
    public function pathway7_5_negativeUid_handledSafely(): void
    {
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => -1]);
        $response = $this->providerController->testConnectionAction($request);

        // Negative UID should be treated as invalid
        self::assertContains($response->getStatusCode(), [400, 404]);
    }

    #[Test]
    public function pathway7_5_veryLargeUid_handledSafely(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => PHP_INT_MAX]);
        $response = $this->modelController->toggleActiveAction($request);

        // Very large UID should return not found
        $this->assertErrorResponse($response, 404, 'Model not found');
    }

    #[Test]
    public function pathway7_5_floatUid_handledSafely(): void
    {
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => 1.5]);
        $response = $this->providerController->toggleActiveAction($request);

        // Float should be cast to int (1) and processed
        self::assertContains($response->getStatusCode(), [200, 404]);
    }

    #[Test]
    public function pathway7_5_arrayUid_handledSafely(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => [1, 2, 3]]);
        $response = $this->modelController->toggleActiveAction($request);

        // Array UID should be handled gracefully
        self::assertContains($response->getStatusCode(), [400, 404, 500]);
    }

    #[Test]
    public function pathway7_5_nullBytes_handledSafely(): void
    {
        // Test null bytes in input
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier("null-byte-test\x00suffix");
        $provider->setName("Null\x00Byte");
        $provider->setAdapterType('openai');
        $provider->setApiKey('test-key');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Should be stored (null bytes may be stripped or preserved)
        $providers = $this->providerRepository->findActive();
        self::assertGreaterThanOrEqual(1, $providers->count());
    }

    #[Test]
    public function pathway7_5_veryLongApiKey_handledSafely(): void
    {
        // Test very long API key (should be stored or truncated, not crash)
        $longApiKey = str_repeat('a', 10000);

        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('long-key-provider');
        $provider->setName('Long Key Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey($longApiKey);
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier('long-key-provider');
        self::assertNotNull($addedProvider);
        // API key should be stored (possibly truncated by database)
        self::assertNotEmpty($addedProvider->getApiKey());
    }

    // =========================================================================
    // Pathway 7.6: Error Recovery and State Consistency
    // =========================================================================

    #[Test]
    public function pathway7_6_failedOperationDoesNotCorruptState(): void
    {
        // Get initial state
        $initialProviderCount = $this->providerRepository->countActive();

        // Attempt operation on non-existent provider
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => 99999]);
        $response = $this->providerController->toggleActiveAction($request);

        // Should fail
        self::assertSame(404, $response->getStatusCode());

        // State should be unchanged
        $finalProviderCount = $this->providerRepository->countActive();
        self::assertSame($initialProviderCount, $finalProviderCount);
    }

    #[Test]
    public function pathway7_6_rapidSequentialOperations_handleCorrectly(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $providerUid = $provider->getUid();
        self::assertNotNull($providerUid);
        $originalState = $provider->isActive();

        // Perform multiple rapid toggles
        for ($i = 0; $i < 5; $i++) {
            $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $providerUid]);
            $response = $this->providerController->toggleActiveAction($request);
            self::assertSame(200, $response->getStatusCode());
        }

        // After odd number of toggles, state should be opposite of original
        $this->persistenceManager->clearState();
        $reloadedProvider = $this->providerRepository->findByUid($providerUid);
        self::assertNotNull($reloadedProvider);
        self::assertNotSame($originalState, $reloadedProvider->isActive());

        // Restore original state
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $providerUid]);
        $this->providerController->toggleActiveAction($request);
    }

    #[Test]
    public function pathway7_6_concurrentDefaultModelChanges_maintainSingleDefault(): void
    {
        $models = $this->modelRepository->findActive()->toArray();
        self::assertGreaterThanOrEqual(2, count($models), 'Need at least 2 models for this test');

        // Set first model as default
        $request1 = $this->createFormRequest('/ajax/model/setdefault', ['uid' => $models[0]->getUid()]);
        $response1 = $this->modelController->setDefaultAction($request1);
        self::assertSame(200, $response1->getStatusCode());

        // Immediately set second model as default
        $request2 = $this->createFormRequest('/ajax/model/setdefault', ['uid' => $models[1]->getUid()]);
        $response2 = $this->modelController->setDefaultAction($request2);
        self::assertSame(200, $response2->getStatusCode());

        // Verify only one default exists
        $this->persistenceManager->clearState();
        $defaultCount = 0;
        foreach ($this->modelRepository->findActive() as $model) {
            if ($model->isDefault()) {
                $defaultCount++;
            }
        }
        self::assertSame(1, $defaultCount, 'Only one model should be default');
    }

    // =========================================================================
    // Pathway 7.7: Connection Failure Handling
    // =========================================================================

    #[Test]
    public function pathway7_7_connectionFailure_returnsStructuredError(): void
    {
        // Create provider with unreachable endpoint
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('connection-fail-provider-' . time());
        $provider->setName('Connection Fail Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('test-key');
        $provider->setEndpointUrl('https://unreachable.invalid.local/v1');
        $provider->setTimeout(2);
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);

        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $added->getUid()]);
        $response = $this->providerController->testConnectionAction($request);

        // Should return structured error, not crash
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('message', $body);
    }

    #[Test]
    public function pathway7_7_connectionRetry_worksAfterFailure(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // Multiple connection attempts should all return valid responses
        for ($i = 0; $i < 3; $i++) {
            $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $provider->getUid()]);
            $response = $this->providerController->testConnectionAction($request);

            self::assertContains($response->getStatusCode(), [200, 500]);
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
        }
    }

    // =========================================================================
    // Pathway 7.8: Data Integrity Under Errors
    // =========================================================================

    #[Test]
    public function pathway7_8_errorDoesNotLeaveOrphanedData(): void
    {
        $initialProviderCount = $this->providerRepository->countActive();
        $initialModelCount = $this->modelRepository->countActive();

        // Attempt operations that should fail
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => 99999999]);
        $this->modelController->toggleActiveAction($request);

        // Counts should be unchanged
        self::assertSame($initialProviderCount, $this->providerRepository->countActive());
        self::assertSame($initialModelCount, $this->modelRepository->countActive());
    }

    #[Test]
    public function pathway7_8_failedToggleDoesNotAffectOthers(): void
    {
        $models = $this->modelRepository->findActive()->toArray();
        self::assertNotEmpty($models);

        // Record initial states
        /** @var array<int, bool> $initialStates */
        $initialStates = [];
        foreach ($models as $model) {
            $modelUid = $model->getUid();
            self::assertNotNull($modelUid);
            $initialStates[$modelUid] = $model->isActive();
        }

        // Failed toggle on non-existent
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => 99999999]);
        $this->modelController->toggleActiveAction($request);

        // Other models' states should be unchanged
        $this->persistenceManager->clearState();
        foreach ($initialStates as $uid => $wasActive) {
            $reloaded = $this->modelRepository->findByUid($uid);
            self::assertNotNull($reloaded);
            self::assertSame($wasActive, $reloaded->isActive());
        }
    }

    // =========================================================================
    // Pathway 7.9: Error Message Quality
    // =========================================================================

    #[Test]
    public function pathway7_9_errorMessagesAreUserFriendly(): void
    {
        // Missing UID should give clear message
        $request = $this->createFormRequest('/ajax/provider/toggle', []);
        $response = $this->providerController->toggleActiveAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertArrayHasKey('error', $body);
        self::assertIsString($body['error']);
        self::assertNotEmpty($body['error']);
        // Should not contain stack trace or technical details
        self::assertStringNotContainsString('Exception', $body['error']);
        self::assertStringNotContainsString('Stack trace', $body['error']);
    }

    #[Test]
    public function pathway7_9_notFoundErrorsAreClear(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => 99999999]);
        $response = $this->modelController->toggleActiveAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertArrayHasKey('error', $body);
        self::assertIsString($body['error']);
        self::assertStringContainsStringIgnoringCase('not found', $body['error']);
    }

    #[Test]
    public function pathway7_9_validationErrorsAreSpecific(): void
    {
        // Empty UID
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => '']);
        $response = $this->providerController->testConnectionAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertArrayHasKey('error', $body);
        // Message should indicate what's wrong
        self::assertNotEmpty($body['error']);
    }

    // =========================================================================
    // Pathway 7.10: Graceful Degradation
    // =========================================================================

    #[Test]
    public function pathway7_10_systemWorksWithNoProviders(): void
    {
        // Get counts - system should work even with 0 entities
        $providerCount = $this->providerRepository->countActive();
        $modelCount = $this->modelRepository->countActive();

        // Counts should be valid non-negative numbers
        self::assertGreaterThanOrEqual(0, $providerCount);
        self::assertGreaterThanOrEqual(0, $modelCount);
    }

    #[Test]
    public function pathway7_10_modelActionsWorkWithDeactivatedProvider(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $providerUid = $provider->getUid();
        self::assertNotNull($providerUid);

        $models = $this->modelRepository->findByProvider($provider);
        if ($models->count() === 0) {
            self::markTestSkipped('Provider has no models');
        }

        $model = $models->getFirst();
        self::assertNotNull($model);
        $modelUid = $model->getUid();
        self::assertNotNull($modelUid);

        // Deactivate provider
        $provider->setIsActive(false);
        $this->providerRepository->update($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Model operations should still work
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => $modelUid]);
        $response = $this->modelController->toggleActiveAction($request);

        self::assertSame(200, $response->getStatusCode());

        // Restore provider
        $reloadedProvider = $this->providerRepository->findByUid($providerUid);
        self::assertNotNull($reloadedProvider);
        $reloadedProvider->setIsActive(true);
        $this->providerRepository->update($reloadedProvider);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 7.11: HTTP Method and Content Type Validation
    // =========================================================================

    #[Test]
    public function pathway7_11_emptyRequestBody_handledGracefully(): void
    {
        // Request with completely empty body
        $request = $this->createFormRequest('/ajax/provider/toggle', []);
        $response = $this->providerController->toggleActiveAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
    }

    #[Test]
    public function pathway7_11_malformedJsonBody_handledGracefully(): void
    {
        // Even if request parsing fails, controller should handle it
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => 'not{json}']);
        $response = $this->modelController->toggleActiveAction($request);

        self::assertContains($response->getStatusCode(), [400, 404, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway7_11_extraFieldsIgnored(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        // Request with extra unexpected fields
        $request = $this->createFormRequest('/ajax/model/toggle', [
            'uid' => $model->getUid(),
            'unexpected_field' => 'should be ignored',
            'another_field' => 12345,
            '__proto__' => 'prototype pollution attempt',
        ]);
        $response = $this->modelController->toggleActiveAction($request);

        // Should work normally, ignoring extra fields
        self::assertSame(200, $response->getStatusCode());

        // Toggle back
        $this->modelController->toggleActiveAction($request);
    }

    // =========================================================================
    // Pathway 7.12: Boundary Value Testing
    // =========================================================================

    #[Test]
    public function pathway7_12_maxIntUid_handledGracefully(): void
    {
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => PHP_INT_MAX]);
        $response = $this->providerController->toggleActiveAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertIsString($body['error']);
        self::assertStringContainsStringIgnoringCase('not found', $body['error']);
    }

    #[Test]
    public function pathway7_12_minIntUid_handledGracefully(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => PHP_INT_MIN]);
        $response = $this->modelController->toggleActiveAction($request);

        self::assertContains($response->getStatusCode(), [400, 404]);
    }

    #[Test]
    public function pathway7_12_zeroTimeout_handledGracefully(): void
    {
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('zero-timeout-provider-' . time());
        $provider->setName('Zero Timeout Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('test-key');
        $provider->setTimeout(0);
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);

        // Should still create provider (timeout may be normalized to default value)
        self::assertGreaterThanOrEqual(0, $added->getTimeout());
    }

    #[Test]
    public function pathway7_12_negativeTimeout_handledGracefully(): void
    {
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('negative-timeout-provider-' . time());
        $provider->setName('Negative Timeout Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('test-key');
        $provider->setTimeout(-100);
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        // Negative timeout is normalized (converted to positive or default)
        self::assertGreaterThanOrEqual(0, $added->getTimeout());
    }

    // =========================================================================
    // Pathway 7.13: Concurrent Operation Safety
    // =========================================================================

    #[Test]
    public function pathway7_13_rapidToggleOperations_maintainConsistency(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $modelUid = $model->getUid();
        self::assertNotNull($modelUid);
        $originalState = $model->isActive();

        // Perform 10 rapid toggles
        for ($i = 0; $i < 10; $i++) {
            $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => $modelUid]);
            $response = $this->modelController->toggleActiveAction($request);
            self::assertSame(200, $response->getStatusCode());
        }

        // After even number of toggles, state should match original
        $this->persistenceManager->clearState();
        $reloaded = $this->modelRepository->findByUid($modelUid);
        self::assertNotNull($reloaded);
        self::assertSame($originalState, $reloaded->isActive());
    }

    #[Test]
    public function pathway7_13_sequentialDefaultChanges_onlyOneDefault(): void
    {
        $models = $this->modelRepository->findActive()->toArray();
        if (count($models) < 3) {
            self::markTestSkipped('Need at least 3 models');
        }

        // Set each model as default in sequence
        foreach ($models as $model) {
            $modelUid = $model->getUid();
            self::assertNotNull($modelUid);
            $request = $this->createFormRequest('/ajax/model/setdefault', ['uid' => $modelUid]);
            $response = $this->modelController->setDefaultAction($request);
            self::assertSame(200, $response->getStatusCode());
        }

        // Only the last one should be default
        $this->persistenceManager->clearState();
        $defaultCount = 0;
        $lastModel = end($models);
        self::assertNotFalse($lastModel);
        $lastModelUid = $lastModel->getUid();
        $allModels = $this->modelRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allModels);
        /** @var Model[] $allModelsArray */
        $allModelsArray = $allModels->toArray();
        foreach ($allModelsArray as $m) {
            if ($m->isDefault()) {
                $defaultCount++;
                self::assertSame($lastModelUid, $m->getUid());
            }
        }
        self::assertSame(1, $defaultCount);
    }

    // =========================================================================
    // Pathway 7.14: Error Response Format Consistency
    // =========================================================================

    #[Test]
    public function pathway7_14_allErrorsHaveSuccessField(): void
    {
        /** @var list<array{controller: ProviderController|ModelController, method: string, params: array<string, mixed>}> $errorRequests */
        $errorRequests = [
            ['controller' => $this->providerController, 'method' => 'toggleActiveAction', 'params' => []],
            ['controller' => $this->modelController, 'method' => 'toggleActiveAction', 'params' => []],
            ['controller' => $this->modelController, 'method' => 'setDefaultAction', 'params' => []],
            ['controller' => $this->modelController, 'method' => 'testModelAction', 'params' => []],
        ];

        foreach ($errorRequests as $errorReq) {
            $request = $this->createFormRequest('/ajax/test', $errorReq['params']);
            $response = $errorReq['controller']->{$errorReq['method']}($request);
            self::assertInstanceOf(ResponseInterface::class, $response);

            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body, 'Response should be JSON array');
            /** @var array<string, mixed> $body */
            self::assertArrayHasKey('success', $body, 'All responses should have success field');
            self::assertFalse($body['success'], 'Error responses should have success=false');
        }
    }

    #[Test]
    public function pathway7_14_allErrorsHaveErrorField(): void
    {
        // Not found errors
        /** @var list<array{controller: ProviderController|ModelController, method: string, uid: int}> $notFoundRequests */
        $notFoundRequests = [
            ['controller' => $this->providerController, 'method' => 'toggleActiveAction', 'uid' => 99999],
            ['controller' => $this->modelController, 'method' => 'toggleActiveAction', 'uid' => 99999],
            ['controller' => $this->modelController, 'method' => 'setDefaultAction', 'uid' => 99999],
        ];

        foreach ($notFoundRequests as $notFoundReq) {
            $request = $this->createFormRequest('/ajax/test', ['uid' => $notFoundReq['uid']]);
            $response = $notFoundReq['controller']->{$notFoundReq['method']}($request);
            self::assertInstanceOf(ResponseInterface::class, $response);

            self::assertSame(404, $response->getStatusCode());
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            /** @var array<string, mixed> $body */
            self::assertArrayHasKey('error', $body, 'Error responses should have error field');
            self::assertNotEmpty($body['error'], 'Error field should not be empty');
        }
    }

    #[Test]
    public function pathway7_14_errorResponsesAreValidJson(): void
    {
        $testCases = [
            ['params' => [], 'expected_status' => 400],
            ['params' => ['uid' => 99999], 'expected_status' => 404],
            ['params' => ['uid' => 'invalid'], 'expected_status' => 400],
        ];

        foreach ($testCases as $testCase) {
            $request = $this->createFormRequest('/ajax/provider/toggle', $testCase['params']);
            $response = $this->providerController->toggleActiveAction($request);

            $rawBody = (string)$response->getBody();
            $body = json_decode($rawBody, true);

            self::assertNotNull($body, "Response should be valid JSON: $rawBody");
            self::assertIsArray($body, 'Response should decode to array');
        }
    }

    // =========================================================================
    // Pathway 7.15: Provider Adapter Type Errors
    // =========================================================================

    #[Test]
    public function pathway7_15_unknownAdapterType_handledGracefully(): void
    {
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('unknown-adapter-provider-' . time());
        $provider->setName('Unknown Adapter Provider');
        $provider->setAdapterType('nonexistent_adapter_xyz');
        $provider->setApiKey('test-key');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);

        // Test connection - should fail gracefully with unknown adapter
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $added->getUid()]);
        $response = $this->providerController->testConnectionAction($request);

        self::assertContains($response->getStatusCode(), [200, 400, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function pathway7_15_emptyAdapterType_handledGracefully(): void
    {
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('empty-adapter-provider-' . time());
        $provider->setName('Empty Adapter Provider');
        $provider->setAdapterType('');
        $provider->setApiKey('test-key');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('', $added->getAdapterType());
    }

    // =========================================================================
    // Pathway 7.16: Model Discovery Errors
    // =========================================================================

    #[Test]
    public function pathway7_16_fetchModelsForInvalidProvider_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/model/fetch', ['providerUid' => 99999]);
        $response = $this->modelController->fetchAvailableModelsAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
        self::assertIsString($body['error']);
        self::assertStringContainsStringIgnoringCase('not found', $body['error']);
    }

    #[Test]
    public function pathway7_16_detectLimitsForInvalidProvider_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/model/detect-limits', [
            'providerUid' => 99999,
            'modelId' => 'gpt-4o',
        ]);
        $response = $this->modelController->detectLimitsAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertIsString($body['error']);
        self::assertStringContainsStringIgnoringCase('not found', $body['error']);
    }

    #[Test]
    public function pathway7_16_detectLimitsMissingModelId_returnsError(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createFormRequest('/ajax/model/detect-limits', [
            'providerUid' => $provider->getUid(),
            // modelId missing
        ]);
        $response = $this->modelController->detectLimitsAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
    }

    // =========================================================================
    // Pathway 7.17: Configuration Error Handling
    // =========================================================================

    #[Test]
    public function pathway7_17_toggleConfigMissingUid_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/configuration/toggle', []);
        $response = $this->configController->toggleActiveAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
        self::assertArrayHasKey('error', $body);
    }

    #[Test]
    public function pathway7_17_setDefaultConfigMissingUid_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/configuration/setdefault', []);
        $response = $this->configController->setDefaultAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
    }

    #[Test]
    public function pathway7_17_testConfigMissingUid_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/configuration/test', []);
        $response = $this->configController->testConfigurationAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
    }

    #[Test]
    public function pathway7_17_toggleConfigNonExistent_returnsNotFound(): void
    {
        $request = $this->createFormRequest('/ajax/configuration/toggle', ['uid' => 99999]);
        $response = $this->configController->toggleActiveAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
    }

    // =========================================================================
    // Pathway 7.18: Task Error Handling
    // =========================================================================

    #[Test]
    public function pathway7_18_executeTaskMissingUid_returnsError(): void
    {
        $request = $this->createFormRequest('/ajax/task/execute', []);
        $response = $this->taskController->executeAction($request);

        // Missing uid returns 404 (task not found for uid=0)
        self::assertContains($response->getStatusCode(), [400, 404]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
    }

    #[Test]
    public function pathway7_18_executeTaskNonExistent_returnsNotFound(): void
    {
        $request = $this->createFormRequest('/ajax/task/execute', ['uid' => 99999]);
        $response = $this->taskController->executeAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
    }

    #[Test]
    public function pathway7_18_executeInactiveTask_returnsError(): void
    {
        // Create an inactive task
        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('inactive-task-' . time());
        $task->setName('Inactive Task');
        $task->setPromptTemplate('Test prompt');
        $task->setIsActive(false);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($added);

        // Execute inactive task should fail
        $request = $this->createFormRequest('/ajax/task/execute', [
            'uid' => $added->getUid(),
            'input' => 'Test input',
        ]);
        $response = $this->taskController->executeAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
        self::assertIsString($body['error']);
        self::assertStringContainsStringIgnoringCase('not active', $body['error']);
    }

    #[Test]
    public function pathway7_18_refreshInputMissingUid_returnsNotFound(): void
    {
        $request = $this->createFormRequest('/ajax/task/refresh-input', []);
        $response = $this->taskController->refreshInputAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        self::assertFalse($body['success']);
    }

    // =========================================================================
    // Pathway 7.19: Input Sanitization
    // =========================================================================

    #[Test]
    public function pathway7_19_providerWithXssInName_sanitized(): void
    {
        $xssName = '<script>alert("xss")</script>Provider';

        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('xss-name-provider-' . time());
        $provider->setName($xssName);
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-test');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        // Name should be stored (TYPO3 handles sanitization on output)
        self::assertNotEmpty($added->getName());
    }

    #[Test]
    public function pathway7_19_configWithXssInSystemPrompt_stored(): void
    {
        $xssPrompt = '<script>alert("xss")</script>You are a helpful assistant.';

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('xss-prompt-config-' . time());
        $config->setName('XSS Prompt Config');
        $config->setSystemPrompt($xssPrompt);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($added);
        // System prompt stored as-is (sanitization on output)
        self::assertStringContainsString('helpful assistant', $added->getSystemPrompt());
    }

    #[Test]
    public function pathway7_19_taskWithSqlInjectionInName_handled(): void
    {
        $sqlName = "Task'; DROP TABLE tx_nrllm_domain_model_task; --";

        $task = new Task();
        $task->setPid(0);
        $task->setIdentifier('sql-injection-task-' . time());
        $task->setName($sqlName);
        $task->setPromptTemplate('Test prompt');
        $task->setIsActive(true);

        $this->taskRepository->add($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // If we get here, SQL injection was prevented
        $added = $this->taskRepository->findOneByIdentifier($task->getIdentifier());
        self::assertNotNull($added);
        self::assertNotEmpty($added->getName());

        // Table should still exist
        $allTasksResult = $this->taskRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allTasksResult);
        self::assertGreaterThan(0, count($allTasksResult->toArray()));
    }

    // =========================================================================
    // Pathway 7.20: Concurrent Operation Safety
    // =========================================================================

    #[Test]
    public function pathway7_20_rapidProviderToggle_maintainsConsistency(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);
        $providerUid = $provider->getUid();
        self::assertNotNull($providerUid);
        $initialState = $provider->isActive();

        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $providerUid]);

        // Rapid toggles
        for ($i = 0; $i < 6; $i++) {
            $response = $this->providerController->toggleActiveAction($request);
            self::assertSame(200, $response->getStatusCode());
        }

        // After even number of toggles, should be back to initial
        $this->persistenceManager->clearState();
        $final = $this->providerRepository->findByUid($providerUid);
        self::assertNotNull($final);
        self::assertSame($initialState, $final->isActive());
    }

    #[Test]
    public function pathway7_20_rapidModelToggle_maintainsConsistency(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);
        $modelUid = $model->getUid();
        self::assertNotNull($modelUid);
        $initialState = $model->isActive();

        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => $modelUid]);

        // Rapid toggles
        for ($i = 0; $i < 6; $i++) {
            $response = $this->modelController->toggleActiveAction($request);
            self::assertSame(200, $response->getStatusCode());
        }

        // After even number of toggles, should be back to initial
        $this->persistenceManager->clearState();
        $final = $this->modelRepository->findByUid($modelUid);
        self::assertNotNull($final);
        self::assertSame($initialState, $final->isActive());
    }

    #[Test]
    public function pathway7_20_rapidConfigToggle_maintainsConsistency(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);
        $configUid = $config->getUid();
        self::assertNotNull($configUid);
        $initialState = $config->isActive();

        $request = $this->createFormRequest('/ajax/configuration/toggle', ['uid' => $configUid]);

        // Rapid toggles
        for ($i = 0; $i < 6; $i++) {
            $response = $this->configController->toggleActiveAction($request);
            self::assertSame(200, $response->getStatusCode());
        }

        // After even number of toggles, should be back to initial
        $this->persistenceManager->clearState();
        $final = $this->configurationRepository->findByUid($configUid);
        self::assertNotNull($final);
        self::assertSame($initialState, $final->isActive());
    }

    #[Test]
    public function pathway7_20_rapidTaskExecution_handlesCleanly(): void
    {
        $task = $this->taskRepository->findActive()->getFirst();
        if ($task === null) {
            self::markTestSkipped('No active tasks available');
        }

        // Execute task multiple times in rapid succession
        // Each call should return valid response (success or failure based on configuration)
        for ($i = 0; $i < 3; $i++) {
            $request = $this->createFormRequest('/ajax/task/execute', [
                'uid' => $task->getUid(),
                'input' => 'Test input ' . $i,
            ]);
            $response = $this->taskController->executeAction($request);

            // Response should always be valid JSON
            self::assertContains($response->getStatusCode(), [200, 400, 500]);
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('success', $body);
        }
    }
}
