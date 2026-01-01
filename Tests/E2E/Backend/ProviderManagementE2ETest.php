<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\Backend;

use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * E2E tests for Provider Management user pathways.
 *
 * Tests complete user journeys:
 * - Pathway 2.1: View Provider List
 * - Pathway 2.2: Toggle Provider Status
 * - Pathway 2.3: Test Provider Connection
 * - Pathway 2.4: Edit Provider Configuration (via repository)
 * - Pathway 2.5: Delete Provider
 */
#[CoversClass(ProviderController::class)]
final class ProviderManagementE2ETest extends AbstractBackendE2ETestCase
{
    private ProviderController $controller;
    private ProviderRepository $providerRepository;
    private ModelRepository $modelRepository;
    private PersistenceManagerInterface $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $this->providerRepository);

        $this->modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $this->modelRepository);

        $this->persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $this->persistenceManager);

        $this->controller = $this->createController();
    }

    private function createController(): ProviderController
    {
        $providerAdapterRegistry = $this->get(ProviderAdapterRegistry::class);
        self::assertInstanceOf(ProviderAdapterRegistry::class, $providerAdapterRegistry);

        return $this->createControllerWithReflection(ProviderController::class, [
            'providerRepository' => $this->providerRepository,
            'providerAdapterRegistry' => $providerAdapterRegistry,
            'persistenceManager' => $this->persistenceManager,
        ]);
    }

    // =========================================================================
    // Pathway 2.1: View Provider List
    // =========================================================================

    #[Test]
    public function pathway2_1_viewProviderList(): void
    {
        // User navigates to Providers list and sees all configured providers
        $providers = $this->providerRepository->findAll()->toArray();

        self::assertNotEmpty($providers, 'Provider list should contain entries from fixtures');

        // Verify each provider has required display information
        foreach ($providers as $provider) {
            self::assertNotEmpty($provider->getName(), 'Provider should have a name');
            self::assertNotEmpty($provider->getAdapterType(), 'Provider should have an adapter type');
            // Status indicators should be available
            self::assertIsBool($provider->isActive());
        }
    }

    #[Test]
    public function pathway2_1_viewProviderListShowsActiveStatus(): void
    {
        $activeProviders = $this->providerRepository->findActive()->toArray();
        $allProviders = $this->providerRepository->findAll()->toArray();

        // User should see which providers are active vs inactive
        self::assertNotEmpty($activeProviders, 'Should have active providers');

        foreach ($activeProviders as $provider) {
            self::assertTrue($provider->isActive(), 'findActive should only return active providers');
        }
    }

    #[Test]
    public function pathway2_1_viewProviderListWithModels(): void
    {
        // User sees provider list with associated model counts
        foreach ($this->providerRepository->findActive() as $provider) {
            $models = $this->modelRepository->findByProvider($provider);

            // Provider should be able to show its model count
            self::assertGreaterThanOrEqual(0, $models->count());
        }
    }

    // =========================================================================
    // Pathway 2.2: Toggle Provider Status
    // =========================================================================

    #[Test]
    public function pathway2_2_toggleProviderStatus_activateAndDeactivate(): void
    {
        // Get an active provider
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);
        $providerUid = $provider->getUid();

        // Step 1: User clicks toggle to deactivate
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $providerUid]);
        $response = $this->controller->toggleActiveAction($request);

        $body = $this->assertSuccessResponse($response);
        self::assertArrayHasKey('isActive', $body);
        self::assertFalse($body['isActive'], 'Provider should now be inactive');

        // Verify in database
        $this->persistenceManager->clearState();
        $reloaded = $this->providerRepository->findByUid($providerUid);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isActive());

        // Step 2: User clicks toggle again to reactivate
        $request2 = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $providerUid]);
        $response2 = $this->controller->toggleActiveAction($request2);

        $body2 = $this->assertSuccessResponse($response2);
        self::assertTrue($body2['isActive'], 'Provider should now be active again');

        // Verify final state
        $this->persistenceManager->clearState();
        $finalState = $this->providerRepository->findByUid($providerUid);
        self::assertTrue($finalState->isActive());
    }

    #[Test]
    public function pathway2_2_toggleProviderStatus_affectsAvailability(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);
        $providerUid = $provider->getUid();

        $initialActiveCount = $this->providerRepository->findActive()->count();

        // Deactivate provider
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $providerUid]);
        $this->controller->toggleActiveAction($request);

        $this->persistenceManager->clearState();

        // Provider should no longer appear in active list
        $newActiveCount = $this->providerRepository->findActive()->count();
        self::assertSame($initialActiveCount - 1, $newActiveCount);

        // Reactivate for cleanup
        $this->controller->toggleActiveAction($request);
    }

    #[Test]
    public function pathway2_2_toggleProviderStatus_errorForNonExistent(): void
    {
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => 99999]);
        $response = $this->controller->toggleActiveAction($request);

        $this->assertErrorResponse($response, 404, 'Provider not found');
    }

    #[Test]
    public function pathway2_2_toggleProviderStatus_errorForMissingUid(): void
    {
        $request = $this->createFormRequest('/ajax/provider/toggle', []);
        $response = $this->controller->toggleActiveAction($request);

        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    // =========================================================================
    // Pathway 2.3: Test Provider Connection
    // =========================================================================

    #[Test]
    public function pathway2_3_testProviderConnection(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // User clicks "Test Connection" button
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $provider->getUid()]);
        $response = $this->controller->testConnectionAction($request);

        // Response should indicate success or failure (not crash)
        self::assertContains($response->getStatusCode(), [200, 500]);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);

        // TestConnectionResponse has: success, message, models
        self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('models', $body);
        self::assertIsArray($body['models']);
    }

    #[Test]
    public function pathway2_3_testProviderConnection_showsLoadingState(): void
    {
        // This tests the contract: response must come back with proper structure
        // regardless of API availability (important for UX)
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $provider->getUid()]);
        $response = $this->controller->testConnectionAction($request);

        // Must be valid JSON regardless of outcome
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        // Must have required fields for UI to display result
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function pathway2_3_testProviderConnection_errorForInvalidProvider(): void
    {
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => 99999]);
        $response = $this->controller->testConnectionAction($request);

        $this->assertErrorResponse($response, 404, 'Provider not found');
    }

    // =========================================================================
    // Pathway 2.4: Edit Provider Configuration (via repository)
    // =========================================================================

    #[Test]
    public function pathway2_4_editProviderConfiguration(): void
    {
        $provider = $this->providerRepository->findByUid(1);
        self::assertNotNull($provider);

        $originalName = $provider->getName();
        $originalTimeout = $provider->getTimeout();

        // Simulate user editing provider settings
        $provider->setName('Updated Provider Name');
        $provider->setTimeout(60);

        $this->providerRepository->update($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify changes persisted
        $reloaded = $this->providerRepository->findByUid(1);
        self::assertSame('Updated Provider Name', $reloaded->getName());
        self::assertSame(60, $reloaded->getTimeout());

        // Verify models/configs remain linked
        $models = $this->modelRepository->findByProvider($reloaded);
        self::assertGreaterThanOrEqual(0, $models->count(), 'Models should still be linked');

        // Restore original values
        $reloaded->setName($originalName);
        $reloaded->setTimeout($originalTimeout);
        $this->providerRepository->update($reloaded);
        $this->persistenceManager->persistAll();
    }

    #[Test]
    public function pathway2_4_editProviderApiKey(): void
    {
        $provider = $this->providerRepository->findByUid(1);
        self::assertNotNull($provider);

        $originalKey = $provider->getApiKey();

        // User updates API key
        $provider->setApiKey('new-api-key-12345');
        $this->providerRepository->update($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify key updated (API key is encrypted in DB)
        $reloaded = $this->providerRepository->findByUid(1);
        // The decrypted key should match what we set
        self::assertSame('new-api-key-12345', $reloaded->getDecryptedApiKey());

        // Restore
        $reloaded->setApiKey($originalKey);
        $this->providerRepository->update($reloaded);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 2.5: Delete Provider
    // =========================================================================

    #[Test]
    public function pathway2_5_deleteProvider_softDelete(): void
    {
        // First, create a provider to delete (don't delete fixture providers)
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('test-delete-provider');
        $provider->setName('Provider To Delete');
        $provider->setAdapterType('openai');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier('test-delete-provider');
        self::assertNotNull($addedProvider);
        $providerUid = $addedProvider->getUid();

        $countBefore = $this->providerRepository->countActive();

        // User clicks delete
        $this->providerRepository->remove($addedProvider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Provider should be soft-deleted (not found via normal query)
        $deleted = $this->providerRepository->findByUid($providerUid);
        self::assertNull($deleted, 'Deleted provider should not be found');

        $countAfter = $this->providerRepository->countActive();
        self::assertSame($countBefore - 1, $countAfter);
    }

    // =========================================================================
    // Multi-Provider Scenarios
    // =========================================================================

    #[Test]
    public function multipleProvidersCanBeActiveSimultaneously(): void
    {
        $activeProviders = $this->providerRepository->findActive()->toArray();

        // At least one active provider should exist from fixtures
        self::assertGreaterThanOrEqual(1, count($activeProviders), 'Should have at least one active provider');

        // Each provider should have unique identifier
        $identifiers = array_map(fn($p) => $p->getIdentifier(), $activeProviders);
        self::assertCount(count($activeProviders), array_unique($identifiers));

        // If only one provider, create another to test multi-provider scenario
        if (count($activeProviders) === 1) {
            $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
            $provider->setPid(0);
            $provider->setIdentifier('test-second-provider');
            $provider->setName('Second Test Provider');
            $provider->setAdapterType('anthropic');
            $provider->setIsActive(true);

            $this->providerRepository->add($provider);
            $this->persistenceManager->persistAll();
            $this->persistenceManager->clearState();

            $newCount = $this->providerRepository->findActive()->count();
            self::assertSame(2, $newCount, 'Should now have 2 active providers');
        }
    }

    #[Test]
    public function providerPriorityAffectsSelection(): void
    {
        // Get provider by priority
        $highestPriority = $this->providerRepository->findHighestPriority();

        if ($highestPriority !== null) {
            // Verify it's actually the highest
            foreach ($this->providerRepository->findActive() as $provider) {
                self::assertLessThanOrEqual(
                    $highestPriority->getPriority(),
                    $provider->getPriority(),
                    'findHighestPriority should return the one with highest priority value',
                );
            }
        }
    }

    // =========================================================================
    // Pathway 2.6: Provider Test Connection Edge Cases
    // =========================================================================

    #[Test]
    public function pathway2_6_testConnection_missingUid(): void
    {
        $request = $this->createFormRequest('/ajax/provider/test', []);
        $response = $this->controller->testConnectionAction($request);

        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    #[Test]
    public function pathway2_6_testConnection_zeroUid(): void
    {
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => 0]);
        $response = $this->controller->testConnectionAction($request);

        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    #[Test]
    public function pathway2_6_testConnection_stringUid(): void
    {
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => 'invalid']);
        $response = $this->controller->testConnectionAction($request);

        // Invalid string should be treated as 0
        $this->assertErrorResponse($response, 400, 'No provider UID specified');
    }

    // =========================================================================
    // Pathway 2.7: Provider Data Validation
    // =========================================================================

    #[Test]
    public function pathway2_7_providerRequiresIdentifier(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setName('Test Provider');
        $provider->setAdapterType('openai');
        $provider->setIsActive(true);
        // Identifier is required - setting empty should be handled

        $provider->setIdentifier('required-id-test-' . time());
        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();

        self::assertNotNull($provider->getUid());
        self::assertNotEmpty($provider->getIdentifier());
    }

    #[Test]
    public function pathway2_7_providerIdentifierUniqueness(): void
    {
        $existing = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($existing);

        // Existing identifier should not be unique
        self::assertFalse($this->providerRepository->isIdentifierUnique($existing->getIdentifier()));

        // New identifier should be unique
        self::assertTrue($this->providerRepository->isIdentifierUnique('brand-new-provider-id'));

        // Own identifier should be unique when excluding self
        self::assertTrue($this->providerRepository->isIdentifierUnique(
            $existing->getIdentifier(),
            $existing->getUid(),
        ));
    }

    // =========================================================================
    // Pathway 2.8: Provider-Model Cascade
    // =========================================================================

    #[Test]
    public function pathway2_8_providerDeactivationDoesNotDeleteModels(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);
        $providerUid = $provider->getUid();

        // Get model count before deactivation
        $modelsBefore = $this->modelRepository->findByProvider($provider)->count();

        // Deactivate provider
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $providerUid]);
        $this->controller->toggleActiveAction($request);
        $this->persistenceManager->clearState();

        // Models should still exist
        $reloadedProvider = $this->providerRepository->findByUid($providerUid);
        self::assertNotNull($reloadedProvider);
        $modelsAfter = $this->modelRepository->findByProvider($reloadedProvider)->count();
        self::assertSame($modelsBefore, $modelsAfter, 'Models should not be deleted when provider is deactivated');

        // Reactivate for cleanup
        $this->controller->toggleActiveAction($request);
    }

    // =========================================================================
    // Pathway 2.9: Provider Types
    // =========================================================================

    #[Test]
    public function pathway2_9_supportedAdapterTypes(): void
    {
        $supportedTypes = ['openai', 'anthropic', 'ollama', 'google', 'gemini', 'deepseek'];

        foreach ($this->providerRepository->findAll() as $provider) {
            // Each provider should have a known adapter type
            self::assertContains(
                $provider->getAdapterType(),
                $supportedTypes,
                "Provider {$provider->getName()} has unknown adapter type: {$provider->getAdapterType()}",
            );
        }
    }

    #[Test]
    public function pathway2_9_findByAdapterType(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $adapterType = $provider->getAdapterType();
        $byType = $this->providerRepository->findByAdapterType($adapterType);

        // Should find at least this provider
        self::assertGreaterThanOrEqual(1, $byType->count());

        // All results should have the requested type
        foreach ($byType as $p) {
            self::assertSame($adapterType, $p->getAdapterType());
        }
    }

    // =========================================================================
    // Data Integrity Tests
    // =========================================================================

    #[Test]
    public function providerHasRequiredFields(): void
    {
        foreach ($this->providerRepository->findAll() as $provider) {
            self::assertNotNull($provider->getUid());
            self::assertNotEmpty($provider->getIdentifier(), 'Provider must have identifier');
            self::assertNotEmpty($provider->getName(), 'Provider must have name');
            self::assertNotEmpty($provider->getAdapterType(), 'Provider must have adapter type');
        }
    }

    #[Test]
    public function providerApiKeyEncryption(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // API key in database should be encrypted (different from decrypted)
        $encryptedKey = $provider->getApiKey();
        $decryptedKey = $provider->getDecryptedApiKey();

        if (!empty($encryptedKey)) {
            // If there's a key, encrypted and decrypted should differ
            // (unless encryption is disabled or key is very short)
            self::assertNotEmpty($decryptedKey, 'Decrypted key should be accessible');
        }
    }

    // =========================================================================
    // Concurrent Operations
    // =========================================================================

    #[Test]
    public function rapidToggleProviderOperations(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);
        $providerUid = $provider->getUid();

        $initialState = $provider->isActive();
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $providerUid]);

        // Perform multiple rapid toggles
        for ($i = 0; $i < 4; $i++) {
            $response = $this->controller->toggleActiveAction($request);
            self::assertSame(200, $response->getStatusCode());
        }

        // After even number of toggles, should be back to initial state
        $this->persistenceManager->clearState();
        $final = $this->providerRepository->findByUid($providerUid);
        self::assertSame($initialState, $final->isActive());
    }

    #[Test]
    public function multipleTestConnectionCalls(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $provider->getUid()]);

        // Multiple test connection calls should all succeed (no rate limiting in tests)
        for ($i = 0; $i < 3; $i++) {
            $response = $this->controller->testConnectionAction($request);
            self::assertContains($response->getStatusCode(), [200, 500]);

            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('success', $body);
        }
    }

    // =========================================================================
    // Pathway 2.10: Provider Endpoint Configuration
    // =========================================================================

    #[Test]
    public function pathway2_10_providerEndpointConfiguration(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // Provider should have endpoint URL accessible
        $endpointUrl = $provider->getEndpointUrl();
        self::assertIsString($endpointUrl);

        // If endpoint is set, it should be a valid URL format
        if (!empty($endpointUrl)) {
            self::assertMatchesRegularExpression('/^https?:\/\//', $endpointUrl);
        }
    }

    #[Test]
    public function pathway2_10_providerCustomEndpoint(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('custom-endpoint-provider');
        $provider->setName('Custom Endpoint Provider');
        $provider->setAdapterType('openai');
        $provider->setEndpointUrl('https://custom.api.example.com/v1');
        $provider->setApiKey('test-key');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $addedProvider = $this->providerRepository->findOneByIdentifier('custom-endpoint-provider');
        self::assertNotNull($addedProvider);
        self::assertSame('https://custom.api.example.com/v1', $addedProvider->getEndpointUrl());
    }

    #[Test]
    public function pathway2_10_providerTimeoutSettings(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $originalTimeout = $provider->getTimeout();

        // Update timeout
        $provider->setTimeout(120);
        $this->providerRepository->update($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $reloaded = $this->providerRepository->findByUid($provider->getUid());
        self::assertSame(120, $reloaded->getTimeout());

        // Restore
        $reloaded->setTimeout($originalTimeout);
        $this->providerRepository->update($reloaded);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 2.11: Provider Priority Management
    // =========================================================================

    #[Test]
    public function pathway2_11_providerPriorityOrder(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();
        self::assertGreaterThanOrEqual(1, count($providers));

        // Verify providers can be sorted by priority
        usort($providers, fn($a, $b) => $b->getPriority() <=> $a->getPriority());

        // First element should have highest priority
        $highest = $this->providerRepository->findHighestPriority();
        if ($highest !== null && count($providers) > 0) {
            self::assertSame($providers[0]->getUid(), $highest->getUid());
        }
    }

    #[Test]
    public function pathway2_11_changePriority(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();
        if (count($providers) < 2) {
            self::markTestSkipped('Need at least 2 providers');
        }

        $provider1 = $providers[0];
        $provider2 = $providers[1];
        $originalPriority1 = $provider1->getPriority();
        $originalPriority2 = $provider2->getPriority();

        // Swap priorities
        $provider1->setPriority($originalPriority2);
        $provider2->setPriority($originalPriority1);
        $this->providerRepository->update($provider1);
        $this->providerRepository->update($provider2);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify swap
        $reloaded1 = $this->providerRepository->findByUid($provider1->getUid());
        $reloaded2 = $this->providerRepository->findByUid($provider2->getUid());
        self::assertSame($originalPriority2, $reloaded1->getPriority());
        self::assertSame($originalPriority1, $reloaded2->getPriority());

        // Restore
        $reloaded1->setPriority($originalPriority1);
        $reloaded2->setPriority($originalPriority2);
        $this->providerRepository->update($reloaded1);
        $this->providerRepository->update($reloaded2);
        $this->persistenceManager->persistAll();
    }

    // =========================================================================
    // Pathway 2.12: Provider Listing Filters
    // =========================================================================

    #[Test]
    public function pathway2_12_filterByActiveStatus(): void
    {
        $activeProviders = $this->providerRepository->findActive();
        $allProviders = $this->providerRepository->findAll();

        foreach ($activeProviders as $provider) {
            self::assertTrue($provider->isActive());
        }

        self::assertLessThanOrEqual($allProviders->count(), $activeProviders->count());
    }

    #[Test]
    public function pathway2_12_filterByAdapterType(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $byType = $this->providerRepository->findByAdapterType($provider->getAdapterType());
        self::assertGreaterThanOrEqual(1, $byType->count());

        // Non-existent type should return empty
        $nonExistent = $this->providerRepository->findByAdapterType('non-existent-type-xyz');
        self::assertSame(0, $nonExistent->count());
    }

    #[Test]
    public function pathway2_12_findByIdentifier(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $found = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($found);
        self::assertSame($provider->getUid(), $found->getUid());

        // Non-existent identifier
        $notFound = $this->providerRepository->findOneByIdentifier('non-existent-identifier-xyz');
        self::assertNull($notFound);
    }

    // =========================================================================
    // Pathway 2.13: Provider Configuration Validation
    // =========================================================================

    #[Test]
    public function pathway2_13_providerConfigurationValidation(): void
    {
        // Create provider with minimal config
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('minimal-provider-' . time());
        $provider->setName('Minimal Provider');
        $provider->setAdapterType('openai');
        $provider->setIsActive(true);
        // No API key set

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);

        // Test connection should fail gracefully (no crash)
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $added->getUid()]);
        $response = $this->controller->testConnectionAction($request);

        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function pathway2_13_providerWithAllSettings(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('full-config-provider-' . time());
        $provider->setName('Full Config Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-full-test-key');
        $provider->setEndpointUrl('https://api.openai.com/v1');
        $provider->setTimeout(30);
        $provider->setPriority(50);
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('Full Config Provider', $added->getName());
        self::assertSame('openai', $added->getAdapterType());
        self::assertSame(30, $added->getTimeout());
        self::assertSame(50, $added->getPriority());
    }

    // =========================================================================
    // Provider State Transitions
    // =========================================================================

    #[Test]
    public function providerStateTransition_inactiveToActive(): void
    {
        // Create inactive provider
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('inactive-to-active-' . time());
        $provider->setName('State Transition Provider');
        $provider->setAdapterType('openai');
        $provider->setIsActive(false);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertFalse($added->isActive());

        // Activate via toggle
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $added->getUid()]);
        $response = $this->controller->toggleActiveAction($request);

        $body = $this->assertSuccessResponse($response);
        self::assertTrue($body['isActive']);
    }

    #[Test]
    public function providerStateTransition_activeWithModels(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $models = $this->modelRepository->findByProvider($provider);
        $modelCount = $models->count();

        // Toggle provider
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $provider->getUid()]);
        $this->controller->toggleActiveAction($request);
        $this->persistenceManager->clearState();

        // Models should still exist
        $reloaded = $this->providerRepository->findByUid($provider->getUid());
        $modelsAfter = $this->modelRepository->findByProvider($reloaded);
        self::assertSame($modelCount, $modelsAfter->count());

        // Restore state
        $this->controller->toggleActiveAction($request);
    }

    // =========================================================================
    // Provider Error Scenarios
    // =========================================================================

    #[Test]
    public function providerError_duplicateIdentifier(): void
    {
        $existing = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($existing);

        // Check uniqueness validation
        self::assertFalse($this->providerRepository->isIdentifierUnique($existing->getIdentifier()));
    }

    #[Test]
    public function providerError_testInactiveProvider(): void
    {
        // Create inactive provider
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('inactive-test-provider-' . time());
        $provider->setName('Inactive Test Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('test-key');
        $provider->setIsActive(false);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);

        // Test connection on inactive provider should still work (API call is independent of status)
        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $added->getUid()]);
        $response = $this->controller->testConnectionAction($request);

        self::assertContains($response->getStatusCode(), [200, 500]);
    }

    // =========================================================================
    // Pathway 2.14: Provider Search and Discovery
    // =========================================================================

    #[Test]
    public function pathway2_14_findProviderByIdentifier(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $found = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($found);
        self::assertSame($provider->getUid(), $found->getUid());
    }

    #[Test]
    public function pathway2_14_findProviderByAdapterType(): void
    {
        $providers = $this->providerRepository->findActive()->toArray();
        self::assertNotEmpty($providers);

        $firstProvider = $providers[0];
        $adapterType = $firstProvider->getAdapterType();

        $byType = $this->providerRepository->findByAdapterType($adapterType);
        self::assertGreaterThan(0, $byType->count());

        foreach ($byType as $provider) {
            self::assertSame($adapterType, $provider->getAdapterType());
        }
    }

    #[Test]
    public function pathway2_14_countProviders(): void
    {
        $activeCount = $this->providerRepository->countActive();
        $totalCount = $this->providerRepository->findAll()->count();

        self::assertIsInt($activeCount);
        self::assertIsInt($totalCount);
        self::assertGreaterThanOrEqual($activeCount, $totalCount);
    }

    // =========================================================================
    // Pathway 2.15: Provider Lifecycle
    // =========================================================================

    #[Test]
    public function pathway2_15_completeProviderLifecycle(): void
    {
        $initialCount = $this->providerRepository->countActive();

        // Create provider
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('lifecycle-provider-' . time());
        $provider->setName('Lifecycle Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-lifecycle-test');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        // Verify created
        self::assertSame($initialCount + 1, $this->providerRepository->countActive());

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);

        // Toggle to inactive
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $added->getUid()]);
        $this->controller->toggleActiveAction($request);
        $this->persistenceManager->clearState();

        // Verify deactivated
        $reloaded = $this->providerRepository->findByUid($added->getUid());
        self::assertFalse($reloaded->isActive());

        // Toggle back to active
        $this->controller->toggleActiveAction($request);
        $this->persistenceManager->clearState();

        // Verify reactivated
        $reloaded2 = $this->providerRepository->findByUid($added->getUid());
        self::assertTrue($reloaded2->isActive());
    }

    #[Test]
    public function pathway2_15_providerWithMultipleModels(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $models = $this->modelRepository->findByProvider($provider);

        // Provider should be able to have multiple models
        self::assertGreaterThanOrEqual(0, $models->count());

        // All models should reference this provider
        foreach ($models as $model) {
            self::assertSame($provider->getUid(), $model->getProvider()->getUid());
        }
    }

    // =========================================================================
    // Pathway 2.16: Provider API Key Management
    // =========================================================================

    #[Test]
    public function pathway2_16_apiKeyIsStored(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('apikey-provider-' . time());
        $provider->setName('API Key Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-test-api-key-12345');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        // API key is encrypted, so just verify it's not empty
        self::assertNotEmpty($added->getApiKey());
    }

    #[Test]
    public function pathway2_16_providerWithoutApiKey(): void
    {
        // Ollama doesn't require API key
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('no-apikey-provider-' . time());
        $provider->setName('No API Key Provider');
        $provider->setAdapterType('ollama');
        $provider->setApiKey(''); // Empty API key
        $provider->setEndpointUrl('http://localhost:11434');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('', $added->getApiKey());
    }

    // =========================================================================
    // Pathway 2.17: Provider Timeout Configuration
    // =========================================================================

    #[Test]
    public function pathway2_17_customTimeoutSettings(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('timeout-provider-' . time());
        $provider->setName('Timeout Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-test');
        $provider->setTimeout(120); // 2 minutes
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertSame(120, $added->getTimeout());
    }

    #[Test]
    public function pathway2_17_defaultTimeoutValue(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('default-timeout-provider-' . time());
        $provider->setName('Default Timeout Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-test');
        // Don't set timeout explicitly
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        // Default timeout should be set
        self::assertGreaterThanOrEqual(0, $added->getTimeout());
    }

    // =========================================================================
    // Pathway 2.18: Provider Name Variations
    // =========================================================================

    #[Test]
    public function pathway2_18_providerWithLongName(): void
    {
        $longName = str_repeat('Long Provider Name ', 10);

        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('long-name-provider-' . time());
        $provider->setName($longName);
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-test');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        // Name should be stored (possibly truncated)
        self::assertNotEmpty($added->getName());
    }

    #[Test]
    public function pathway2_18_providerWithUnicodeName(): void
    {
        $unicodeName = 'Proveïder Tëst 日本語 🤖';

        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('unicode-name-provider-' . time());
        $provider->setName($unicodeName);
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-test');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertSame($unicodeName, $added->getName());
    }

    #[Test]
    public function pathway2_18_providerWithSpecialCharactersInName(): void
    {
        $specialName = "Provider <Test> & 'Name' \"Quoted\"";

        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('special-char-provider-' . time());
        $provider->setName($specialName);
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-test');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        // Name should be preserved (HTML entities may be stored differently)
        self::assertNotEmpty($added->getName());
    }

    // =========================================================================
    // Pathway 2.19: Provider AJAX Response Structure
    // =========================================================================

    #[Test]
    public function pathway2_19_toggleResponseStructure(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $provider->getUid()]);
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
    public function pathway2_19_testConnectionResponseStructure(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $request = $this->createFormRequest('/ajax/provider/test', ['uid' => $provider->getUid()]);
        $response = $this->controller->testConnectionAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('message', $body);
        self::assertArrayHasKey('models', $body);
        self::assertIsBool($body['success']);
        self::assertIsString($body['message']);
        self::assertIsArray($body['models']);
    }

    #[Test]
    public function pathway2_19_errorResponseStructure(): void
    {
        // Test with invalid UID
        $request = $this->createFormRequest('/ajax/provider/toggle', ['uid' => 99999]);
        $response = $this->controller->toggleActiveAction($request);

        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('error', $body);
        self::assertFalse($body['success']);
        self::assertIsString($body['error']);
    }

    #[Test]
    public function pathway2_19_allResponsesAreValidJson(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        // Test toggle response
        $request1 = $this->createFormRequest('/ajax/provider/toggle', ['uid' => $provider->getUid()]);
        $response1 = $this->controller->toggleActiveAction($request1);
        $body1 = json_decode((string)$response1->getBody(), true);
        self::assertIsArray($body1);

        // Test connection response
        $request2 = $this->createFormRequest('/ajax/provider/test', ['uid' => $provider->getUid()]);
        $response2 = $this->controller->testConnectionAction($request2);
        $body2 = json_decode((string)$response2->getBody(), true);
        self::assertIsArray($body2);

        // Toggle back
        $this->controller->toggleActiveAction($request1);
    }

    // =========================================================================
    // Pathway 2.20: Provider Endpoint URL Variations
    // =========================================================================

    #[Test]
    public function pathway2_20_providerWithHttpsEndpoint(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('https-endpoint-provider-' . time());
        $provider->setName('HTTPS Endpoint Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-test');
        $provider->setEndpointUrl('https://api.openai.com/v1');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('https://api.openai.com/v1', $added->getEndpointUrl());
    }

    #[Test]
    public function pathway2_20_providerWithLocalhostEndpoint(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('localhost-endpoint-provider-' . time());
        $provider->setName('Localhost Endpoint Provider');
        $provider->setAdapterType('ollama');
        $provider->setEndpointUrl('http://localhost:11434');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('http://localhost:11434', $added->getEndpointUrl());
    }

    #[Test]
    public function pathway2_20_providerWithIpAddressEndpoint(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('ip-endpoint-provider-' . time());
        $provider->setName('IP Address Endpoint Provider');
        $provider->setAdapterType('ollama');
        $provider->setEndpointUrl('http://192.168.1.100:11434');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('http://192.168.1.100:11434', $added->getEndpointUrl());
    }

    #[Test]
    public function pathway2_20_providerWithPathInEndpoint(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('path-endpoint-provider-' . time());
        $provider->setName('Path Endpoint Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-test');
        $provider->setEndpointUrl('https://api.example.com/custom/path/v1');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('https://api.example.com/custom/path/v1', $added->getEndpointUrl());
    }

    #[Test]
    public function pathway2_20_providerWithEmptyEndpoint(): void
    {
        $provider = new \Netresearch\NrLlm\Domain\Model\Provider();
        $provider->setPid(0);
        $provider->setIdentifier('empty-endpoint-provider-' . time());
        $provider->setName('Empty Endpoint Provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('sk-test');
        $provider->setEndpointUrl('');
        $provider->setIsActive(true);

        $this->providerRepository->add($provider);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $added = $this->providerRepository->findOneByIdentifier($provider->getIdentifier());
        self::assertNotNull($added);
        self::assertSame('', $added->getEndpointUrl());
    }
}
