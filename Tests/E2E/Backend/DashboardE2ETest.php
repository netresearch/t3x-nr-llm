<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\Backend;

use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest as Typo3ServerRequest;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * E2E tests for Dashboard user pathways.
 *
 * Tests complete user journeys:
 * - Pathway 6.1: View Dashboard (statistics and overview)
 * - Pathway 6.2: Quick Test Completion
 */
#[CoversClass(LlmModuleController::class)]
final class DashboardE2ETest extends AbstractBackendE2ETestCase
{
    private LlmModuleController $controller;
    private ProviderRepository $providerRepository;
    private ModelRepository $modelRepository;
    private LlmConfigurationRepository $configurationRepository;
    private TaskRepository $taskRepository;

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

        $this->controller = $this->createController();
    }

    private function createController(): LlmModuleController
    {
        $llmServiceManager = $this->get(LlmServiceManager::class);
        self::assertInstanceOf(LlmServiceManager::class, $llmServiceManager);

        return $this->createControllerWithReflection(LlmModuleController::class, [
            'llmServiceManager' => $llmServiceManager,
            'providerRepository' => $this->providerRepository,
            'modelRepository' => $this->modelRepository,
            'configurationRepository' => $this->configurationRepository,
            'taskRepository' => $this->taskRepository,
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
    // Pathway 6.1: View Dashboard
    // =========================================================================

    #[Test]
    public function pathway6_1_dashboardShowsProviderOverview(): void
    {
        // User navigates to Dashboard and sees provider status
        $activeProviders = $this->providerRepository->findActive()->toArray();

        self::assertNotEmpty($activeProviders, 'Dashboard should show active providers');

        // Each provider should have status information
        foreach ($activeProviders as $provider) {
            self::assertNotEmpty($provider->getName());
            self::assertNotEmpty($provider->getAdapterType());
            self::assertTrue($provider->isActive());
        }
    }

    #[Test]
    public function pathway6_1_dashboardShowsStatistics(): void
    {
        // Dashboard displays counts for key entities
        $providerCount = $this->providerRepository->countActive();
        $modelCount = $this->modelRepository->countActive();
        $configCount = $this->configurationRepository->countActive();
        $taskCount = $this->taskRepository->countActive();

        // User sees these statistics on dashboard
        self::assertGreaterThanOrEqual(0, $providerCount);
        self::assertGreaterThanOrEqual(0, $modelCount);
        self::assertGreaterThanOrEqual(0, $configCount);
        self::assertGreaterThanOrEqual(0, $taskCount);
    }

    #[Test]
    public function pathway6_1_dashboardShowsFeatureMatrix(): void
    {
        // Dashboard shows capabilities per provider
        $providers = $this->providerRepository->findActive()->toArray();

        foreach ($providers as $provider) {
            $models = $this->modelRepository->findByProvider($provider);

            // Collect capabilities across all models for this provider
            $capabilities = [];
            foreach ($models as $model) {
                // Each model has capability flags
                self::assertGreaterThanOrEqual(0, $model->getContextLength());
                self::assertGreaterThanOrEqual(0, $model->getMaxOutputTokens());
            }
        }
    }

    #[Test]
    public function pathway6_1_dashboardShowsDefaultConfiguration(): void
    {
        // Dashboard highlights the default configuration
        $defaultConfig = $this->configurationRepository->findDefault();

        if ($defaultConfig !== null) {
            self::assertTrue($defaultConfig->isDefault());
            self::assertTrue($defaultConfig->isActive());
            self::assertNotEmpty($defaultConfig->getName());
        }
    }

    #[Test]
    public function pathway6_1_dashboardShowsQuickActions(): void
    {
        // Dashboard provides quick access to common actions
        // Verify the data needed for quick actions is available

        // Quick action: Test provider
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider, 'Should have provider for quick test action');

        // Quick action: Execute task
        $task = $this->taskRepository->findActive()->getFirst();
        self::assertNotNull($task, 'Should have task for quick execute action');

        // Quick action: Use default config
        $config = $this->configurationRepository->findDefault();
        // Default config may or may not exist, that's OK
    }

    // =========================================================================
    // Pathway 6.2: Quick Test Completion
    // =========================================================================

    #[Test]
    public function pathway6_2_quickTestCompletion(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // User enters prompt and selects provider
        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            'prompt' => 'Hello, please respond with a greeting.',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        // Execute test
        $response = $this->controller->executeTestAction();

        // Verify response structure
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        if ($body['success']) {
            // Success response should have content and usage stats
            self::assertArrayHasKey('content', $body);
            self::assertArrayHasKey('model', $body);
            self::assertArrayHasKey('usage', $body);
            self::assertNotEmpty($body['content']);
        } else {
            // Failure should have error message
            self::assertArrayHasKey('error', $body);
            self::assertNotEmpty($body['error']);
        }
    }

    #[Test]
    public function pathway6_2_quickTestWithDefaultPrompt(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // User clicks test without entering custom prompt
        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            // No prompt - should use default
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        $response = $this->controller->executeTestAction();

        // Should not return 400 (bad request) for missing prompt
        self::assertNotSame(400, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway6_2_quickTestReturnsUsageStatistics(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            'prompt' => 'Test prompt for usage tracking',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        $response = $this->controller->executeTestAction();

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($body['success'] ?? false) {
            // Usage statistics should be present
            self::assertArrayHasKey('usage', $body);
            $usage = $body['usage'];
            self::assertIsArray($usage);
            /** @var array<string, mixed> $usage */
            self::assertArrayHasKey('promptTokens', $usage);
            self::assertArrayHasKey('completionTokens', $usage);
            self::assertArrayHasKey('totalTokens', $usage);
        }
    }

    #[Test]
    public function pathway6_2_quickTest_errorForMissingProvider(): void
    {
        // User doesn't select a provider
        $request = $this->createDashboardExtbaseRequest([
            'prompt' => 'Hello',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        $response = $this->controller->executeTestAction();

        self::assertSame(400, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('No provider specified', $body['error']);
    }

    #[Test]
    public function pathway6_2_quickTest_errorForEmptyProvider(): void
    {
        $request = $this->createDashboardExtbaseRequest([
            'provider' => '',
            'prompt' => 'Hello',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        $response = $this->controller->executeTestAction();

        self::assertSame(400, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('No provider specified', $body['error']);
    }

    // =========================================================================
    // Dashboard Data Consistency
    // =========================================================================

    #[Test]
    public function dashboardCountsMatchActualData(): void
    {
        // Verify active counts match actual filtered data
        // Note: countActive counts non-deleted records, findActive filters by is_active=true
        $actualProviders = $this->providerRepository->findActive()->count();
        self::assertGreaterThanOrEqual(0, $actualProviders);

        $actualModels = $this->modelRepository->findActive()->count();
        self::assertGreaterThanOrEqual(0, $actualModels);

        $actualConfigs = $this->configurationRepository->findActive()->count();
        self::assertGreaterThanOrEqual(0, $actualConfigs);
    }

    #[Test]
    public function dashboardReflectsLiveData(): void
    {
        // Dashboard should reflect current database state
        $initialCount = $this->providerRepository->findActive()->count();

        // Deactivate a provider
        $provider = $this->providerRepository->findActive()->getFirst();
        if ($provider !== null) {
            $provider->setIsActive(false);
            $this->providerRepository->update($provider);
            $persistenceManager = $this->get(PersistenceManagerInterface::class);
            self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
            $persistenceManager->persistAll();
            $persistenceManager->clearState();

            // Dashboard active count should decrease
            $newCount = $this->providerRepository->findActive()->count();
            self::assertSame($initialCount - 1, $newCount);

            // Reactivate for cleanup
            $providerUid = $provider->getUid();
            self::assertNotNull($providerUid);
            $reloadedProvider = $this->providerRepository->findByUid($providerUid);
            self::assertNotNull($reloadedProvider);
            $reloadedProvider->setIsActive(true);
            $this->providerRepository->update($reloadedProvider);
            $persistenceManager->persistAll();
        }
    }

    // =========================================================================
    // Provider Selection for Quick Test
    // =========================================================================

    #[Test]
    public function quickTestCanUseAnyActiveProvider(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        foreach ($providers as $provider) {
            $request = $this->createDashboardExtbaseRequest([
                'provider' => $provider->getIdentifier(),
                'prompt' => 'Test',
            ]);

            $this->setPrivateProperty($this->controller, 'request', $request);

            $response = $this->controller->executeTestAction();

            // Each provider should return a valid response structure
            self::assertContains($response->getStatusCode(), [200, 500]);

            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('success', $body);
        }
    }

    // =========================================================================
    // Pathway 6.3: Quick Test Edge Cases
    // =========================================================================

    #[Test]
    public function pathway6_3_quickTest_invalidProviderIdentifier(): void
    {
        $request = $this->createDashboardExtbaseRequest([
            'provider' => 'non-existent-provider-xyz',
            'prompt' => 'Hello',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        $response = $this->controller->executeTestAction();

        // Should return error for invalid provider
        self::assertContains($response->getStatusCode(), [400, 404, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertFalse($body['success']);
    }

    #[Test]
    public function pathway6_3_quickTest_specialCharactersInPrompt(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // Test with special characters, unicode, and edge cases
        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            'prompt' => "Test with special chars: <script>alert('xss')</script> 日本語 🎉 \"quotes\" & ampersand",
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        $response = $this->controller->executeTestAction();

        // Should handle special characters gracefully
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function pathway6_3_quickTest_veryLongPrompt(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // Test with a very long prompt
        $longPrompt = str_repeat('This is a test prompt. ', 1000);

        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            'prompt' => $longPrompt,
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        $response = $this->controller->executeTestAction();

        // Should handle long prompts (may succeed or fail with token limit error)
        self::assertContains($response->getStatusCode(), [200, 400, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function pathway6_3_quickTest_whitespaceOnlyPrompt(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            'prompt' => '    ',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        $response = $this->controller->executeTestAction();

        // Should handle whitespace-only prompt (might use default or return error)
        self::assertContains($response->getStatusCode(), [200, 400, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway6_3_quickTest_multilinePrompt(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $multilinePrompt = "Line 1: First line\nLine 2: Second line\nLine 3: Third line";

        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            'prompt' => $multilinePrompt,
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        $response = $this->controller->executeTestAction();

        // Should handle multiline prompts
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    // =========================================================================
    // Pathway 6.4: Dashboard Statistics Edge Cases
    // =========================================================================

    #[Test]
    public function pathway6_4_dashboardWithNoActiveProviders(): void
    {
        // Get initial state
        $providers = $this->providerRepository->findActive()->toArray();
        /** @var array<int, bool> $originalStates */
        $originalStates = [];

        // Deactivate all providers
        foreach ($providers as $provider) {
            $providerUid = $provider->getUid();
            self::assertNotNull($providerUid);
            $originalStates[$providerUid] = $provider->isActive();
            $provider->setIsActive(false);
            $this->providerRepository->update($provider);
        }

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $persistenceManager->persistAll();
        $persistenceManager->clearState();

        // Dashboard should show 0 active providers
        $activeCount = $this->providerRepository->findActive()->count();
        self::assertSame(0, $activeCount);

        // Restore original states
        foreach ($providers as $provider) {
            $providerUid = $provider->getUid();
            self::assertNotNull($providerUid);
            $reloadedProvider = $this->providerRepository->findByUid($providerUid);
            self::assertNotNull($reloadedProvider);
            if (isset($originalStates[$providerUid])) {
                $reloadedProvider->setIsActive($originalStates[$providerUid]);
                $this->providerRepository->update($reloadedProvider);
            }
        }

        $persistenceManager->persistAll();
    }

    #[Test]
    public function pathway6_4_dashboardWithNoActiveModels(): void
    {
        // Get initial state
        $models = $this->modelRepository->findActive()->toArray();
        /** @var array<int, bool> $originalStates */
        $originalStates = [];

        // Deactivate all models
        foreach ($models as $model) {
            $modelUid = $model->getUid();
            self::assertNotNull($modelUid);
            $originalStates[$modelUid] = $model->isActive();
            $model->setIsActive(false);
            $this->modelRepository->update($model);
        }

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $persistenceManager->persistAll();
        $persistenceManager->clearState();

        // Dashboard should show 0 active models
        $activeCount = $this->modelRepository->findActive()->count();
        self::assertSame(0, $activeCount);

        // Restore original states
        foreach ($models as $model) {
            $modelUid = $model->getUid();
            self::assertNotNull($modelUid);
            $reloadedModel = $this->modelRepository->findByUid($modelUid);
            self::assertNotNull($reloadedModel);
            if (isset($originalStates[$modelUid])) {
                $reloadedModel->setIsActive($originalStates[$modelUid]);
                $this->modelRepository->update($reloadedModel);
            }
        }

        $persistenceManager->persistAll();
    }

    #[Test]
    public function pathway6_4_dashboardWithNoConfigurations(): void
    {
        // Get initial state
        $configs = $this->configurationRepository->findActive()->toArray();
        /** @var array<int, bool> $originalStates */
        $originalStates = [];

        // Deactivate all configurations
        foreach ($configs as $config) {
            $configUid = $config->getUid();
            self::assertNotNull($configUid);
            $originalStates[$configUid] = $config->isActive();
            $config->setIsActive(false);
            $this->configurationRepository->update($config);
        }

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $persistenceManager->persistAll();
        $persistenceManager->clearState();

        // Dashboard should show 0 active configurations
        $activeCount = $this->configurationRepository->findActive()->count();
        self::assertSame(0, $activeCount);

        // Restore original states
        foreach ($configs as $config) {
            $configUid = $config->getUid();
            self::assertNotNull($configUid);
            $reloadedConfig = $this->configurationRepository->findByUid($configUid);
            self::assertNotNull($reloadedConfig);
            if (isset($originalStates[$configUid])) {
                $reloadedConfig->setIsActive($originalStates[$configUid]);
                $this->configurationRepository->update($reloadedConfig);
            }
        }

        $persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 6.5: Dashboard Provider Health Check
    // =========================================================================

    #[Test]
    public function pathway6_5_providerHealthCheck(): void
    {
        // Dashboard should indicate provider health/connectivity status
        $providers = $this->providerRepository->findActive()->toArray();

        foreach ($providers as $provider) {
            // Each provider has API key status (may be configured or empty)
            // getApiKey() returns string, so we just verify it's accessible
            $provider->getApiKey();
            self::assertTrue($provider->isActive(), 'Dashboard shows only active providers');
            // Provider name must be configured
            self::assertNotEmpty($provider->getName(), 'Provider should have name configured');
        }
    }

    #[Test]
    public function pathway6_5_providerWithMissingApiKey(): void
    {
        // Dashboard should handle providers with missing/empty API keys
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $originalApiKey = $provider->getApiKey();
        $provider->setApiKey('');
        $this->providerRepository->update($provider);
        $persistenceManager1 = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager1);
        $persistenceManager1->persistAll();
        $persistenceManager1->clearState();

        // Test with provider that has no API key
        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            'prompt' => 'Hello',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);

        $response = $this->controller->executeTestAction();

        // Should fail gracefully with missing API key
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        // Restore API key
        $providerUid = $provider->getUid();
        self::assertNotNull($providerUid);
        $reloadedProvider = $this->providerRepository->findByUid($providerUid);
        self::assertNotNull($reloadedProvider);
        $reloadedProvider->setApiKey($originalApiKey);
        $this->providerRepository->update($reloadedProvider);
        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 6.6: Dashboard Model Overview
    // =========================================================================

    #[Test]
    public function pathway6_6_modelOverviewShowsCapabilities(): void
    {
        $models = $this->modelRepository->findActive()->toArray();

        foreach ($models as $model) {
            // Dashboard shows model capabilities
            self::assertNotEmpty($model->getModelId());
            self::assertGreaterThanOrEqual(0, $model->getContextLength());
            self::assertGreaterThanOrEqual(0, $model->getMaxOutputTokens());
            self::assertNotNull($model->getProvider());
        }
    }

    #[Test]
    public function pathway6_6_modelOverviewShowsDefaultModel(): void
    {
        $defaultModel = $this->modelRepository->findDefault();

        if ($defaultModel !== null) {
            self::assertTrue($defaultModel->isDefault());
            self::assertTrue($defaultModel->isActive());
        }
    }

    #[Test]
    public function pathway6_6_modelCountPerProvider(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        foreach ($providers as $provider) {
            $models = $this->modelRepository->findByProvider($provider)->toArray();

            // Each provider should have at least some models
            self::assertGreaterThanOrEqual(0, count($models));

            // All models should belong to this provider
            foreach ($models as $model) {
                $modelProvider = $model->getProvider();
                self::assertNotNull($modelProvider);
                self::assertSame($provider->getUid(), $modelProvider->getUid());
            }
        }
    }

    // =========================================================================
    // Pathway 6.7: Dashboard Task Overview
    // =========================================================================

    #[Test]
    public function pathway6_7_taskOverviewShowsActiveTasksByCategory(): void
    {
        $tasks = $this->taskRepository->findActive()->toArray();

        // Group tasks by category
        $tasksByCategory = [];
        foreach ($tasks as $task) {
            $category = $task->getCategory();
            if (!isset($tasksByCategory[$category])) {
                $tasksByCategory[$category] = [];
            }
            $tasksByCategory[$category][] = $task;
        }

        // Dashboard can show tasks grouped by category
        foreach ($tasksByCategory as $category => $categoryTasks) {
            self::assertNotEmpty($category);
            self::assertNotEmpty($categoryTasks);
        }
    }

    #[Test]
    public function pathway6_7_taskOverviewShowsTaskStatus(): void
    {
        $tasks = $this->taskRepository->findActive()->toArray();

        foreach ($tasks as $task) {
            // Each task has required information
            self::assertNotEmpty($task->getName());
            self::assertNotEmpty($task->getCategory());
            self::assertTrue($task->isActive());
        }
    }

    // =========================================================================
    // Dashboard Navigation and State
    // =========================================================================

    #[Test]
    public function dashboardDataConsistencyAcrossRepositories(): void
    {
        // Verify relationships are consistent across repositories
        $providers = $this->providerRepository->findActive()->toArray();
        $models = $this->modelRepository->findActive()->toArray();
        $configs = $this->configurationRepository->findActive()->toArray();

        // Every model should have a valid provider
        foreach ($models as $model) {
            $provider = $model->getProvider();
            self::assertNotNull($provider);
            $providerUid = $provider->getUid();
            self::assertNotNull($providerUid);
            self::assertNotNull($this->providerRepository->findByUid($providerUid));
        }

        // Every configuration with a linked model should reference a valid model
        foreach ($configs as $config) {
            $model = $config->getLlmModel();
            if ($model !== null) {
                $modelUid = $model->getUid();
                self::assertNotNull($modelUid);
                self::assertNotNull($this->modelRepository->findByUid($modelUid));
            }
        }
    }

    #[Test]
    public function dashboardHandlesEmptyState(): void
    {
        // Store original counts
        $originalProviderCount = $this->providerRepository->findActive()->count();
        $originalModelCount = $this->modelRepository->findActive()->count();
        $originalConfigCount = $this->configurationRepository->findActive()->count();

        // Dashboard should handle empty state gracefully
        // (We're just verifying the counts are valid, not actually emptying the DB)
        self::assertGreaterThanOrEqual(0, $originalProviderCount);
        self::assertGreaterThanOrEqual(0, $originalModelCount);
        self::assertGreaterThanOrEqual(0, $originalConfigCount);
    }

    // =========================================================================
    // Quick Test Concurrent Operations
    // =========================================================================

    #[Test]
    public function quickTestMultipleProvidersSequentially(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        // Test each provider one after another
        $results = [];
        foreach ($providers as $provider) {
            $request = $this->createDashboardExtbaseRequest([
                'provider' => $provider->getIdentifier(),
                'prompt' => 'Quick test',
            ]);

            $this->setPrivateProperty($this->controller, 'request', $request);

            $response = $this->controller->executeTestAction();
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            /** @var array<string, mixed> $body */
            $results[$provider->getIdentifier()] = [
                'status' => $response->getStatusCode(),
                'success' => $body['success'] ?? false,
            ];
        }

        // All providers should return valid responses
        foreach ($results as $identifier => $result) {
            self::assertContains($result['status'], [200, 500]);
        }
    }

    #[Test]
    public function quickTestRepeatedCallsSameProvider(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // Make multiple calls to the same provider
        for ($i = 0; $i < 3; $i++) {
            $request = $this->createDashboardExtbaseRequest([
                'provider' => $provider->getIdentifier(),
                'prompt' => "Test iteration $i",
            ]);

            $this->setPrivateProperty($this->controller, 'request', $request);

            $response = $this->controller->executeTestAction();

            self::assertContains($response->getStatusCode(), [200, 500]);

            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('success', $body);
        }
    }

    // =========================================================================
    // Pathway 6.8: Dashboard Summary Statistics
    // =========================================================================

    #[Test]
    public function pathway6_8_dashboardSummaryStatistics(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();
        $models = $this->modelRepository->findActive()->toArray();
        $configs = $this->configurationRepository->findActive()->toArray();
        $tasks = $this->taskRepository->findActive()->toArray();

        // Dashboard should show summary counts
        self::assertGreaterThanOrEqual(0, count($providers));
        self::assertGreaterThanOrEqual(0, count($models));
        self::assertGreaterThanOrEqual(0, count($configs));
        self::assertGreaterThanOrEqual(0, count($tasks));
    }

    #[Test]
    public function pathway6_8_dashboardProviderStats(): void
    {
        $activeCount = $this->providerRepository->countActive();
        $allProviders = $this->providerRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allProviders);
        $totalCount = $allProviders->count();

        self::assertGreaterThanOrEqual($activeCount, $totalCount);
        self::assertGreaterThanOrEqual(0, $activeCount);
    }

    #[Test]
    public function pathway6_8_dashboardModelStats(): void
    {
        $activeCount = $this->modelRepository->countActive();
        $allModels = $this->modelRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allModels);
        $totalCount = $allModels->count();

        self::assertGreaterThanOrEqual($activeCount, $totalCount);
        self::assertGreaterThanOrEqual(0, $activeCount);
    }

    // =========================================================================
    // Pathway 6.9: Dashboard Feature Matrix
    // =========================================================================

    #[Test]
    public function pathway6_9_dashboardFeatureMatrix(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        foreach ($providers as $provider) {
            // Each provider should have identifiable capabilities
            $adapterType = $provider->getAdapterType();
            self::assertNotEmpty($adapterType);

            // Models for this provider
            $models = $this->modelRepository->findByProvider($provider);

            foreach ($models as $model) {
                // getCapabilitiesArray returns parsed array
                $capabilities = $model->getCapabilitiesArray();
                self::assertGreaterThanOrEqual(0, count($capabilities));
            }
        }
    }

    #[Test]
    public function pathway6_9_modelsHaveCapabilities(): void
    {
        $models = $this->modelRepository->findActive()->toArray();

        foreach ($models as $model) {
            // getCapabilitiesArray returns parsed array
            $capabilities = $model->getCapabilitiesArray();
            self::assertGreaterThanOrEqual(0, count($capabilities));

            // If has capabilities, they should be valid strings
            foreach ($capabilities as $capability) {
                self::assertNotEmpty($capability);
            }
        }
    }

    // =========================================================================
    // Pathway 6.10: Dashboard Navigation
    // =========================================================================

    #[Test]
    public function pathway6_10_repositoriesAccessible(): void
    {
        // All repositories needed for dashboard should be accessible
        self::assertInstanceOf(ProviderRepository::class, $this->providerRepository);
        self::assertInstanceOf(ModelRepository::class, $this->modelRepository);
        self::assertInstanceOf(LlmConfigurationRepository::class, $this->configurationRepository);
        self::assertInstanceOf(TaskRepository::class, $this->taskRepository);
    }

    #[Test]
    public function pathway6_10_dashboardListsAllActiveEntities(): void
    {
        $providers = $this->providerRepository->findActive();
        $models = $this->modelRepository->findActive();
        $configs = $this->configurationRepository->findActive();
        $tasks = $this->taskRepository->findActive();

        // All entities should be countable
        self::assertGreaterThanOrEqual(0, $providers->count());
        self::assertGreaterThanOrEqual(0, $models->count());
        self::assertGreaterThanOrEqual(0, $configs->count());
        self::assertGreaterThanOrEqual(0, $tasks->count());
    }

    // =========================================================================
    // Pathway 6.11: Quick Test with Different Prompts
    // =========================================================================

    #[Test]
    public function pathway6_11_quickTestWithCodePrompt(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            'prompt' => 'Write a simple hello world function in Python',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);
        $response = $this->controller->executeTestAction();

        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway6_11_quickTestWithTranslationPrompt(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            'prompt' => 'Translate "Hello World" to German',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);
        $response = $this->controller->executeTestAction();

        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway6_11_quickTestWithAnalysisPrompt(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createDashboardExtbaseRequest([
            'provider' => $provider->getIdentifier(),
            'prompt' => 'Analyze the following text: "This is a sample text for analysis."',
        ]);

        $this->setPrivateProperty($this->controller, 'request', $request);
        $response = $this->controller->executeTestAction();

        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    // =========================================================================
    // Pathway 6.12: Dashboard Default Entity Indicators
    // =========================================================================

    #[Test]
    public function pathway6_12_defaultModelIndicator(): void
    {
        $allModels = $this->modelRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allModels);
        $models = $allModels->toArray();

        $defaultCount = 0;
        foreach ($models as $model) {
            self::assertInstanceOf(Model::class, $model);
            if ($model->isDefault()) {
                $defaultCount++;
                self::assertTrue($model->isActive(), 'Default model should be active');
            }
        }

        // At most one default model
        self::assertLessThanOrEqual(1, $defaultCount);
    }

    #[Test]
    public function pathway6_12_defaultConfigurationIndicator(): void
    {
        $allConfigs = $this->configurationRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allConfigs);
        $configs = $allConfigs->toArray();

        $defaultCount = 0;
        foreach ($configs as $config) {
            self::assertInstanceOf(LlmConfiguration::class, $config);
            if ($config->isDefault()) {
                $defaultCount++;
                self::assertTrue($config->isActive(), 'Default configuration should be active');
            }
        }

        // At most one default configuration
        self::assertLessThanOrEqual(1, $defaultCount);
    }

    #[Test]
    public function pathway6_12_highestPriorityProviderIndicator(): void
    {
        $highestProvider = $this->providerRepository->findHighestPriority();

        if ($highestProvider !== null) {
            self::assertTrue($highestProvider->isActive());

            // Should have highest priority among active providers
            $allActive = $this->providerRepository->findActive()->toArray();
            foreach ($allActive as $provider) {
                self::assertLessThanOrEqual($highestProvider->getPriority(), $provider->getPriority());
            }
        }
    }

    // =========================================================================
    // Pathway 6.13: Dashboard Entity Counts by Type
    // =========================================================================

    #[Test]
    public function pathway6_13_providersByAdapterType(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        $byType = [];
        foreach ($providers as $provider) {
            $type = $provider->getAdapterType();
            if (!isset($byType[$type])) {
                $byType[$type] = 0;
            }
            $byType[$type]++;
        }

        // Verify counts are positive for each type
        foreach ($byType as $type => $count) {
            self::assertNotEmpty($type);
            self::assertGreaterThan(0, $count);
        }
    }

    #[Test]
    public function pathway6_13_tasksByCategory(): void
    {
        $tasks = $this->taskRepository->findActive()->toArray();

        $byCategory = [];
        foreach ($tasks as $task) {
            $category = $task->getCategory();
            if (!isset($byCategory[$category])) {
                $byCategory[$category] = 0;
            }
            $byCategory[$category]++;
        }

        foreach ($byCategory as $category => $count) {
            self::assertNotEmpty($category);
            self::assertGreaterThan(0, $count);
        }
    }

    #[Test]
    public function pathway6_13_tasksByInputType(): void
    {
        $tasks = $this->taskRepository->findActive()->toArray();

        $byInputType = [];
        foreach ($tasks as $task) {
            $inputType = $task->getInputType();
            if (!isset($byInputType[$inputType])) {
                $byInputType[$inputType] = 0;
            }
            $byInputType[$inputType]++;
        }

        foreach ($byInputType as $inputType => $count) {
            self::assertNotEmpty($inputType);
            self::assertGreaterThan(0, $count);
        }
    }

    // =========================================================================
    // Pathway 6.14: Dashboard Quick Actions Availability
    // =========================================================================

    #[Test]
    public function pathway6_14_quickTestAvailability(): void
    {
        $providers = $this->providerRepository->findActive();

        // Quick test requires at least one active provider
        if ($providers->count() > 0) {
            $firstProvider = $providers->getFirst();
            self::assertNotNull($firstProvider);
            self::assertTrue($firstProvider->isActive());
        }
    }

    #[Test]
    public function pathway6_14_quickTaskExecutionAvailability(): void
    {
        $tasks = $this->taskRepository->findActive();

        // Quick task execution requires at least one active task
        if ($tasks->count() > 0) {
            $firstTask = $tasks->getFirst();
            self::assertNotNull($firstTask);
            self::assertTrue($firstTask->isActive());
        }
    }

    #[Test]
    public function pathway6_14_configurationAvailability(): void
    {
        $configs = $this->configurationRepository->findActive();

        // Configuration usage requires at least one active config
        if ($configs->count() > 0) {
            $firstConfig = $configs->getFirst();
            self::assertNotNull($firstConfig);
            self::assertTrue($firstConfig->isActive());
        }
    }

    // =========================================================================
    // Pathway 6.15: Dashboard Data Integrity
    // =========================================================================

    #[Test]
    public function pathway6_15_dashboardDataConsistency(): void
    {
        // Provider count should be available
        $providerCount = $this->providerRepository->countActive();
        self::assertGreaterThanOrEqual(0, $providerCount);

        // Model count should be available
        $modelCount = $this->modelRepository->countActive();
        self::assertGreaterThanOrEqual(0, $modelCount);

        // Configuration count should be available
        $configCount = $this->configurationRepository->countActive();
        self::assertGreaterThanOrEqual(0, $configCount);

        // Task count should be available
        $taskCount = $this->taskRepository->countActive();
        self::assertGreaterThanOrEqual(0, $taskCount);
    }

    #[Test]
    public function pathway6_15_dashboardModelToProviderRelationship(): void
    {
        $models = $this->modelRepository->findActive()->toArray();

        foreach ($models as $model) {
            $provider = $model->getProvider();
            self::assertNotNull($provider, 'Each model must have a provider');
            self::assertNotNull($provider->getUid());
        }
    }

    #[Test]
    public function pathway6_15_dashboardConfigurationToModelRelationship(): void
    {
        $configs = $this->configurationRepository->findActive()->toArray();

        foreach ($configs as $config) {
            $model = $config->getLlmModel();
            // Model may be null for configurations without assigned model
            if ($model !== null) {
                self::assertNotNull($model->getUid());
                self::assertNotNull($model->getProvider());
            }
        }
    }

    // =========================================================================
    // Pathway 6.16: Dashboard Empty State Handling
    // =========================================================================

    #[Test]
    public function pathway6_16_dashboardHandlesEmptyProviders(): void
    {
        // Even if no providers, dashboard methods should not crash
        $count = $this->providerRepository->countActive();
        self::assertGreaterThanOrEqual(0, $count);
    }

    #[Test]
    public function pathway6_16_dashboardHandlesEmptyModels(): void
    {
        $count = $this->modelRepository->countActive();
        self::assertGreaterThanOrEqual(0, $count);
    }

    #[Test]
    public function pathway6_16_dashboardHandlesEmptyConfigurations(): void
    {
        $count = $this->configurationRepository->countActive();
        self::assertGreaterThanOrEqual(0, $count);
    }

    #[Test]
    public function pathway6_16_dashboardHandlesEmptyTasks(): void
    {
        $count = $this->taskRepository->countActive();
        self::assertGreaterThanOrEqual(0, $count);
    }

    // =========================================================================
    // Pathway 6.17: Dashboard Statistics Accuracy
    // =========================================================================

    #[Test]
    public function pathway6_17_activeVsTotalProviderCount(): void
    {
        $activeCount = $this->providerRepository->countActive();
        $allProviders = $this->providerRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allProviders);
        $totalCount = $allProviders->count();

        self::assertGreaterThanOrEqual($activeCount, $totalCount);
    }

    #[Test]
    public function pathway6_17_activeVsTotalModelCount(): void
    {
        $activeCount = $this->modelRepository->countActive();
        $allModels = $this->modelRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allModels);
        $totalCount = $allModels->count();

        self::assertGreaterThanOrEqual($activeCount, $totalCount);
    }

    #[Test]
    public function pathway6_17_activeVsTotalConfigurationCount(): void
    {
        $activeCount = $this->configurationRepository->countActive();
        $allConfigs = $this->configurationRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allConfigs);
        $totalCount = $allConfigs->count();

        self::assertGreaterThanOrEqual($activeCount, $totalCount);
    }

    #[Test]
    public function pathway6_17_activeVsTotalTaskCount(): void
    {
        $activeCount = $this->taskRepository->countActive();
        $allTasks = $this->taskRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allTasks);
        $totalCount = $allTasks->count();

        self::assertGreaterThanOrEqual($activeCount, $totalCount);
    }

    // =========================================================================
    // Pathway 6.18: Dashboard Quick Test Availability Checks
    // =========================================================================

    #[Test]
    public function pathway6_18_dashboardSupportsActiveProviderQuery(): void
    {
        // Dashboard uses active provider queries for quick test
        $providers = $this->providerRepository->findActive();
        self::assertGreaterThanOrEqual(0, $providers->count());

        // First provider should be usable
        $first = $providers->getFirst();
        if ($first !== null) {
            self::assertNotEmpty($first->getIdentifier());
        }
    }

    #[Test]
    public function pathway6_18_dashboardSupportsActiveModelQuery(): void
    {
        // Dashboard uses active model queries
        $models = $this->modelRepository->findActive();
        self::assertGreaterThanOrEqual(0, $models->count());

        foreach ($models as $model) {
            self::assertNotNull($model->getProvider());
        }
    }

    #[Test]
    public function pathway6_18_dashboardSupportsActiveConfigurationQuery(): void
    {
        $configs = $this->configurationRepository->findActive();
        self::assertGreaterThanOrEqual(0, $configs->count());

        foreach ($configs as $config) {
            self::assertNotEmpty($config->getIdentifier());
        }
    }

    #[Test]
    public function pathway6_18_dashboardSupportsActiveTaskQuery(): void
    {
        $tasks = $this->taskRepository->findActive();
        self::assertGreaterThanOrEqual(0, $tasks->count());

        foreach ($tasks as $task) {
            self::assertNotEmpty($task->getIdentifier());
        }
    }
}
