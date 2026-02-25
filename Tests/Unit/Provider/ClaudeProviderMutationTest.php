<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Mutation-killing tests for ClaudeProvider.
 */
#[CoversClass(ClaudeProvider::class)]
class ClaudeProviderMutationTest extends AbstractUnitTestCase
{
    private function createProvider(): ClaudeProvider
    {
        $provider = new ClaudeProvider(
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
    public function getNameReturnsAnthropicClaude(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('Anthropic Claude', $provider->getName());
    }

    #[Test]
    public function getIdentifierReturnsClaude(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('claude', $provider->getIdentifier());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultWhenNotConfigured(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        self::assertEquals('claude-sonnet-4-5-20250929', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModelWhenSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'claude-opus-4-5-20251124',
        ]);

        self::assertEquals('claude-opus-4-5-20251124', $provider->getDefaultModel());
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
        self::assertEquals('claude-sonnet-4-5-20250929', $provider->getDefaultModel());
    }

    #[Test]
    public function getAvailableModelsReturnsNonEmptyArray(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        self::assertNotEmpty($models);
        self::assertArrayHasKey('claude-opus-4-5-20251124', $models);
        self::assertArrayHasKey('claude-sonnet-4-5-20250929', $models);
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
    public function supportsFeatureReturnsFalseForEmbeddings(): void
    {
        $provider = $this->createProvider();

        // Claude does not support embeddings
        self::assertFalse($provider->supportsFeature('embeddings'));
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
    public function defaultBaseUrlIsAnthropicApi(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');
        /** @var string $baseUrlValue */
        $baseUrlValue = $baseUrl->getValue($provider);

        self::assertStringContainsString('api.anthropic.com', $baseUrlValue);
    }

    #[Test]
    public function supportsFeatureReturnsTrueForCompletion(): void
    {
        $provider = $this->createProvider();

        self::assertTrue($provider->supportsFeature('completion'));
    }

    #[Test]
    public function embeddingsThrowsUnsupportedFeatureException(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('Anthropic Claude does not support embeddings');

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
        self::assertArrayHasKey('claude-opus-4-1-20250805', $models);
        self::assertArrayHasKey('claude-3-5-sonnet-20241022', $models);
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
    public function getSupportedImageFormatsHasExactlyFiveFormats(): void
    {
        $provider = $this->createProvider();

        $formats = $provider->getSupportedImageFormats();

        self::assertCount(5, $formats);
    }

    #[Test]
    public function getAvailableModelsHasMinimumModels(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        // Claude should have at least 5 models available
        self::assertGreaterThanOrEqual(5, count($models));
    }
}
