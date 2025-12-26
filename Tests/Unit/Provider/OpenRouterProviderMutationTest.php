<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\OpenRouterProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mutation-killing tests for OpenRouterProvider.
 */
#[CoversClass(OpenRouterProvider::class)]
class OpenRouterProviderMutationTest extends AbstractUnitTestCase
{
    private function createProvider(): OpenRouterProvider
    {
        return new OpenRouterProvider(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
    }

    #[Test]
    public function getNameReturnsOpenRouter(): void
    {
        $provider = $this->createProvider();

        $this->assertEquals('OpenRouter', $provider->getName());
    }

    #[Test]
    public function getIdentifierReturnsOpenrouter(): void
    {
        $provider = $this->createProvider();

        $this->assertEquals('openrouter', $provider->getIdentifier());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultChatModelWhenNotConfigured(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $this->assertEquals('anthropic/claude-sonnet-4-5', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModelWhenSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => 'openai/gpt-5.2',
        ]);

        $this->assertEquals('openai/gpt-5.2', $provider->getDefaultModel());
    }

    #[Test]
    public function configureSetsSiteUrl(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'siteUrl' => 'https://example.com',
        ]);

        $reflection = new \ReflectionClass($provider);
        $siteUrl = $reflection->getProperty('siteUrl');

        $this->assertEquals('https://example.com', $siteUrl->getValue($provider));
    }

    #[Test]
    public function configureSetsAppName(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'appName' => 'My Custom App',
        ]);

        $reflection = new \ReflectionClass($provider);
        $appName = $reflection->getProperty('appName');

        $this->assertEquals('My Custom App', $appName->getValue($provider));
    }

    #[Test]
    #[DataProvider('validRoutingStrategyProvider')]
    public function configureSetsValidRoutingStrategy(string $strategy): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'routingStrategy' => $strategy,
        ]);

        $reflection = new \ReflectionClass($provider);
        $routingStrategy = $reflection->getProperty('routingStrategy');

        $this->assertEquals($strategy, $routingStrategy->getValue($provider));
    }

    public static function validRoutingStrategyProvider(): array
    {
        return [
            'cost_optimized' => ['cost_optimized'],
            'performance' => ['performance'],
            'balanced' => ['balanced'],
            'explicit' => ['explicit'],
        ];
    }

    #[Test]
    public function configureIgnoresInvalidRoutingStrategy(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'routingStrategy' => 'invalid_strategy',
        ]);

        $reflection = new \ReflectionClass($provider);
        $routingStrategy = $reflection->getProperty('routingStrategy');

        // Should remain at default 'balanced'
        $this->assertEquals('balanced', $routingStrategy->getValue($provider));
    }

    #[Test]
    public function configureSetsAutoFallbackToTrue(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'autoFallback' => true,
        ]);

        $reflection = new \ReflectionClass($provider);
        $autoFallback = $reflection->getProperty('autoFallback');

        $this->assertTrue($autoFallback->getValue($provider));
    }

    #[Test]
    public function configureSetsAutoFallbackToFalse(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'autoFallback' => false,
        ]);

        $reflection = new \ReflectionClass($provider);
        $autoFallback = $reflection->getProperty('autoFallback');

        $this->assertFalse($autoFallback->getValue($provider));
    }

    #[Test]
    public function configureConvertsAutoFallbackToBool(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'autoFallback' => 'yes', // truthy string
        ]);

        $reflection = new \ReflectionClass($provider);
        $autoFallback = $reflection->getProperty('autoFallback');

        $this->assertTrue($autoFallback->getValue($provider));
    }

    #[Test]
    public function configureParsesFallbackModelsFromString(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'fallbackModels' => 'openai/gpt-5.2, anthropic/claude-sonnet-4-5, google/gemini-3-flash',
        ]);

        $reflection = new \ReflectionClass($provider);
        $fallbackModels = $reflection->getProperty('fallbackModels');

        $expected = ['openai/gpt-5.2', 'anthropic/claude-sonnet-4-5', 'google/gemini-3-flash'];
        $this->assertEquals($expected, $fallbackModels->getValue($provider));
    }

    #[Test]
    public function configureIgnoresFallbackModelsWhenNotString(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'fallbackModels' => ['array', 'of', 'models'], // Not a string
        ]);

        $reflection = new \ReflectionClass($provider);
        $fallbackModels = $reflection->getProperty('fallbackModels');

        // Should remain empty array
        $this->assertEmpty($fallbackModels->getValue($provider));
    }

    #[Test]
    public function getAvailableModelsReturnsStaticList(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        $this->assertIsArray($models);
        $this->assertNotEmpty($models);
        $this->assertArrayHasKey('anthropic/claude-sonnet-4-5', $models);
        $this->assertArrayHasKey('openai/gpt-5.2', $models);
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
    public function supportsFeatureReturnsTrueForEmbeddings(): void
    {
        $provider = $this->createProvider();

        $this->assertTrue($provider->supportsFeature('embeddings'));
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
    public function configureFiltersEmptyFallbackModels(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'fallbackModels' => 'model1, , model2, , model3', // Some empty strings
        ]);

        $reflection = new \ReflectionClass($provider);
        $fallbackModels = $reflection->getProperty('fallbackModels');
        $models = $fallbackModels->getValue($provider);

        // Should have filtered out empty strings
        $this->assertCount(3, $models);
        $this->assertEquals(['model1', 'model2', 'model3'], array_values($models));
    }

    #[Test]
    public function configureTrimsFallbackModels(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'fallbackModels' => '  model1  ,  model2  ', // Whitespace around
        ]);

        $reflection = new \ReflectionClass($provider);
        $fallbackModels = $reflection->getProperty('fallbackModels');
        $models = $fallbackModels->getValue($provider);

        $this->assertContains('model1', $models);
        $this->assertContains('model2', $models);
        $this->assertNotContains('  model1  ', $models);
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeySet(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $this->assertTrue($provider->isAvailable());
    }

    #[Test]
    public function defaultBaseUrlIsOpenRouterApi(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $reflection = new \ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');

        $this->assertStringContainsString('openrouter.ai/api/v1', $baseUrl->getValue($provider));
    }
}
