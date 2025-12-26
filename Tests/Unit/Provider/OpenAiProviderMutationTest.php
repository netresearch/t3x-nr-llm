<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mutation-killing tests for OpenAiProvider.
 */
#[CoversClass(OpenAiProvider::class)]
class OpenAiProviderMutationTest extends AbstractUnitTestCase
{
    private function createProvider(): OpenAiProvider
    {
        return new OpenAiProvider(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
    }

    #[Test]
    public function getNameReturnsOpenAI(): void
    {
        $provider = $this->createProvider();

        $this->assertEquals('OpenAI', $provider->getName());
    }

    #[Test]
    public function getIdentifierReturnsOpenai(): void
    {
        $provider = $this->createProvider();

        $this->assertEquals('openai', $provider->getIdentifier());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultChatModelWhenNotConfigured(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $this->assertEquals('gpt-5.2', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModelWhenSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => 'gpt-5.2-pro',
        ]);

        $this->assertEquals('gpt-5.2-pro', $provider->getDefaultModel());
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
        $this->assertEquals('gpt-5.2', $provider->getDefaultModel());
    }

    #[Test]
    public function getAvailableModelsReturnsNonEmptyArray(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        $this->assertIsArray($models);
        $this->assertNotEmpty($models);
        $this->assertArrayHasKey('gpt-5.2', $models);
        $this->assertArrayHasKey('gpt-5.2-pro', $models);
        $this->assertArrayHasKey('gpt-5.2-instant', $models);
        $this->assertArrayHasKey('o3', $models);
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
    public function isAvailableReturnsTrueWhenApiKeySet(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $this->assertTrue($provider->isAvailable());
    }

    #[Test]
    public function defaultBaseUrlIsOpenAiApi(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKey' => $this->randomApiKey()]);

        $reflection = new \ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');

        $this->assertStringContainsString('api.openai.com', $baseUrl->getValue($provider));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForCompletion(): void
    {
        $provider = $this->createProvider();

        $this->assertTrue($provider->supportsFeature('completion'));
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
}
