<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use BadMethodCallException;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Mutation-killing tests for GroqProvider.
 */
#[CoversClass(GroqProvider::class)]
class GroqProviderMutationTest extends AbstractUnitTestCase
{
    private function createProvider(): GroqProvider
    {
        $provider = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        return $provider;
    }

    #[Test]
    public function getNameReturnsGroq(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('Groq', $provider->getName());
    }

    #[Test]
    public function getIdentifierReturnsGroq(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('groq', $provider->getIdentifier());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultWhenNotConfigured(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        self::assertEquals('llama-3.3-70b-versatile', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModelWhenSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mixtral-8x7b-32768',
        ]);

        self::assertEquals('mixtral-8x7b-32768', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultWhenConfiguredModelIsEmpty(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => '',
        ]);

        // Should return default when empty string is configured
        self::assertEquals('llama-3.3-70b-versatile', $provider->getDefaultModel());
    }

    #[Test]
    public function getAvailableModelsReturnsNonEmptyArray(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        self::assertNotEmpty($models);
        self::assertArrayHasKey('llama-3.3-70b-versatile', $models);
        self::assertArrayHasKey('mixtral-8x7b-32768', $models);
    }

    #[Test]
    public function supportsStreamingReturnsTrue(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsStreaming());
    }

    #[Test]
    public function supportsToolsReturnsTrue(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsTools());
    }

    #[Test]
    public function supportsFeatureReturnsTrueForChat(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsFeature('chat'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForEmbeddings(): void
    {
        $provider = $this->createProvider();

        // Groq does not support embeddings
        self::assertFalse($provider->supportsFeature('embeddings'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForVision(): void
    {
        $provider = $this->createProvider();

        // Groq does not support vision as a feature
        self::assertFalse($provider->supportsFeature('vision'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForStreaming(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsFeature('streaming'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForTools(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsFeature('tools'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForUnknownFeature(): void
    {
        $provider = $this->createProvider();

        self::assertFalse($provider->supportsFeature('unknown_feature'));
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeySet(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        self::assertTrue($provider->isAvailable());
    }

    #[Test]
    public function defaultBaseUrlIsGroqApi(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');
        /** @var string $baseUrlValue */
        $baseUrlValue = $baseUrl->getValue($provider);

        self::assertStringContainsString('api.groq.com', $baseUrlValue);
    }

    #[Test]
    public function supportsFeatureReturnsTrueForCompletion(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsFeature('completion'));
    }

    #[Test]
    public function embeddingsThrowsBadMethodCallException(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Groq does not support embeddings');

        $provider->embeddings('test input');
    }

    #[Test]
    public function getAvailableModelsContainsCorrectModelNames(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        // Verify model IDs and descriptions are non-empty
        foreach ($models as $modelId => $description) {
            self::assertNotEmpty($modelId);
            self::assertNotEmpty($description);
        }

        // Verify specific expected models exist
        self::assertArrayHasKey('llama-3.1-8b-instant', $models);
        self::assertArrayHasKey('gemma2-9b-it', $models);
    }

    #[Test]
    public function configureCustomBaseUrl(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'baseUrl' => 'https://custom-api.example.com/v1',
        ]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');

        self::assertEquals('https://custom-api.example.com/v1', $baseUrl->getValue($provider));
    }

    #[Test]
    public function getAvailableModelsHasMinimumModels(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        // Groq should have at least 8 models available
        self::assertGreaterThanOrEqual(8, count($models));
    }

    #[Test]
    public function getFastModelReturnsInstantModel(): void
    {
        self::assertEquals('llama-3.1-8b-instant', GroqProvider::getFastModel());
    }

    #[Test]
    public function getQualityModelReturnsVersatileModel(): void
    {
        self::assertEquals('llama-3.3-70b-versatile', GroqProvider::getQualityModel());
    }

    #[Test]
    public function getVisionModelReturnsVisionPreviewModel(): void
    {
        self::assertEquals('llama-3.2-90b-vision-preview', GroqProvider::getVisionModel());
    }
}
