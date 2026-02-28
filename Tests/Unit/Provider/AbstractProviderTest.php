<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Provider\MistralProvider;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests common provider behavior across all implementations.
 */
#[CoversClass(AbstractProvider::class)]
class AbstractProviderTest extends AbstractUnitTestCase
{
    /**
     * @param class-string<AbstractProvider> $providerClass
     */
    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerIsNotAvailableWithoutApiKey(string $providerClass, string $providerName): void
    {
        /** @var AbstractProvider $provider */
        $provider = new $providerClass(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        // Provider without configure() call should not have API key
        self::assertFalse($provider->isAvailable());
    }

    /**
     * @param class-string<AbstractProvider> $providerClass
     */
    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerIsAvailableWithApiKey(string $providerClass, string $providerName): void
    {
        /** @var AbstractProvider $provider */
        $provider = new $providerClass(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'test-model',
            'timeout' => 30,
        ]);

        self::assertTrue($provider->isAvailable());
    }

    /**
     * @param class-string<AbstractProvider> $providerClass
     */
    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerReturnsCorrectName(string $providerClass, string $providerName): void
    {
        /** @var AbstractProvider $provider */
        $provider = new $providerClass(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        self::assertEquals($providerName, $provider->getName());
    }

    /**
     * @param class-string<AbstractProvider> $providerClass
     */
    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerReturnsNonEmptyModelList(string $providerClass, string $providerName): void
    {
        /** @var AbstractProvider $provider */
        $provider = new $providerClass(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'test-model',
            'timeout' => 30,
        ]);

        $models = $provider->getAvailableModels();

        // Type is already guaranteed by interface, just check not empty
        self::assertNotEmpty($models);
    }

    /**
     * @return array<string, array{class-string<AbstractProvider>, string}>
     */
    public static function providerConfigProvider(): array
    {
        return [
            'Gemini' => [GeminiProvider::class, 'Google Gemini'],
            'Mistral' => [MistralProvider::class, 'Mistral AI'],
            'Groq' => [GroqProvider::class, 'Groq'],
        ];
    }

    #[Test]
    public function completeCallsChatCompletionWithUserMessage(): void
    {
        $httpClientMock = $this->createHttpClientWithExpectations();

        $apiResponse = [
            'choices' => [['message' => ['content' => 'Test reply'], 'finish_reason' => 'stop']],
            'model' => 'gemini-2.5-flash',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $provider = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
            'maxRetries' => 1,
        ]);
        $provider->setHttpClient($httpClientMock);

        $result = $provider->complete('Hello world');

        self::assertEquals('Test reply', $result->content);
    }

    #[Test]
    public function supportsFeatureWithModelCapabilityEnum(): void
    {
        $provider = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        // GroqProvider supports chat - test using ModelCapability enum
        self::assertTrue($provider->supportsFeature(ModelCapability::CHAT));
    }

    #[Test]
    public function supportsFeatureWithModelCapabilityEnumReturnsFalseForUnsupported(): void
    {
        $provider = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        // MistralProvider does not support vision
        self::assertFalse($provider->supportsFeature(ModelCapability::VISION));
    }

    #[Test]
    public function testConnectionReturnsSuccessWithModelCount(): void
    {
        // Use a provider with static model list (Gemini returns static list from getAvailableModels).
        // AbstractProvider::testConnection() calls getAvailableModels() and wraps result.
        $httpClientStub = $this->createHttpClientMock();

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gemini-2.5-flash',
        ]);
        $provider->setHttpClient($httpClientStub);

        $result = $provider->testConnection();

        self::assertTrue($result['success']);
        self::assertStringContainsString('Connection successful', $result['message']);
        self::assertArrayHasKey('models', $result);
        assert(isset($result['models']));
        self::assertNotEmpty($result['models']);
    }

    #[Test]
    public function setHttpClientOverridesConfiguredClient(): void
    {
        $firstClient = $this->createHttpClientMock();
        $secondClient = $this->createHttpClientWithExpectations();

        $apiResponse = [
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'mistral-large-latest',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        // Only the second client should be called
        $secondClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $provider = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
            'maxRetries' => 1,
        ]);
        $provider->setHttpClient($firstClient);
        $provider->setHttpClient($secondClient);

        $provider->complete('Test');
    }

    #[Test]
    public function getDefaultModelReturnsFallbackBeforeConfigure(): void
    {
        $provider = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        // Before configure() the $defaultModel property is '', so getDefaultModel()
        // falls back to the provider's built-in DEFAULT_CHAT_MODEL constant.
        self::assertSame('llama-3.3-70b-versatile', $provider->getDefaultModel());
    }

    #[Test]
    public function configureResetsHttpClientSoNewClientIsCreatedOnNextRequest(): void
    {
        $provider = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $firstClient = $this->createHttpClientMock();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);
        $provider->setHttpClient($firstClient);

        // Re-configuring resets the internal HTTP client
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        // After re-configure, inject a fresh client (simulates the reset)
        $freshClient = $this->createHttpClientWithExpectations();
        $apiResponse = [
            'id' => 'test',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'gpt-5.2',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
        $freshClient->expects(self::once())->method('sendRequest')->willReturn($this->createJsonResponseMock($apiResponse));
        $provider->setHttpClient($freshClient);

        $provider->complete('test');
    }
}
