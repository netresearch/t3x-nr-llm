<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Netresearch\NrLlm\Controller\Backend\SetupWizardController;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Service\SetupWizard\ConfigurationGenerator;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Netresearch\NrLlm\Service\SetupWizard\ProviderDetector;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Functional tests for SetupWizardController AJAX actions.
 *
 * Tests user pathways:
 * - Pathway 1.1: First-Time Provider Setup (detect, test, discover, generate, save)
 * - Pathway 1.2: Add Additional Provider
 * - Error handling for missing/invalid input
 *
 * Uses reflection to create controller with only AJAX-required dependencies,
 * bypassing Extbase ActionController initialization that requires request context.
 */
#[CoversClass(SetupWizardController::class)]
final class SetupWizardControllerTest extends AbstractFunctionalTestCase
{
    private const AJAX_NRLLM_WIZARD_GENERATE = '/ajax/nrllm/wizard/generate';

    private const AJAX_NRLLM_WIZARD_DETECT = '/ajax/nrllm/wizard/detect';

    private const HTTPS_API_OPENAI_COM_V1 = 'https://api.openai.com/v1';

    private const ENDPOINT_URL_IS_REQUIRED = 'Endpoint URL is required';

    private const AJAX_NRLLM_WIZARD_SAVE = '/ajax/nrllm/wizard/save';

    private const APPLICATION_JSON = 'application/json';

