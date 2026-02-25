<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Mutation-killing tests for OpenAiProvider.
 */
#[CoversClass(OpenAiProvider::class)]
class OpenAiProviderMutationTest extends AbstractUnitTestCase
{
    private function createProvider(): OpenAiProvider
    {
        $provider = new OpenAiProvider(
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
    public function getNameReturnsOpenAI(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('OpenAI', $provider->getName());
    }

    #[Test]
    public function getIdentifierReturnsOpenai(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('openai', $provider->getIdentifier());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultChatModelWhenNotConfigured(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        self::assertEquals('gpt-5.2', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModelWhenSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gpt-5.2-pro',
        ]);

        self::assertEquals('gpt-5.2-pro', $provider->getDefaultModel());
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
        self::assertEquals('gpt-5.2', $provider->getDefaultModel());
    }

    #[Test]
    public function getAvailableModelsReturnsNonEmptyArray(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        self::assertNotEmpty($models);
        self::assertArrayHasKey('gpt-5.2', $models);
        self::assertArrayHasKey('gpt-5.2-pro', $models);
        self::assertArrayHasKey('gpt-5.2-instant', $models);
        self::assertArrayHasKey('o3', $models);
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
    public function isAvailableReturnsTrueWhenApiKeySet(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        self::assertTrue($provider->isAvailable());
    }

    #[Test]
    public function defaultBaseUrlIsOpenAiApi(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');

        /** @var string $baseUrlValue */
        $baseUrlValue = $baseUrl->getValue($provider);
        self::assertStringContainsString('api.openai.com', $baseUrlValue);
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

        // Verify model entries are non-empty
        foreach ($models as $modelId => $description) {
            self::assertNotEmpty($modelId);
            self::assertNotEmpty($description);
        }
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
}
