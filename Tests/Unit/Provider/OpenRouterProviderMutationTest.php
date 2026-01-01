<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\OpenRouterProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Mutation-killing tests for OpenRouterProvider.
 */
#[CoversClass(OpenRouterProvider::class)]
class OpenRouterProviderMutationTest extends AbstractUnitTestCase
{
    private function createProvider(): OpenRouterProvider
    {
        $provider = new OpenRouterProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        return $provider;
    }

    #[Test]
    public function getNameReturnsOpenRouter(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('OpenRouter', $provider->getName());
    }

    #[Test]
    public function getIdentifierReturnsOpenrouter(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('openrouter', $provider->getIdentifier());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultChatModelWhenNotConfigured(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        self::assertEquals('anthropic/claude-sonnet-4-5', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModelWhenSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => 'openai/gpt-5.2',
        ]);

        self::assertEquals('openai/gpt-5.2', $provider->getDefaultModel());
    }

    #[Test]
    public function configureSetsSiteUrl(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'siteUrl' => 'https://example.com',
        ]);

        $reflection = new ReflectionClass($provider);
        $siteUrl = $reflection->getProperty('siteUrl');

        self::assertEquals('https://example.com', $siteUrl->getValue($provider));
    }

    #[Test]
    public function configureSetsAppName(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'appName' => 'My Custom App',
        ]);

        $reflection = new ReflectionClass($provider);
        $appName = $reflection->getProperty('appName');

        self::assertEquals('My Custom App', $appName->getValue($provider));
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

        $reflection = new ReflectionClass($provider);
        $routingStrategy = $reflection->getProperty('routingStrategy');

        self::assertEquals($strategy, $routingStrategy->getValue($provider));
    }

    /**
     * @return array<string, array{string}>
     */
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

        $reflection = new ReflectionClass($provider);
        $routingStrategy = $reflection->getProperty('routingStrategy');

        // Should remain at default 'balanced'
        self::assertEquals('balanced', $routingStrategy->getValue($provider));
    }

    #[Test]
    public function configureSetsAutoFallbackToTrue(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'autoFallback' => true,
        ]);

        $reflection = new ReflectionClass($provider);
        $autoFallback = $reflection->getProperty('autoFallback');

        self::assertTrue($autoFallback->getValue($provider));
    }

    #[Test]
    public function configureSetsAutoFallbackToFalse(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'autoFallback' => false,
        ]);

        $reflection = new ReflectionClass($provider);
        $autoFallback = $reflection->getProperty('autoFallback');

        self::assertFalse($autoFallback->getValue($provider));
    }

    #[Test]
    public function configureConvertsAutoFallbackToBool(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'autoFallback' => 'yes', // truthy string
        ]);

        $reflection = new ReflectionClass($provider);
        $autoFallback = $reflection->getProperty('autoFallback');

        self::assertTrue($autoFallback->getValue($provider));
    }

    #[Test]
    public function configureParsesFallbackModelsFromString(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'fallbackModels' => 'openai/gpt-5.2, anthropic/claude-sonnet-4-5, google/gemini-3-flash',
        ]);

        $reflection = new ReflectionClass($provider);
        $fallbackModels = $reflection->getProperty('fallbackModels');

        $expected = ['openai/gpt-5.2', 'anthropic/claude-sonnet-4-5', 'google/gemini-3-flash'];
        self::assertEquals($expected, $fallbackModels->getValue($provider));
    }

    #[Test]
    public function configureIgnoresFallbackModelsWhenNotString(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'fallbackModels' => ['array', 'of', 'models'], // Not a string
        ]);

        $reflection = new ReflectionClass($provider);
        $fallbackModels = $reflection->getProperty('fallbackModels');

        // Should remain empty array
        self::assertEmpty($fallbackModels->getValue($provider));
    }

    #[Test]
    public function getAvailableModelsReturnsStaticList(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        self::assertNotEmpty($models);
        self::assertArrayHasKey('anthropic/claude-sonnet-4-5', $models);
        self::assertArrayHasKey('openai/gpt-5.2', $models);
    }

    #[Test]
    public function supportsVisionReturnsTrue(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsVision());
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
    public function getSupportedImageFormatsReturnsCorrectList(): void
    {
        $provider = $this->createProvider();

        $formats = $provider->getSupportedImageFormats();

        self::assertContains('png', $formats);
        self::assertContains('jpeg', $formats);
        self::assertContains('jpg', $formats);
        self::assertContains('gif', $formats);
        self::assertContains('webp', $formats);
    }

    #[Test]
    public function getMaxImageSizeReturns20MB(): void
    {
        $provider = $this->createProvider();

        $maxSize = $provider->getMaxImageSize();

        self::assertEquals(20 * 1024 * 1024, $maxSize);
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

        self::assertTrue($provider->supportsFeature('embeddings'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForVision(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsFeature('vision'));
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
    public function configureFiltersEmptyFallbackModels(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'fallbackModels' => 'model1, , model2, , model3', // Some empty strings
        ]);

        $reflection = new ReflectionClass($provider);
        $fallbackModels = $reflection->getProperty('fallbackModels');
        $models = $fallbackModels->getValue($provider);

        // Should have filtered out empty strings
        self::assertIsArray($models);
        self::assertCount(3, $models);
        self::assertEquals(['model1', 'model2', 'model3'], array_values($models));
    }

    #[Test]
    public function configureTrimsFallbackModels(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'fallbackModels' => '  model1  ,  model2  ', // Whitespace around
        ]);

        $reflection = new ReflectionClass($provider);
        $fallbackModels = $reflection->getProperty('fallbackModels');
        $models = $fallbackModels->getValue($provider);

        self::assertIsArray($models);
        self::assertContains('model1', $models);
        self::assertContains('model2', $models);
        self::assertNotContains('  model1  ', $models);
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeySet(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        self::assertTrue($provider->isAvailable());
    }

    #[Test]
    public function defaultBaseUrlIsOpenRouterApi(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');
        $baseUrlValue = $baseUrl->getValue($provider);

        self::assertIsString($baseUrlValue);
        self::assertStringContainsString('openrouter.ai/api/v1', $baseUrlValue);
    }
}
