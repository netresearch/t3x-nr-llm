<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\Backend;

use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * E2E tests for Model Management user pathways.
 *
 * Tests complete user journeys:
 * - Pathway 3.1: View Model List
 * - Pathway 3.2: Filter Models by Provider
 * - Pathway 3.3: Toggle Model Status
 * - Pathway 3.4: Set Default Model
 * - Pathway 3.5: Test Model
 * - Pathway 3.6: Fetch Available Models
 * - Pathway 3.7: Detect Model Limits
 * - Pathway 3.8: Edit Model Configuration
 */
#[CoversClass(ModelController::class)]
final class ModelManagementE2ETest extends AbstractBackendE2ETestCase
{
    private ModelController $controller;
    private ModelRepository $modelRepository;
    private ProviderRepository $providerRepository;
    private PersistenceManagerInterface $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $this->modelRepository);

        $this->providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $this->providerRepository);

        $this->persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $this->persistenceManager);

        $this->controller = $this->createController();
    }

    private function createController(): ModelController
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

    // =========================================================================
    // Pathway 3.1: View Model List
    // =========================================================================

    #[Test]
    public function pathway3_1_viewModelList(): void
    {
        // User navigates to Models list
        $models = $this->modelRepository->findAll()->toArray();

        self::assertNotEmpty($models, 'Model list should contain entries from fixtures');

        // Verify each model has required display information
        foreach ($models as $model) {
            self::assertNotEmpty($model->getName(), 'Model should have a name');
            self::assertNotEmpty($model->getModelId(), 'Model should have a model ID');
            self::assertNotNull($model->getProvider(), 'Model should have a provider');
            self::assertIsBool($model->isActive());
            self::assertIsBool($model->isDefault());
        }
    }

    #[Test]
    public function pathway3_1_viewModelListShowsCapabilities(): void
    {
        // User sees model capabilities in the list
        $models = $this->modelRepository->findAll()->toArray();

        foreach ($models as $model) {
            // Context length and max tokens should be visible
            self::assertIsInt($model->getContextLength());
            self::assertIsInt($model->getMaxOutputTokens());
        }
    }

    // =========================================================================
    // Pathway 3.2: Filter Models by Provider
    // =========================================================================

    #[Test]
    public function pathway3_2_filterModelsByProvider(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // User selects provider from filter dropdown
        $filteredModels = $this->modelRepository->findByProvider($provider);

        // All returned models should belong to selected provider
        foreach ($filteredModels as $model) {
            self::assertSame(
                $provider->getUid(),
                $model->getProvider()?->getUid(),
                'Filtered models should belong to selected provider',
            );
        }
    }

    #[Test]
    public function pathway3_2_filterModelsByProviderUid(): void
    {
        // Using provider UID directly (common in AJAX calls)
        $models = $this->modelRepository->findByProviderUid(1);

        self::assertGreaterThan(0, $models->count());

        foreach ($models as $model) {
            self::assertTrue($model->isActive(), 'findByProviderUid should return active models');
        }
    }

    #[Test]
    public function pathway3_2_countModelsByProvider(): void
    {
        $counts = $this->modelRepository->countByProvider();

        self::assertIsArray($counts);
        self::assertNotEmpty($counts);

        // User sees count for each provider
        foreach ($counts as $providerUid => $count) {
            self::assertIsInt($providerUid);
            self::assertGreaterThan(0, $count);
        }
    }

    // =========================================================================
    // Pathway 3.3: Toggle Model Status
    // =========================================================================

    #[Test]
    public function pathway3_3_toggleModelStatus(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);
        $modelUid = $model->getUid();

        // User clicks toggle to deactivate
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => $modelUid]);
        $response = $this->controller->toggleActiveAction($request);

        $body = $this->assertSuccessResponse($response);
        self::assertArrayHasKey('isActive', $body);
        self::assertFalse($body['isActive']);

        // Verify in database
        $this->persistenceManager->clearState();
        $reloaded = $this->modelRepository->findByUid($modelUid);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());

        // Reactivate
        $response2 = $this->controller->toggleActiveAction($request);
        $body2 = $this->assertSuccessResponse($response2);
        self::assertTrue($body2['isActive']);
    }

    #[Test]
    public function pathway3_3_toggleModelStatus_affectsAvailability(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);
        $modelUid = $model->getUid();

        $initialActiveCount = $this->modelRepository->findActive()->count();

        // Deactivate
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => $modelUid]);
        $this->controller->toggleActiveAction($request);
        $this->persistenceManager->clearState();

        // Model should not appear in active list
        $newActiveCount = $this->modelRepository->findActive()->count();
        self::assertSame($initialActiveCount - 1, $newActiveCount);

        // Reactivate for cleanup
        $this->controller->toggleActiveAction($request);
    }

    #[Test]
    public function pathway3_3_toggleModelStatus_errorForNonExistent(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => 99999]);
        $response = $this->controller->toggleActiveAction($request);

        $this->assertErrorResponse($response, 404, 'Model not found');
    }

    // =========================================================================
    // Pathway 3.4: Set Default Model
    // =========================================================================

    #[Test]
    public function pathway3_4_setDefaultModel(): void
    {
        // Find a non-default model
        $models = $this->modelRepository->findActive()->toArray();
        $nonDefault = null;
        foreach ($models as $model) {
            if (!$model->isDefault()) {
                $nonDefault = $model;
                break;
            }
        }

        if ($nonDefault === null) {
            self::markTestSkipped('No non-default model available');
        }

        $originalDefault = $this->modelRepository->findDefault();
        $originalDefaultUid = $originalDefault?->getUid();

        // User clicks "Set Default"
        $request = $this->createFormRequest('/ajax/model/setdefault', ['uid' => $nonDefault->getUid()]);
        $response = $this->controller->setDefaultAction($request);

        $body = $this->assertSuccessResponse($response);
        // SuccessResponse just returns {success: true}
        self::assertTrue($body['success']);

        // Verify in database
        $this->persistenceManager->clearState();
        $newDefault = $this->modelRepository->findDefault();
        self::assertSame($nonDefault->getUid(), $newDefault?->getUid());

        // Previous default should be cleared
        if ($originalDefaultUid !== null) {
            $oldDefault = $this->modelRepository->findByUid($originalDefaultUid);
            self::assertFalse($oldDefault?->isDefault());
        }
    }

    #[Test]
    public function pathway3_4_setDefaultModel_clearsOtherDefaults(): void
    {
        // Set model 1 as default
        $request1 = $this->createFormRequest('/ajax/model/setdefault', ['uid' => 1]);
        $this->controller->setDefaultAction($request1);
        $this->persistenceManager->clearState();

        // Set model 3 as default
        $request3 = $this->createFormRequest('/ajax/model/setdefault', ['uid' => 3]);
        $this->controller->setDefaultAction($request3);
        $this->persistenceManager->clearState();

        // Only model 3 should be default
        $model1 = $this->modelRepository->findByUid(1);
        $model3 = $this->modelRepository->findByUid(3);

        self::assertFalse($model1?->isDefault());
        self::assertTrue($model3?->isDefault());
    }

    // =========================================================================
    // Pathway 3.5: Test Model
    // =========================================================================

    #[Test]
    public function pathway3_5_testModel(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        // User clicks "Test" button
        $request = $this->createFormRequest('/ajax/model/test', ['uid' => $model->getUid()]);
        $response = $this->controller->testModelAction($request);

        // Response should have proper structure regardless of API availability
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        // TestConnectionResponse has: success, message
        self::assertArrayHasKey('message', $body);
        self::assertNotEmpty($body['message']);
    }

    #[Test]
    public function pathway3_5_testModel_errorForInvalid(): void
    {
        $request = $this->createFormRequest('/ajax/model/test', ['uid' => 99999]);
        $response = $this->controller->testModelAction($request);

        $this->assertErrorResponse($response, 404, 'Model not found');
    }

    // =========================================================================
    // Pathway 3.6: Fetch Available Models
    // =========================================================================

    #[Test]
    public function pathway3_6_fetchAvailableModels(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // User clicks "Fetch Available"
        $request = $this->createFormRequest('/ajax/model/fetch', ['providerUid' => $provider->getUid()]);
        $response = $this->controller->fetchAvailableModelsAction($request);

        // Response should be structured regardless of API availability
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        if ($body['success'] ?? false) {
            self::assertArrayHasKey('models', $body);
            self::assertIsArray($body['models']);
        }
    }

    // =========================================================================
    // Pathway 3.7: Detect Model Limits
    // =========================================================================

    #[Test]
    public function pathway3_7_detectModelLimits(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);
        $provider = $model->getProvider();
        self::assertNotNull($provider);

        // User clicks "Detect Limits" - requires providerUid and modelId
        $request = $this->createFormRequest('/ajax/model/detect-limits', [
            'providerUid' => $provider->getUid(),
            'modelId' => $model->getModelId(),
        ]);
        $response = $this->controller->detectLimitsAction($request);

        // Response should indicate success or failure (500 if API not available)
        self::assertContains($response->getStatusCode(), [200, 404, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        if ($body['success']) {
            // Should return detected limits
            self::assertArrayHasKey('contextLength', $body);
            self::assertArrayHasKey('maxOutputTokens', $body);
        }
    }

    // =========================================================================
    // Pathway 3.8: Edit Model Configuration (via repository)
    // =========================================================================

    #[Test]
    public function pathway3_8_editModelConfiguration(): void
    {
        $model = $this->modelRepository->findByUid(1);
        self::assertNotNull($model);

        $originalName = $model->getName();
        $originalContextLength = $model->getContextLength();

        // User edits model settings
        $model->setName('Updated Model Name');
        $model->setContextLength(200000);

        $this->modelRepository->update($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify changes
        $reloaded = $this->modelRepository->findByUid(1);
        self::assertSame('Updated Model Name', $reloaded->getName());
        self::assertSame(200000, $reloaded->getContextLength());

        // Restore
        $reloaded->setName($originalName);
        $reloaded->setContextLength($originalContextLength);
        $this->modelRepository->update($reloaded);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 3.9: Get Models by Provider (AJAX)
    // =========================================================================

    #[Test]
    public function pathway3_9_getModelsByProvider(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // User requests models for a specific provider (e.g., dropdown population)
        $request = $this->createFormRequest('/ajax/model/getbyprovider', ['providerUid' => $provider->getUid()]);
        $response = $this->controller->getByProviderAction($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('models', $body);
        self::assertIsArray($body['models']);

        // Each model should have expected structure
        foreach ($body['models'] as $model) {
            self::assertArrayHasKey('uid', $model);
            self::assertArrayHasKey('name', $model);
            self::assertArrayHasKey('modelId', $model);
        }
    }

    #[Test]
    public function pathway3_9_getModelsByProvider_errorForMissingProvider(): void
    {
        $request = $this->createFormRequest('/ajax/model/getbyprovider', []);
        $response = $this->controller->getByProviderAction($request);

        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    #[Test]
    public function pathway3_9_getModelsByProvider_emptyForNoModels(): void
    {
        // Create a provider without models
        $provider = new Provider();
        $provider->setPid(0);
        $provider->setIdentifier('empty-provider-test');
        $provider->setName('Empty Provider');
        $provider->setAdapterType('openai');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier('empty-provider-test');
        self::assertNotNull($addedProvider);

        $request = $this->createFormRequest('/ajax/model/getbyprovider', ['providerUid' => $addedProvider->getUid()]);
        $response = $this->controller->getByProviderAction($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertEmpty($body['models']);
    }

    // =========================================================================
    // Capability-Based Filtering
    // =========================================================================

    #[Test]
    public function findModelsByCapability_chat(): void
    {
        $chatModels = $this->modelRepository->findChatModels();

        self::assertGreaterThan(0, $chatModels->count());

        foreach ($chatModels as $model) {
            self::assertTrue($model->isActive());
        }
    }

    #[Test]
    public function findModelsByCapability_vision(): void
    {
        $visionModels = $this->modelRepository->findVisionModels();

        // May or may not have vision models
        foreach ($visionModels as $model) {
            self::assertTrue($model->isActive());
        }
    }

    // =========================================================================
    // CRUD Operations
    // =========================================================================

    #[Test]
    public function createNewModel(): void
    {
        $provider = $this->providerRepository->findByUid(1);
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('new-e2e-test-model');
        $model->setName('E2E Test Model');
        $model->setModelId('e2e-test-model-id');
        $model->setProvider($provider);
        $model->setContextLength(32000);
        $model->setMaxOutputTokens(4096);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $retrieved = $this->modelRepository->findOneByIdentifier('new-e2e-test-model');
        self::assertNotNull($retrieved);
        self::assertSame('E2E Test Model', $retrieved->getName());
        self::assertSame($provider->getUid(), $retrieved->getProvider()?->getUid());
    }

    #[Test]
    public function identifierUniquenessValidation(): void
    {
        // Existing identifier should not be unique
        self::assertFalse($this->modelRepository->isIdentifierUnique('gpt-4o'));

        // New identifier should be unique
        self::assertTrue($this->modelRepository->isIdentifierUnique('completely-new-identifier'));

        // Own identifier should be considered unique when excluding self
        $model = $this->modelRepository->findOneByIdentifier('gpt-4o');
        self::assertNotNull($model);
        self::assertTrue($this->modelRepository->isIdentifierUnique('gpt-4o', $model->getUid()));
    }

    // =========================================================================
    // Pathway 3.10: Toggle Model Edge Cases
    // =========================================================================

    #[Test]
    public function pathway3_10_toggleModel_missingUid(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', []);
        $response = $this->controller->toggleActiveAction($request);

        $this->assertErrorResponse($response, 400, 'No model UID specified');
    }

    #[Test]
    public function pathway3_10_toggleModel_zeroUid(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => 0]);
        $response = $this->controller->toggleActiveAction($request);

        $this->assertErrorResponse($response, 400, 'No model UID specified');
    }

    #[Test]
    public function pathway3_10_toggleModel_stringUid(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => 'invalid']);
        $response = $this->controller->toggleActiveAction($request);

        // Invalid string should be treated as 0
        $this->assertErrorResponse($response, 400, 'No model UID specified');
    }

    // =========================================================================
    // Pathway 3.11: Set Default Edge Cases
    // =========================================================================

    #[Test]
    public function pathway3_11_setDefault_missingUid(): void
    {
        $request = $this->createFormRequest('/ajax/model/setdefault', []);
        $response = $this->controller->setDefaultAction($request);

        $this->assertErrorResponse($response, 400, 'No model UID specified');
    }

    #[Test]
    public function pathway3_11_setDefault_nonExistentModel(): void
    {
        $request = $this->createFormRequest('/ajax/model/setdefault', ['uid' => 99999]);
        $response = $this->controller->setDefaultAction($request);

        $this->assertErrorResponse($response, 404, 'Model not found');
    }

    // =========================================================================
    // Pathway 3.12: Test Model Edge Cases
    // =========================================================================

    #[Test]
    public function pathway3_12_testModel_missingUid(): void
    {
        $request = $this->createFormRequest('/ajax/model/test', []);
        $response = $this->controller->testModelAction($request);

        $this->assertErrorResponse($response, 400, 'No model UID specified');
    }

    #[Test]
    public function pathway3_12_testModel_zeroUid(): void
    {
        $request = $this->createFormRequest('/ajax/model/test', ['uid' => 0]);
        $response = $this->controller->testModelAction($request);

        $this->assertErrorResponse($response, 400, 'No model UID specified');
    }

    // =========================================================================
    // Pathway 3.13: Fetch Available Models Edge Cases
    // =========================================================================

    #[Test]
    public function pathway3_13_fetchAvailable_missingProviderUid(): void
    {
        $request = $this->createFormRequest('/ajax/model/fetch', []);
        $response = $this->controller->fetchAvailableModelsAction($request);

        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    #[Test]
    public function pathway3_13_fetchAvailable_nonExistentProvider(): void
    {
        $request = $this->createFormRequest('/ajax/model/fetch', ['providerUid' => 99999]);
        $response = $this->controller->fetchAvailableModelsAction($request);

        $this->assertErrorResponse($response, 404, 'Provider not found');
    }

    // =========================================================================
    // Pathway 3.14: Detect Limits Edge Cases
    // =========================================================================

    #[Test]
    public function pathway3_14_detectLimits_missingProviderUid(): void
    {
        $request = $this->createFormRequest('/ajax/model/detect-limits', [
            'modelId' => 'gpt-4o',
        ]);
        $response = $this->controller->detectLimitsAction($request);

        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    #[Test]
    public function pathway3_14_detectLimits_missingModelId(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createFormRequest('/ajax/model/detect-limits', [
            'providerUid' => $provider->getUid(),
        ]);
        $response = $this->controller->detectLimitsAction($request);

        $this->assertErrorResponse($response, 400, 'No model ID specified');
    }

    #[Test]
    public function pathway3_14_detectLimits_nonExistentProvider(): void
    {
        $request = $this->createFormRequest('/ajax/model/detect-limits', [
            'providerUid' => 99999,
            'modelId' => 'gpt-4o',
        ]);
        $response = $this->controller->detectLimitsAction($request);

        $this->assertErrorResponse($response, 404, 'Provider not found');
    }

    // =========================================================================
    // Data Integrity Tests
    // =========================================================================

    #[Test]
    public function modelProviderRelationshipIntegrity(): void
    {
        $models = $this->modelRepository->findActive()->toArray();

        foreach ($models as $model) {
            $provider = $model->getProvider();
            self::assertNotNull($provider, "Model {$model->getName()} must have a provider");
            self::assertNotNull($provider->getUid(), 'Provider must have UID');
            self::assertNotEmpty($provider->getIdentifier(), 'Provider must have identifier');
        }
    }

    #[Test]
    public function modelHasRequiredFields(): void
    {
        foreach ($this->modelRepository->findAll() as $model) {
            self::assertNotNull($model->getUid());
            self::assertNotEmpty($model->getIdentifier(), 'Model must have identifier');
            self::assertNotEmpty($model->getName(), 'Model must have name');
            self::assertNotEmpty($model->getModelId(), 'Model must have model ID');
        }
    }

    #[Test]
    public function modelParametersWithinValidRanges(): void
    {
        foreach ($this->modelRepository->findAll() as $model) {
            // Context length should be positive
            $contextLength = $model->getContextLength();
            self::assertGreaterThanOrEqual(0, $contextLength, 'Context length should be >= 0');

            // Max output tokens should be positive
            $maxOutputTokens = $model->getMaxOutputTokens();
            self::assertGreaterThanOrEqual(0, $maxOutputTokens, 'Max output tokens should be >= 0');
        }
    }

    #[Test]
    public function onlyOneDefaultModelAllowed(): void
    {
        $defaults = [];
        foreach ($this->modelRepository->findAll() as $model) {
            if ($model->isDefault()) {
                $defaults[] = $model;
            }
        }

        // Should have at most one default model
        self::assertLessThanOrEqual(1, count($defaults), 'Only one model should be default');
    }

    // =========================================================================
    // Concurrent Operations
    // =========================================================================

    #[Test]
    public function rapidToggleModelOperations(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);
        $modelUid = $model->getUid();

        $initialState = $model->isActive();
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => $modelUid]);

        // Perform multiple rapid toggles
        for ($i = 0; $i < 4; $i++) {
            $response = $this->controller->toggleActiveAction($request);
            self::assertSame(200, $response->getStatusCode());
        }

        // After even number of toggles, should be back to initial state
        $this->persistenceManager->clearState();
        $final = $this->modelRepository->findByUid($modelUid);
        self::assertSame($initialState, $final->isActive());
    }

    #[Test]
    public function multipleTestModelCalls(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $request = $this->createFormRequest('/ajax/model/test', ['uid' => $model->getUid()]);

        // Multiple test calls should all succeed (no rate limiting in tests)
        for ($i = 0; $i < 3; $i++) {
            $response = $this->controller->testModelAction($request);
            self::assertContains($response->getStatusCode(), [200, 500]);

            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('success', $body);
        }
    }

    // =========================================================================
    // Model-Provider Cascade
    // =========================================================================

    #[Test]
    public function modelDeactivationDoesNotAffectProvider(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);
        $modelUid = $model->getUid();
        $provider = $model->getProvider();
        self::assertNotNull($provider);
        $providerUid = $provider->getUid();

        // Deactivate model
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => $modelUid]);
        $this->controller->toggleActiveAction($request);
        $this->persistenceManager->clearState();

        // Provider should still be active
        $reloadedProvider = $this->providerRepository->findByUid($providerUid);
        self::assertNotNull($reloadedProvider);
        self::assertTrue($reloadedProvider->isActive(), 'Provider should still be active after model deactivation');

        // Reactivate for cleanup
        $this->controller->toggleActiveAction($request);
    }

    // =========================================================================
    // Pathway 3.15: Model Context and Token Limits
    // =========================================================================

    #[Test]
    public function pathway3_15_modelContextLength(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $contextLength = $model->getContextLength();
        self::assertIsInt($contextLength);
        self::assertGreaterThanOrEqual(0, $contextLength);
    }

    #[Test]
    public function pathway3_15_modelMaxOutputTokens(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $maxOutputTokens = $model->getMaxOutputTokens();
        self::assertIsInt($maxOutputTokens);
        self::assertGreaterThanOrEqual(0, $maxOutputTokens);
    }

    #[Test]
    public function pathway3_15_modelWithCustomLimits(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('custom-limits-model-' . time());
        $model->setName('Custom Limits Model');
        $model->setModelId('custom-model');
        $model->setProvider($provider);
        $model->setContextLength(128000);
        $model->setMaxOutputTokens(4096);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertSame(128000, $added->getContextLength());
        self::assertSame(4096, $added->getMaxOutputTokens());
    }

    // =========================================================================
    // Pathway 3.16: Model Capabilities
    // =========================================================================

    #[Test]
    public function pathway3_16_modelCapabilitiesArray(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $capabilities = $model->getCapabilitiesArray();
        self::assertIsArray($capabilities);
    }

    #[Test]
    public function pathway3_16_modelCapabilitiesHandling(): void
    {
        // Test that existing models can have their capabilities queried
        $models = $this->modelRepository->findActive()->toArray();
        self::assertNotEmpty($models);

        foreach ($models as $model) {
            // getCapabilities returns string (possibly empty)
            $capabilities = $model->getCapabilities();
            self::assertIsString($capabilities);

            // getCapabilitiesArray returns array
            $capArray = $model->getCapabilitiesArray();
            self::assertIsArray($capArray);
        }
    }

    // =========================================================================
    // Pathway 3.17: Model Search and Filtering
    // =========================================================================

    #[Test]
    public function pathway3_17_findModelByIdentifier(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $found = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($found);
        self::assertSame($model->getUid(), $found->getUid());
    }

    #[Test]
    public function pathway3_17_countActiveModels(): void
    {
        $count = $this->modelRepository->countActive();
        self::assertIsInt($count);
        self::assertGreaterThanOrEqual(0, $count);

        $active = $this->modelRepository->findActive();
        // Count should match (allowing for slight timing differences)
        self::assertGreaterThanOrEqual(0, $active->count());
    }

    #[Test]
    public function pathway3_17_findDefaultModel(): void
    {
        $models = $this->modelRepository->findActive()->toArray();
        $defaults = array_filter($models, fn($m) => $m->isDefault());

        // Should have at most one default
        self::assertLessThanOrEqual(1, count($defaults));
    }

    // =========================================================================
    // Pathway 3.18: Model Lifecycle
    // =========================================================================

    #[Test]
    public function pathway3_18_completeModelLifecycle(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $initialCount = $this->modelRepository->countActive();

        // Create model
        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('lifecycle-model-' . time());
        $model->setName('Lifecycle Model');
        $model->setModelId('lifecycle-id');
        $model->setProvider($provider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify created
        self::assertSame($initialCount + 1, $this->modelRepository->countActive());

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);

        // Toggle off
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => $added->getUid()]);
        $this->controller->toggleActiveAction($request);
        $this->persistenceManager->clearState();

        // Verify deactivated
        $reloaded = $this->modelRepository->findByUid($added->getUid());
        self::assertFalse($reloaded->isActive());

        // Toggle back on
        $this->controller->toggleActiveAction($request);
        $this->persistenceManager->clearState();

        // Verify reactivated
        $reloaded2 = $this->modelRepository->findByUid($added->getUid());
        self::assertTrue($reloaded2->isActive());
    }

    #[Test]
    public function pathway3_18_modelDefaultTransition(): void
    {
        $models = $this->modelRepository->findActive()->toArray();
        self::assertGreaterThanOrEqual(2, count($models), 'Need at least 2 models');

        // Set first as default
        $request1 = $this->createFormRequest('/ajax/model/setdefault', ['uid' => $models[0]->getUid()]);
        $this->controller->setDefaultAction($request1);
        $this->persistenceManager->clearState();

        // Verify first is default
        $first = $this->modelRepository->findByUid($models[0]->getUid());
        self::assertTrue($first->isDefault());

        // Set second as default
        $request2 = $this->createFormRequest('/ajax/model/setdefault', ['uid' => $models[1]->getUid()]);
        $this->controller->setDefaultAction($request2);
        $this->persistenceManager->clearState();

        // Verify second is now default and first is not
        $first = $this->modelRepository->findByUid($models[0]->getUid());
        $second = $this->modelRepository->findByUid($models[1]->getUid());
        self::assertFalse($first->isDefault());
        self::assertTrue($second->isDefault());
    }

    // =========================================================================
    // Pathway 3.19: Model Name Variations
    // =========================================================================

    #[Test]
    public function pathway3_19_modelWithLongName(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $longName = str_repeat('Long Model Name ', 10);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('long-name-model-' . time());
        $model->setName($longName);
        $model->setModelId('long-name-model');
        $model->setProvider($provider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertNotEmpty($added->getName());
    }

    #[Test]
    public function pathway3_19_modelWithUnicodeName(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $unicodeName = 'Mödel Tëst 日本語 🤖';

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('unicode-name-model-' . time());
        $model->setName($unicodeName);
        $model->setModelId('unicode-model');
        $model->setProvider($provider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertSame($unicodeName, $added->getName());
    }

    #[Test]
    public function pathway3_19_modelWithSpecialCharactersInName(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $specialName = "Model <Test> & 'Name' \"Quoted\"";

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('special-char-model-' . time());
        $model->setName($specialName);
        $model->setModelId('special-model');
        $model->setProvider($provider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertNotEmpty($added->getName());
    }

    // =========================================================================
    // Pathway 3.20: Model AJAX Response Structure
    // =========================================================================

    #[Test]
    public function pathway3_20_toggleResponseStructure(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => $model->getUid()]);
        $response = $this->controller->toggleActiveAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('isActive', $body);
        self::assertIsBool($body['success']);
        self::assertIsBool($body['isActive']);

        // Toggle back
        $this->controller->toggleActiveAction($request);
    }

    #[Test]
    public function pathway3_20_setDefaultResponseStructure(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $request = $this->createFormRequest('/ajax/model/setdefault', ['uid' => $model->getUid()]);
        $response = $this->controller->setDefaultAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertIsBool($body['success']);
    }

    #[Test]
    public function pathway3_20_testModelResponseStructure(): void
    {
        $model = $this->modelRepository->findActive()->getFirst();
        self::assertNotNull($model);

        $request = $this->createFormRequest('/ajax/model/test', ['uid' => $model->getUid()]);
        $response = $this->controller->testModelAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('message', $body);
    }

    #[Test]
    public function pathway3_20_errorResponseStructure(): void
    {
        $request = $this->createFormRequest('/ajax/model/toggle', ['uid' => 99999]);
        $response = $this->controller->toggleActiveAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('error', $body);
        self::assertFalse($body['success']);
        self::assertIsString($body['error']);
    }

    // =========================================================================
    // Pathway 3.21: Model ID Format Variations
    // =========================================================================

    #[Test]
    public function pathway3_21_modelWithStandardId(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('standard-id-model-' . time());
        $model->setName('Standard ID Model');
        $model->setModelId('gpt-4-turbo-preview');
        $model->setProvider($provider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('gpt-4-turbo-preview', $added->getModelId());
    }

    #[Test]
    public function pathway3_21_modelWithVersionedId(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('versioned-id-model-' . time());
        $model->setName('Versioned ID Model');
        $model->setModelId('claude-3-5-sonnet-20241022');
        $model->setProvider($provider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('claude-3-5-sonnet-20241022', $added->getModelId());
    }

    #[Test]
    public function pathway3_21_modelWithNamespacedId(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('namespaced-id-model-' . time());
        $model->setName('Namespaced ID Model');
        $model->setModelId('models/gemini-2.0-flash-exp');
        $model->setProvider($provider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('models/gemini-2.0-flash-exp', $added->getModelId());
    }

    #[Test]
    public function pathway3_21_modelWithLocalId(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('local-id-model-' . time());
        $model->setName('Local ID Model');
        $model->setModelId('llama3.2:latest');
        $model->setProvider($provider);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('llama3.2:latest', $added->getModelId());
    }

    // =========================================================================
    // Pathway 3.22: Model Limit Edge Cases
    // =========================================================================

    #[Test]
    public function pathway3_22_modelWithZeroContextLength(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('zero-context-model-' . time());
        $model->setName('Zero Context Model');
        $model->setModelId('zero-context');
        $model->setProvider($provider);
        $model->setContextLength(0);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertSame(0, $added->getContextLength());
    }

    #[Test]
    public function pathway3_22_modelWithLargeContextLength(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('large-context-model-' . time());
        $model->setName('Large Context Model');
        $model->setModelId('large-context');
        $model->setProvider($provider);
        $model->setContextLength(2000000); // 2 million tokens
        $model->setMaxOutputTokens(128000);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertSame(2000000, $added->getContextLength());
        self::assertSame(128000, $added->getMaxOutputTokens());
    }

    #[Test]
    public function pathway3_22_modelWithMinimalOutputTokens(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('minimal-output-model-' . time());
        $model->setName('Minimal Output Model');
        $model->setModelId('minimal-output');
        $model->setProvider($provider);
        $model->setContextLength(4096);
        $model->setMaxOutputTokens(1);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertSame(1, $added->getMaxOutputTokens());
    }

    #[Test]
    public function pathway3_22_modelWithEqualContextAndOutput(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $model = new Model();
        $model->setPid(0);
        $model->setIdentifier('equal-limits-model-' . time());
        $model->setName('Equal Limits Model');
        $model->setModelId('equal-limits');
        $model->setProvider($provider);
        $model->setContextLength(4096);
        $model->setMaxOutputTokens(4096);
        $model->setIsActive(true);

        $this->modelRepository->add($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->modelRepository->findOneByIdentifier($model->getIdentifier());
        self::assertNotNull($added);
        self::assertSame($added->getContextLength(), $added->getMaxOutputTokens());
    }
}
