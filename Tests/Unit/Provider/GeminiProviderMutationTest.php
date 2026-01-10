<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Mutation-killing tests for GeminiProvider.
 */
#[CoversClass(GeminiProvider::class)]
class GeminiProviderMutationTest extends AbstractUnitTestCase
{
    private function createProvider(): GeminiProvider
    {
        $provider = new GeminiProvider(
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
    public function getNameReturnsGoogleGemini(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('Google Gemini', $provider->getName());
    }

    #[Test]
    public function getIdentifierReturnsGemini(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('gemini', $provider->getIdentifier());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultWhenNotConfigured(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        self::assertEquals('gemini-3-flash-preview', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModelWhenSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gemini-2.5-pro',
        ]);

        self::assertEquals('gemini-2.5-pro', $provider->getDefaultModel());
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
        self::assertEquals('gemini-3-flash-preview', $provider->getDefaultModel());
    }

    #[Test]
    public function getAvailableModelsReturnsNonEmptyArray(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        self::assertNotEmpty($models);
        self::assertArrayHasKey('gemini-3-flash-preview', $models);
        self::assertArrayHasKey('gemini-2.5-pro', $models);
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
        self::assertContains('heic', $formats);
        self::assertContains('heif', $formats);
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

        // Gemini supports embeddings
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
    public function isAvailableReturnsTrueWhenApiKeySet(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        self::assertTrue($provider->isAvailable());
    }

    #[Test]
    public function defaultBaseUrlIsGoogleApi(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');
        /** @var string $baseUrlValue */
        $baseUrlValue = $baseUrl->getValue($provider);

        self::assertStringContainsString('generativelanguage.googleapis.com', $baseUrlValue);
    }

    #[Test]
    public function supportsFeatureReturnsTrueForCompletion(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsFeature('completion'));
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
        self::assertArrayHasKey('gemini-3-flash-preview', $models);
        self::assertArrayHasKey('gemini-2.5-flash', $models);
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
    public function getSupportedImageFormatsHasSevenFormats(): void
    {
        $provider = $this->createProvider();

        $formats = $provider->getSupportedImageFormats();

        self::assertCount(7, $formats);
    }

    #[Test]
    public function getAvailableModelsHasMinimumModels(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        // Gemini should have at least 4 models available
        self::assertGreaterThanOrEqual(4, count($models));
    }
}
