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
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Provider\MistralProvider;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
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

    /**
     * complete() must surface options['system_prompt'] (how a configuration's
     * system prompt reaches this method) as a leading system message, since the
     * adapters read the system instruction from the message list.
     */
    #[Test]
    public function completePrependsConfiguredSystemPromptAsSystemMessage(): void
    {
        $capturedBody = null;
        $streamFactory = self::createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content) use (&$capturedBody): StreamInterface {
                $capturedBody = $content;
                $stream = self::createStub(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);
                $stream->method('getContents')->willReturn($content);
                return $stream;
            },
        );

        $apiResponse = [
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'mistral-large-latest',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
        ];
        $httpClientMock = $this->createHttpClientMock();
        $httpClientMock->method('sendRequest')->willReturn($this->createJsonResponseMock($apiResponse));

        $provider = new MistralProvider(
            $this->createRequestFactoryMock(),
            $streamFactory,
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

        $provider->complete('Hello world', ['system_prompt' => 'You are helpful']);

        self::assertIsString($capturedBody);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload['messages'] ?? null);
        self::assertSame(['role' => 'system', 'content' => 'You are helpful'], $payload['messages'][0]);
        self::assertSame('user', $payload['messages'][1]['role']);
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

    #[Test]
    public function clientExceptionMapsToProviderConnectionExceptionWithSanitisedMessage(): void
    {
        // A PSR-18 ClientExceptionInterface raised by the HTTP client is a
        // transport failure; sendRequest() exhausts its retries and rethrows the
        // typed ProviderConnectionException, scrubbing any `?key=<secret>` the
        // underlying message carried. This is the unit-suite counterpart of the
        // mapping previously only exercised by the mutation suite.
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

        $clientException = new class ('Client error reaching https://api.openai.com/v1/chat/completions?key=sk-secret123', 1799990100) extends RuntimeException implements ClientExceptionInterface {};
        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willThrowException($clientException);
        $provider->setHttpClient($client);

        try {
            $provider->complete('hello');
            self::fail('Expected ProviderConnectionException was not thrown');
        } catch (ProviderConnectionException $e) {
            self::assertStringContainsString('key=***', $e->getMessage());
            self::assertStringNotContainsString('sk-secret123', $e->getMessage());
            // The transport failure is mapped, not swallowed: the original
            // ClientException is preserved as the cause.
            self::assertSame($clientException, $e->getPrevious());
        }
    }

    #[Test]
    public function networkExceptionMapsToProviderConnectionExceptionWithSanitisedMessage(): void
    {
        // A PSR-18 NetworkExceptionInterface (a connection-level failure that
        // carries the originating request) follows the same retry/rethrow path
        // as a ClientException: it maps to a sanitised ProviderConnectionException.
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

        $networkException = new class ('DNS lookup failed for https://api.openai.com/v1/chat/completions?token=sk-secret456', 1799990101) extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                throw new RuntimeException('not needed for this test', 1799990102);
            }
        };
        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willThrowException($networkException);
        $provider->setHttpClient($client);

        try {
            $provider->complete('hello');
            self::fail('Expected ProviderConnectionException was not thrown');
        } catch (ProviderConnectionException $e) {
            self::assertStringContainsString('token=***', $e->getMessage());
            self::assertStringNotContainsString('sk-secret456', $e->getMessage());
            self::assertSame($networkException, $e->getPrevious());
        }
    }

    /**
     * An empty `system_prompt` must NOT be surfaced as a leading system
     * message: the guard is `is_string($p) && $p !== ''`, so the first
     * message stays the user turn. Kills the `&&`->`||` mutant, which would
     * add a `{role: system, content: ''}` message for the empty string.
     */
    #[Test]
    public function completeDoesNotPrependEmptySystemPrompt(): void
    {
        $capturedBody = null;
        $httpClientMock = $this->createHttpClientMock();
        $httpClientMock->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'mistral-large-latest',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]));

        $provider = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createCapturingStreamFactory($capturedBody),
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

        $provider->complete('Hello world', ['system_prompt' => '']);

        self::assertIsString($capturedBody);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload['messages'] ?? null);
        $first = $payload['messages'][0] ?? null;
        self::assertIsArray($first);
        self::assertSame('user', $first['role'] ?? null);
    }

    /**
     * The request URL is built as `rtrim(baseUrl,'/') . '/' . ltrim(endpoint,'/')`.
     * Pin the full absolute URL so the mutant that drops the base-URL operand
     * (leaving only `/endpoint`) is caught.
     */
    #[Test]
    public function sendRequestBuildsAbsoluteUrlFromBaseUrlAndEndpoint(): void
    {
        $capturedUrl = null;
        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request) use (&$capturedUrl): ResponseInterface {
                $capturedUrl = (string)$request->getUri();
                return $this->createJsonResponseMock([
                    'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                    'model' => 'mistral-large-latest',
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
                ]);
            },
        );

        $provider = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'baseUrl' => 'https://mock.test/v1',
            'defaultModel' => 'mistral-large-latest',
            'maxRetries' => 1,
        ]);
        $provider->setHttpClient($client);

        $provider->complete('hi');

        // MistralProvider posts to the 'chat/completions' endpoint.
        self::assertSame('https://mock.test/v1/chat/completions', $capturedUrl);
    }

    /**
     * A payload byte that is not valid UTF-8 must be json-encoded with
     * `JSON_INVALID_UTF8_SUBSTITUTE` (replacement char U+FFFD), never dropped.
     * The mutant that ANDs the two JSON flags yields flags == 0, so json_encode
     * returns false on the invalid byte and stream creation fails — either way
     * the substituted body assertion below no longer holds.
     */
    #[Test]
    public function sendRequestSubstitutesInvalidUtf8InEncodedBody(): void
    {
        $capturedBody = null;
        $httpClientMock = $this->createHttpClientMock();
        $httpClientMock->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'mistral-large-latest',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]));

        $provider = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createCapturingStreamFactory($capturedBody),
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

        // 0xB1 is a lone continuation byte — invalid UTF-8 on its own.
        $provider->complete("\xB1");

        self::assertIsString($capturedBody);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload['messages'] ?? null);
        $first = $payload['messages'][0] ?? null;
        self::assertIsArray($first);
        self::assertSame("\u{FFFD}", $first['content'] ?? null);
    }

    /**
     * With `maxRetries = 0` the retry loop never runs, so `$lastException`
     * stays null and the final exception message falls back to the
     * `?? self::UNKNOWN_ERROR` placeholder. Exercises the null-safe operator
     * (the mutant drops `?->` and calls getMessage() on null) and pins the
     * exception code to 0.
     */
    #[Test]
    public function connectionExhaustionWithZeroRetriesFallsBackToUnknownErrorAndCodeZero(): void
    {
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
            'maxRetries' => 0,
        ]);
        $provider->setHttpClient($this->createHttpClientMock());

        try {
            $provider->complete('hi');
            self::fail('Expected ProviderConnectionException was not thrown');
        } catch (ProviderConnectionException $e) {
            self::assertSame(0, $e->getCode());
            self::assertStringContainsString('after 0 attempts', $e->getMessage());
            self::assertStringContainsString('Unknown error', $e->getMessage());
        }
    }

    /**
     * A 3xx (here 300) is neither a 2xx success nor a 4xx client error, so it
     * falls through to the final `throw new ProviderConnectionException(...)`.
     * Pins both the `< 300` success bound (the mutant `<= 300` would treat 300
     * as success) and the presence of the throw (the mutant drops it, so the
     * method returns null → TypeError, and the "Server returned status 300"
     * text never reaches the surfaced message).
     */
    #[Test]
    public function threeHundredStatusSurfacesAsConnectionException(): void
    {
        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'mistral-large-latest',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], 300));

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
        $provider->setHttpClient($client);

        try {
            $provider->complete('hi');
            self::fail('Expected ProviderConnectionException was not thrown');
        } catch (ProviderConnectionException $e) {
            self::assertStringContainsString('Server returned status 300', $e->getMessage());
        }
    }

    /**
     * A 4xx body that is NOT valid JSON falls back to the
     * `['error' => ['message' => self::UNKNOWN_ERROR]]` shape, so the surfaced
     * message is exactly "Unknown error". The mutants that strip that array
     * item degrade the message to "Unknown provider error" instead.
     */
    #[Test]
    public function nonJsonFourxxBodyFallsBackToUnknownError(): void
    {
        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willReturn($this->createHttpResponseMock(400, 'this is not json'));

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
        $provider->setHttpClient($client);

        try {
            $provider->complete('hi');
            self::fail('Expected ProviderResponseException was not thrown');
        } catch (ProviderResponseException $e) {
            self::assertSame('Unknown error', $e->getMessage());
        }
    }

    /**
     * A 300 streaming status is not a 2xx, so `assertStreamingResponseOk()`
     * must throw. Pins the `< 300` bound: the mutant `<= 300` returns instead
     * of throwing, silently yielding an empty stream.
     */
    #[Test]
    public function streamingThreeHundredStatusThrowsConnectionException(): void
    {
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
        $client->method('sendRequest')->willReturn($this->createJsonResponseMock([], 300));
        $provider->setHttpClient($client);

        try {
            $provider->streamChatCompletion([['role' => 'user', 'content' => 'hello']])->current();
            self::fail('Expected ProviderConnectionException was not thrown');
        } catch (ProviderConnectionException $e) {
            self::assertSame(300, $e->getCode());
            self::assertStringContainsString('Server returned status 300', $e->getMessage());
        }
    }

    /**
     * A streaming status of EXACTLY 400 must map to ProviderResponseException
     * (the 4xx branch). Pins the `>= 400` lower bound: the mutant `> 400`
     * skips the 4xx branch for 400 and mis-maps it to a
     * ProviderConnectionException.
     */
    #[Test]
    public function streamingExactly400MapsToResponseException(): void
    {
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
        $client->method('sendRequest')->willReturn(
            $this->createJsonResponseMock(['error' => ['message' => 'bad request']], 400),
        );
        $provider->setHttpClient($client);

        try {
            $provider->streamChatCompletion([['role' => 'user', 'content' => 'hello']])->current();
            self::fail('Expected ProviderResponseException was not thrown');
        } catch (ProviderResponseException $e) {
            self::assertSame(400, $e->getCode());
        }
    }

    /**
     * `sendRequest()` calls `validateConfiguration()` first, which rejects a
     * provider with no API-key identifier (code 1307337100). The mutant that
     * drops that call would let the request proceed and succeed. Uses a named
     * fixture so the protected `sendRequest()` is reachable.
     */
    #[Test]
    public function sendRequestValidatesConfigurationBeforeDispatch(): void
    {
        // No apiKeyIdentifier configured -> validateConfiguration() must throw.
        $provider = $this->makeGateProbe(['baseUrl' => 'https://ok.test/v1']);
        $client = $this->createHttpClientMock();
        $client->method('sendRequest')->willReturn($this->createJsonResponseMock(['data' => []]));
        $provider->setHttpClient($client);

        try {
            $provider->exposedSendRequest('models', []);
            self::fail('Expected ProviderConfigurationException was not thrown');
        } catch (ProviderConfigurationException $e) {
            self::assertSame(1307337100, $e->getCode());
        }
    }

    /**
     * `getSecretPlacementOptions()` supplies the vault audit `reason` passed to
     * `withAuthentication()`. Pins the exact array so the mutant that returns
     * `[]` is caught.
     */
    #[Test]
    public function secretPlacementOptionsCarryAuditReason(): void
    {
        $capturedOptions = null;
        $vaultHttpClient = self::createStub(VaultHttpClientInterface::class);
        $vaultHttpClient->method('withAuthentication')->willReturnCallback(
            function (string $secretIdentifier, SecretPlacement $placement, array $options) use (&$capturedOptions, $vaultHttpClient): VaultHttpClientInterface {
                $capturedOptions = $options;
                return $vaultHttpClient;
            },
        );

        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('http')->willReturn($vaultHttpClient);

        $provider = $this->makeGateProbe(['apiKeyIdentifier' => 'vault-id'], $vault);

        $provider->exposedGetHttpClient();

        self::assertSame(['reason' => 'LLM API call to Gate Probe'], $capturedOptions);
    }

    /**
     * `buildAuditReason()` maps the endpoint shape to a purpose and appends a
     * string model when present. Each assertion pins one match arm (embedding /
     * the four chat spellings / the default) plus the string-model guard, so
     * the arm-removal and `&&`->`||` mutants are all caught.
     */
    #[Test]
    public function buildAuditReasonMapsEndpointPurposeAndModel(): void
    {
        $provider = $this->makeGateProbe([]);

        self::assertSame('LLM embedding call to Gate Probe', $provider->exposedBuildAuditReason('v1/embeddings', []));
        self::assertSame('LLM chat call to Gate Probe', $provider->exposedBuildAuditReason('v1/chat/completions', []));
        self::assertSame('LLM chat call to Gate Probe', $provider->exposedBuildAuditReason('v1/messages', []));
        self::assertSame('LLM chat call to Gate Probe', $provider->exposedBuildAuditReason('models/x:generateContent', []));
        self::assertSame('LLM chat call to Gate Probe', $provider->exposedBuildAuditReason('api/generate', []));
        self::assertSame('LLM API call to Gate Probe', $provider->exposedBuildAuditReason('v1/models', []));

        // A string model is appended; a non-string model is ignored (the `&&`
        // guard), so the `||` mutant that appends `(123)` is caught.
        self::assertSame('LLM chat call to Gate Probe (m1)', $provider->exposedBuildAuditReason('v1/chat', ['model' => 'm1']));
        self::assertSame('LLM chat call to Gate Probe', $provider->exposedBuildAuditReason('v1/chat', ['model' => 123]));
    }

    /**
     * A whitespace-only base URL trims to empty, so the SSRF gate returns
     * early (nothing to check) and hands back a client. The mutant that drops
     * `trim()` treats "   " as a non-empty host and rejects it.
     */
    #[Test]
    public function endpointGateTrimsBaseUrlAndReturnsEarlyForBlank(): void
    {
        $provider = $this->makeGateProbe(['baseUrl' => '   ']);

        self::assertInstanceOf(ClientInterface::class, $provider->exposedGetHttpClient());
    }

    /**
     * A schemeless private/link-local IP literal must be rejected by the SSRF
     * gate with code 1751452800. The concat mutants that mangle the
     * protocol-relative `'//' . $baseUrl` prefix produce an unparseable host
     * (code 1751452801 instead), and the +/-1 code mutants change the code.
     */
    #[Test]
    public function endpointGateRejectsSchemelessPrivateIpLiteral(): void
    {
        $provider = $this->makeGateProbe(['baseUrl' => '169.254.169.254']);

        try {
            $provider->exposedGetHttpClient();
            self::fail('Expected ProviderConfigurationException was not thrown');
        } catch (ProviderConfigurationException $e) {
            self::assertSame(1751452800, $e->getCode());
        }
    }

    /**
     * An uppercase scheme must still be recognised (the `#i` regex flag) so the
     * link-local host behind it is parsed and rejected (code 1751452800). The
     * flag-removal mutant fails to match the scheme, mis-parses the host as
     * "HTTPS" and lets it through.
     */
    #[Test]
    public function endpointGateMatchesUppercaseSchemeCaseInsensitively(): void
    {
        $provider = $this->makeGateProbe(['baseUrl' => 'HTTPS://169.254.169.254']);

        try {
            $provider->exposedGetHttpClient();
            self::fail('Expected ProviderConfigurationException was not thrown');
        } catch (ProviderConfigurationException $e) {
            self::assertSame(1751452800, $e->getCode());
        }
    }

    /**
     * The scheme regex is anchored (`^`): a `://` occurring later in a
     * schemeless value must NOT be treated as a scheme. Here the anchored
     * match fails, the value is parsed protocol-relative, the link-local host
     * is found and rejected (code 1751452800). The caret-removal mutant matches
     * the embedded `://`, mis-parses to a null host and yields code 1751452801.
     */
    #[Test]
    public function endpointGateAnchorsSchemeRegexAtStart(): void
    {
        $provider = $this->makeGateProbe(['baseUrl' => '169.254.169.254/x://y']);

        try {
            $provider->exposedGetHttpClient();
            self::fail('Expected ProviderConfigurationException was not thrown');
        } catch (ProviderConfigurationException $e) {
            self::assertSame(1751452800, $e->getCode());
        }
    }

    /**
     * A parseable, allowed host passes the SSRF gate and yields a client. This
     * pins the `!is_string($host) || $host === ''` guard being FALSE for a real
     * host: the negation/identity/or-negation mutants flip it true and throw.
     */
    #[Test]
    public function endpointGateAcceptsAllowedHost(): void
    {
        $provider = $this->makeGateProbe(['baseUrl' => 'https://ok.test']);

        self::assertInstanceOf(ClientInterface::class, $provider->exposedGetHttpClient());
    }

    /**
     * A non-empty base URL with no derivable host (a bare path) fails closed
     * with code 1751452801. Pins the guard being TRUE for a null host: the
     * `||`->`&&` mutant makes it false and calls isHostAllowed(null) -> TypeError.
     */
    #[Test]
    public function endpointGateRejectsUnparseableHost(): void
    {
        $provider = $this->makeGateProbe(['baseUrl' => '/onlypath']);

        try {
            $provider->exposedGetHttpClient();
            self::fail('Expected ProviderConfigurationException was not thrown');
        } catch (ProviderConfigurationException $e) {
            self::assertSame(1751452801, $e->getCode());
        }
    }

    /**
     * Stream factory stub that records the last content it was asked to wrap,
     * so tests can assert on the exact JSON-encoded request body.
     */
    private function createCapturingStreamFactory(?string &$captured): StreamFactoryInterface
    {
        $factory = self::createStub(StreamFactoryInterface::class);
        $factory->method('createStream')->willReturnCallback(
            function (string $content) use (&$captured): StreamInterface {
                $captured = $content;
                $stream = self::createStub(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);
                $stream->method('getContents')->willReturn($content);
                return $stream;
            },
        );

        return $factory;
    }

    /**
     * Build a named AbstractProvider fixture that exposes the protected members
     * under test (SSRF gate, buildAuditReason, sendRequest). A named class —
     * not an anonymous subclass — is required so PHPStan can see the accessors
     * through the declared return type.
     *
     * @param array<string, mixed> $config
     */
    private function makeGateProbe(array $config, ?VaultServiceInterface $vault = null): GateProbeProvider
    {
        $provider = new GateProbeProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $vault ?? $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure($config);

        return $provider;
    }
}

/**
 * Named AbstractProvider fixture exposing protected members for unit tests.
 *
 * PHPStan level 10 cannot resolve test-only accessors declared on an anonymous
 * subclass returned through an `AbstractProvider` type, so this fixture is a
 * top-level named class whose helper return type is itself.
 */
final class GateProbeProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'Gate Probe';
    }

    public function getIdentifier(): string
    {
        return 'gate-probe';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://gate.example.invalid/v1';
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        return ['model-a' => 'Model A'];
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

    public function exposedGetHttpClient(): ClientInterface
    {
        return $this->getHttpClient();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function exposedBuildAuditReason(string $endpoint, array $payload): string
    {
        return $this->buildAuditReason($endpoint, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function exposedSendRequest(string $endpoint, array $payload): array
    {
        return $this->sendRequest($endpoint, $payload);
    }
}
