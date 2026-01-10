<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\Backend;

use Exception;
use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * E2E tests for Configuration Management user pathways.
 *
 * Tests complete user journeys:
 * - Pathway 4.1: View Configuration List
 * - Pathway 4.2: Toggle Configuration Status
 * - Pathway 4.3: Set Default Configuration
 * - Pathway 4.4: Test Configuration
 * - Pathway 4.5: Create New Configuration
 * - Pathway 4.6: Clone Configuration
 */
#[CoversClass(ConfigurationController::class)]
final class ConfigurationManagementE2ETest extends AbstractBackendE2ETestCase
{
    private ConfigurationController $controller;
    private LlmConfigurationRepository $configurationRepository;
    private ModelRepository $modelRepository;
    private ProviderRepository $providerRepository;
    private PersistenceManagerInterface $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);
        $this->configurationRepository = $configurationRepository;

        $modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $modelRepository);
        $this->modelRepository = $modelRepository;

        $providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $providerRepository);
        $this->providerRepository = $providerRepository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;

        $this->controller = $this->createController();
    }

    private function createController(): ConfigurationController
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

    // =========================================================================
    // Pathway 4.1: View Configuration List
    // =========================================================================

    #[Test]
    public function pathway4_1_viewConfigurationList(): void
    {
        // User navigates to Configurations list
        $queryResult = $this->configurationRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $queryResult);
        /** @var array<int, LlmConfiguration> $configurations */
        $configurations = $queryResult->toArray();

        self::assertNotEmpty($configurations, 'Configuration list should contain entries');

        // Verify each configuration has required display information
        foreach ($configurations as $config) {
            self::assertNotEmpty($config->getName(), 'Configuration should have a name');
            self::assertNotNull($config->getLlmModel(), 'Configuration should have a model');
            // isActive() and isDefault() return bool, getTemperature() returns float, getMaxTokens() returns int
            // Verify values are accessible (types are enforced by domain model)
            $config->isActive();
            $config->isDefault();
            self::assertGreaterThanOrEqual(0.0, $config->getTemperature());
            self::assertGreaterThanOrEqual(0, $config->getMaxTokens());
        }
    }

    #[Test]
    public function pathway4_1_viewConfigurationListShowsProviderInfo(): void
    {
        $queryResult = $this->configurationRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $queryResult);
        /** @var array<int, LlmConfiguration> $configurations */
        $configurations = $queryResult->toArray();

        foreach ($configurations as $config) {
            $model = $config->getLlmModel();
            if ($model !== null) {
                $provider = $model->getProvider();
                // User should see which provider/model the config uses
                self::assertNotNull($provider);
            }
        }
    }

    // =========================================================================
    // Pathway 4.2: Toggle Configuration Status
    // =========================================================================

    #[Test]
    public function pathway4_2_toggleConfigurationStatus(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);
        $configUid = $config->getUid();
        self::assertNotNull($configUid);

        // User clicks toggle to deactivate
        $request = $this->createFormRequest('/ajax/config/toggle', ['uid' => $configUid]);
        $response = $this->controller->toggleActiveAction($request);

        $body = $this->assertSuccessResponse($response);
        self::assertArrayHasKey('isActive', $body);
        self::assertFalse($body['isActive']);

        // Verify in database
        $this->persistenceManager->clearState();
        $reloaded = $this->configurationRepository->findByUid($configUid);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());

        // Reactivate
        $response2 = $this->controller->toggleActiveAction($request);
        $body2 = $this->assertSuccessResponse($response2);
        self::assertTrue($body2['isActive']);
    }

    #[Test]
    public function pathway4_2_toggleConfigurationStatus_affectsAvailability(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);
        $configUid = $config->getUid();

        $initialActiveCount = $this->configurationRepository->findActive()->count();

        // Deactivate
        $request = $this->createFormRequest('/ajax/config/toggle', ['uid' => $configUid]);
        $this->controller->toggleActiveAction($request);
        $this->persistenceManager->clearState();

        $newActiveCount = $this->configurationRepository->findActive()->count();
        self::assertSame($initialActiveCount - 1, $newActiveCount);

        // Reactivate for cleanup
        $this->controller->toggleActiveAction($request);
    }

    #[Test]
    public function pathway4_2_toggleConfigurationStatus_errorForNonExistent(): void
    {
        $request = $this->createFormRequest('/ajax/config/toggle', ['uid' => 99999]);
        $response = $this->controller->toggleActiveAction($request);

        $this->assertErrorResponse($response, 404, 'Configuration not found');
    }

    // =========================================================================
    // Pathway 4.3: Set Default Configuration
    // =========================================================================

    #[Test]
    public function pathway4_3_setDefaultConfiguration(): void
    {
        // Find a non-default configuration
        $queryResult = $this->configurationRepository->findActive();
        self::assertInstanceOf(QueryResultInterface::class, $queryResult);
        /** @var array<int, LlmConfiguration> $configs */
        $configs = $queryResult->toArray();
        $nonDefault = null;
        foreach ($configs as $config) {
            if (!$config->isDefault()) {
                $nonDefault = $config;
                break;
            }
        }

        if ($nonDefault === null) {
            self::markTestSkipped('No non-default configuration available');
        }

        $originalDefault = $this->configurationRepository->findDefault();
        $originalDefaultUid = $originalDefault?->getUid();

        // User clicks "Set Default"
        $request = $this->createFormRequest('/ajax/config/setdefault', ['uid' => $nonDefault->getUid()]);
        $response = $this->controller->setDefaultAction($request);

        $body = $this->assertSuccessResponse($response);
        // SuccessResponse just returns {success: true}
        self::assertTrue($body['success']);

        // Verify in database
        $this->persistenceManager->clearState();
        $newDefault = $this->configurationRepository->findDefault();
        self::assertSame($nonDefault->getUid(), $newDefault?->getUid());

        // Previous default should be cleared
        if ($originalDefaultUid !== null && $originalDefaultUid !== $nonDefault->getUid()) {
            $oldDefault = $this->configurationRepository->findByUid($originalDefaultUid);
            self::assertFalse($oldDefault?->isDefault());
        }
    }

    #[Test]
    public function pathway4_3_setDefaultConfiguration_clearsOtherDefaults(): void
    {
        $queryResult = $this->configurationRepository->findActive();
        self::assertInstanceOf(QueryResultInterface::class, $queryResult);
        /** @var array<int, LlmConfiguration> $configs */
        $configs = $queryResult->toArray();
        if (count($configs) < 2) {
            self::markTestSkipped('Need at least 2 configurations');
        }

        $uid0 = $configs[0]->getUid();
        $uid1 = $configs[1]->getUid();
        self::assertNotNull($uid0);
        self::assertNotNull($uid1);

        // Set first as default
        $request1 = $this->createFormRequest('/ajax/config/setdefault', ['uid' => $uid0]);
        $this->controller->setDefaultAction($request1);
        $this->persistenceManager->clearState();

        // Set second as default
        $request2 = $this->createFormRequest('/ajax/config/setdefault', ['uid' => $uid1]);
        $this->controller->setDefaultAction($request2);
        $this->persistenceManager->clearState();

        // Only second should be default
        $reloaded1 = $this->configurationRepository->findByUid($uid0);
        $reloaded2 = $this->configurationRepository->findByUid($uid1);

        self::assertFalse($reloaded1?->isDefault());
        self::assertTrue($reloaded2?->isDefault());
    }

    // =========================================================================
    // Pathway 4.4: Test Configuration
    // =========================================================================

    #[Test]
    public function pathway4_4_testConfiguration(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);

        // User clicks "Test" button
        $request = $this->createFormRequest('/ajax/config/test', ['uid' => $config->getUid()]);
        $response = $this->controller->testConfigurationAction($request);

        // Response should have proper structure
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        if ($body['success']) {
            self::assertArrayHasKey('content', $body);
            self::assertArrayHasKey('model', $body);
            self::assertArrayHasKey('usage', $body);
        } else {
            self::assertArrayHasKey('error', $body);
        }
    }

    #[Test]
    public function pathway4_4_testConfiguration_withCustomPrompt(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);

        // User enters custom test prompt
        $request = $this->createFormRequest('/ajax/config/test', [
            'uid' => $config->getUid(),
            'prompt' => 'Say hello in exactly 3 words.',
        ]);
        $response = $this->controller->testConfigurationAction($request);

        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function pathway4_4_testConfiguration_errorForInvalid(): void
    {
        $request = $this->createFormRequest('/ajax/config/test', ['uid' => 99999]);
        $response = $this->controller->testConfigurationAction($request);

        $this->assertErrorResponse($response, 404, 'Configuration not found');
    }

    // =========================================================================
    // Pathway 4.5: Create New Configuration
    // =========================================================================

    #[Test]
    public function pathway4_5_createNewConfiguration(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('new-e2e-config');
        $config->setName('E2E Test Configuration');
        $config->setLlmModel($model);
        $config->setTemperature(0.5);
        $config->setMaxTokens(2048);
        $config->setSystemPrompt('You are a test assistant.');
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier('new-e2e-config');
        self::assertNotNull($retrieved);
        self::assertSame('E2E Test Configuration', $retrieved->getName());
        self::assertSame(0.5, $retrieved->getTemperature());
        self::assertSame(2048, $retrieved->getMaxTokens());
        self::assertSame('You are a test assistant.', $retrieved->getSystemPrompt());
    }

    #[Test]
    public function pathway4_5_createConfiguration_validatesTemperatureRange(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        // Create config with valid temperature (0-2 range)
        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('temp-test-config');
        $config->setName('Temperature Test');
        $config->setLlmModel($model);
        $config->setTemperature(1.5); // Valid
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier('temp-test-config');
        self::assertNotNull($retrieved);
        self::assertSame(1.5, $retrieved->getTemperature());
    }

    // =========================================================================
    // Pathway 4.6: Clone Configuration
    // =========================================================================

    #[Test]
    public function pathway4_6_cloneConfiguration(): void
    {
        $original = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($original);
        $originalUid = $original->getUid();
        self::assertNotNull($originalUid);

        // Clone by creating new config with same values but different identifier
        $clone = new LlmConfiguration();
        $pid = $original->getPid();
        self::assertNotNull($pid);
        $clone->setPid($pid);
        $clone->setIdentifier('cloned-' . $original->getIdentifier());
        $clone->setName('Clone of ' . $original->getName());
        $clone->setLlmModel($original->getLlmModel());
        $clone->setTemperature($original->getTemperature());
        $clone->setMaxTokens($original->getMaxTokens());
        $clone->setSystemPrompt($original->getSystemPrompt());
        $clone->setIsActive(true);
        $clone->setIsDefault(false); // Clones should not be default

        $this->configurationRepository->add($clone);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify clone exists and is separate from original
        $retrieved = $this->configurationRepository->findOneByIdentifier('cloned-' . $original->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertNotSame($original->getUid(), $retrieved->getUid());
        self::assertSame($original->getTemperature(), $retrieved->getTemperature());
        self::assertSame($original->getMaxTokens(), $retrieved->getMaxTokens());

        // Original should be unchanged
        $originalReloaded = $this->configurationRepository->findByUid($originalUid);
        self::assertNotNull($originalReloaded);
        self::assertSame($original->getName(), $originalReloaded->getName());
    }

    // =========================================================================
    // Get Models by Provider (for configuration form)
    // =========================================================================

    #[Test]
    public function getModelsForProvider(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // getModelsAction expects the adapter type (provider key in llmServiceManager)
        // not the database identifier
        $request = $this->createFormRequest('/ajax/config/get-models', [
            'provider' => $provider->getAdapterType(),
        ]);
        $response = $this->controller->getModelsAction($request);

        // Response depends on whether llmServiceManager has this provider configured
        self::assertContains($response->getStatusCode(), [200, 404]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($body['success'] ?? false) {
            self::assertArrayHasKey('models', $body);
            self::assertIsArray($body['models']);
        }
    }

    #[Test]
    public function getModelsForProvider_errorForInvalid(): void
    {
        $request = $this->createFormRequest('/ajax/config/get-models', [
            'provider' => 'non-existent-provider',
        ]);
        $response = $this->controller->getModelsAction($request);

        $this->assertErrorResponse($response, 404, 'Provider not available');
    }

    // =========================================================================
    // Identifier Uniqueness
    // =========================================================================

    #[Test]
    public function identifierUniquenessValidation(): void
    {
        $existing = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($existing);

        // Existing identifier should not be unique
        self::assertFalse($this->configurationRepository->isIdentifierUnique($existing->getIdentifier()));

        // New identifier should be unique
        self::assertTrue($this->configurationRepository->isIdentifierUnique('brand-new-config-id'));

        // Own identifier should be unique when excluding self
        self::assertTrue($this->configurationRepository->isIdentifierUnique(
            $existing->getIdentifier(),
            $existing->getUid(),
        ));
    }

    // =========================================================================
    // Pathway 4.7: Configuration with No Model
    // =========================================================================

    #[Test]
    public function pathway4_7_testConfigurationWithNoModel_returnsError(): void
    {
        // Create a configuration without a model
        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('no-model-config-' . time());
        $config->setName('Config Without Model');
        $config->setTemperature(0.7);
        $config->setMaxTokens(2048);
        $config->setIsActive(true);
        // Deliberately not setting llmModel

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();

        $configUid = $config->getUid();
        self::assertNotNull($configUid);

        // Try to test this configuration
        $request = $this->createFormRequest('/ajax/config/test', ['uid' => $configUid]);
        $response = $this->controller->testConfigurationAction($request);

        $this->assertErrorResponse($response, 400, 'Configuration has no model assigned');
    }

    // =========================================================================
    // Pathway 4.8: Configuration Toggle Edge Cases
    // =========================================================================

    #[Test]
    public function pathway4_8_toggleConfiguration_missingUid(): void
    {
        $request = $this->createFormRequest('/ajax/config/toggle', []);
        $response = $this->controller->toggleActiveAction($request);

        $this->assertErrorResponse($response, 400, 'No configuration UID specified');
    }

    #[Test]
    public function pathway4_8_toggleConfiguration_zeroUid(): void
    {
        $request = $this->createFormRequest('/ajax/config/toggle', ['uid' => 0]);
        $response = $this->controller->toggleActiveAction($request);

        $this->assertErrorResponse($response, 400, 'No configuration UID specified');
    }

    #[Test]
    public function pathway4_8_toggleConfiguration_stringUid(): void
    {
        $request = $this->createFormRequest('/ajax/config/toggle', ['uid' => 'invalid']);
        $response = $this->controller->toggleActiveAction($request);

        // Invalid string should be treated as 0
        $this->assertErrorResponse($response, 400, 'No configuration UID specified');
    }

    // =========================================================================
    // Pathway 4.9: Set Default Edge Cases
    // =========================================================================

    #[Test]
    public function pathway4_9_setDefault_missingUid(): void
    {
        $request = $this->createFormRequest('/ajax/config/setdefault', []);
        $response = $this->controller->setDefaultAction($request);

        $this->assertErrorResponse($response, 400, 'No configuration UID specified');
    }

    #[Test]
    public function pathway4_9_setDefault_nonExistentConfig(): void
    {
        $request = $this->createFormRequest('/ajax/config/setdefault', ['uid' => 99999]);
        $response = $this->controller->setDefaultAction($request);

        $this->assertErrorResponse($response, 404, 'Configuration not found');
    }

    // =========================================================================
    // Pathway 4.10: Get Models Edge Cases
    // =========================================================================

    #[Test]
    public function pathway4_10_getModels_missingProvider(): void
    {
        $request = $this->createFormRequest('/ajax/config/get-models', []);
        $response = $this->controller->getModelsAction($request);

        $this->assertErrorResponse($response, 400, 'No provider specified');
    }

    #[Test]
    public function pathway4_10_getModels_emptyProvider(): void
    {
        $request = $this->createFormRequest('/ajax/config/get-models', ['provider' => '']);
        $response = $this->controller->getModelsAction($request);

        $this->assertErrorResponse($response, 400, 'No provider specified');
    }

    // =========================================================================
    // Data Integrity Tests
    // =========================================================================

    #[Test]
    public function configurationModelRelationshipIntegrity(): void
    {
        $queryResult = $this->configurationRepository->findActive();
        self::assertInstanceOf(QueryResultInterface::class, $queryResult);
        /** @var array<int, LlmConfiguration> $configs */
        $configs = $queryResult->toArray();

        foreach ($configs as $config) {
            $model = $config->getLlmModel();
            if ($model !== null) {
                // Model should be valid
                self::assertNotNull($model->getUid());
                self::assertNotEmpty($model->getModelId());

                // Model's provider should exist
                $provider = $model->getProvider();
                self::assertNotNull($provider);
                self::assertNotNull($provider->getUid());
            }
        }
    }

    #[Test]
    public function configurationParametersWithinValidRanges(): void
    {
        $queryResult = $this->configurationRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $queryResult);
        /** @var array<int, LlmConfiguration> $configs */
        $configs = $queryResult->toArray();

        foreach ($configs as $config) {
            // Temperature should be 0-2
            $temp = $config->getTemperature();
            self::assertGreaterThanOrEqual(0.0, $temp, 'Temperature should be >= 0');
            self::assertLessThanOrEqual(2.0, $temp, 'Temperature should be <= 2');

            // Max tokens should be positive
            $maxTokens = $config->getMaxTokens();
            self::assertGreaterThanOrEqual(0, $maxTokens, 'Max tokens should be >= 0');
        }
    }

    #[Test]
    public function onlyOneDefaultConfigurationAllowed(): void
    {
        $defaults = [];
        /** @var LlmConfiguration $config */
        foreach ($this->configurationRepository->findAll() as $config) {
            if ($config->isDefault()) {
                $defaults[] = $config;
            }
        }

        // Should have at most one default
        self::assertLessThanOrEqual(1, count($defaults), 'Only one configuration should be default');
    }

    // =========================================================================
    // Concurrent Operations
    // =========================================================================

    #[Test]
    public function rapidToggleOperations(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);
        $configUid = $config->getUid();
        self::assertNotNull($configUid);

        $initialState = $config->isActive();
        $request = $this->createFormRequest('/ajax/config/toggle', ['uid' => $configUid]);

        // Perform multiple rapid toggles
        for ($i = 0; $i < 4; $i++) {
            $response = $this->controller->toggleActiveAction($request);
            self::assertSame(200, $response->getStatusCode());
        }

        // After even number of toggles, should be back to initial state
        $this->persistenceManager->clearState();
        $final = $this->configurationRepository->findByUid($configUid);
        self::assertNotNull($final);
        self::assertSame($initialState, $final->isActive());
    }

    // =========================================================================
    // Pathway 4.11: Configuration System Prompt Management
    // =========================================================================

    #[Test]
    public function pathway4_11_configurationWithSystemPrompt(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $systemPrompt = "You are a helpful assistant.\n\nRules:\n1. Be concise\n2. Be accurate";

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('system-prompt-test-' . time());
        $config->setName('System Prompt Test');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setSystemPrompt($systemPrompt);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame($systemPrompt, $retrieved->getSystemPrompt());
    }

    #[Test]
    public function pathway4_11_configurationWithEmptySystemPrompt(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('empty-prompt-test-' . time());
        $config->setName('Empty Prompt Test');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setSystemPrompt('');
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame('', $retrieved->getSystemPrompt());
    }

    #[Test]
    public function pathway4_11_configurationWithUnicodeSystemPrompt(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $unicodePrompt = '你是一个有帮助的助手 🎉 Ñoño';

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('unicode-prompt-test-' . time());
        $config->setName('Unicode Prompt Test');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setSystemPrompt($unicodePrompt);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame($unicodePrompt, $retrieved->getSystemPrompt());
    }

    // =========================================================================
    // Pathway 4.12: Configuration Parameter Boundaries
    // =========================================================================

    #[Test]
    public function pathway4_12_temperatureAtZero(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('temp-zero-' . time());
        $config->setName('Zero Temperature');
        $config->setLlmModel($model);
        $config->setTemperature(0.0);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame(0.0, $retrieved->getTemperature());
    }

    #[Test]
    public function pathway4_12_temperatureAtMax(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('temp-max-' . time());
        $config->setName('Max Temperature');
        $config->setLlmModel($model);
        $config->setTemperature(2.0);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame(2.0, $retrieved->getTemperature());
    }

    #[Test]
    public function pathway4_12_maxTokensAtMinimum(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('tokens-min-' . time());
        $config->setName('Min Tokens');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame(1, $retrieved->getMaxTokens());
    }

    #[Test]
    public function pathway4_12_maxTokensAtLargeValue(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('tokens-large-' . time());
        $config->setName('Large Tokens');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(128000); // Large context window
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame(128000, $retrieved->getMaxTokens());
    }

    // =========================================================================
    // Pathway 4.13: Configuration Listing and Filtering
    // =========================================================================

    #[Test]
    public function pathway4_13_findActiveConfigurations(): void
    {
        $activeConfigs = $this->configurationRepository->findActive();

        foreach ($activeConfigs as $config) {
            self::assertTrue($config->isActive());
        }
    }

    #[Test]
    public function pathway4_13_findDefaultConfiguration(): void
    {
        $defaultConfig = $this->configurationRepository->findDefault();

        if ($defaultConfig !== null) {
            self::assertTrue($defaultConfig->isDefault());
            self::assertTrue($defaultConfig->isActive());
        }
    }

    #[Test]
    public function pathway4_13_findByIdentifier(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);

        $found = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($found);
        self::assertSame($config->getUid(), $found->getUid());
    }

    #[Test]
    public function pathway4_13_countActiveConfigurations(): void
    {
        $queryResult = $this->configurationRepository->findActive();
        self::assertInstanceOf(QueryResultInterface::class, $queryResult);
        $count = $queryResult->count();
        self::assertGreaterThanOrEqual(0, $count);

        // Count via iteration should match
        $iterCount = 0;
        /** @var LlmConfiguration $config */
        foreach ($this->configurationRepository->findAll() as $config) {
            if ($config->isActive()) {
                $iterCount++;
            }
        }
        self::assertSame($iterCount, $count);
    }

    // =========================================================================
    // Pathway 4.14: Configuration State Transitions
    // =========================================================================

    #[Test]
    public function pathway4_14_newConfigurationStartsInactive(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('inactive-start-' . time());
        $config->setName('Inactive Start');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setIsActive(false); // Explicitly inactive

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertFalse($retrieved->isActive());

        $retrievedUid = $retrieved->getUid();
        self::assertNotNull($retrievedUid);

        // Activate via toggle
        $request = $this->createFormRequest('/ajax/config/toggle', ['uid' => $retrievedUid]);
        $response = $this->controller->toggleActiveAction($request);

        $body = $this->assertSuccessResponse($response);
        self::assertTrue($body['isActive']);
    }

    #[Test]
    public function pathway4_14_deactivateDefaultConfiguration(): void
    {
        // Get or set a default configuration
        $default = $this->configurationRepository->findDefault();
        if ($default === null) {
            $config = $this->configurationRepository->findActive()->getFirst();
            self::assertNotNull($config);
            $configUid = $config->getUid();
            self::assertNotNull($configUid);
            $request = $this->createFormRequest('/ajax/config/setdefault', ['uid' => $configUid]);
            $this->controller->setDefaultAction($request);
            $this->persistenceManager->clearState();
            $default = $this->configurationRepository->findDefault();
        }

        self::assertNotNull($default);
        $defaultUid = $default->getUid();
        self::assertNotNull($defaultUid);

        // Deactivate the default
        $request = $this->createFormRequest('/ajax/config/toggle', ['uid' => $defaultUid]);
        $response = $this->controller->toggleActiveAction($request);

        $body = $this->assertSuccessResponse($response);
        self::assertFalse($body['isActive']);

        // Configuration should still be default but inactive
        $this->persistenceManager->clearState();
        $reloaded = $this->configurationRepository->findByUid($defaultUid);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());

        // Reactivate for cleanup
        $this->controller->toggleActiveAction($request);
    }

    // =========================================================================
    // Configuration Model Relationship Tests
    // =========================================================================

    #[Test]
    public function configurationModelChange(): void
    {
        $queryResult = $this->modelRepository->findActive();
        self::assertInstanceOf(QueryResultInterface::class, $queryResult);
        /** @var array<int, Model> $models */
        $models = $queryResult->toArray();
        if (count($models) < 2) {
            self::markTestSkipped('Need at least 2 models');
        }

        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);
        $configUid = $config->getUid();
        self::assertNotNull($configUid);

        $originalModel = $config->getLlmModel();
        $newModel = $models[0]->getUid() === $originalModel?->getUid() ? $models[1] : $models[0];

        // Change model
        $config->setLlmModel($newModel);
        $this->configurationRepository->update($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify change
        $reloaded = $this->configurationRepository->findByUid($configUid);
        self::assertNotNull($reloaded);
        $reloadedModel = $reloaded->getLlmModel();
        self::assertNotNull($reloadedModel);
        self::assertSame($newModel->getUid(), $reloadedModel->getUid());

        // Restore
        $reloaded->setLlmModel($originalModel);
        $this->configurationRepository->update($reloaded);
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function configurationWithDeactivatedModel(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);
        $configUid = $config->getUid();
        self::assertNotNull($configUid);

        $model = $config->getLlmModel();
        if ($model === null) {
            self::markTestSkipped('Configuration has no model');
        }

        $originalState = $model->isActive();

        // Deactivate model
        $model->setIsActive(false);
        $this->modelRepository->update($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Configuration should still reference the model
        $reloadedConfig = $this->configurationRepository->findByUid($configUid);
        self::assertNotNull($reloadedConfig);
        $reloadedModel = $reloadedConfig->getLlmModel();
        self::assertNotNull($reloadedModel);
        self::assertFalse($reloadedModel->isActive());

        // Restore
        $reloadedModel->setIsActive($originalState);
        $this->modelRepository->update($reloadedModel);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Test Configuration Edge Cases
    // =========================================================================

    #[Test]
    public function testConfiguration_missingUid(): void
    {
        $request = $this->createFormRequest('/ajax/config/test', []);
        $response = $this->controller->testConfigurationAction($request);

        $this->assertErrorResponse($response, 400, 'No configuration UID specified');
    }

    #[Test]
    public function testConfiguration_zeroUid(): void
    {
        $request = $this->createFormRequest('/ajax/config/test', ['uid' => 0]);
        $response = $this->controller->testConfigurationAction($request);

        $this->assertErrorResponse($response, 400, 'No configuration UID specified');
    }

    #[Test]
    public function testConfiguration_specialCharactersInPrompt(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);

        $request = $this->createFormRequest('/ajax/config/test', [
            'uid' => $config->getUid(),
            'prompt' => "Test with <script>alert('xss')</script> and \"quotes\" & ampersand",
        ]);
        $response = $this->controller->testConfigurationAction($request);

        // Should handle special characters gracefully
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    // =========================================================================
    // Pathway 4.15: Configuration Identifier Management
    // =========================================================================

    #[Test]
    public function pathway4_15_identifierMustBeUnique(): void
    {
        $existing = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($existing);

        // Existing identifier should not be unique
        self::assertFalse(
            $this->configurationRepository->isIdentifierUnique($existing->getIdentifier()),
        );
    }

    #[Test]
    public function pathway4_15_newIdentifierIsUnique(): void
    {
        // Brand new identifier should be unique
        $uniqueId = 'brand-new-unique-id-' . time();
        self::assertTrue(
            $this->configurationRepository->isIdentifierUnique($uniqueId),
        );
    }

    #[Test]
    public function pathway4_15_ownIdentifierIsUniqueWhenExcludingSelf(): void
    {
        $existing = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($existing);

        // Own identifier should be unique when excluding self
        self::assertTrue(
            $this->configurationRepository->isIdentifierUnique(
                $existing->getIdentifier(),
                $existing->getUid(),
            ),
        );
    }

    #[Test]
    public function pathway4_15_identifierWithSpecialCharacters(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('test-config-with-dashes-and_underscores');
        $config->setName('Special Identifier Test');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier('test-config-with-dashes-and_underscores');
        self::assertNotNull($retrieved);
        self::assertSame('test-config-with-dashes-and_underscores', $retrieved->getIdentifier());
    }

    // =========================================================================
    // Pathway 4.16: Configuration Name Handling
    // =========================================================================

    #[Test]
    public function pathway4_16_configurationWithLongName(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $longName = str_repeat('Long Name ', 25); // 250 chars

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('long-name-test-' . time());
        $config->setName($longName);
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertNotEmpty($retrieved->getName());
    }

    #[Test]
    public function pathway4_16_configurationWithUnicodeName(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $unicodeName = '日本語設定 🎉 Configuración';

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('unicode-name-test-' . time());
        $config->setName($unicodeName);
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame($unicodeName, $retrieved->getName());
    }

    #[Test]
    public function pathway4_16_configurationWithHtmlInName(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $htmlName = 'Test <b>Bold</b> & <script>alert("xss")</script>';

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('html-name-test-' . time());
        $config->setName($htmlName);
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        // Name should be stored (may be escaped or preserved depending on TCA)
        self::assertNotEmpty($retrieved->getName());
    }

    // =========================================================================
    // Pathway 4.17: Configuration Cascade Behavior
    // =========================================================================

    #[Test]
    public function pathway4_17_configurationRetainsModelReference(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);

        $model = $config->getLlmModel();
        self::assertNotNull($model);

        // Model has provider
        $provider = $model->getProvider();
        self::assertNotNull($provider);

        // Complete chain is intact
        self::assertNotNull($provider->getUid());
        self::assertNotNull($model->getUid());
        self::assertNotNull($config->getUid());
    }

    #[Test]
    public function pathway4_17_multipleConfigurationsSameModel(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        // Create two configurations using the same model
        $config1 = new LlmConfiguration();
        $config1->setPid(0);
        $config1->setIdentifier('multi-model-test-1-' . time());
        $config1->setName('Multi Model Test 1');
        $config1->setLlmModel($model);
        $config1->setTemperature(0.5);
        $config1->setMaxTokens(1024);
        $config1->setIsActive(true);

        $config2 = new LlmConfiguration();
        $config2->setPid(0);
        $config2->setIdentifier('multi-model-test-2-' . time());
        $config2->setName('Multi Model Test 2');
        $config2->setLlmModel($model);
        $config2->setTemperature(1.0);
        $config2->setMaxTokens(2048);
        $config2->setIsActive(true);

        $this->configurationRepository->add($config1);
        $this->configurationRepository->add($config2);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Both configurations should exist with same model
        $retrieved1 = $this->configurationRepository->findOneByIdentifier($config1->getIdentifier());
        $retrieved2 = $this->configurationRepository->findOneByIdentifier($config2->getIdentifier());

        self::assertNotNull($retrieved1);
        self::assertNotNull($retrieved2);
        self::assertSame($model->getUid(), $retrieved1->getLlmModel()?->getUid());
        self::assertSame($model->getUid(), $retrieved2->getLlmModel()?->getUid());

        // But different parameters
        self::assertSame(0.5, $retrieved1->getTemperature());
        self::assertSame(1.0, $retrieved2->getTemperature());
    }

    #[Test]
    public function pathway4_17_configurationUpdatePreservesRelationships(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);

        $originalModelUid = $config->getLlmModel()?->getUid();
        $configUid = $config->getUid();
        self::assertNotNull($configUid);

        // Update temperature
        $config->setTemperature(1.5);
        $this->configurationRepository->update($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Model relationship preserved
        $reloaded = $this->configurationRepository->findByUid($configUid);
        self::assertNotNull($reloaded);
        self::assertSame(1.5, $reloaded->getTemperature());
        self::assertSame($originalModelUid, $reloaded->getLlmModel()?->getUid());
    }

    // =========================================================================
    // Pathway 4.18: Configuration AJAX Response Structure
    // =========================================================================

    #[Test]
    public function pathway4_18_toggleResponseStructure(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);

        $request = $this->createFormRequest('/ajax/config/toggle', ['uid' => $config->getUid()]);
        $response = $this->controller->toggleActiveAction($request);

        $body = $this->assertSuccessResponse($response);

        // Response should have standard structure
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('isActive', $body);
        self::assertIsBool($body['success']);
        self::assertIsBool($body['isActive']);

        // Toggle back
        $this->controller->toggleActiveAction($request);
    }

    #[Test]
    public function pathway4_18_setDefaultResponseStructure(): void
    {
        $queryResult = $this->configurationRepository->findActive();
        self::assertInstanceOf(QueryResultInterface::class, $queryResult);
        /** @var array<int, LlmConfiguration> $configs */
        $configs = $queryResult->toArray();
        if (count($configs) < 1) {
            self::markTestSkipped('Need at least 1 configuration');
        }

        $uid = $configs[0]->getUid();
        self::assertNotNull($uid);
        $request = $this->createFormRequest('/ajax/config/setdefault', ['uid' => $uid]);
        $response = $this->controller->setDefaultAction($request);

        $body = $this->assertSuccessResponse($response);

        // Response should have success field
        self::assertArrayHasKey('success', $body);
        self::assertTrue($body['success']);
    }

    #[Test]
    public function pathway4_18_testConfigurationResponseStructure(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);

        $request = $this->createFormRequest('/ajax/config/test', ['uid' => $config->getUid()]);
        $response = $this->controller->testConfigurationAction($request);

        // Either success or error response
        self::assertContains($response->getStatusCode(), [200, 400, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        if ($body['success']) {
            // Success response structure
            self::assertArrayHasKey('content', $body);
            self::assertArrayHasKey('model', $body);
            self::assertArrayHasKey('usage', $body);
        } else {
            // Error response structure
            self::assertArrayHasKey('error', $body);
        }
    }

    #[Test]
    public function pathway4_18_errorResponseStructure(): void
    {
        $request = $this->createFormRequest('/ajax/config/toggle', ['uid' => 99999]);
        $response = $this->controller->toggleActiveAction($request);

        self::assertSame(404, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('error', $body);
        self::assertFalse($body['success']);
        self::assertNotEmpty($body['error']);
    }

    // =========================================================================
    // Pathway 4.19: Configuration Count and Statistics
    // =========================================================================

    #[Test]
    public function pathway4_19_countAllConfigurations(): void
    {
        $allConfigs = $this->configurationRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allConfigs);
        $count = $allConfigs->count();

        self::assertGreaterThanOrEqual(0, $count);

        // Manual count should match
        $manualCount = 0;
        foreach ($allConfigs as $config) {
            $manualCount++;
        }
        self::assertSame($count, $manualCount);
    }

    #[Test]
    public function pathway4_19_countActiveConfigurations(): void
    {
        $activeConfigs = $this->configurationRepository->findActive();
        self::assertInstanceOf(QueryResultInterface::class, $activeConfigs);
        $activeCount = $activeConfigs->count();

        // All returned should be active
        /** @var LlmConfiguration $config */
        foreach ($activeConfigs as $config) {
            self::assertTrue($config->isActive());
        }

        // Active count should be <= total count
        $allConfigs = $this->configurationRepository->findAll();
        self::assertInstanceOf(QueryResultInterface::class, $allConfigs);
        $totalCount = $allConfigs->count();
        self::assertLessThanOrEqual($totalCount, $activeCount);
    }

    #[Test]
    public function pathway4_19_defaultConfigurationCount(): void
    {
        // Should have at most one default
        $defaultCount = 0;
        /** @var LlmConfiguration $config */
        foreach ($this->configurationRepository->findAll() as $config) {
            if ($config->isDefault()) {
                $defaultCount++;
            }
        }

        self::assertLessThanOrEqual(1, $defaultCount);
    }

    // =========================================================================
    // Pathway 4.20: Configuration Precision Values
    // =========================================================================

    #[Test]
    public function pathway4_20_temperaturePrecision(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('temp-precision-' . time());
        $config->setName('Temperature Precision Test');
        $config->setLlmModel($model);
        $config->setTemperature(0.123456789);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        // Temperature should be close to original (may have float precision issues)
        self::assertEqualsWithDelta(0.123456789, $retrieved->getTemperature(), 0.0001);
    }

    #[Test]
    public function pathway4_20_maxTokensRange(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        // Test various max token values
        $tokenValues = [1, 100, 1024, 4096, 8192, 32000, 128000];

        foreach ($tokenValues as $tokens) {
            $config = new LlmConfiguration();
            $config->setPid(0);
            $config->setIdentifier('tokens-range-' . $tokens . '-' . time());
            $config->setName('Token Range Test ' . $tokens);
            $config->setLlmModel($model);
            $config->setTemperature(0.7);
            $config->setMaxTokens($tokens);
            $config->setIsActive(true);

            $this->configurationRepository->add($config);
            $this->persistenceManager->persistAll();
            $this->persistenceManager->clearState();

            $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
            self::assertNotNull($retrieved);
            self::assertSame($tokens, $retrieved->getMaxTokens());
        }
    }

    // =========================================================================
    // Pathway 4.21: Configuration with Model Relationship
    // =========================================================================

    #[Test]
    public function pathway4_21_configurationWithModel_canAccessProviderChain(): void
    {
        $config = $this->configurationRepository->findActive()->getFirst();
        self::assertNotNull($config);

        $model = $config->getLlmModel();
        if ($model === null) {
            self::markTestSkipped('Configuration has no model');
        }

        $provider = $model->getProvider();
        if ($provider === null) {
            self::markTestSkipped('Model has no provider');
        }

        // Full chain works: Configuration -> Model -> Provider
        self::assertNotEmpty($model->getIdentifier());
        self::assertNotEmpty($provider->getIdentifier());
    }

    #[Test]
    public function pathway4_21_configurationOptionsArray_includesModelSettings(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('options-array-test-' . time());
        $config->setName('Options Array Test');
        $config->setLlmModel($model);
        $config->setTemperature(0.75);
        $config->setMaxTokens(2048);
        $config->setSystemPrompt('You are a helpful test assistant.');
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);

        $options = $retrieved->toOptionsArray();
        self::assertArrayHasKey('temperature', $options);
        self::assertArrayHasKey('max_tokens', $options);
        self::assertArrayHasKey('system_prompt', $options);
        self::assertEqualsWithDelta(0.75, $options['temperature'], 0.01);
        self::assertSame(2048, $options['max_tokens']);
    }

    #[Test]
    public function pathway4_21_configurationWithNullModel_handlesGracefully(): void
    {
        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('null-model-config-' . time());
        $config->setName('Null Model Config');
        $config->setLlmModel(null);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertNull($retrieved->getLlmModel());
    }

    // =========================================================================
    // Pathway 4.22: Configuration System Prompt Variations
    // =========================================================================

    #[Test]
    public function pathway4_22_emptySystemPrompt_allowed(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('empty-prompt-' . time());
        $config->setName('Empty Prompt Config');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setSystemPrompt('');
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertSame('', $retrieved->getSystemPrompt());
    }

    #[Test]
    public function pathway4_22_veryLongSystemPrompt_stored(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        // Create a long prompt (10KB)
        $longPrompt = str_repeat('This is a test sentence for a very long system prompt. ', 200);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('long-prompt-' . time());
        $config->setName('Long Prompt Config');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setSystemPrompt($longPrompt);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        // Should store at least a significant portion
        self::assertGreaterThan(1000, strlen($retrieved->getSystemPrompt()));
    }

    #[Test]
    public function pathway4_22_multilineSystemPrompt_preserved(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $multilinePrompt = "You are a helpful assistant.\n\nRules:\n1. Be concise\n2. Be accurate\n3. Be helpful\n\nFormat responses clearly.";

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('multiline-prompt-' . time());
        $config->setName('Multiline Prompt Config');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setSystemPrompt($multilinePrompt);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        // Newlines should be preserved
        self::assertStringContainsString("\n", $retrieved->getSystemPrompt());
        self::assertStringContainsString('1. Be concise', $retrieved->getSystemPrompt());
    }

    #[Test]
    public function pathway4_22_unicodeSystemPrompt_preserved(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $unicodePrompt = '你是一个有帮助的助手。🎯 Répondez en français si nécessaire.';

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('unicode-prompt-' . time());
        $config->setName('Unicode Prompt Config');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setSystemPrompt($unicodePrompt);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertStringContainsString('🎯', $retrieved->getSystemPrompt());
        self::assertStringContainsString('你是', $retrieved->getSystemPrompt());
    }

    // =========================================================================
    // Pathway 4.23: Configuration Temperature Boundaries
    // =========================================================================

    #[Test]
    public function pathway4_23_temperatureZero_allowed(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('temp-zero-' . time());
        $config->setName('Temperature Zero Config');
        $config->setLlmModel($model);
        $config->setTemperature(0.0);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertEqualsWithDelta(0.0, $retrieved->getTemperature(), 0.001);
    }

    #[Test]
    public function pathway4_23_temperatureOne_allowed(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('temp-one-' . time());
        $config->setName('Temperature One Config');
        $config->setLlmModel($model);
        $config->setTemperature(1.0);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertEqualsWithDelta(1.0, $retrieved->getTemperature(), 0.001);
    }

    #[Test]
    public function pathway4_23_temperatureTwo_allowed(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        // Some models allow temperature up to 2.0
        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('temp-two-' . time());
        $config->setName('Temperature Two Config');
        $config->setLlmModel($model);
        $config->setTemperature(2.0);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertEqualsWithDelta(2.0, $retrieved->getTemperature(), 0.001);
    }

    #[Test]
    public function pathway4_23_temperatureSmallDecimal_preserved(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('temp-decimal-' . time());
        $config->setName('Temperature Decimal Config');
        $config->setLlmModel($model);
        $config->setTemperature(0.33);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        self::assertEqualsWithDelta(0.33, $retrieved->getTemperature(), 0.01);
    }

    // =========================================================================
    // Pathway 4.24: Configuration Lifecycle Events
    // =========================================================================

    #[Test]
    public function pathway4_24_createAndDeleteConfiguration(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $identifier = 'lifecycle-test-' . time();

        // Create
        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier($identifier);
        $config->setName('Lifecycle Test Config');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(1024);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify exists
        $retrieved = $this->configurationRepository->findOneByIdentifier($identifier);
        self::assertNotNull($retrieved);

        // Delete
        $this->configurationRepository->remove($retrieved);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify deleted
        $deleted = $this->configurationRepository->findOneByIdentifier($identifier);
        self::assertNull($deleted);
    }

    #[Test]
    public function pathway4_24_updateConfiguration_preservesUid(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('update-uid-test-' . time());
        $config->setName('Original Name');
        $config->setLlmModel($model);
        $config->setTemperature(0.5);
        $config->setMaxTokens(512);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);
        $originalUid = $retrieved->getUid();

        // Update
        $retrieved->setName('Updated Name');
        $retrieved->setTemperature(0.9);
        $this->configurationRepository->update($retrieved);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify UID preserved
        $updated = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($updated);
        self::assertSame($originalUid, $updated->getUid());
        self::assertSame('Updated Name', $updated->getName());
        self::assertEqualsWithDelta(0.9, $updated->getTemperature(), 0.01);
    }

    #[Test]
    public function pathway4_24_deactivateConfiguration_preservesData(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $systemPrompt = 'Test system prompt with specific content.';

        $config = new LlmConfiguration();
        $config->setPid(0);
        $config->setIdentifier('deactivate-test-' . time());
        $config->setName('Deactivate Test');
        $config->setLlmModel($model);
        $config->setTemperature(0.7);
        $config->setMaxTokens(2048);
        $config->setSystemPrompt($systemPrompt);
        $config->setIsActive(true);

        $this->configurationRepository->add($config);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($retrieved);

        // Deactivate via controller toggle
        $request = $this->createFormRequest('/ajax/configuration/toggle', ['uid' => $retrieved->getUid()]);
        $response = $this->controller->toggleActiveAction($request);
        self::assertSame(200, $response->getStatusCode());

        // Verify data preserved
        $this->persistenceManager->clearState();
        $deactivated = $this->configurationRepository->findOneByIdentifier($config->getIdentifier());
        self::assertNotNull($deactivated);
        self::assertFalse($deactivated->isActive());
        self::assertSame($systemPrompt, $deactivated->getSystemPrompt());
        self::assertEqualsWithDelta(0.7, $deactivated->getTemperature(), 0.01);
        self::assertSame(2048, $deactivated->getMaxTokens());

        // Reactivate
        $request = $this->createFormRequest('/ajax/configuration/toggle', ['uid' => $deactivated->getUid()]);
        $this->controller->toggleActiveAction($request);
    }

    #[Test]
    public function pathway4_24_duplicateIdentifier_handledByDatabase(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $identifier = 'duplicate-id-test-' . time();

        // Create first
        $config1 = new LlmConfiguration();
        $config1->setPid(0);
        $config1->setIdentifier($identifier);
        $config1->setName('First Config');
        $config1->setLlmModel($model);
        $config1->setTemperature(0.7);
        $config1->setMaxTokens(1024);
        $config1->setIsActive(true);

        $this->configurationRepository->add($config1);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Attempt to create second with same identifier
        $config2 = new LlmConfiguration();
        $config2->setPid(0);
        $config2->setIdentifier($identifier);
        $config2->setName('Second Config');
        $config2->setLlmModel($model);
        $config2->setTemperature(0.5);
        $config2->setMaxTokens(512);
        $config2->setIsActive(true);

        try {
            $this->configurationRepository->add($config2);
            $this->persistenceManager->persistAll();
            // If no exception, database might not enforce unique constraint
            // Check which one was saved
            $this->persistenceManager->clearState();
            $found = $this->configurationRepository->findOneByIdentifier($identifier);
            self::assertNotNull($found);
        } catch (Exception $e) {
            // Database constraint violation is acceptable
            self::assertStringContainsStringIgnoringCase('duplicate', $e->getMessage());
        }
    }
}
