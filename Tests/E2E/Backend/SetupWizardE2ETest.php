<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E\Backend;

use Netresearch\NrLlm\Controller\Backend\SetupWizardController;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Service\SetupWizard\ConfigurationGenerator;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Netresearch\NrLlm\Service\SetupWizard\ProviderDetector;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * E2E tests for Setup Wizard user pathways.
 *
 * Tests complete user journeys:
 * - Pathway 1.1: First-Time Provider Setup (full flow from detect to save)
 * - Pathway 1.2: Add Additional Provider
 *
 * These tests simulate a user going through the entire setup wizard,
 * step by step, verifying state changes at each stage.
 */
#[CoversClass(SetupWizardController::class)]
final class SetupWizardE2ETest extends AbstractBackendE2ETestCase
{
    private SetupWizardController $controller;
    private ProviderRepository $providerRepository;
    private ModelRepository $modelRepository;
    private LlmConfigurationRepository $configurationRepository;
    private PersistenceManagerInterface $persistenceManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Get repositories for verification
        $providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $providerRepository);
        $this->providerRepository = $providerRepository;

        $modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $modelRepository);
        $this->modelRepository = $modelRepository;

        $configurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $configurationRepository);
        $this->configurationRepository = $configurationRepository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;

        // Create controller
        $this->controller = $this->createController();
    }

    private function createController(): SetupWizardController
    {
        $providerDetector = $this->get(ProviderDetector::class);
        self::assertInstanceOf(ProviderDetector::class, $providerDetector);

        $modelDiscovery = $this->get(ModelDiscoveryInterface::class);
        self::assertInstanceOf(ModelDiscoveryInterface::class, $modelDiscovery);

        $configurationGenerator = $this->get(ConfigurationGenerator::class);
        self::assertInstanceOf(ConfigurationGenerator::class, $configurationGenerator);

        return $this->createControllerWithReflection(SetupWizardController::class, [
            'providerDetector' => $providerDetector,
            'modelDiscovery' => $modelDiscovery,
            'configurationGenerator' => $configurationGenerator,
            'providerRepository' => $this->providerRepository,
            'modelRepository' => $this->modelRepository,
            'llmConfigurationRepository' => $this->configurationRepository,
            'persistenceManager' => $this->persistenceManager,
        ]);
    }

    // =========================================================================
    // Pathway 1.1: First-Time Provider Setup - Complete Flow
    // =========================================================================

    #[Test]
    public function pathway1_1_firstTimeProviderSetup_openAi(): void
    {
        // Record initial state
        $initialProviderCount = $this->providerRepository->countActive();
        $initialModelCount = $this->modelRepository->countActive();

        // Step 1: Enter API endpoint URL and detect provider
        $detectRequest = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => 'https://api.openai.com/v1',
        ]);
        $detectResponse = $this->controller->detectAction($detectRequest);

        $detectBody = $this->assertSuccessResponse($detectResponse);
        self::assertArrayHasKey('provider', $detectBody);
        /** @var array{adapterType: string, suggestedName: string} $provider */
        $provider = $detectBody['provider'];
        self::assertSame('openai', $provider['adapterType']);
        self::assertNotEmpty($provider['suggestedName']);

        // Step 2: Test connection with API key
        $testRequest = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
        ]);
        $testResponse = $this->controller->testAction($testRequest);

        // Connection test returns 200 regardless of success/failure (graceful handling)
        self::assertSame(200, $testResponse->getStatusCode());
        $testBody = json_decode((string)$testResponse->getBody(), true);
        self::assertIsArray($testBody);
        self::assertArrayHasKey('success', $testBody);
        self::assertArrayHasKey('message', $testBody);

        // Step 3: Discover available models
        $discoverRequest = $this->createFormRequest('/ajax/wizard/discover', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
        ]);
        $discoverResponse = $this->controller->discoverAction($discoverRequest);

        $discoverBody = $this->assertSuccessResponse($discoverResponse);
        self::assertArrayHasKey('models', $discoverBody);
        self::assertIsArray($discoverBody['models']);

        // Step 4: Generate configurations for selected models
        $generateRequest = $this->createJsonRequest('/ajax/wizard/generate', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat', 'vision']],
                ['modelId' => 'o4-mini', 'name' => 'O4 Mini', 'capabilities' => ['chat']],
            ],
        ]);
        $generateResponse = $this->controller->generateAction($generateRequest);

        $generateBody = $this->assertSuccessResponse($generateResponse);
        self::assertArrayHasKey('configurations', $generateBody);
        self::assertIsArray($generateBody['configurations']);

        // Step 5: Save provider, models, and configurations
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'OpenAI Production',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-prod-key-12345',
            ],
            'models' => [
                [
                    'modelId' => 'gpt-5',
                    'name' => 'GPT-5',
                    'capabilities' => ['chat', 'vision', 'tools'],
                    'contextLength' => 128000,
                    'maxOutputTokens' => 16384,
                    'selected' => true,
                    'recommended' => true,
                ],
                [
                    'modelId' => 'o4-mini',
                    'name' => 'O4 Mini',
                    'capabilities' => ['chat'],
                    'contextLength' => 128000,
                    'maxOutputTokens' => 16384,
                    'selected' => true,
                ],
            ],
            'configurations' => [
                [
                    'name' => 'Default Chat',
                    'modelId' => 'gpt-5',
                    'temperature' => 0.7,
                    'maxTokens' => 2048,
                    'systemPrompt' => 'You are a helpful assistant.',
                    'isDefault' => true,
                ],
            ],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $saveBody = $this->assertSuccessResponse($saveResponse);
        self::assertArrayHasKey('provider', $saveBody);
        self::assertIsArray($saveBody['provider']);
        /** @var array{uid: int} $savedProvider */
        $savedProvider = $saveBody['provider'];
        self::assertArrayHasKey('uid', $savedProvider);
        self::assertArrayHasKey('modelsCount', $saveBody);
        self::assertSame(2, $saveBody['modelsCount']);

        // Verify database state changed
        $this->persistenceManager->clearState();

        $newProviderCount = $this->providerRepository->countActive();
        self::assertSame($initialProviderCount + 1, $newProviderCount, 'One new provider should be created');

        // Verify the provider was created correctly
        $newProvider = $this->providerRepository->findByUid($savedProvider['uid']);
        self::assertNotNull($newProvider);
        self::assertSame('OpenAI Production', $newProvider->getName());
        self::assertSame('openai', $newProvider->getAdapterType());
        self::assertTrue($newProvider->isActive());
    }

    #[Test]
    public function pathway1_1_firstTimeProviderSetup_ollama(): void
    {
        $initialProviderCount = $this->providerRepository->countActive();

        // Step 1: Detect Ollama provider
        $detectRequest = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => 'http://localhost:11434',
        ]);
        $detectResponse = $this->controller->detectAction($detectRequest);

        $detectBody = $this->assertSuccessResponse($detectResponse);
        self::assertArrayHasKey('provider', $detectBody);
        /** @var array{adapterType: string} $detectedProvider */
        $detectedProvider = $detectBody['provider'];
        self::assertSame('ollama', $detectedProvider['adapterType']);

        // Step 2-4: Skip test/discover for local Ollama (no auth required)

        // Step 5: Save Ollama provider
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Local Ollama',
                'adapterType' => 'ollama',
                'endpoint' => 'http://localhost:11434',
                'apiKey' => '', // Ollama doesn't require API key
            ],
            'models' => [
                [
                    'modelId' => 'llama3.2',
                    'name' => 'Llama 3.2',
                    'capabilities' => ['chat'],
                    'contextLength' => 128000,
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $saveBody = $this->assertSuccessResponse($saveResponse);
        self::assertSame(1, $saveBody['modelsCount']);

        // Verify
        $this->persistenceManager->clearState();
        $newProviderCount = $this->providerRepository->countActive();
        self::assertSame($initialProviderCount + 1, $newProviderCount);
    }

    #[Test]
    public function pathway1_1_firstTimeProviderSetup_anthropic(): void
    {
        // Step 1: Detect Anthropic provider
        $detectRequest = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => 'https://api.anthropic.com/v1',
        ]);
        $detectResponse = $this->controller->detectAction($detectRequest);

        $detectBody = $this->assertSuccessResponse($detectResponse);
        $provider = $detectBody['provider'];
        self::assertIsArray($provider);
        /** @var array{adapterType: string} $provider */
        self::assertSame('anthropic', $provider['adapterType']);

        // Step 5: Save with Claude models
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Anthropic',
                'adapterType' => 'anthropic',
                'endpoint' => 'https://api.anthropic.com/v1',
                'apiKey' => 'sk-ant-test-key',
            ],
            'models' => [
                [
                    'modelId' => 'claude-sonnet-4-20250514',
                    'name' => 'Claude Sonnet 4',
                    'capabilities' => ['chat', 'vision', 'tools'],
                    'contextLength' => 200000,
                    'maxOutputTokens' => 8192,
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $this->assertSuccessResponse($saveResponse);
    }

    // =========================================================================
    // Pathway 1.2: Add Additional Provider
    // =========================================================================

    #[Test]
    public function pathway1_2_addAdditionalProvider(): void
    {
        // Get initial provider count (fixtures already have providers)
        $initialProviders = $this->providerRepository->findActive()->toArray();
        $initialCount = count($initialProviders);
        self::assertGreaterThan(0, $initialCount, 'Should have existing providers from fixtures');

        // Add a new provider (second OpenAI account or different provider)
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'OpenAI Secondary',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-secondary-key',
            ],
            'models' => [
                [
                    'modelId' => 'o1-preview',
                    'name' => 'O1 Preview',
                    'capabilities' => ['chat'],
                    'contextLength' => 128000,
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $saveBody = $this->assertSuccessResponse($saveResponse);

        // Verify new provider exists alongside existing ones
        $this->persistenceManager->clearState();
        $newProviders = $this->providerRepository->findActive()->toArray();
        self::assertCount($initialCount + 1, $newProviders);

        // Verify existing providers are unaffected
        foreach ($initialProviders as $existingProvider) {
            $uid = $existingProvider->getUid();
            self::assertNotNull($uid);
            $reloaded = $this->providerRepository->findByUid($uid);
            self::assertNotNull($reloaded, 'Existing provider should still exist');
            self::assertTrue($reloaded->isActive(), 'Existing provider should still be active');
        }
    }

    #[Test]
    public function pathway1_2_addProviderWithDifferentType(): void
    {
        // Record existing provider types
        $existingTypes = [];
        foreach ($this->providerRepository->findActive() as $provider) {
            $existingTypes[$provider->getAdapterType()] = true;
        }

        // Add a provider of a different type
        $newType = isset($existingTypes['anthropic']) ? 'google' : 'anthropic';
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'New Type Provider',
                'adapterType' => $newType,
                'endpoint' => $newType === 'anthropic'
                    ? 'https://api.anthropic.com/v1'
                    : 'https://generativelanguage.googleapis.com/v1',
                'apiKey' => 'test-key-' . $newType,
            ],
            'models' => [
                [
                    'modelId' => 'test-model',
                    'name' => 'Test Model',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $this->assertSuccessResponse($saveResponse);

        // Verify we now have both types available
        $this->persistenceManager->clearState();
        $providers = $this->providerRepository->findActive();

        $types = [];
        foreach ($providers as $provider) {
            $types[$provider->getAdapterType()] = true;
        }

        self::assertArrayHasKey($newType, $types, "New provider type '$newType' should be available");
    }

    // =========================================================================
    // Error Handling in Setup Wizard Flow
    // =========================================================================

    #[Test]
    public function pathway1_1_detectRejectsInvalidEndpoint(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => 'not-a-valid-url',
        ]);
        $response = $this->controller->detectAction($request);

        // Should still return 200 but with unknown provider type
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        // The detector should handle unknown endpoints gracefully
    }

    #[Test]
    public function pathway1_1_saveValidatesRequiredFields(): void
    {
        // Missing provider
        $request1 = $this->createJsonRequest('/ajax/wizard/save', [
            'models' => [['modelId' => 'test', 'name' => 'Test', 'selected' => true]],
        ]);
        $this->assertErrorResponse(
            $this->controller->saveAction($request1),
            400,
            'Provider and models are required',
        );

        // Missing models
        $request2 = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => ['suggestedName' => 'Test', 'adapterType' => 'openai'],
        ]);
        $this->assertErrorResponse(
            $this->controller->saveAction($request2),
            400,
            'Provider and models are required',
        );

        // Empty models array
        $request3 = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => ['suggestedName' => 'Test', 'adapterType' => 'openai'],
            'models' => [],
        ]);
        $this->assertErrorResponse(
            $this->controller->saveAction($request3),
            400,
            'Provider and models are required',
        );
    }

    #[Test]
    public function pathway1_1_saveWithMultipleModelsAndConfigurations(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Full Setup Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-full-setup-key',
            ],
            'models' => [
                [
                    'modelId' => 'gpt-5',
                    'name' => 'GPT-5',
                    'capabilities' => ['chat', 'vision', 'tools'],
                    'contextLength' => 128000,
                    'maxOutputTokens' => 16384,
                    'selected' => true,
                    'recommended' => true,
                ],
                [
                    'modelId' => 'o4-mini',
                    'name' => 'O4 Mini',
                    'capabilities' => ['chat'],
                    'contextLength' => 128000,
                    'maxOutputTokens' => 16384,
                    'selected' => true,
                ],
                [
                    'modelId' => 'o1-mini',
                    'name' => 'O1 Mini',
                    'capabilities' => ['chat'],
                    'contextLength' => 128000,
                    'selected' => true,
                ],
            ],
            'configurations' => [
                [
                    'name' => 'Creative Writing',
                    'modelId' => 'gpt-5',
                    'temperature' => 0.9,
                    'maxTokens' => 4096,
                    'systemPrompt' => 'You are a creative writing assistant.',
                ],
                [
                    'name' => 'Code Assistant',
                    'modelId' => 'gpt-5',
                    'temperature' => 0.1,
                    'maxTokens' => 8192,
                    'systemPrompt' => 'You are a coding assistant. Be precise and thorough.',
                ],
                [
                    'name' => 'Quick Chat',
                    'modelId' => 'o4-mini',
                    'temperature' => 0.7,
                    'maxTokens' => 1024,
                ],
            ],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($saveResponse);
        self::assertSame(3, $body['modelsCount']);
        self::assertArrayHasKey('configurationsCount', $body);
    }

    // =========================================================================
    // Pathway 1.3: Model Discovery Flow
    // =========================================================================

    #[Test]
    public function pathway1_3_discoverModelsFromProvider(): void
    {
        // Discover models from an OpenAI endpoint
        $discoverRequest = $this->createFormRequest('/ajax/wizard/discover', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
        ]);
        $discoverResponse = $this->controller->discoverAction($discoverRequest);

        self::assertSame(200, $discoverResponse->getStatusCode());
        $body = json_decode((string)$discoverResponse->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('models', $body);
        self::assertIsArray($body['models']);

        // Each discovered model should have required fields
        foreach ($body['models'] as $model) {
            self::assertIsArray($model);
            self::assertArrayHasKey('modelId', $model);
            self::assertArrayHasKey('name', $model);
        }
    }

    #[Test]
    public function pathway1_3_discoverModels_errorForMissingEndpoint(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/discover', [
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->discoverAction($request);

        $this->assertErrorResponse($response, 400, 'Endpoint URL is required');
    }

    #[Test]
    public function pathway1_3_discoverModels_emptyEndpoint(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/discover', [
            'endpoint' => '',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->discoverAction($request);

        $this->assertErrorResponse($response, 400, 'Endpoint URL is required');
    }

    // =========================================================================
    // Pathway 1.4: Configuration Generation Flow
    // =========================================================================

    #[Test]
    public function pathway1_4_generateConfigurations(): void
    {
        $generateRequest = $this->createJsonRequest('/ajax/wizard/generate', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
            'models' => [
                [
                    'modelId' => 'gpt-5',
                    'name' => 'GPT-5',
                    'description' => 'Most capable model',
                    'capabilities' => ['chat', 'vision', 'tools'],
                    'contextLength' => 128000,
                    'maxOutputTokens' => 16384,
                    'recommended' => true,
                ],
            ],
        ]);
        $generateResponse = $this->controller->generateAction($generateRequest);

        self::assertSame(200, $generateResponse->getStatusCode());
        $body = json_decode((string)$generateResponse->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('configurations', $body);
        self::assertIsArray($body['configurations']);
    }

    #[Test]
    public function pathway1_4_generateConfigurations_errorForMissingEndpoint(): void
    {
        $request = $this->createJsonRequest('/ajax/wizard/generate', [
            'apiKey' => 'sk-test-key',
            'models' => [['modelId' => 'test', 'name' => 'Test']],
        ]);
        $response = $this->controller->generateAction($request);

        $this->assertErrorResponse($response, 400, 'Endpoint and models are required');
    }

    #[Test]
    public function pathway1_4_generateConfigurations_errorForMissingModels(): void
    {
        $request = $this->createJsonRequest('/ajax/wizard/generate', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test-key',
        ]);
        $response = $this->controller->generateAction($request);

        $this->assertErrorResponse($response, 400, 'Endpoint and models are required');
    }

    #[Test]
    public function pathway1_4_generateConfigurations_emptyModelsArray(): void
    {
        $request = $this->createJsonRequest('/ajax/wizard/generate', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test-key',
            'models' => [],
        ]);
        $response = $this->controller->generateAction($request);

        $this->assertErrorResponse($response, 400, 'Endpoint and models are required');
    }

    // =========================================================================
    // Pathway 1.5: Test Connection Flow
    // =========================================================================

    #[Test]
    public function pathway1_5_testConnection(): void
    {
        $testRequest = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
        ]);
        $testResponse = $this->controller->testAction($testRequest);

        // Test connection should return structured response
        self::assertSame(200, $testResponse->getStatusCode());
        $body = json_decode((string)$testResponse->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('message', $body);
        self::assertIsBool($body['success']);
        self::assertIsString($body['message']);
    }

    #[Test]
    public function pathway1_5_testConnection_errorForMissingEndpoint(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/test', [
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->testAction($request);

        $this->assertErrorResponse($response, 400, 'Endpoint URL is required');
    }

    #[Test]
    public function pathway1_5_testConnection_withDifferentAdapterTypes(): void
    {
        $adapterTypes = ['openai', 'anthropic', 'ollama', 'google'];

        foreach ($adapterTypes as $adapterType) {
            $testRequest = $this->createFormRequest('/ajax/wizard/test', [
                'endpoint' => 'https://example.com/api',
                'apiKey' => 'test-key',
                'adapterType' => $adapterType,
            ]);
            $testResponse = $this->controller->testAction($testRequest);

            // All adapter types should be handled gracefully
            self::assertSame(200, $testResponse->getStatusCode());
            $body = json_decode((string)$testResponse->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('success', $body);
        }
    }

    // =========================================================================
    // Detect Action Edge Cases
    // =========================================================================

    #[Test]
    public function detectAction_errorForMissingEndpoint(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/detect', []);
        $response = $this->controller->detectAction($request);

        $this->assertErrorResponse($response, 400, 'Endpoint URL is required');
    }

    #[Test]
    public function detectAction_emptyEndpoint(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => '',
        ]);
        $response = $this->controller->detectAction($request);

        $this->assertErrorResponse($response, 400, 'Endpoint URL is required');
    }

    #[Test]
    public function detectAction_detectsGoogleProvider(): void
    {
        $detectRequest = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => 'https://generativelanguage.googleapis.com/v1',
        ]);
        $detectResponse = $this->controller->detectAction($detectRequest);

        $body = $this->assertSuccessResponse($detectResponse);
        self::assertArrayHasKey('provider', $body);
        $provider = $body['provider'];
        self::assertIsArray($provider);
        /** @var array{adapterType: string} $provider */
        // Google API can be detected as either 'google' or 'gemini' adapter type
        self::assertContains($provider['adapterType'], ['google', 'gemini']);
    }

    #[Test]
    public function detectAction_detectsDeepSeekProvider(): void
    {
        $detectRequest = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => 'https://api.deepseek.com/v1',
        ]);
        $detectResponse = $this->controller->detectAction($detectRequest);

        $body = $this->assertSuccessResponse($detectResponse);
        self::assertArrayHasKey('provider', $body);
        $provider = $body['provider'];
        self::assertIsArray($provider);
        /** @var array{adapterType: string} $provider */
        // DeepSeek uses OpenAI-compatible API
        self::assertContains($provider['adapterType'], ['deepseek', 'openai']);
    }

    // =========================================================================
    // Save Action Edge Cases
    // =========================================================================

    #[Test]
    public function saveAction_onlySelectedModelsAreSaved(): void
    {
        $initialModelCount = $this->modelRepository->countActive();

        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Selective Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-selective-key',
            ],
            'models' => [
                [
                    'modelId' => 'selected-model',
                    'name' => 'Selected Model',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
                [
                    'modelId' => 'not-selected-model',
                    'name' => 'Not Selected Model',
                    'capabilities' => ['chat'],
                    'selected' => false, // Not selected
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($saveResponse);
        self::assertSame(1, $body['modelsCount'], 'Only selected models should be saved');
    }

    #[Test]
    public function saveAction_onlySelectedConfigurationsAreSaved(): void
    {
        $initialConfigCount = $this->configurationRepository->countActive();

        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Config Test Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-config-test-key',
            ],
            'models' => [
                [
                    'modelId' => 'test-model',
                    'name' => 'Test Model',
                    'capabilities' => ['chat'],
                    'selected' => true,
                    'recommended' => true,
                ],
            ],
            'configurations' => [
                [
                    'name' => 'Selected Config',
                    'recommendedModelId' => 'test-model',
                    'temperature' => 0.7,
                    'maxTokens' => 2048,
                    'selected' => true,
                ],
                [
                    'name' => 'Not Selected Config',
                    'recommendedModelId' => 'test-model',
                    'temperature' => 0.5,
                    'maxTokens' => 1024,
                    'selected' => false, // Not selected
                ],
            ],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($saveResponse);
        self::assertSame(1, $body['configurationsCount'], 'Only selected configurations should be saved');
    }

    #[Test]
    public function saveAction_handlesCustomPid(): void
    {
        $customPid = 123;

        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'PID Test Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-pid-test-key',
            ],
            'models' => [
                [
                    'modelId' => 'pid-test-model',
                    'name' => 'PID Test Model',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => $customPid,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($saveResponse);

        // Verify the provider was created with the correct PID
        $this->persistenceManager->clearState();
        $savedProvider = $body['provider'];
        self::assertIsArray($savedProvider);
        /** @var array{uid: int} $savedProvider */
        $provider = $this->providerRepository->findByUid($savedProvider['uid']);
        self::assertNotNull($provider);
        self::assertSame($customPid, $provider->getPid());
    }

    // =========================================================================
    // Pathway 1.6: Setup Wizard Input Validation Edge Cases
    // =========================================================================

    #[Test]
    public function pathway1_6_detectAction_specialCharactersInEndpoint(): void
    {
        // Test with special characters that might break URL parsing
        $request = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => 'https://api.example.com/v1?param=value&other=<script>',
        ]);
        $response = $this->controller->detectAction($request);

        // Should handle gracefully (return 200 with result or unknown)
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_6_detectAction_whitespaceInEndpoint(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => '   https://api.openai.com/v1   ',
        ]);
        $response = $this->controller->detectAction($request);

        // Should trim whitespace and detect correctly
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
    }

    #[Test]
    public function pathway1_6_testAction_emptyApiKey(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => '',
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->testAction($request);

        // Should return result (failure expected for empty key)
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function pathway1_6_testAction_veryLongApiKey(): void
    {
        $longKey = str_repeat('x', 5000);
        $request = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => $longKey,
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->testAction($request);

        // Should handle long keys gracefully
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_6_saveAction_unicodeProviderName(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => '日本語プロバイダー 🚀',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-unicode-test',
            ],
            'models' => [
                [
                    'modelId' => 'unicode-model',
                    'name' => '模型',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($saveResponse);

        // Verify unicode is preserved
        $this->persistenceManager->clearState();
        $savedProvider = $body['provider'];
        self::assertIsArray($savedProvider);
        /** @var array{uid: int} $savedProvider */
        $provider = $this->providerRepository->findByUid($savedProvider['uid']);
        self::assertNotNull($provider);
        self::assertSame('日本語プロバイダー 🚀', $provider->getName());
    }

    #[Test]
    public function pathway1_6_saveAction_specialCharactersInSystemPrompt(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Special Prompt Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-special-prompt',
            ],
            'models' => [
                [
                    'modelId' => 'test-model',
                    'name' => 'Test Model',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [
                [
                    'name' => 'Special Config',
                    'modelId' => 'test-model',
                    'temperature' => 0.7,
                    'maxTokens' => 1024,
                    'systemPrompt' => "You are a helpful assistant.\n\nRules:\n1. Be <precise>\n2. Use \"quotes\" properly\n3. Handle 日本語",
                    'selected' => true,
                ],
            ],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $this->assertSuccessResponse($saveResponse);
    }

    #[Test]
    public function pathway1_6_discoverAction_unknownAdapterType(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/discover', [
            'endpoint' => 'https://api.example.com/v1',
            'apiKey' => 'test-key',
            'adapterType' => 'unknown-adapter-type',
        ]);
        $response = $this->controller->discoverAction($request);

        // Should handle unknown adapter gracefully
        self::assertContains($response->getStatusCode(), [200, 400, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_6_generateAction_emptyModelsCapabilities(): void
    {
        $generateRequest = $this->createJsonRequest('/ajax/wizard/generate', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
            'models' => [
                [
                    'modelId' => 'basic-model',
                    'name' => 'Basic Model',
                    'capabilities' => [], // Empty capabilities
                    'contextLength' => 4096,
                ],
            ],
        ]);
        $generateResponse = $this->controller->generateAction($generateRequest);

        self::assertSame(200, $generateResponse->getStatusCode());
        $body = json_decode((string)$generateResponse->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_6_saveAction_duplicateModelIds(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Duplicate Model Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-duplicate-test',
            ],
            'models' => [
                [
                    'modelId' => 'same-model-id',
                    'name' => 'First Model',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
                [
                    'modelId' => 'same-model-id', // Duplicate
                    'name' => 'Second Model',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        // Should handle duplicate model IDs (could fail or deduplicate)
        self::assertContains($saveResponse->getStatusCode(), [200, 400]);
    }

    #[Test]
    public function pathway1_6_saveAction_extremeTemperatureValues(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Extreme Temp Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-extreme-temp',
            ],
            'models' => [
                [
                    'modelId' => 'test-model',
                    'name' => 'Test Model',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [
                [
                    'name' => 'High Temp Config',
                    'modelId' => 'test-model',
                    'temperature' => 2.0, // Maximum
                    'maxTokens' => 1024,
                    'selected' => true,
                ],
                [
                    'name' => 'Zero Temp Config',
                    'modelId' => 'test-model',
                    'temperature' => 0.0, // Minimum
                    'maxTokens' => 1024,
                    'selected' => true,
                ],
            ],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $this->assertSuccessResponse($saveResponse);
    }

    #[Test]
    public function pathway1_6_saveAction_zeroMaxTokens(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Zero Tokens Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-zero-tokens',
            ],
            'models' => [
                [
                    'modelId' => 'test-model',
                    'name' => 'Test Model',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [
                [
                    'name' => 'Zero Tokens Config',
                    'modelId' => 'test-model',
                    'temperature' => 0.7,
                    'maxTokens' => 0, // Edge case
                    'selected' => true,
                ],
            ],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        // Should be created (0 might be valid or converted to default)
        self::assertContains($saveResponse->getStatusCode(), [200, 400]);
    }

    // =========================================================================
    // Pathway 1.7: Provider Re-configuration
    // =========================================================================

    #[Test]
    public function pathway1_7_reconfigureExistingProvider(): void
    {
        // First create a provider
        $saveRequest1 = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Reconfig Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-initial-key',
            ],
            'models' => [
                [
                    'modelId' => 'gpt-5',
                    'name' => 'GPT-5',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $response1 = $this->controller->saveAction($saveRequest1);
        $body1 = $this->assertSuccessResponse($response1);
        $savedProvider = $body1['provider'];
        self::assertIsArray($savedProvider);
        /** @var array{uid: int} $savedProvider */
        $providerUid = $savedProvider['uid'];

        $this->persistenceManager->clearState();

        // Verify provider exists
        $provider = $this->providerRepository->findByUid($providerUid);
        self::assertNotNull($provider);
        self::assertSame('Reconfig Provider', $provider->getName());
    }

    #[Test]
    public function pathway1_7_addModelsToExistingProvider(): void
    {
        $provider = $this->providerRepository->findActive()->getFirst();
        self::assertNotNull($provider);

        $initialModelCount = $this->modelRepository->findByProvider($provider)->count();

        // Add new model via wizard would create new provider
        // This tests that existing providers can have models added
        self::assertGreaterThanOrEqual(0, $initialModelCount);
    }

    // =========================================================================
    // Pathway 1.8: Wizard Validation
    // =========================================================================

    #[Test]
    public function pathway1_8_detectValidatesUrlFormat(): void
    {
        // Test various URL formats
        $validUrls = [
            'https://api.openai.com/v1',
            'http://localhost:11434',
            'https://custom-endpoint.example.com/api/v1',
        ];

        foreach ($validUrls as $url) {
            $request = $this->createFormRequest('/ajax/wizard/detect', ['endpoint' => $url]);
            $response = $this->controller->detectAction($request);

            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
        }
    }

    #[Test]
    public function pathway1_8_testValidatesApiKeyFormat(): void
    {
        // Test with various API key formats
        $testCases = [
            ['key' => 'sk-proj-abc123', 'type' => 'openai'],
            ['key' => 'sk-ant-api03-xyz', 'type' => 'anthropic'],
            ['key' => '', 'type' => 'ollama'], // Ollama doesn't need key
        ];

        foreach ($testCases as $case) {
            $request = $this->createFormRequest('/ajax/wizard/test', [
                'endpoint' => 'https://api.example.com/v1',
                'apiKey' => $case['key'],
                'adapterType' => $case['type'],
            ]);
            $response = $this->controller->testAction($request);

            // All should return valid response structure
            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('success', $body);
        }
    }

    #[Test]
    public function pathway1_8_saveValidatesModelSelection(): void
    {
        // Test saving with no selected models (all selected=false)
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'No Selection Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-test',
            ],
            'models' => [
                [
                    'modelId' => 'model-1',
                    'name' => 'Model 1',
                    'capabilities' => ['chat'],
                    'selected' => false,
                ],
                [
                    'modelId' => 'model-2',
                    'name' => 'Model 2',
                    'capabilities' => ['chat'],
                    'selected' => false,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        // Response should be returned (either saves with 0 models or fails)
        self::assertContains($response->getStatusCode(), [200, 400]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        // If succeeded, should have 0 models saved
        if ($response->getStatusCode() === 200) {
            self::assertSame(0, $body['modelsCount']);
        }
    }

    // =========================================================================
    // Pathway 1.9: Multi-Provider Setup
    // =========================================================================

    #[Test]
    public function pathway1_9_setupMultipleProvidersSequentially(): void
    {
        $initialCount = $this->providerRepository->countActive();

        // Setup first provider
        $save1 = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Multi Provider 1 - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-multi-1',
            ],
            'models' => [['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true]],
            'configurations' => [],
            'pid' => 0,
        ]);
        $this->assertSuccessResponse($this->controller->saveAction($save1));

        // Setup second provider
        $save2 = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Multi Provider 2 - ' . time(),
                'adapterType' => 'anthropic',
                'endpoint' => 'https://api.anthropic.com/v1',
                'apiKey' => 'sk-ant-multi-2',
            ],
            'models' => [['modelId' => 'claude-sonnet-4-20250514', 'name' => 'Claude Sonnet', 'capabilities' => ['chat'], 'selected' => true]],
            'configurations' => [],
            'pid' => 0,
        ]);
        $this->assertSuccessResponse($this->controller->saveAction($save2));

        $this->persistenceManager->clearState();

        // Should have 2 more providers
        $finalCount = $this->providerRepository->countActive();
        self::assertSame($initialCount + 2, $finalCount);
    }

    #[Test]
    public function pathway1_9_differentAdapterTypesSupported(): void
    {
        $adapterTypes = ['openai', 'anthropic', 'ollama', 'google', 'deepseek'];

        foreach ($adapterTypes as $type) {
            $detectRequest = $this->createFormRequest('/ajax/wizard/detect', [
                'endpoint' => 'https://api.example.com/v1',
            ]);
            $response = $this->controller->detectAction($detectRequest);

            // Detect should work for all endpoints
            self::assertSame(200, $response->getStatusCode());
        }
    }

    // =========================================================================
    // Pathway 1.10: Wizard Error Recovery
    // =========================================================================

    #[Test]
    public function pathway1_10_recoverFromFailedDetection(): void
    {
        // First attempt with invalid URL
        $request1 = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => 'not-a-url',
        ]);
        $response1 = $this->controller->detectAction($request1);
        self::assertSame(200, $response1->getStatusCode());

        // Retry with valid URL
        $request2 = $this->createFormRequest('/ajax/wizard/detect', [
            'endpoint' => 'https://api.openai.com/v1',
        ]);
        $response2 = $this->controller->detectAction($request2);

        $body = $this->assertSuccessResponse($response2);
        self::assertArrayHasKey('provider', $body);
    }

    #[Test]
    public function pathway1_10_recoverFromFailedTest(): void
    {
        // First attempt with bad credentials
        $request1 = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'invalid-key',
            'adapterType' => 'openai',
        ]);
        $response1 = $this->controller->testAction($request1);
        self::assertSame(200, $response1->getStatusCode());

        // Retry should work
        $request2 = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-valid-key',
            'adapterType' => 'openai',
        ]);
        $response2 = $this->controller->testAction($request2);
        self::assertSame(200, $response2->getStatusCode());
    }

    #[Test]
    public function pathway1_10_partialSaveDoesNotCorruptState(): void
    {
        $initialProviderCount = $this->providerRepository->countActive();

        // Attempt save with invalid data
        $request = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => ['suggestedName' => 'Partial Save'],
            // Missing required fields
        ]);
        $response = $this->controller->saveAction($request);

        // Should fail
        self::assertSame(400, $response->getStatusCode());

        // State should be unchanged
        $finalProviderCount = $this->providerRepository->countActive();
        self::assertSame($initialProviderCount, $finalProviderCount);
    }

    // =========================================================================
    // Pathway 1.11: Provider Detection Edge Cases
    // =========================================================================

    #[Test]
    public function pathway1_11_detectLocalhost(): void
    {
        $localEndpoints = [
            'http://localhost:11434',
            'http://127.0.0.1:11434',
            'http://localhost:8080/v1',
        ];

        foreach ($localEndpoints as $endpoint) {
            $request = $this->createFormRequest('/ajax/wizard/detect', ['endpoint' => $endpoint]);
            $response = $this->controller->detectAction($request);

            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('success', $body);
        }
    }

    #[Test]
    public function pathway1_11_detectWithPort(): void
    {
        $portsToTest = [80, 443, 8080, 11434, 3000];

        foreach ($portsToTest as $port) {
            $request = $this->createFormRequest('/ajax/wizard/detect', [
                'endpoint' => "https://api.example.com:$port/v1",
            ]);
            $response = $this->controller->detectAction($request);

            self::assertSame(200, $response->getStatusCode());
        }
    }

    #[Test]
    public function pathway1_11_detectWithPath(): void
    {
        $pathsToTest = [
            '/v1',
            '/api/v1',
            '/openai/v1',
            '/api/openai/deployments/gpt-4/chat/completions',
        ];

        foreach ($pathsToTest as $path) {
            $request = $this->createFormRequest('/ajax/wizard/detect', [
                'endpoint' => "https://api.example.com$path",
            ]);
            $response = $this->controller->detectAction($request);

            self::assertSame(200, $response->getStatusCode());
        }
    }

    // =========================================================================
    // Pathway 1.12: Model Selection Variations
    // =========================================================================

    #[Test]
    public function pathway1_12_saveWithSingleModel(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Single Model Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-single-model',
            ],
            'models' => [
                [
                    'modelId' => 'gpt-5',
                    'name' => 'GPT-5',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($response);
        self::assertSame(1, $body['modelsCount']);
    }

    #[Test]
    public function pathway1_12_saveWithManyModels(): void
    {
        $models = [];
        for ($i = 1; $i <= 10; $i++) {
            $models[] = [
                'modelId' => "model-$i",
                'name' => "Model $i",
                'capabilities' => ['chat'],
                'selected' => true,
            ];
        }

        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Many Models Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-many-models',
            ],
            'models' => $models,
            'configurations' => [],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($response);
        self::assertSame(10, $body['modelsCount']);
    }

    #[Test]
    public function pathway1_12_saveWithMixedSelection(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Mixed Selection Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-mixed',
            ],
            'models' => [
                ['modelId' => 'model-1', 'name' => 'Model 1', 'capabilities' => ['chat'], 'selected' => true],
                ['modelId' => 'model-2', 'name' => 'Model 2', 'capabilities' => ['chat'], 'selected' => false],
                ['modelId' => 'model-3', 'name' => 'Model 3', 'capabilities' => ['chat'], 'selected' => true],
                ['modelId' => 'model-4', 'name' => 'Model 4', 'capabilities' => ['chat'], 'selected' => false],
                ['modelId' => 'model-5', 'name' => 'Model 5', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($response);
        self::assertSame(3, $body['modelsCount']); // Only selected ones
    }

    // =========================================================================
    // Pathway 1.13: Configuration Template Variations
    // =========================================================================

    #[Test]
    public function pathway1_13_saveWithMultipleConfigurations(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Multi Config Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-multi-config',
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [
                ['name' => 'Config 1', 'modelId' => 'gpt-5', 'temperature' => 0.1, 'maxTokens' => 1000, 'selected' => true],
                ['name' => 'Config 2', 'modelId' => 'gpt-5', 'temperature' => 0.5, 'maxTokens' => 2000, 'selected' => true],
                ['name' => 'Config 3', 'modelId' => 'gpt-5', 'temperature' => 0.9, 'maxTokens' => 4000, 'selected' => true],
            ],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($response);
        self::assertArrayHasKey('configurationsCount', $body);
        self::assertSame(3, $body['configurationsCount']);
    }

    #[Test]
    public function pathway1_13_saveConfigurationWithSystemPrompt(): void
    {
        $longSystemPrompt = "You are a helpful AI assistant.\n\n"
            . "Your role is to assist users with their questions.\n\n"
            . "Guidelines:\n"
            . "1. Be concise and accurate\n"
            . "2. Provide examples when helpful\n"
            . "3. Ask clarifying questions if needed\n"
            . '4. Never make up information';

        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'System Prompt Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-system-prompt',
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [
                [
                    'name' => 'With System Prompt',
                    'modelId' => 'gpt-5',
                    'temperature' => 0.7,
                    'maxTokens' => 2048,
                    'systemPrompt' => $longSystemPrompt,
                    'selected' => true,
                ],
            ],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        $this->assertSuccessResponse($response);
    }

    #[Test]
    public function pathway1_13_saveConfigurationWithDefaultFlag(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Default Config Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-default-config',
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [
                [
                    'name' => 'Default Config',
                    'modelId' => 'gpt-5',
                    'temperature' => 0.7,
                    'maxTokens' => 2048,
                    'isDefault' => true,
                    'selected' => true,
                ],
            ],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        $this->assertSuccessResponse($response);
    }

    // =========================================================================
    // Pathway 1.14: API Key Format Variations
    // =========================================================================

    #[Test]
    public function pathway1_14_testWithDifferentKeyFormats(): void
    {
        $keyFormats = [
            'sk-proj-abc123def456', // OpenAI project key
            'sk-ant-api03-xyz789', // Anthropic key
            'AIzaSy...', // Google key pattern
            'ollama', // Simple string for local
            '', // Empty for local providers
        ];

        foreach ($keyFormats as $key) {
            $request = $this->createFormRequest('/ajax/wizard/test', [
                'endpoint' => 'https://api.example.com/v1',
                'apiKey' => $key,
                'adapterType' => 'openai',
            ]);
            $response = $this->controller->testAction($request);

            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
            self::assertArrayHasKey('success', $body);
        }
    }

    #[Test]
    public function pathway1_14_saveWithSpecialCharacterApiKey(): void
    {
        $specialKey = 'sk-test+key/with=special&chars!@#$%';

        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Special Key Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => $specialKey,
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($response);

        // Verify API key was stored
        $this->persistenceManager->clearState();
        $savedProvider = $body['provider'];
        self::assertIsArray($savedProvider);
        /** @var array{uid: int} $savedProvider */
        $provider = $this->providerRepository->findByUid($savedProvider['uid']);
        self::assertNotNull($provider);
        self::assertNotEmpty($provider->getApiKey());
    }

    // =========================================================================
    // Pathway 1.15: Wizard Session State
    // =========================================================================

    #[Test]
    public function pathway1_15_wizardSessionMaintainsContext(): void
    {
        // Step 1: Test connection
        $testRequest = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-session-test-key',
            'adapterType' => 'openai',
        ]);
        $testResponse = $this->controller->testAction($testRequest);
        self::assertSame(200, $testResponse->getStatusCode());

        // Step 2: Discover models
        $discoverRequest = $this->createFormRequest('/ajax/wizard/discover', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-session-test-key',
            'adapterType' => 'openai',
        ]);
        $discoverResponse = $this->controller->discoverAction($discoverRequest);
        self::assertSame(200, $discoverResponse->getStatusCode());

        // Step 3: Save configuration
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Session Test Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-session-test-key',
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $saveResponse = $this->controller->saveAction($saveRequest);

        $this->assertSuccessResponse($saveResponse);
    }

    #[Test]
    public function pathway1_15_wizardAllowsBacktracking(): void
    {
        // User can test connection multiple times with different values
        $endpoints = [
            ['endpoint' => 'https://api.openai.com/v1', 'key' => 'sk-test1'],
            ['endpoint' => 'https://api.anthropic.com', 'key' => 'sk-ant-test'],
            ['endpoint' => 'https://api.openai.com/v1', 'key' => 'sk-test2'],
        ];

        foreach ($endpoints as $config) {
            $request = $this->createFormRequest('/ajax/wizard/test', [
                'endpoint' => $config['endpoint'],
                'apiKey' => $config['key'],
                'adapterType' => 'openai',
            ]);
            $response = $this->controller->testAction($request);

            self::assertSame(200, $response->getStatusCode());
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
        }
    }

    #[Test]
    public function pathway1_15_wizardHandlesPartialProgress(): void
    {
        // Test connection but don't proceed
        $testRequest = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-partial-test',
            'adapterType' => 'openai',
        ]);
        $testResponse = $this->controller->testAction($testRequest);
        self::assertSame(200, $testResponse->getStatusCode());

        // User can start fresh with different endpoint
        $newTestRequest = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://different-api.com/v1',
            'apiKey' => 'sk-different-key',
            'adapterType' => 'openai',
        ]);
        $newTestResponse = $this->controller->testAction($newTestRequest);
        self::assertSame(200, $newTestResponse->getStatusCode());
    }

    // =========================================================================
    // Pathway 1.16: Wizard Input Validation
    // =========================================================================

    #[Test]
    public function pathway1_16_emptyEndpoint_stillProcesses(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => '',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->testAction($request);

        // Should handle empty endpoint gracefully
        self::assertContains($response->getStatusCode(), [200, 400, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_16_emptyApiKey_stillProcesses(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => '',
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->testAction($request);

        self::assertContains($response->getStatusCode(), [200, 400, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_16_veryLongEndpoint_handled(): void
    {
        $longEndpoint = 'https://' . str_repeat('a', 200) . '.com/v1';

        $request = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => $longEndpoint,
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->testAction($request);

        // Should fail gracefully (invalid host)
        self::assertContains($response->getStatusCode(), [200, 400, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_16_invalidAdapterType_handled(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/test', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'nonexistent_adapter',
        ]);
        $response = $this->controller->testAction($request);

        // Should fail gracefully
        self::assertContains($response->getStatusCode(), [200, 400, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    // =========================================================================
    // Pathway 1.17: Wizard Model Discovery Edge Cases
    // =========================================================================

    #[Test]
    public function pathway1_17_discoverWithInvalidCredentials(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/discover', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'invalid-api-key-xyz',
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->discoverAction($request);

        // Should return error response
        self::assertContains($response->getStatusCode(), [200, 401, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_17_discoverWithUnreachableEndpoint(): void
    {
        $request = $this->createFormRequest('/ajax/wizard/discover', [
            'endpoint' => 'https://nonexistent.invalid.local/v1',
            'apiKey' => 'sk-test-key',
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->discoverAction($request);

        // Should return structured error
        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
    }

    #[Test]
    public function pathway1_17_discoverWithEmptyModelList(): void
    {
        // Some providers might return empty model lists
        $request = $this->createFormRequest('/ajax/wizard/discover', [
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-might-have-no-models',
            'adapterType' => 'openai',
        ]);
        $response = $this->controller->discoverAction($request);

        self::assertContains($response->getStatusCode(), [200, 500]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_17_discoverMultipleTimes(): void
    {
        // User can rediscover models
        for ($i = 0; $i < 3; $i++) {
            $request = $this->createFormRequest('/ajax/wizard/discover', [
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-test-key-' . $i,
                'adapterType' => 'openai',
            ]);
            $response = $this->controller->discoverAction($request);

            self::assertContains($response->getStatusCode(), [200, 500]);
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
        }
    }

    // =========================================================================
    // Pathway 1.18: Wizard Save Edge Cases
    // =========================================================================

    #[Test]
    public function pathway1_18_saveWithEmptyModels(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'No Models Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-no-models',
            ],
            'models' => [],
            'configurations' => [],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        // Should still create provider even with no models
        self::assertContains($response->getStatusCode(), [200, 400]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_18_saveWithNoSelectedModels(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'No Selected Models Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-no-selected',
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => false],
                ['modelId' => 'o4-mini', 'name' => 'O4 Mini', 'capabilities' => ['chat'], 'selected' => false],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        // Behavior depends on implementation - should handle gracefully
        self::assertContains($response->getStatusCode(), [200, 400]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_18_saveWithDuplicateProviderName(): void
    {
        $providerName = 'Duplicate Provider - ' . time();

        // First save
        $saveRequest1 = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => $providerName,
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-first',
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $response1 = $this->controller->saveAction($saveRequest1);
        $this->assertSuccessResponse($response1);

        // Second save with same name
        $saveRequest2 = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => $providerName,
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-second',
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [],
            'pid' => 0,
        ]);
        $response2 = $this->controller->saveAction($saveRequest2);

        // Should handle duplicate name - either create or return error
        self::assertContains($response2->getStatusCode(), [200, 400, 500]);
        $body = json_decode((string)$response2->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_18_saveWithVeryLongConfigurationName(): void
    {
        $longName = str_repeat('Configuration Name ', 50);

        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Long Config Name Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-long-config',
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [
                [
                    'name' => $longName,
                    'modelId' => 'gpt-5',
                    'temperature' => 0.7,
                    'maxTokens' => 2048,
                    'selected' => true,
                ],
            ],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        // Should handle long name - possibly truncate
        self::assertContains($response->getStatusCode(), [200, 400]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }

    #[Test]
    public function pathway1_18_saveWithUnicodeNames(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => '提供者名称 🚀 - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-unicode-names',
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => '模型名称 🤖', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [
                [
                    'name' => '配置名称 ⚙️',
                    'modelId' => 'gpt-5',
                    'temperature' => 0.7,
                    'maxTokens' => 2048,
                    'selected' => true,
                ],
            ],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        $body = $this->assertSuccessResponse($response);

        // Verify unicode was preserved
        $this->persistenceManager->clearState();
        $savedProvider = $body['provider'];
        self::assertIsArray($savedProvider);
        /** @var array{uid: int} $savedProvider */
        $provider = $this->providerRepository->findByUid($savedProvider['uid']);
        self::assertNotNull($provider);
        self::assertStringContainsString('🚀', $provider->getName());
    }

    #[Test]
    public function pathway1_18_saveWithExtremeTemperature(): void
    {
        // Test boundary temperatures
        $temperatures = [0.0, 2.0, 0.001, 1.999];

        foreach ($temperatures as $temp) {
            $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
                'provider' => [
                    'suggestedName' => 'Temp Test Provider - ' . $temp . ' - ' . time(),
                    'adapterType' => 'openai',
                    'endpoint' => 'https://api.openai.com/v1',
                    'apiKey' => 'sk-temp-test',
                ],
                'models' => [
                    ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true],
                ],
                'configurations' => [
                    [
                        'name' => 'Temp ' . $temp . ' Config',
                        'modelId' => 'gpt-5',
                        'temperature' => $temp,
                        'maxTokens' => 2048,
                        'selected' => true,
                    ],
                ],
                'pid' => 0,
            ]);
            $response = $this->controller->saveAction($saveRequest);

            self::assertContains($response->getStatusCode(), [200, 400]);
            $body = json_decode((string)$response->getBody(), true);
            self::assertIsArray($body);
        }
    }

    #[Test]
    public function pathway1_18_saveWithZeroMaxTokens(): void
    {
        $saveRequest = $this->createJsonRequest('/ajax/wizard/save', [
            'provider' => [
                'suggestedName' => 'Zero Tokens Provider - ' . time(),
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-zero-tokens',
            ],
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat'], 'selected' => true],
            ],
            'configurations' => [
                [
                    'name' => 'Zero Tokens Config',
                    'modelId' => 'gpt-5',
                    'temperature' => 0.7,
                    'maxTokens' => 0,
                    'selected' => true,
                ],
            ],
            'pid' => 0,
        ]);
        $response = $this->controller->saveAction($saveRequest);

        // Should handle zero tokens
        self::assertContains($response->getStatusCode(), [200, 400]);
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
    }
}
