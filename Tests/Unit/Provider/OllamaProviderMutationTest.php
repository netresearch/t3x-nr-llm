<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\OllamaProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Mutation-killing tests for OllamaProvider.
 */
#[CoversClass(OllamaProvider::class)]
class OllamaProviderMutationTest extends AbstractUnitTestCase
{
    private function createProvider(): OllamaProvider
    {
        $provider = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        return $provider;
    }

    /**
     * Create provider with specific HTTP client for testing API responses.
     *
     * @return array{provider: OllamaProvider, httpClient: ClientInterface&Stub}
     */
    private function createProviderWithHttpClient(): array
    {
        $httpClient = $this->createHttpClientMock();
        $provider = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => '',
            'baseUrl' => 'http://localhost:11434',
        ]);
        $provider->setHttpClient($httpClient);

        return ['provider' => $provider, 'httpClient' => $httpClient];
    }

    #[Test]
    public function getNameReturnsOllama(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('Ollama', $provider->getName());
    }

    #[Test]
    public function getIdentifierReturnsOllama(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('ollama', $provider->getIdentifier());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultWhenNotConfigured(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => '']);

        self::assertEquals('llama3.2', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModelWhenSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'mistral',
        ]);

        self::assertEquals('mistral', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultWhenConfiguredModelIsEmpty(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => '',
        ]);

        // Should return default when empty string is configured
        self::assertEquals('llama3.2', $provider->getDefaultModel());
    }

    #[Test]
    public function getAvailableModelsReturnsDefaultModelsOnError(): void
    {
        ['provider' => $provider, 'httpClient' => $httpClient] = $this->createProviderWithHttpClient();

        $httpClient->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection refused'));

        $models = $provider->getAvailableModels();

        // Should return default models when server is unavailable
        self::assertNotEmpty($models);
        self::assertArrayHasKey('llama3.2', $models);
        self::assertArrayHasKey('mistral', $models);
        self::assertArrayHasKey('codellama', $models);
    }

    #[Test]
    public function getAvailableModelsReturnsModelsFromServer(): void
    {
        ['provider' => $provider, 'httpClient' => $httpClient] = $this->createProviderWithHttpClient();

        $apiResponse = [
            'models' => [
                ['name' => 'llama3.2:latest', 'modified_at' => '2024-01-01T00:00:00Z'],
                ['name' => 'phi3:latest', 'modified_at' => '2024-01-01T00:00:00Z'],
            ],
        ];

        $httpClient->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $models = $provider->getAvailableModels();

        self::assertArrayHasKey('llama3.2:latest', $models);
        self::assertArrayHasKey('phi3:latest', $models);
    }

    #[Test]
    public function supportsStreamingReturnsTrue(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsStreaming());
    }

    #[Test]
    public function supportsFeatureReturnsTrueForChat(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsFeature('chat'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForEmbeddings(): void
    {
        $provider = $this->createProvider();

        // Ollama supports embeddings
        self::assertTrue($provider->supportsFeature('embeddings'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForVision(): void
    {
        $provider = $this->createProvider();

        // Ollama does not support vision as a feature in its interface
        self::assertFalse($provider->supportsFeature('vision'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForStreaming(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsFeature('streaming'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForTools(): void
    {
        $provider = $this->createProvider();

        // Ollama does not implement ToolCapableInterface
        self::assertFalse($provider->supportsFeature('tools'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForUnknownFeature(): void
    {
        $provider = $this->createProvider();

        self::assertFalse($provider->supportsFeature('unknown_feature'));
    }

    #[Test]
    public function isAvailableReturnsTrueWhenBaseUrlSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => '',
            'baseUrl' => 'http://localhost:11434',
        ]);

        // Ollama doesn't require API key, just a base URL
        self::assertTrue($provider->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsTrueWithDefaultBaseUrl(): void
    {
        $provider = $this->createProvider();
        // Omit baseUrl key to get the default
        $provider->configure([
            'apiKeyIdentifier' => '',
        ]);

        // Should use default base URL
        self::assertTrue($provider->isAvailable());
    }

    #[Test]
    public function defaultBaseUrlIsLocalhost(): void
    {
        $provider = $this->createProvider();
        // Omit baseUrl key to get the default
        $provider->configure([
            'apiKeyIdentifier' => '',
        ]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');
        /** @var string $baseUrlValue */
        $baseUrlValue = $baseUrl->getValue($provider);

        self::assertStringContainsString('localhost:11434', $baseUrlValue);
    }

    #[Test]
    public function supportsFeatureReturnsTrueForCompletion(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsFeature('completion'));
    }

    #[Test]
    public function configureCustomBaseUrl(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => '',
            'baseUrl' => 'http://192.168.1.100:11434',
        ]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');

        self::assertEquals('http://192.168.1.100:11434', $baseUrl->getValue($provider));
    }

    #[Test]
    public function testConnectionReturnsSuccessOnValidResponse(): void
    {
        ['provider' => $provider, 'httpClient' => $httpClient] = $this->createProviderWithHttpClient();

        $apiResponse = [
            'models' => [
                ['name' => 'llama3.2:latest'],
                ['name' => 'mistral:latest'],
                ['name' => 'phi3:latest'],
            ],
        ];

        $httpClient->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $provider->testConnection();

        self::assertTrue($result['success']);
        self::assertStringContainsString('3 models', $result['message']);
        self::assertArrayHasKey('models', $result);
        self::assertCount(3, $result['models']);
    }

    #[Test]
    public function getAvailableModelsSkipsEmptyModelNames(): void
    {
        ['provider' => $provider, 'httpClient' => $httpClient] = $this->createProviderWithHttpClient();

        $apiResponse = [
            'models' => [
                ['name' => 'llama3.2:latest'],
                ['name' => ''],  // Empty name should be skipped
                ['name' => 'mistral:latest'],
            ],
        ];

        $httpClient->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $models = $provider->getAvailableModels();

        self::assertCount(2, $models);
        self::assertArrayHasKey('llama3.2:latest', $models);
        self::assertArrayHasKey('mistral:latest', $models);
    }

    #[Test]
    public function configureWithOmittedBaseUrlUsesDefault(): void
    {
        $provider = $this->createProvider();
        // Omitting baseUrl key triggers the default value from getDefaultBaseUrl()
        $provider->configure([
            'apiKeyIdentifier' => '',
        ]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');
        /** @var string $baseUrlValue */
        $baseUrlValue = $baseUrl->getValue($provider);

        // Should have set the default base URL
        self::assertEquals('http://localhost:11434', $baseUrlValue);
    }
}
