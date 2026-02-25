<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\MistralProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Mutation-killing tests for MistralProvider.
 */
#[CoversClass(MistralProvider::class)]
class MistralProviderMutationTest extends AbstractUnitTestCase
{
    private function createProvider(): MistralProvider
    {
        $provider = new MistralProvider(
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
    public function getNameReturnsMistralAi(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('Mistral AI', $provider->getName());
    }

    #[Test]
    public function getIdentifierReturnsMistral(): void
    {
        $provider = $this->createProvider();

        self::assertEquals('mistral', $provider->getIdentifier());
    }

    #[Test]
    public function getDefaultModelReturnsDefaultWhenNotConfigured(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        self::assertEquals('mistral-large-latest', $provider->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModelWhenSet(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-small-latest',
        ]);

        self::assertEquals('mistral-small-latest', $provider->getDefaultModel());
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
        self::assertEquals('mistral-large-latest', $provider->getDefaultModel());
    }

    #[Test]
    public function getAvailableModelsReturnsNonEmptyArray(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $models = $provider->getAvailableModels();

        self::assertNotEmpty($models);
        self::assertArrayHasKey('mistral-large-latest', $models);
        self::assertArrayHasKey('mistral-small-latest', $models);
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
    public function supportsFeatureReturnsTrueForEmbeddings(): void
    {
        $provider = $this->createProvider();

        // Mistral supports embeddings
        self::assertTrue($provider->supportsFeature('embeddings'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForVision(): void
    {
        $provider = $this->createProvider();

        // Mistral does not support vision
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
    public function defaultBaseUrlIsMistralApi(): void
    {
        $provider = $this->createProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');
        /** @var string $baseUrlValue */
        $baseUrlValue = $baseUrl->getValue($provider);

        self::assertStringContainsString('api.mistral.ai', $baseUrlValue);
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
        self::assertArrayHasKey('codestral-latest', $models);
        self::assertArrayHasKey('open-mistral-nemo', $models);
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

        // Mistral should have at least 8 models available
        self::assertGreaterThanOrEqual(8, count($models));
    }

    #[Test]
    public function getCodeModelReturnsCodestral(): void
    {
        self::assertEquals('codestral-latest', MistralProvider::getCodeModel());
    }

    #[Test]
    public function getSmallModelReturnsMistralSmall(): void
    {
        self::assertEquals('mistral-small-latest', MistralProvider::getSmallModel());
    }
}