    private SetupWizardController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // Every wizard AJAX endpoint now requires an authenticated admin
        // (RequiresBackendAdminTrait — ADR-037). saveAction additionally needs
        // an authorized actor for the nr-vault store() call. Set up an admin so
        // the detect/test/discover/generate/save success + validation paths run.
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)

        // Get real services from container
        $providerDetector = $this->get(ProviderDetector::class);
        self::assertInstanceOf(ProviderDetector::class, $providerDetector);

        $modelDiscovery = $this->get(ModelDiscoveryInterface::class);
        self::assertInstanceOf(ModelDiscoveryInterface::class, $modelDiscovery);

        $configurationGenerator = $this->get(ConfigurationGenerator::class);
        self::assertInstanceOf(ConfigurationGenerator::class, $configurationGenerator);

        $providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $providerRepository);

        $modelRepository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $modelRepository);

        $llmConfigurationRepository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $llmConfigurationRepository);

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);

        // saveAction persists the API key through nr-vault, so the controller
        // needs the vault service injected as well.
        $vaultService = $this->get(VaultServiceInterface::class);
        self::assertInstanceOf(VaultServiceInterface::class, $vaultService);

        // Create controller via reflection to inject only AJAX-required dependencies
        // This bypasses initializeAction() which requires Extbase request context
        $this->controller = $this->createControllerWithDependencies(
            $providerDetector,
            $modelDiscovery,
            $configurationGenerator,
            $providerRepository,
            $modelRepository,
            $llmConfigurationRepository,
            $persistenceManager,
            $vaultService,
            new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * Create controller instance with only the dependencies needed for AJAX actions.
     * Uses reflection to bypass constructor and set only required properties.
     */
    private function createControllerWithDependencies(
        ProviderDetector $providerDetector,
        ModelDiscoveryInterface $modelDiscovery,
        ConfigurationGenerator $configurationGenerator,
        ProviderRepository $providerRepository,
        ModelRepository $modelRepository,
        LlmConfigurationRepository $llmConfigurationRepository,
        PersistenceManagerInterface $persistenceManager,
        VaultServiceInterface $vaultService,
        LoggerInterface $logger,
    ): SetupWizardController {
        $reflection = new ReflectionClass(SetupWizardController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        // Set only the properties needed for AJAX actions
        $this->setPrivateProperty($controller, 'providerDetector', $providerDetector);
        $this->setPrivateProperty($controller, 'modelDiscovery', $modelDiscovery);
        $this->setPrivateProperty($controller, 'configurationGenerator', $configurationGenerator);
        $this->setPrivateProperty($controller, 'providerRepository', $providerRepository);
        $this->setPrivateProperty($controller, 'modelRepository', $modelRepository);
        $this->setPrivateProperty($controller, 'llmConfigurationRepository', $llmConfigurationRepository);
        $this->setPrivateProperty($controller, 'persistenceManager', $persistenceManager);
        $this->setPrivateProperty($controller, 'vaultService', $vaultService);
        $this->setPrivateProperty($controller, 'logger', $logger);

        return $controller;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    // -------------------------------------------------------------------------
    // Pathway 1.1: Provider Detection
    // -------------------------------------------------------------------------

    #[Test]
    public function detectReturnsProviderInfoForValidEndpoint(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_DETECT);
        $request = $request->withParsedBody(['endpoint' => self::HTTPS_API_OPENAI_COM_V1]);

        // Act
        $response = $this->controller->detectAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('provider', $body);
        $provider = $body['provider'];
        self::assertIsArray($provider);
        self::assertArrayHasKey('adapterType', $provider);
        self::assertArrayHasKey('suggestedName', $provider);
    }

    #[Test]
    public function detectReturnsErrorForMissingEndpoint(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_DETECT);
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->detectAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame(self::ENDPOINT_URL_IS_REQUIRED, $body['error']);
    }

    #[Test]
    public function detectReturnsErrorForEmptyEndpoint(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_DETECT);
        $request = $request->withParsedBody(['endpoint' => '']);

        // Act
        $response = $this->controller->detectAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
    }

    // -------------------------------------------------------------------------
    // Pathway 1.1: Connection Testing
    // -------------------------------------------------------------------------

    #[Test]
    public function testActionReturnsErrorForMissingEndpoint(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/test');
        $request = $request->withParsedBody(['apiKey' => 'sk-test']);

        // Act
        $response = $this->controller->testAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame(self::ENDPOINT_URL_IS_REQUIRED, $body['error']);
    }

    #[Test]
    public function testActionReturnsResponseForValidInput(): void
    {
        // Note: This test verifies the controller action flow.
        // Without a real API, it will return connection failure which is expected.
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/test');
        $request = $request->withParsedBody([
            'endpoint' => self::HTTPS_API_OPENAI_COM_V1,
            'apiKey' => 'sk-invalid-key',
            'adapterType' => 'openai',
        ]);

        // Act
        $response = $this->controller->testAction($request);

        // Assert - response is 200 with success or failure message
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('success', $body);
        self::assertArrayHasKey('message', $body);
    }

    // -------------------------------------------------------------------------
    // Pathway 1.1: Model Discovery
    // -------------------------------------------------------------------------

    #[Test]
    public function discoverActionReturnsErrorForMissingEndpoint(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/discover');
        $request = $request->withParsedBody(['apiKey' => 'sk-test']);

        // Act
        $response = $this->controller->discoverAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame(self::ENDPOINT_URL_IS_REQUIRED, $body['error']);
    }

    #[Test]
    public function discoverActionReturnsModelsArray(): void
    {
        // Note: Without real API, this returns empty models array
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/discover');
        $request = $request->withParsedBody([
            'endpoint' => self::HTTPS_API_OPENAI_COM_V1,
            'apiKey' => 'sk-test',
            'adapterType' => 'openai',
        ]);

        // Act
        $response = $this->controller->discoverAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('models', $body);
        self::assertIsArray($body['models']);
    }

    // -------------------------------------------------------------------------
    // Pathway 1.1: Configuration Generation
    // -------------------------------------------------------------------------

    #[Test]
    public function generateActionReturnsErrorForMissingEndpoint(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_GENERATE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'models' => [['modelId' => 'gpt-5', 'name' => 'GPT-5']],
        ])));

        // Act
        $response = $this->controller->generateAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Endpoint and models are required', $body['error']);
    }

    #[Test]
    public function generateActionReturnsErrorForMissingModels(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_GENERATE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'endpoint' => self::HTTPS_API_OPENAI_COM_V1,
        ])));

        // Act
        $response = $this->controller->generateAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
    }

    #[Test]
    public function generateActionReturnsConfigurationsArray(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_GENERATE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'endpoint' => self::HTTPS_API_OPENAI_COM_V1,
            'apiKey' => 'sk-test',
            'adapterType' => 'openai',
            'models' => [
                ['modelId' => 'gpt-5', 'name' => 'GPT-5', 'capabilities' => ['chat']],
            ],
        ])));

        // Act
        $response = $this->controller->generateAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('configurations', $body);
        self::assertIsArray($body['configurations']);
    }

    // -------------------------------------------------------------------------
    // Pathway 1.2: Additional Provider Types
    // -------------------------------------------------------------------------

    #[Test]
    public function detectHandlesOllamaEndpoint(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_DETECT);
        $request = $request->withParsedBody(['endpoint' => 'http://localhost:11434']);

        // Act
        $response = $this->controller->detectAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        $provider = $body['provider'];
        self::assertIsArray($provider);
        self::assertSame('ollama', $provider['adapterType']);
    }

    #[Test]
    public function detectHandlesAnthropicEndpoint(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_DETECT);
        $request = $request->withParsedBody(['endpoint' => 'https://api.anthropic.com/v1']);

        // Act
        $response = $this->controller->detectAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        $provider = $body['provider'];
        self::assertIsArray($provider);
        self::assertSame('anthropic', $provider['adapterType']);
    }

    // -------------------------------------------------------------------------
    // Pathway 1.1: Save Wizard Results (saveAction)
    // -------------------------------------------------------------------------

    #[Test]
    public function saveActionReturnsErrorForMissingProvider(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_SAVE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'models' => [['modelId' => 'gpt-5', 'name' => 'GPT-5', 'selected' => true]],
        ])));

        // Act
        $response = $this->controller->saveAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Provider and models are required', $body['error']);
    }

    #[Test]
    public function saveActionReturnsErrorForMissingModels(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_SAVE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'provider' => [
                'suggestedName' => 'OpenAI',
                'adapterType' => 'openai',
                'endpoint' => self::HTTPS_API_OPENAI_COM_V1,
                'apiKey' => 'sk-test',
            ],
        ])));

        // Act
        $response = $this->controller->saveAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Provider and models are required', $body['error']);
    }

    #[Test]
    public function saveActionCreatesProviderAndModels(): void
    {
        // The admin backend user (required by both the admin guard and the
        // nr-vault store() call) is set up in setUp().
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_SAVE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'provider' => [
                'suggestedName' => 'Test OpenAI Provider',
                'adapterType' => 'openai',
                'endpoint' => self::HTTPS_API_OPENAI_COM_V1,
                'apiKey' => 'sk-test-key-12345',
            ],
            'models' => [
                [
                    'modelId' => 'gpt-5',
                    'name' => 'GPT-5',
                    'capabilities' => ['chat', 'vision'],
                    'contextLength' => 128000,
                    'maxOutputTokens' => 16384,
                    'selected' => true,
                    'recommended' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ])));

        // Act
        $response = $this->controller->saveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('provider', $body);
        $provider = $body['provider'];
        self::assertIsArray($provider);
        self::assertArrayHasKey('uid', $provider);
        self::assertArrayHasKey('modelsCount', $body);
        self::assertGreaterThan(0, $provider['uid']);
        self::assertSame(1, $body['modelsCount']);
    }

    #[Test]
    public function saveActionSeedsDimensionsForKnownEmbeddingModels(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_SAVE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'provider' => [
                'suggestedName' => 'OpenAI Embeddings',
                'adapterType' => 'openai',
                'endpoint' => self::HTTPS_API_OPENAI_COM_V1,
                'apiKey' => 'sk-test-key-12345',
            ],
            'models' => [
                [
                    'modelId' => 'text-embedding-3-small',
                    'name' => 'Text Embedding 3 Small',
                    'capabilities' => ['embeddings'],
                    'selected' => true,
                ],
                [
                    'modelId' => 'gpt-5',
                    'name' => 'GPT-5',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ])));

        // Act
        $response = $this->controller->saveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        // Known embedding models get the published dimensionality (ADR-055),
        // everything else keeps the column default 0 ("unknown").
        self::assertSame(1536, $this->dimensionsOfModelId('text-embedding-3-small'));
        self::assertSame(0, $this->dimensionsOfModelId('gpt-5'));
    }

    #[Test]
    public function aRecommendedDecisionModelKeepsItsPriceButNeverBecomesTheDefaultModel(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_SAVE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'provider' => [
                'suggestedName' => 'TypeSafe',
                'adapterType' => 'typesafe',
                'endpoint' => 'https://api.typesafe.ai/v1',
                'apiKey' => 'ts-test-key-12345',
            ],
            'models' => [
                [
                    'modelId' => 'jev-1.13.0',
                    'name' => 'Jev 1.13',
                    'capabilities' => ['decision'],
                    'costInput' => 4.2,
                    'costOutput' => 0.0,
                    'selected' => true,
                    'recommended' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ])));

        self::assertSame(200, $this->controller->saveAction($request)->getStatusCode());

        $row = $this->modelRow('jev-1.13.0');
        self::assertIsNumeric($row['cost_input']);
        self::assertSame(4.2, (float)$row['cost_input'], 'the discovered price is kept');
        // Generic chat calls go to the default model, which a decision model refuses.
        self::assertSame(0, (int)$row['is_default']);
    }

    #[Test]
    public function severalRecommendedChatModelsYieldOneDefaultModel(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_SAVE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'provider' => [
                'suggestedName' => 'Local Ollama',
                'adapterType' => 'ollama',
                'endpoint' => 'http://ollama:11434',
                'apiKey' => '',
            ],
            // Ollama recommends every installed model.
            'models' => [
                ['modelId' => 'llama3:latest', 'name' => 'Llama 3', 'capabilities' => ['chat'], 'selected' => true, 'recommended' => true],
                ['modelId' => 'qwen:latest', 'name' => 'Qwen', 'capabilities' => ['chat'], 'selected' => true, 'recommended' => true],
            ],
            'configurations' => [],
            'pid' => 0,
        ])));

        self::assertSame(200, $this->controller->saveAction($request)->getStatusCode());

        self::assertSame(1, (int)$this->modelRow('llama3:latest')['is_default'], 'the first eligible model');
        self::assertSame(0, (int)$this->modelRow('qwen:latest')['is_default']);
    }

    /**
     * @return array<string, mixed>
     */
    private function modelRow(string $modelId): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_nrllm_model');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('cost_input', 'is_default')
            ->from('tx_nrllm_model')
            ->where($queryBuilder->expr()->eq('model_id', $queryBuilder->createNamedParameter($modelId)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function dimensionsOfModelId(string $modelId): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_nrllm_model');
        $queryBuilder->getRestrictions()->removeAll();
        $value = $queryBuilder
            ->select('dimensions')
            ->from('tx_nrllm_model')
            ->where(
                $queryBuilder->expr()->eq(
                    'model_id',
                    $queryBuilder->createNamedParameter($modelId),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$value;
    }

    #[Test]
    public function saveActionNormalizesBareOpenAiEndpointToIncludeV1(): void
    {
        // #98: a bare OpenAI host entered in the wizard must be stored WITH the /v1
        // version path — OpenAiProvider expects it in the base URL and does not add
        // it — otherwise every request hits /models instead of /v1/models.
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_SAVE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'provider' => [
                'suggestedName' => 'Bare OpenAI',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com',
                'apiKey' => 'sk-test-key-12345',
            ],
            'models' => [
                [
                    'modelId' => 'gpt-5',
                    'name' => 'GPT-5',
                    'capabilities' => ['chat'],
                    'contextLength' => 128000,
                    'selected' => true,
                ],
            ],
            'configurations' => [],
            'pid' => 0,
        ])));

        $response = $this->controller->saveAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('provider', $body);
        $provider = $body['provider'];
        self::assertIsArray($provider);
        self::assertArrayHasKey('uid', $provider);
        $uid = (int)$provider['uid'];

        $providerRepository = $this->get(ProviderRepository::class);
        self::assertInstanceOf(ProviderRepository::class, $providerRepository);
        $saved = $providerRepository->findByUid($uid);
        self::assertNotNull($saved);
        self::assertSame(
            'https://api.openai.com/v1',
            $saved->getEndpointUrl(),
            'the wizard must persist the canonical /v1 base URL for OpenAI',
        );
    }

    #[Test]
    public function saveActionCreatesProviderWithConfigurations(): void
    {
        $request = new ServerRequest('POST', self::AJAX_NRLLM_WIZARD_SAVE);
        $request = $request->withHeader('Content-Type', self::APPLICATION_JSON);
        $request = $request->withBody(
            Utils::streamFor(
                json_encode(
                    [
                        'provider' => [
                            'suggestedName' => 'Wizard Persistence Oracle',
                            'adapterType' => 'openai',
                            'endpoint' => self::HTTPS_API_OPENAI_COM_V1,
                            'apiKey' => '',
                        ],
                        'models' => [
                            [
                                'modelId' => 'gpt-5',
                                'name' => 'GPT-5',
                                'capabilities' => ['chat'],
                                'selected' => true,
                            ],
                            [
                                'modelId' => 'o4-mini',
                                'name' => 'O4 Mini',
                                'capabilities' => ['chat'],
                                'selected' => true,
                            ],
                            [
                                'modelId' => 'whisper-1',
                                'name' => 'Ignored Model',
                                'capabilities' => ['transcription'],
                                'selected' => false,
                            ],
                        ],
                        'configurations' => [
                            [
                                'identifier' => 'audit_wizard_selected',
                                'name' => 'Selected Configuration',
                                'recommendedModelId' => 'o4-mini',
                                'temperature' => 0.25,
                                'maxTokens' => 42,
                                'systemPrompt' => 'Only the selected model should serve this configuration.',
                                'selected' => true,
                            ],
                            [
                                'identifier' => 'audit_wizard_ignored',
                                'name' => 'Ignored Configuration',
                                'selected' => false,
                            ],
                        ],
                        'pid' => 0,
                    ],
                ),
            ),
        );

        $response = $this->controller->saveAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertIsArray($body['provider']);
        self::assertIsInt($body['provider']['uid']);
        self::assertGreaterThan(0, $body['provider']['uid']);
        self::assertSame(2, $body['modelsCount']);
        self::assertSame(1, $body['configurationsCount']);

        $modelQuery = $this->getConnectionPool()->getQueryBuilderForTable('tx_nrllm_model');
        $models = $modelQuery
            ->select('uid', 'model_id')
            ->from('tx_nrllm_model')
            ->where(
                $modelQuery->expr()->eq(
                    'provider_uid',
                    $modelQuery->createNamedParameter($body['provider']['uid']),
                ),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
        self::assertSame(
            ['gpt-5', 'o4-mini'],
            array_column($models, 'model_id'),
        );

        $configQuery = $this
            ->getConnectionPool()
            ->getQueryBuilderForTable('tx_nrllm_configuration');
        $configurations = $configQuery
            ->select(
                'identifier',
                'model_uid',
                'system_prompt',
                'temperature',
                'max_tokens',
                'is_active',
                'pid',
            )
            ->from('tx_nrllm_configuration')
            ->where(
                $configQuery->expr()->or(
                    $configQuery->expr()->eq(
                        'identifier',
                        $configQuery->createNamedParameter(
                            'audit_wizard_selected',
                        ),
                    ),
                    $configQuery->expr()->eq(
                        'identifier',
                        $configQuery->createNamedParameter(
                            'audit_wizard_ignored',
                        ),
                    ),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();
        self::assertCount(1, $configurations);
        self::assertSame(
            'audit_wizard_selected',
            $configurations[0]['identifier'],
        );
        self::assertSame(
            (int)$models[1]['uid'],
            (int)$configurations[0]['model_uid'],
        );
        self::assertSame(
            'Only the selected model should serve this configuration.',
            $configurations[0]['system_prompt'],
        );
        self::assertIsNumeric($configurations[0]['temperature']);
        self::assertSame(0.25, (float)$configurations[0]['temperature']);
        self::assertSame(42, (int)$configurations[0]['max_tokens']);
        self::assertSame(1, (int)$configurations[0]['is_active']);
        self::assertSame(0, (int)$configurations[0]['pid']);
    }
}
