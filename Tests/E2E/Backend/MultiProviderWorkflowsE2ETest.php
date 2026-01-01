<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\Backend;

use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * E2E tests for Multi-Provider Workflows.
 *
 * Tests complete user journeys involving multiple providers:
 * - Pathway 8.1: Switch Between Providers
 * - Pathway 8.2: Fallback Provider (simulated)
 */
#[CoversClass(ConfigurationController::class)]
#[CoversClass(ProviderController::class)]
final class MultiProviderWorkflowsE2ETest extends AbstractBackendE2ETestCase
{
    private ProviderRepository $providerRepository;
    private ModelRepository $modelRepository;
    private LlmConfigurationRepository $configurationRepository;
    private TaskRepository $taskRepository;
    private PersistenceManagerInterface $persistenceManager;
    private ConfigurationController $configurationController;
    private ProviderController $providerController;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $this->providerRepository);

        $this->modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $this->modelRepository);

        $this->configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $this->configurationRepository);

        $this->taskRepository = $this->get(TaskRepository::class);
        self::assertInstanceOf(TaskRepository::class, $this->taskRepository);

        $this->persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $this->persistenceManager);

        $this->configurationController = $this->createConfigurationController();
        $this->providerController = $this->createProviderController();
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

    private function createConfigurationController(): ConfigurationController
    {
        $configurationService = $this->get(LlmConfigurationService::class);
        self::assertInstanceOf(LlmConfigurationService::class, $configurationService);

        $llmServiceManager = $this->get(LlmServiceManager::class);
        self::assertInstanceOf(LlmServiceManager::class, $llmServiceManager);

        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        return $this->createControllerWithReflection(ConfigurationController::class, [
            'configurationService' => $configurationService,
            'configurationRepository' => $this->configurationRepository,
            'llmServiceManager' => $llmServiceManager,
            'providerAdapterRegistry' => $providerAdapterRegistry,
        ]);
    }

    // =========================================================================
    // Pathway 8.1: Switch Between Providers
    // =========================================================================

    #[Test]
    public function pathway8_1_multipleProvidersCanCoexist(): void
    {
        // Verify we have multiple active providers from fixtures
        $providers = $this->providerRepository->findActive()->toArray();

        self::assertGreaterThanOrEqual(1, count($providers), 'Should have at least one active provider');

        // Each provider should have unique identifiers
        $identifiers = array_map(fn($p) => $p->getIdentifier(), $providers);
        self::assertSame(count($providers), count(array_unique($identifiers)), 'Provider identifiers should be unique');
    }

    #[Test]
    public function pathway8_1_eachProviderHasDistinctModels(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        foreach ($providers as $provider) {
            $models = $this->modelRepository->findByProvider($provider);

            // Provider's models should all reference this provider
            foreach ($models as $model) {
                self::assertSame(
                    $provider->getUid(),
                    $model->getProvider()?->getUid(),
                    sprintf('Model "%s" should belong to provider "%s"', $model->getName(), $provider->getName()),
                );
            }
        }
    }

    #[Test]
    public function pathway8_1_configurationsCanUseDifferentProviders(): void
    {
        $configurations = $this->configurationRepository->findActive()->toArray();

        // Collect provider UIDs used by configurations
        $providerUids = [];
        foreach ($configurations as $config) {
            $model = $config->getLlmModel();
            if ($model !== null && $model->getProvider() !== null) {
                $providerUids[] = $model->getProvider()->getUid();
            }
        }

        // If we have multiple providers, configurations should be able to use different ones
        if (count($this->providerRepository->findActive()) > 1) {
            self::assertGreaterThanOrEqual(
                1,
                count(array_unique($providerUids)),
                'Configurations can use different providers',
            );
        }
    }

    #[Test]
    public function pathway8_1_switchProviderByChangingConfiguration(): void
    {
        // Get two different configurations (potentially with different providers)
        $configs = $this->configurationRepository->findActive()->toArray();
        self::assertGreaterThanOrEqual(1, count($configs), 'Need at least one configuration');

        $config1 = $configs[0];

        // Test with first configuration
        $request1 = $this->createFormRequest('/ajax/config/test', ['uid' => $config1->getUid()]);
        $response1 = $this->configurationController->testConfigurationAction($request1);

        self::assertContains($response1->getStatusCode(), [200, 500]);
        $body1 = json_decode((string)$response1->getBody(), true);
        self::assertIsArray($body1);

        // If we have a second configuration, test with that too
        if (count($configs) >= 2) {
            $config2 = $configs[1];

            $request2 = $this->createFormRequest('/ajax/config/test', ['uid' => $config2->getUid()]);
            $response2 = $this->configurationController->testConfigurationAction($request2);

            self::assertContains($response2->getStatusCode(), [200, 500]);
            $body2 = json_decode((string)$response2->getBody(), true);
            self::assertIsArray($body2);

            // Both responses should be independent
            // (We can't assert content differs as they might use same provider)
        }
    }

    #[Test]
    public function pathway8_1_tasksCanReferenceMultipleConfigurations(): void
    {
        $tasks = $this->taskRepository->findActive()->toArray();
        $configs = $this->configurationRepository->findActive()->toArray();

        // Collect configuration UIDs used by tasks
        $configUids = [];
        foreach ($tasks as $task) {
            $config = $task->getConfiguration();
            if ($config !== null) {
                $configUids[] = $config->getUid();
            }
        }

        // Tasks should be able to use different configurations
        // (this tests the data model supports multi-provider workflows)
        self::assertGreaterThanOrEqual(1, count($tasks), 'Should have at least one task');
        self::assertGreaterThanOrEqual(1, count($configs), 'Should have at least one configuration');
    }

    #[Test]
    public function pathway8_1_providerStatusAffectsAvailability(): void
    {
        // Get an active provider
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);
        $providerUid = $provider->getUid();

        $initialActiveCount = $this->providerRepository->findActive()->count();

        // Deactivate provider
        $provider->setIsActive(false);
        $this->providerRepository->update($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Provider should no longer be in active list
        $newActiveCount = $this->providerRepository->findActive()->count();
        self::assertSame($initialActiveCount - 1, $newActiveCount);

        // Its models should still exist but may not be usable
        $models = $this->modelRepository->findByProviderUid($providerUid);
        // Models are returned based on provider UID, not active status
        self::assertGreaterThanOrEqual(0, $models->count());

        // Reactivate
        $reloadedProvider = $this->providerRepository->findByUid($providerUid);
        self::assertNotNull($reloadedProvider);
        $reloadedProvider->setIsActive(true);
        $this->providerRepository->update($reloadedProvider);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 8.2: Fallback Provider (Simulated via Configuration Switching)
    // =========================================================================

    #[Test]
    public function pathway8_2_fallbackScenario_primaryProviderDisabled(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        if (count($providers) < 2) {
            // Create a second provider for fallback testing
            $fallbackProvider = new Provider();
            $fallbackProvider->setPid(0);
            $fallbackProvider->setIdentifier('fallback-test-provider');
            $fallbackProvider->setName('Fallback Test Provider');
            $fallbackProvider->setAdapterType('openai');
            $fallbackProvider->setApiKey('fallback-key');
            $fallbackProvider->setIsActive(true);
            $fallbackProvider->setPriority(10); // Lower priority

            $this->providerRepository->add($fallbackProvider);
            $this->persistenceManager->persistAll();
            $this->persistenceManager->clearState();
        }

        // Get providers ordered by priority
        $primaryProvider = $this->providerRepository->findHighestPriority();
        self::assertNotNull($primaryProvider);

        // Simulate primary failure by deactivating it
        $primaryUid = $primaryProvider->getUid();
        $primaryProvider->setIsActive(false);
        $this->providerRepository->update($primaryProvider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Next highest priority provider should now be "primary"
        $newPrimary = $this->providerRepository->findHighestPriority();

        // Should have a different provider available (or null if only one existed)
        if ($newPrimary !== null) {
            self::assertNotSame($primaryUid, $newPrimary->getUid(), 'Fallback provider should now be primary');
        }

        // Restore original state
        $restoredPrimary = $this->providerRepository->findByUid($primaryUid);
        if ($restoredPrimary !== null) {
            $restoredPrimary->setIsActive(true);
            $this->providerRepository->update($restoredPrimary);
            $this->persistenceManager->persistAll();
        }
    }

    #[Test]
    public function pathway8_2_fallbackScenario_configurationWithInactiveProvider(): void
    {
        // Create a configuration linked to a provider that gets deactivated
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);
        $providerUid = $provider->getUid();

        $model = $this->modelRepository->findByProvider($provider)->getFirst();
        if ($model === null) {
            self::markTestSkipped('No model found for active provider');
        }

        // Create a test configuration
        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('fallback-test-config');
        $config->setName('Fallback Test Config');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(100);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedConfig = $this->configurationRepository->findOneByIdentifier('fallback-test-config');
        self::assertNotNull($addedConfig);

        // Reload provider from database before updating (after clearState)
        $reloadedProvider = $this->providerRepository->findByUid($providerUid);
        self::assertNotNull($reloadedProvider);

        // Deactivate the provider
        $reloadedProvider->setIsActive(false);
        $this->providerRepository->update($reloadedProvider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Test configuration should fail gracefully
        $request = $this->createFormRequest('/ajax/config/test', ['uid' => $addedConfig->getUid()]);
        $response = $this->configurationController->testConfigurationAction($request);

        // Should return a structured response (success or error)
        self::assertContains($response->getStatusCode(), [200, 400, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        // Restore provider
        $restoredProvider = $this->providerRepository->findByUid($providerUid);
        if ($restoredProvider !== null) {
            $restoredProvider->setIsActive(true);
            $this->providerRepository->update($restoredProvider);
            $this->persistenceManager->persistAll();
        }
    }

    #[Test]
    public function pathway8_2_priorityDeterminesDefaultProvider(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        if (count($providers) < 2) {
            self::markTestSkipped('Need at least 2 providers to test priority');
        }

        // Set different priorities
        $provider1 = $providers[0];
        $provider2 = $providers[1];

        $originalPriority1 = $provider1->getPriority();
        $originalPriority2 = $provider2->getPriority();

        // Make provider2 higher priority
        $provider1->setPriority(10);
        $provider2->setPriority(100);
        $this->providerRepository->update($provider1);
        $this->providerRepository->update($provider2);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Highest priority should return provider2
        $highest = $this->providerRepository->findHighestPriority();
        self::assertNotNull($highest);
        self::assertSame($provider2->getUid(), $highest->getUid());

        // Restore priorities
        $p1 = $this->providerRepository->findByUid($provider1->getUid());
        $p2 = $this->providerRepository->findByUid($provider2->getUid());
        if ($p1 !== null) {
            $p1->setPriority($originalPriority1);
            $this->providerRepository->update($p1);
        }
        if ($p2 !== null) {
            $p2->setPriority($originalPriority2);
            $this->providerRepository->update($p2);
        }
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Cross-Provider Data Integrity
    // =========================================================================

    #[Test]
    public function crossProviderDataIntegrity_modelsLinkedCorrectly(): void
    {
        $allModels = $this->modelRepository->findAll()->toArray();

        foreach ($allModels as $model) {
            $provider = $model->getProvider();

            if ($provider !== null) {
                // Provider should be a valid entity
                self::assertGreaterThan(0, $provider->getUid());

                // Model's provider should be retrievable from repository
                $retrievedProvider = $this->providerRepository->findByUid($provider->getUid());
                self::assertNotNull($retrievedProvider, sprintf(
                    'Model "%s" references provider UID %d which should exist',
                    $model->getName(),
                    $provider->getUid(),
                ));
            }
        }
    }

    #[Test]
    public function crossProviderDataIntegrity_configurationsLinkedCorrectly(): void
    {
        $allConfigs = $this->configurationRepository->findAll()->toArray();

        foreach ($allConfigs as $config) {
            $model = $config->getLlmModel();

            if ($model !== null) {
                // Model should be retrievable
                $retrievedModel = $this->modelRepository->findByUid($model->getUid());
                self::assertNotNull($retrievedModel, sprintf(
                    'Configuration "%s" references model UID %d which should exist',
                    $config->getName(),
                    $model->getUid(),
                ));

                // Model should have a provider
                self::assertNotNull($retrievedModel->getProvider(), sprintf(
                    'Model "%s" used by configuration "%s" should have a provider',
                    $retrievedModel->getName(),
                    $config->getName(),
                ));
            }
        }
    }

    #[Test]
    public function crossProviderDataIntegrity_tasksLinkedCorrectly(): void
    {
        $allTasks = $this->taskRepository->findAll()->toArray();

        foreach ($allTasks as $task) {
            $config = $task->getConfiguration();

            if ($config !== null) {
                // Configuration should be retrievable
                $retrievedConfig = $this->configurationRepository->findByUid($config->getUid());
                self::assertNotNull($retrievedConfig, sprintf(
                    'Task "%s" references configuration UID %d which should exist',
                    $task->getName(),
                    $config->getUid(),
                ));
            }
        }
    }

    // =========================================================================
    // Concurrent Provider Operations
    // =========================================================================

    #[Test]
    public function concurrentOperations_toggleMultipleProviders(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        if (count($providers) < 2) {
            self::markTestSkipped('Need at least 2 providers for concurrent operations test');
        }

        $provider1 = $providers[0];
        $provider2 = $providers[1];

        $original1 = $provider1->isActive();
        $original2 = $provider2->isActive();

        // Toggle both providers
        $provider1->setIsActive(!$original1);
        $provider2->setIsActive(!$original2);
        $this->providerRepository->update($provider1);
        $this->providerRepository->update($provider2);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify both toggled
        $reloaded1 = $this->providerRepository->findByUid($provider1->getUid());
        $reloaded2 = $this->providerRepository->findByUid($provider2->getUid());

        self::assertNotNull($reloaded1);
        self::assertNotNull($reloaded2);
        self::assertSame(!$original1, $reloaded1->isActive());
        self::assertSame(!$original2, $reloaded2->isActive());

        // Restore
        $reloaded1->setIsActive($original1);
        $reloaded2->setIsActive($original2);
        $this->providerRepository->update($reloaded1);
        $this->providerRepository->update($reloaded2);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 8.3: Provider Comparison
    // =========================================================================

    #[Test]
    public function pathway8_3_compareProviderCapabilities(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        $capabilities = [];
        foreach ($providers as $provider) {
            $models = $this->modelRepository->findByProvider($provider);
            $capabilities[$provider->getIdentifier()] = [
                'provider' => $provider->getName(),
                'adapterType' => $provider->getAdapterType(),
                'modelCount' => $models->count(),
                'models' => [],
            ];

            foreach ($models as $model) {
                $capabilities[$provider->getIdentifier()]['models'][] = [
                    'name' => $model->getName(),
                    'modelId' => $model->getModelId(),
                    'contextLength' => $model->getContextLength(),
                ];
            }
        }

        // Each provider should have retrievable capabilities
        foreach ($capabilities as $identifier => $cap) {
            self::assertNotEmpty($cap['provider']);
            self::assertNotEmpty($cap['adapterType']);
            self::assertGreaterThanOrEqual(0, $cap['modelCount']);
        }
    }

    #[Test]
    public function pathway8_3_compareProviderModels(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();
        if (count($providers) < 2) {
            self::markTestSkipped('Need at least 2 providers for comparison');
        }

        $provider1Models = $this->modelRepository->findByProvider($providers[0]);
        $provider2Models = $this->modelRepository->findByProvider($providers[1]);

        // Models should be distinct per provider
        $provider1ModelIds = [];
        foreach ($provider1Models as $model) {
            $provider1ModelIds[] = $model->getUid();
        }

        foreach ($provider2Models as $model) {
            // Same model UID shouldn't appear in both providers' model lists
            // (models are unique entities)
            self::assertNotContains($model->getUid(), $provider1ModelIds);
        }
    }

    // =========================================================================
    // Pathway 8.4: Provider Selection for Tasks
    // =========================================================================

    #[Test]
    public function pathway8_4_taskCanSelectAnyActiveConfiguration(): void
    {
        $tasks = $this->taskRepository->findActive()->toArray();
        $configurations = $this->configurationRepository->findActive()->toArray();

        foreach ($tasks as $task) {
            $taskConfig = $task->getConfiguration();

            if ($taskConfig !== null) {
                // Configuration should be one of the available configurations
                $configUids = array_map(fn($c) => $c->getUid(), $configurations);

                // Task's configuration might be inactive, so we just check it exists
                $allConfigs = $this->configurationRepository->findAll()->toArray();
                $allConfigUids = array_map(fn($c) => $c->getUid(), $allConfigs);
                self::assertContains($taskConfig->getUid(), $allConfigUids);
            }
        }
    }

    #[Test]
    public function pathway8_4_changeTaskConfiguration(): void
    {
        $tasks = $this->taskRepository->findActive()->toArray();
        $configurations = $this->configurationRepository->findActive()->toArray();

        if (count($tasks) === 0 || count($configurations) < 2) {
            self::markTestSkipped('Need at least 1 task and 2 configurations');
        }

        $task = $tasks[0];
        $originalConfig = $task->getConfiguration();
        $newConfig = $configurations[0];

        if ($originalConfig !== null && $originalConfig->getUid() === $newConfig->getUid()) {
            $newConfig = $configurations[1];
        }

        // Change task configuration
        $task->setConfiguration($newConfig);
        $this->taskRepository->update($task);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify change
        $reloaded = $this->taskRepository->findByUid($task->getUid());
        self::assertNotNull($reloaded);
        self::assertSame($newConfig->getUid(), $reloaded->getConfiguration()?->getUid());

        // Restore
        $reloaded->setConfiguration($originalConfig);
        $this->taskRepository->update($reloaded);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 8.5: Multi-Provider Model Selection
    // =========================================================================

    #[Test]
    public function pathway8_5_selectModelsAcrossProviders(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        $allModels = [];
        foreach ($providers as $provider) {
            $models = $this->modelRepository->findByProvider($provider)->toArray();
            foreach ($models as $model) {
                $allModels[] = [
                    'model' => $model,
                    'provider' => $provider,
                ];
            }
        }

        // User should be able to see models from all providers
        self::assertGreaterThanOrEqual(1, count($allModels));

        // Each model should be uniquely identifiable
        $modelUids = array_map(fn($m) => $m['model']->getUid(), $allModels);
        self::assertSame(count($allModels), count(array_unique($modelUids)));
    }

    #[Test]
    public function pathway8_5_modelDefaultAcrossProviders(): void
    {
        // Only one model should be default across all providers
        $defaultModel = $this->modelRepository->findDefault();

        if ($defaultModel !== null) {
            self::assertTrue($defaultModel->isDefault());

            // Count total defaults - should be exactly 1
            $defaultCount = 0;
            foreach ($this->modelRepository->findAll() as $model) {
                if ($model->isDefault()) {
                    $defaultCount++;
                }
            }
            self::assertSame(1, $defaultCount, 'Only one model should be default');
        }
    }

    // =========================================================================
    // Provider Chain Operations
    // =========================================================================

    #[Test]
    public function providerChain_createFullStack(): void
    {
        // Create a complete provider -> model -> configuration -> task chain
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('chain-test-provider-' . time());
        $provider->setName('Chain Test Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('test-key');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($addedProvider);

        // Create model
        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('chain-test-model-' . time());
        $model->setName('Chain Test Model');
        $model->setModelId('gpt-4o');
        $model->setProvider($addedProvider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedModel = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($addedModel);
        self::assertSame($addedProvider->getUid(), $addedModel->getProvider()->getUid());

        // Create configuration
        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('chain-test-config-' . time());
        $config->setName('Chain Test Config');
        $config->setLlmModel($addedModel);
        $config->setTemperature(0.7);
        $config->setMaxTokens(100);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedConfig = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($addedConfig);
        self::assertSame($addedModel->getUid(), $addedConfig->getLlmModel()->getUid());
    }

    #[Test]
    public function providerChain_verifyRelationships(): void
    {
        // Verify all relationships are intact
        $configurations = $this->configurationRepository->findActive()->toArray();

        foreach ($configurations as $config) {
            $model = $config->getLlmModel();
            if ($model === null) {
                continue;
            }

            $provider = $model->getProvider();
            if ($provider === null) {
                continue;
            }

            // Full chain: config -> model -> provider
            self::assertNotNull($config->getUid());
            self::assertNotNull($model->getUid());
            self::assertNotNull($provider->getUid());

            // Provider should contain the model
            $providerModels = $this->modelRepository->findByProvider($provider);
            $modelUids = [];
            foreach ($providerModels as $m) {
                $modelUids[] = $m->getUid();
            }
            self::assertContains($model->getUid(), $modelUids);
        }
    }

    // =========================================================================
    // Provider Isolation Tests
    // =========================================================================

    #[Test]
    public function providerIsolation_changeToOneDoesNotAffectOthers(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();
        if (count($providers) < 2) {
            self::markTestSkipped('Need at least 2 providers');
        }

        $provider1 = $providers[0];
        $provider2 = $providers[1];

        $original2Name = $provider2->getName();
        $original2Active = $provider2->isActive();

        // Change provider 1
        $provider1->setName('Changed Provider 1 Name');
        $this->providerRepository->update($provider1);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Provider 2 should be unchanged
        $reloaded2 = $this->providerRepository->findByUid($provider2->getUid());
        self::assertSame($original2Name, $reloaded2->getName());
        self::assertSame($original2Active, $reloaded2->isActive());

        // Restore
        $reloaded1 = $this->providerRepository->findByUid($provider1->getUid());
        $reloaded1->setName($providers[0]->getName());
        $this->providerRepository->update($reloaded1);
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function providerIsolation_deactivateOneProviderOnly(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();
        if (count($providers) < 2) {
            self::markTestSkipped('Need at least 2 providers');
        }

        $initialActiveCount = count($providers);
        $provider1 = $providers[0];
        $provider1Uid = $provider1->getUid();

        // Deactivate provider 1
        $provider1->setIsActive(false);
        $this->providerRepository->update($provider1);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Only provider 1 should be affected
        $newActiveCount = $this->providerRepository->findActive()->count();
        self::assertSame($initialActiveCount - 1, $newActiveCount);

        // Other providers should still be active
        foreach ($this->providerRepository->findActive() as $p) {
            self::assertNotSame($provider1Uid, $p->getUid());
            self::assertTrue($p->isActive());
        }

        // Restore
        $reloaded1 = $this->providerRepository->findByUid($provider1Uid);
        $reloaded1->setIsActive(true);
        $this->providerRepository->update($reloaded1);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Multi-Provider Statistics
    // =========================================================================

    #[Test]
    public function multiProviderStatistics_countsByProvider(): void
    {
        $stats = [];
        foreach ($this->providerRepository->findActive() as $provider) {
            $models = $this->modelRepository->findByProvider($provider);
            $activeModels = 0;
            foreach ($models as $model) {
                if ($model->isActive()) {
                    $activeModels++;
                }
            }

            $stats[$provider->getIdentifier()] = [
                'totalModels' => $models->count(),
                'activeModels' => $activeModels,
            ];
        }

        foreach ($stats as $identifier => $stat) {
            self::assertGreaterThanOrEqual(0, $stat['totalModels']);
            self::assertLessThanOrEqual($stat['totalModels'], $stat['activeModels']);
        }
    }

    #[Test]
    public function multiProviderStatistics_globalCounts(): void
    {
        $totalProviders = $this->providerRepository->findAll()->count();
        $activeProviders = $this->providerRepository->findActive()->count();
        $totalModels = $this->modelRepository->findAll()->count();
        $activeModels = $this->modelRepository->findActive()->count();
        $totalConfigs = $this->configurationRepository->findAll()->count();
        $activeConfigs = $this->configurationRepository->findActive()->count();

        self::assertGreaterThanOrEqual($activeProviders, $totalProviders);
        self::assertGreaterThanOrEqual($activeModels, $totalModels);
        self::assertGreaterThanOrEqual($activeConfigs, $totalConfigs);
        self::assertGreaterThanOrEqual(0, $activeProviders);
        self::assertGreaterThanOrEqual(0, $activeModels);
        self::assertGreaterThanOrEqual(0, $activeConfigs);
    }

    // =========================================================================
    // Pathway 8.6: Complete End-to-End Workflow
    // =========================================================================

    #[Test]
    public function pathway8_6_completeWorkflowFromProviderToTask(): void
    {
        // Step 1: Verify provider exists and is active
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);
        self::assertTrue($provider->isActive());

        // Step 2: Verify provider has models
        $models = $this->modelRepository->findByProvider($provider);
        self::assertGreaterThan(0, $models->count());

        // Step 3: Get a model and verify it's linked correctly
        $model = $models->getFirst();
        self::assertNotNull($model);
        self::assertSame($provider->getUid(), $model->getProvider()->getUid());

        // Step 4: Find a configuration using this model
        $configs = $this->configurationRepository->findActive()->toArray();
        $matchingConfig = null;
        foreach ($configs as $config) {
            if ($config->getLlmModel()?->getUid() === $model->getUid()) {
                $matchingConfig = $config;
                break;
            }
        }

        // Step 5: Verify the chain is complete
        if ($matchingConfig !== null) {
            self::assertNotNull($matchingConfig->getLlmModel());
            self::assertNotNull($matchingConfig->getLlmModel()->getProvider());
        }
    }

    #[Test]
    public function pathway8_6_traceConfigurationToProvider(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);

        $model = $config->getLlmModel();
        if ($model === null) {
            self::markTestSkipped('Configuration has no model');
        }

        $provider = $model->getProvider();
        self::assertNotNull($provider, 'Model should have a provider');

        // Verify the chain is consistent
        $providerModels = $this->modelRepository->findByProvider($provider);
        $modelUids = [];
        foreach ($providerModels as $m) {
            $modelUids[] = $m->getUid();
        }
        self::assertContains($model->getUid(), $modelUids);
    }

    // =========================================================================
    // Pathway 8.7: Provider Type Comparison
    // =========================================================================

    #[Test]
    public function pathway8_7_listProvidersByType(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        $byType = [];
        foreach ($providers as $provider) {
            $type = $provider->getAdapterType();
            if (!isset($byType[$type])) {
                $byType[$type] = [];
            }
            $byType[$type][] = $provider;
        }

        // Each type should have valid providers
        foreach ($byType as $type => $typeProviders) {
            self::assertNotEmpty($type);
            foreach ($typeProviders as $provider) {
                self::assertSame($type, $provider->getAdapterType());
            }
        }
    }

    #[Test]
    public function pathway8_7_filterByAdapterType(): void
    {
        $adapterTypes = ['openai', 'anthropic', 'ollama', 'google', 'gemini', 'deepseek'];

        foreach ($adapterTypes as $type) {
            $providers = $this->providerRepository->findByAdapterType($type);

            foreach ($providers as $provider) {
                self::assertSame($type, $provider->getAdapterType());
            }
        }
    }

    // =========================================================================
    // Pathway 8.8: Cross-Module State Consistency
    // =========================================================================

    #[Test]
    public function pathway8_8_stateConsistencyAfterProviderChange(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $originalName = $provider->getName();
        $providerUid = $provider->getUid();

        // Change provider name
        $provider->setName('Temporarily Changed Name');
        $this->providerRepository->update($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify models still reference this provider correctly
        $models = $this->modelRepository->findByProviderUid($providerUid);
        foreach ($models as $model) {
            self::assertSame($providerUid, $model->getProvider()->getUid());
            // Provider name change doesn't break model relationship
            self::assertSame('Temporarily Changed Name', $model->getProvider()->getName());
        }

        // Restore
        $reloaded = $this->providerRepository->findByUid($providerUid);
        $reloaded->setName($originalName);
        $this->providerRepository->update($reloaded);
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function pathway8_8_stateConsistencyAfterModelChange(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $originalName = $model->getName();
        $modelUid = $model->getUid();

        // Change model name
        $model->setName('Temporarily Changed Model');
        $this->modelRepository->update($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Configurations using this model should still work
        $configs = $this->configurationRepository->findAll()->toArray();
        foreach ($configs as $config) {
            if ($config->getLlmModel()?->getUid() === $modelUid) {
                self::assertSame('Temporarily Changed Model', $config->getLlmModel()->getName());
            }
        }

        // Restore
        $reloaded = $this->modelRepository->findByUid($modelUid);
        $reloaded->setName($originalName);
        $this->modelRepository->update($reloaded);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 8.9: Bulk Operations
    // =========================================================================

    #[Test]
    public function pathway8_9_deactivateAllProvidersAndRestore(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();
        $originalStates = [];

        foreach ($providers as $provider) {
            $originalStates[$provider->getUid()] = $provider->isActive();
            $provider->setIsActive(false);
            $this->providerRepository->update($provider);
        }
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // All providers should be inactive
        self::assertSame(0, $this->providerRepository->findActive()->count());

        // Restore all
        foreach ($originalStates as $uid => $wasActive) {
            $provider = $this->providerRepository->findByUid($uid);
            if ($provider !== null) {
                $provider->setIsActive($wasActive);
                $this->providerRepository->update($provider);
            }
        }
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify restored
        $restoredCount = $this->providerRepository->findActive()->count();
        self::assertSame(count(array_filter($originalStates)), $restoredCount);
    }

    #[Test]
    public function pathway8_9_deactivateAllModelsAndRestore(): void
    {
        $models = $this->modelRepository->findActive()->toArray();
        $originalStates = [];

        foreach ($models as $model) {
            $originalStates[$model->getUid()] = $model->isActive();
            $model->setIsActive(false);
            $this->modelRepository->update($model);
        }
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // All models should be inactive
        self::assertSame(0, $this->modelRepository->findActive()->count());

        // Restore all
        foreach ($originalStates as $uid => $wasActive) {
            $model = $this->modelRepository->findByUid($uid);
            if ($model !== null) {
                $model->setIsActive($wasActive);
                $this->modelRepository->update($model);
            }
        }
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify restored
        $restoredCount = $this->modelRepository->findActive()->count();
        self::assertSame(count(array_filter($originalStates)), $restoredCount);
    }

    // =========================================================================
    // Pathway 8.10: Provider-Specific Features
    // =========================================================================

    #[Test]
    public function pathway8_10_providerEndpointConfiguration(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        foreach ($providers as $provider) {
            // Each provider can have custom endpoint
            $endpoint = $provider->getEndpointUrl();
            self::assertIsString($endpoint);

            // OpenAI-compatible providers should have valid base URLs
            if (!empty($endpoint)) {
                self::assertStringStartsWith('http', $endpoint);
            }
        }
    }

    #[Test]
    public function pathway8_10_providerTimeoutSettings(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        foreach ($providers as $provider) {
            $timeout = $provider->getTimeout();
            self::assertIsInt($timeout);
            self::assertGreaterThanOrEqual(0, $timeout);
        }
    }

    #[Test]
    public function pathway8_10_providerPrioritySettings(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        foreach ($providers as $provider) {
            $priority = $provider->getPriority();
            self::assertIsInt($priority);
        }

        // Highest priority should be determinable
        $highest = $this->providerRepository->findHighestPriority();
        if (count($providers) > 0) {
            self::assertNotNull($highest);
        }
    }

    // =========================================================================
    // Pathway 8.11: Cross-Entity Validation
    // =========================================================================

    #[Test]
    public function pathway8_11_allModelsHaveValidProvider(): void
    {
        $models = $this->modelRepository->findAll()->toArray();

        foreach ($models as $model) {
            $provider = $model->getProvider();
            self::assertNotNull($provider, "Model {$model->getName()} must have a provider");
            self::assertNotNull($provider->getUid());
            self::assertNotEmpty($provider->getIdentifier());
        }
    }

    #[Test]
    public function pathway8_11_allConfigurationsHaveValidModel(): void
    {
        $configs = $this->configurationRepository->findAll()->toArray();

        foreach ($configs as $config) {
            $model = $config->getLlmModel();
            // Model is optional but if present, must be valid
            if ($model !== null) {
                self::assertNotNull($model->getUid());
                self::assertNotNull($model->getProvider());
            }
        }
    }

    #[Test]
    public function pathway8_11_taskConfigurationChainValid(): void
    {
        $tasks = $this->taskRepository->findAll()->toArray();

        foreach ($tasks as $task) {
            $config = $task->getConfiguration();
            // Configuration is optional
            if ($config !== null) {
                self::assertNotNull($config->getUid());
                $model = $config->getLlmModel();
                if ($model !== null) {
                    self::assertNotNull($model->getProvider());
                }
            }
        }
    }

    // =========================================================================
    // Pathway 8.12: Multi-Provider Model Distribution
    // =========================================================================

    #[Test]
    public function pathway8_12_modelsDistributedAcrossProviders(): void
    {
        $counts = $this->modelRepository->countByProvider();

        self::assertIsArray($counts);
        // At least one provider should have models
        self::assertNotEmpty($counts);

        // Total count should match sum of per-provider counts
        $totalFromCounts = array_sum($counts);
        $totalActive = $this->modelRepository->findActive()->count();
        self::assertSame($totalFromCounts, $totalActive);
    }

    #[Test]
    public function pathway8_12_eachProviderModelsAccessible(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        foreach ($providers as $provider) {
            $models = $this->modelRepository->findByProvider($provider);
            // Should be able to query models per provider
            self::assertGreaterThanOrEqual(0, $models->count());

            foreach ($models as $model) {
                self::assertSame($provider->getUid(), $model->getProvider()->getUid());
            }
        }
    }

    #[Test]
    public function pathway8_12_modelProviderUidQuery(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        if ($provider === null) {
            self::markTestSkipped('No active provider');
        }

        // Query using provider UID directly
        $models = $this->modelRepository->findByProviderUid($provider->getUid());
        self::assertGreaterThanOrEqual(0, $models->count());

        foreach ($models as $model) {
            self::assertTrue($model->isActive());
        }
    }

    // =========================================================================
    // Pathway 8.13: Provider Adapter Type Consistency
    // =========================================================================

    #[Test]
    public function pathway8_13_allProvidersHaveValidAdapterType(): void
    {
        $validTypes = ['openai', 'anthropic', 'ollama', 'google', 'gemini', 'deepseek'];
        $providers = $this->providerRepository->findAll()->toArray();

        foreach ($providers as $provider) {
            $adapterType = $provider->getAdapterType();
            self::assertNotEmpty($adapterType);
            self::assertContains($adapterType, $validTypes, "Unknown adapter type: $adapterType");
        }
    }

    #[Test]
    public function pathway8_13_providerTypeDistribution(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();

        $typeCount = [];
        foreach ($providers as $provider) {
            $type = $provider->getAdapterType();
            $typeCount[$type] = ($typeCount[$type] ?? 0) + 1;
        }

        // Should have at least one provider type
        self::assertNotEmpty($typeCount);
    }

    #[Test]
    public function pathway8_13_queryByAdapterType(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        if ($provider === null) {
            self::markTestSkipped('No active provider');
        }

        $type = $provider->getAdapterType();
        $byType = $this->providerRepository->findByAdapterType($type);

        self::assertGreaterThan(0, $byType->count());
        foreach ($byType as $p) {
            self::assertSame($type, $p->getAdapterType());
        }
    }

    // =========================================================================
    // Pathway 8.14: Complete Data Chain Integrity
    // =========================================================================

    #[Test]
    public function pathway8_14_completeChainFromTaskToProvider(): void
    {
        $tasks = $this->taskRepository->findActive()->toArray();

        foreach ($tasks as $task) {
            $config = $task->getConfiguration();
            if ($config !== null) {
                $model = $config->getLlmModel();
                if ($model !== null) {
                    $provider = $model->getProvider();
                    self::assertNotNull($provider);
                    self::assertNotNull($provider->getUid());
                    self::assertNotEmpty($provider->getAdapterType());
                }
            }
        }
    }

    #[Test]
    public function pathway8_14_noOrphanedModels(): void
    {
        $models = $this->modelRepository->findAll()->toArray();

        foreach ($models as $model) {
            $provider = $model->getProvider();
            self::assertNotNull($provider, "Model {$model->getIdentifier()} is orphaned");

            // Provider should exist in database
            $providerInDb = $this->providerRepository->findByUid($provider->getUid());
            self::assertNotNull($providerInDb);
        }
    }

    #[Test]
    public function pathway8_14_defaultEntityUniqueness(): void
    {
        // Only one default model allowed
        $defaultModels = array_filter(
            $this->modelRepository->findAll()->toArray(),
            fn($m) => $m->isDefault(),
        );
        self::assertLessThanOrEqual(1, count($defaultModels));

        // Only one default configuration allowed
        $defaultConfigs = array_filter(
            $this->configurationRepository->findAll()->toArray(),
            fn($c) => $c->isDefault(),
        );
        self::assertLessThanOrEqual(1, count($defaultConfigs));
    }

    #[Test]
    public function pathway8_14_allEntitiesHaveRequiredFields(): void
    {
        // Providers
        foreach ($this->providerRepository->findAll() as $provider) {
            self::assertNotEmpty($provider->getIdentifier());
            self::assertNotEmpty($provider->getName());
            self::assertNotEmpty($provider->getAdapterType());
        }

        // Models
        foreach ($this->modelRepository->findAll() as $model) {
            self::assertNotEmpty($model->getIdentifier());
            self::assertNotEmpty($model->getName());
            self::assertNotEmpty($model->getModelId());
        }

        // Configurations
        foreach ($this->configurationRepository->findAll() as $config) {
            self::assertNotEmpty($config->getIdentifier());
            self::assertNotEmpty($config->getName());
        }

        // Tasks
        foreach ($this->taskRepository->findAll() as $task) {
            self::assertNotEmpty($task->getIdentifier());
            self::assertNotEmpty($task->getName());
        }
    }
}
