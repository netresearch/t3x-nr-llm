<?php

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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
    private SetupWizardController $controller;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

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
        );
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
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/detect');
        $request = $request->withParsedBody(['endpoint' => 'https://api.openai.com/v1']);

        // Act
        $response = $this->controller->detectAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('provider', $body);
        self::assertArrayHasKey('adapterType', $body['provider']);
        self::assertArrayHasKey('suggestedName', $body['provider']);
    }

    #[Test]
    public function detectReturnsErrorForMissingEndpoint(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/detect');
        $request = $request->withParsedBody([]);

        // Act
        $response = $this->controller->detectAction($request);

        // Assert
        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse($body['success']);
        self::assertSame('Endpoint URL is required', $body['error']);
    }

    #[Test]
    public function detectReturnsErrorForEmptyEndpoint(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/detect');
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
        self::assertSame('Endpoint URL is required', $body['error']);
    }

    #[Test]
    public function testActionReturnsResponseForValidInput(): void
    {
        // Note: This test verifies the controller action flow.
        // Without a real API, it will return connection failure which is expected.
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/test');
        $request = $request->withParsedBody([
            'endpoint' => 'https://api.openai.com/v1',
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
        self::assertSame('Endpoint URL is required', $body['error']);
    }

    #[Test]
    public function discoverActionReturnsModelsArray(): void
    {
        // Note: Without real API, this returns empty models array
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/discover');
        $request = $request->withParsedBody([
            'endpoint' => 'https://api.openai.com/v1',
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
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/generate');
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withBody(Utils::streamFor(json_encode([
            'models' => [['modelId' => 'gpt-4o', 'name' => 'GPT-4o']],
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
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/generate');
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withBody(Utils::streamFor(json_encode([
            'endpoint' => 'https://api.openai.com/v1',
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
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/generate');
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withBody(Utils::streamFor(json_encode([
            'endpoint' => 'https://api.openai.com/v1',
            'apiKey' => 'sk-test',
            'adapterType' => 'openai',
            'models' => [
                ['modelId' => 'gpt-4o', 'name' => 'GPT-4o', 'capabilities' => ['chat']],
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
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/detect');
        $request = $request->withParsedBody(['endpoint' => 'http://localhost:11434']);

        // Act
        $response = $this->controller->detectAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame('ollama', $body['provider']['adapterType']);
    }

    #[Test]
    public function detectHandlesAnthropicEndpoint(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/detect');
        $request = $request->withParsedBody(['endpoint' => 'https://api.anthropic.com/v1']);

        // Act
        $response = $this->controller->detectAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertSame('anthropic', $body['provider']['adapterType']);
    }

    // -------------------------------------------------------------------------
    // Pathway 1.1: Save Wizard Results (saveAction)
    // -------------------------------------------------------------------------

    #[Test]
    public function saveActionReturnsErrorForMissingProvider(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/save');
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withBody(Utils::streamFor(json_encode([
            'models' => [['modelId' => 'gpt-4o', 'name' => 'GPT-4o', 'selected' => true]],
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
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/save');
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withBody(Utils::streamFor(json_encode([
            'provider' => [
                'suggestedName' => 'OpenAI',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
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
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/save');
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withBody(Utils::streamFor(json_encode([
            'provider' => [
                'suggestedName' => 'Test OpenAI Provider',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-test-key-12345',
            ],
            'models' => [
                [
                    'modelId' => 'gpt-4o',
                    'name' => 'GPT-4o',
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
        self::assertArrayHasKey('uid', $body['provider']);
        self::assertArrayHasKey('modelsCount', $body);
        self::assertGreaterThan(0, $body['provider']['uid']);
        self::assertSame(1, $body['modelsCount']);
    }

    #[Test]
    public function saveActionCreatesProviderWithConfigurations(): void
    {
        $request = new ServerRequest('POST', '/ajax/nrllm/wizard/save');
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withBody(Utils::streamFor(json_encode([
            'provider' => [
                'suggestedName' => 'OpenAI With Config',
                'adapterType' => 'openai',
                'endpoint' => 'https://api.openai.com/v1',
                'apiKey' => 'sk-test',
            ],
            'models' => [
                [
                    'modelId' => 'gpt-4o-mini',
                    'name' => 'GPT-4o Mini',
                    'capabilities' => ['chat'],
                    'selected' => true,
                ],
            ],
            'configurations' => [
                [
                    'name' => 'Fast Chat',
                    'modelId' => 'gpt-4o-mini',
                    'temperature' => 0.7,
                    'maxTokens' => 1000,
                    'systemPrompt' => 'You are a helpful assistant.',
                ],
            ],
            'pid' => 0,
        ])));

        // Act
        $response = $this->controller->saveAction($request);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('configurationsCount', $body);
    }
}
