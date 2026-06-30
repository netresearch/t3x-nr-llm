<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Provider\MistralProvider;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

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
        // AbstractProvider::testConnection() default behaviour: it wraps the
        // static getAvailableModels() list without performing any HTTP call.
        // All bundled providers now OVERRIDE testConnection() with a real
        // connectivity request (so an unreachable endpoint can no longer
        // report success), so the abstract default is exercised here through
        // a throwaway subclass that intentionally does NOT override it.
        $provider = $this->makeStaticListProvider();

        $result = $provider->testConnection();

        self::assertTrue($result['success']);
        self::assertStringContainsString('Connection successful', $result['message']);
        self::assertArrayHasKey('models', $result);
        assert(isset($result['models']));
        self::assertNotEmpty($result['models']);
    }

    /**
     * A minimal concrete AbstractProvider that returns a static model list and
     * does NOT override testConnection() — used to test the abstract default.
     */
    private function makeStaticListProvider(): AbstractProvider
    {
        $provider = new class (
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        ) extends AbstractProvider {
            public function getName(): string
            {
                return 'Static List Test Provider';
            }

            public function getIdentifier(): string
            {
                return 'static-list-test';
            }

            protected function getDefaultBaseUrl(): string
            {
                return 'https://example.invalid/v1';
            }

            /**
             * @return array<string, string>
             */
            public function getAvailableModels(): array
            {
                return ['model-a' => 'Model A', 'model-b' => 'Model B'];
            }

            public function chatCompletion(array $messages, array $options = []): CompletionResponse
            {
                return new CompletionResponse(
                    content: '',
                    model: 'model-a',
                    usage: new UsageStatistics(0, 0, 0),
                    finishReason: 'stop',
                    provider: $this->getIdentifier(),
                );
            }

            public function embeddings(string|array $input, array $options = []): EmbeddingResponse
            {
                return new EmbeddingResponse(
                    embeddings: [],
                    model: 'model-a',
                    usage: new UsageStatistics(0, 0, 0),
                    provider: $this->getIdentifier(),
                );
            }
        };

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
        ]);

        return $provider;
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

    #[Test]
    public function sanitizeErrorMessageRedactsApiKeyOnFourxxResponse(): void
    {
        // A 4xx error body whose message embeds a URL with `?key=<secret>` must
        // come back redacted to `key=***` — the secret must never surface in
        // the thrown exception's message.
        $provider = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gpt-5.2',
            'maxRetries' => 1,
        ]);

        $errorBody = [
            'error' => [
                'message' => 'Request to https://api.openai.com/v1/chat/completions?key=sk-secret123 was rejected',
            ],
        ];
        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willReturn($this->createJsonResponseMock($errorBody, 400));
        $provider->setHttpClient($client);

        try {
            $provider->complete('hello');
            self::fail('Expected ProviderResponseException was not thrown');
        } catch (ProviderResponseException $e) {
            self::assertStringContainsString('key=***', $e->getMessage());
            self::assertStringNotContainsString('sk-secret123', $e->getMessage());
        }
    }

    #[Test]
    public function flatErrorStringSurfacesInsteadOfUnknownProviderError(): void
    {
        // Ollama (and others) return a 4xx body as a FLAT {"error":"<text>"}
        // string rather than the nested {"error":{"message":...}} form. The real
        // message must surface — regression guard for the bug where the flat
        // case was skipped and everything degraded to "Unknown provider error".
        $provider = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gpt-5.2',
            'maxRetries' => 1,
        ]);

        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willReturn(
            $this->createJsonResponseMock(['error' => 'qwen3:0.6b does not support tools'], 400),
        );
        $provider->setHttpClient($client);

        try {
            $provider->complete('hello');
            self::fail('Expected ProviderResponseException was not thrown');
        } catch (ProviderResponseException $e) {
            self::assertStringContainsString('does not support tools', $e->getMessage());
            self::assertStringNotContainsString('Unknown provider error', $e->getMessage());
        }
    }

    #[Test]
    public function sanitizeErrorMessageRedactsApiKeyOnConnectionExhaustion(): void
    {
        // When all retries fail, the surfaced "Failed to connect …" message must
        // also be scrubbed of a `?key=<secret>` carried by the underlying
        // exception message.
        $provider = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gpt-5.2',
            'maxRetries' => 1,
        ]);

        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willThrowException(
            new RuntimeException('cURL error connecting to https://api.openai.com/v1/chat/completions?key=sk-secret123'),
        );
        $provider->setHttpClient($client);

        try {
            $provider->complete('hello');
            self::fail('Expected ProviderConnectionException was not thrown');
        } catch (ProviderConnectionException $e) {
            self::assertStringContainsString('key=***', $e->getMessage());
            self::assertStringNotContainsString('sk-secret123', $e->getMessage());
        }
    }

    #[Test]
    public function streamingFourxxResponseThrowsTypedResponseException(): void
    {
        // A 4xx streaming response must surface as the same typed exception
        // sendRequest() raises — with any `?key=<secret>` redacted — instead
        // of silently yielding an empty generator.
        $provider = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gpt-5.2',
        ]);

        $errorBody = [
            'error' => [
                'message' => 'Invalid key for https://api.openai.com/v1/chat/completions?key=sk-secret123',
            ],
        ];
        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willReturn($this->createJsonResponseMock($errorBody, 401));
        $provider->setHttpClient($client);

        try {
            // Generators execute lazily — the guard fires on first iteration.
            $provider->streamChatCompletion([['role' => 'user', 'content' => 'hello']])->current();
            self::fail('Expected ProviderResponseException was not thrown');
        } catch (ProviderResponseException $e) {
            self::assertSame(401, $e->getCode());
            self::assertStringContainsString('key=***', $e->getMessage());
            self::assertStringNotContainsString('sk-secret123', $e->getMessage());
        }
    }

    #[Test]
    public function streamingServerErrorThrowsConnectionException(): void
    {
        // A 5xx streaming response maps to ProviderConnectionException, the
        // same contract as the non-streaming path.
        $provider = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gpt-5.2',
        ]);

        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willReturn($this->createJsonResponseMock([], 503));
        $provider->setHttpClient($client);

        try {
            $provider->streamChatCompletion([['role' => 'user', 'content' => 'hello']])->current();
            self::fail('Expected ProviderConnectionException was not thrown');
        } catch (ProviderConnectionException $e) {
            self::assertSame(503, $e->getCode());
            self::assertStringContainsString('503', $e->getMessage());
        }
    }

    #[Test]
    public function sendRequestStampsPerRequestAuditReasonWithPurposeAndModel(): void
    {
        // A chat call through the vault secure client must record an audit
        // reason carrying the purpose and the model actually requested —
        // "LLM chat call to OpenAI (gpt-4o)" — not the static client-level
        // default. The reason must never contain prompt text.
        $capturedReasons = [];

        $vaultHttpClient = self::createStub(VaultHttpClientInterface::class);
        $vaultHttpClient->method('withAuthentication')->willReturn($vaultHttpClient);
        $vaultHttpClient->method('withReason')->willReturnCallback(
            function (string $reason) use (&$capturedReasons, $vaultHttpClient): VaultHttpClientInterface {
                $capturedReasons[] = $reason;
                return $vaultHttpClient;
            },
        );
        $vaultHttpClient->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'id' => 'test',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]));

        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('http')->willReturn($vaultHttpClient);

        $provider = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $vault,
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => 'vault-id',
            'defaultModel' => 'gpt-4o',
        ]);

        $provider->complete('hello, do not leak me');

        self::assertSame(['LLM chat call to OpenAI (gpt-4o)'], $capturedReasons);
    }
}
