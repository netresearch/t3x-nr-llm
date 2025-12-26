<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mutation-killing tests for ClaudeProvider.
 */
#[CoversClass(ClaudeProvider::class)]
class ClaudeProviderMutationTest extends AbstractUnitTestCase
{
    private function createProvider(): ClaudeProvider
    {
        return new ClaudeProvider(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
    }

    #[Test]
    public function getNameReturnsAnthropicClaude(): void
    {
        $provider = $this->createProvider();

        $this->assertEquals('Anthropic Claude', $provider->getName());
    }

    #[Test]
    public function getIdentifierReturnsClaude(): void
    {
        $provider = $this->createProvider();

        $this->assertEquals('claude', $provider->getIdentifier());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultWhenNotConfigured(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $this->assertEquals('claude-sonnet-4-5-20250929', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModelWhenSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => 'claude-opus-4-5-20251124',
        ]);

        $this->assertEquals('claude-opus-4-5-20251124', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultWhenConfiguredModelIsEmpty(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => '',
        ]);

        // Should return default when empty string is configured
        $this->assertEquals('claude-sonnet-4-5-20250929', $provider->getDefaultModel());
    }

    #[Test]
    public function getAvailableModelsReturnsNonEmptyArray(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        $this->assertIsArray($models);
        $this->assertNotEmpty($models);
        $this->assertArrayHasKey('claude-opus-4-5-20251124', $models);
        $this->assertArrayHasKey('claude-sonnet-4-5-20250929', $models);
    }

    #[Test]
    public function supportsVisionReturnsTrue(): void
    {
        $provider = $this->createProvider();

        $this->assertTrue($provider->supportsVision());
    }

    #[Test]
    public function supportsStreamingReturnsTrue(): void
    {
        $provider = $this->createProvider();

        $this->assertTrue($provider->supportsStreaming());
    }

    #[Test]
    public function supportsToolsReturnsTrue(): void
    {
        $provider = $this->createProvider();

        $this->assertTrue($provider->supportsTools());
    }

    #[Test]
    public function getSupportedImageFormatsReturnsCorrectList(): void
    {
        $provider = $this->createProvider();

        $formats = $provider->getSupportedImageFormats();

        $this->assertContains('png', $formats);
        $this->assertContains('jpeg', $formats);
        $this->assertContains('jpg', $formats);
        $this->assertContains('gif', $formats);
        $this->assertContains('webp', $formats);
    }

    #[Test]
    public function getMaxImageSizeReturns20MB(): void
    {
        $provider = $this->createProvider();

        $maxSize = $provider->getMaxImageSize();

        $this->assertEquals(20 * 1024 * 1024, $maxSize);
    }

    #[Test]
    public function supportsFeatureReturnsTrueForChat(): void
    {
        $provider = $this->createProvider();

        $this->assertTrue($provider->supportsFeature('chat'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForEmbeddings(): void
    {
        $provider = $this->createProvider();

        // Claude does not support embeddings
        $this->assertFalse($provider->supportsFeature('embeddings'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForVision(): void
    {
        $provider = $this->createProvider();

        $this->assertTrue($provider->supportsFeature('vision'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForStreaming(): void
    {
        $provider = $this->createProvider();

        $this->assertTrue($provider->supportsFeature('streaming'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForTools(): void
    {
        $provider = $this->createProvider();

        $this->assertTrue($provider->supportsFeature('tools'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForUnknownFeature(): void
    {
        $provider = $this->createProvider();

        $this->assertFalse($provider->supportsFeature('unknown_feature'));
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeySet(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $this->assertTrue($provider->isAvailable());
    }

    #[Test]
    public function defaultBaseUrlIsAnthropicApi(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $reflection = new \ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');

        $this->assertStringContainsString('api.anthropic.com', $baseUrl->getValue($provider));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForCompletion(): void
    {
        $provider = $this->createProvider();

        $this->assertTrue($provider->supportsFeature('completion'));
    }

    #[Test]
    public function embeddingsThrowsUnsupportedFeatureException(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('Anthropic Claude does not support embeddings');

        $provider->embeddings('test input');
    }

    #[Test]
    public function getAvailableModelsContainsCorrectModelNames(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        // Verify model descriptions are strings
        foreach ($models as $modelId => $description) {
            $this->assertIsString($modelId);
            $this->assertIsString($description);
            $this->assertNotEmpty($modelId);
            $this->assertNotEmpty($description);
        }

        // Verify specific expected models exist
        $this->assertArrayHasKey('claude-opus-4-1-20250805', $models);
        $this->assertArrayHasKey('claude-3-5-sonnet-20241022', $models);
    }

    #[Test]
    public function configureCustomBaseUrl(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'baseUrl' => 'https://custom-api.example.com/v1',
        ]);

        $reflection = new \ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');

        $this->assertEquals('https://custom-api.example.com/v1', $baseUrl->getValue($provider));
    }

    #[Test]
    public function getSupportedImageFormatsHasExactlyFiveFormats(): void
    {
        $provider = $this->createProvider();

        $formats = $provider->getSupportedImageFormats();

        $this->assertCount(5, $formats);
    }

    #[Test]
    public function getAvailableModelsHasMinimumModels(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        // Claude should have at least 5 models available
        $this->assertGreaterThanOrEqual(5, count($models));
    }
}
