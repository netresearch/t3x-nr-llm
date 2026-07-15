<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscovery;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(ModelDiscovery::class)]
#[CoversClass(DiscoveredModel::class)]
#[CoversClass(DetectedProvider::class)]
#[AllowMockObjectsWithoutExpectations]
class ModelDiscoveryTest extends AbstractUnitTestCase
{
    private ClientInterface&Stub $httpClientStub;
    private RequestFactoryInterface&Stub $requestFactoryStub;
    private StreamFactoryInterface&Stub $streamFactoryStub;
    private VaultServiceInterface $vaultStub;
    private SecureHttpClientFactory $httpClientFactory;
    private LoggerInterface&Stub $loggerStub;
    private ModelDiscovery $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = self::createStub(ClientInterface::class);
        $this->requestFactoryStub = self::createStub(RequestFactoryInterface::class);
        $this->streamFactoryStub = self::createStub(StreamFactoryInterface::class);
        $this->vaultStub = $this->createVaultServiceMock();
        $this->httpClientFactory = $this->createSecureHttpClientFactoryMock();
        $this->loggerStub = self::createStub(LoggerInterface::class);

        $this->subject = new ModelDiscovery(
            $this->vaultStub,
            $this->httpClientFactory,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $this->loggerStub,
        );
        // Inject the plain HTTP stub through the test seam so requests bypass
        // the vault secure client (which would otherwise resolve DNS / enforce
        // the host allowlist). The dedicated SSRF tests use a fresh subject
        // WITHOUT the seam to exercise the host gate.
        $this->subject->setHttpClient($this->httpClientStub);
    }

    private function createJsonResponseStubForDiscovery(int $statusCode, string $body): ResponseInterface&Stub
    {
        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('getContents')->willReturn($body);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn($statusCode);
        $responseStub->method('getBody')->willReturn($streamStub);

        return $responseStub;
    }

    // ==================== testConnection tests ====================

    #[Test]
    public function testConnectionSucceedsForOpenAi(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{"data": []}'));

        $result = $this->subject->testConnection($provider, 'test-api-key');

        self::assertTrue($result['success']);
        self::assertStringContainsString('Connected to OpenAI successfully', $result['message']);
    }

    #[Test]
    public function testConnectionSucceedsForAnthropic(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{}'));

        $result = $this->subject->testConnection($provider, 'test-api-key');

        self::assertTrue($result['success']);
    }

    #[Test]
    public function testConnectionSucceedsForGemini(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{}'));

        $result = $this->subject->testConnection($provider, 'test-api-key');

        self::assertTrue($result['success']);
    }

    #[Test]
    public function testConnectionSucceedsForOllama(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{}'));

        $result = $this->subject->testConnection($provider, '');

        self::assertTrue($result['success']);
    }

    #[Test]
    public function testConnectionFailsOn401(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(401, '{"error": "unauthorized"}'));

        $result = $this->subject->testConnection($provider, 'invalid-key');

        self::assertFalse($result['success']);
        self::assertStringContainsString('Authentication failed', $result['message']);
    }

    #[Test]
    public function testConnectionFailsOnOtherStatusCode(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(500, '{"error": "server error"}'));

        $result = $this->subject->testConnection($provider, 'test-key');

        self::assertFalse($result['success']);
        self::assertStringContainsString('status code 500', $result['message']);
    }

    #[Test]
    public function testConnectionHandlesException(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Network error'));

        $result = $this->subject->testConnection($provider, 'test-key');

        self::assertFalse($result['success']);
        // The raw exception detail must NOT leak back to the client — only a
        // generic message is returned; the detail is logged server-side.
        self::assertStringNotContainsString('Network error', $result['message']);
        self::assertStringContainsString('Connection error', $result['message']);
    }

    // ==================== SSRF host-guard tests ====================

    #[Test]
    public function testConnectionRejectsDisallowedHost(): void
    {
        // No setHttpClient() seam → dispatch() runs the real isHostAllowed()
        // gate. The cloud metadata IP (169.254.169.254) is always blocked.
        $subject = new ModelDiscovery(
            $this->vaultStub,
            $this->createSecureHttpClientFactoryMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->loggerStub,
        );

        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://169.254.169.254/latest/meta-data',
            suggestedName: 'Metadata SSRF',
        );

        $result = $subject->testConnection($provider, 'test-key');

        self::assertFalse($result['success']);
        self::assertStringContainsString('Connection error', $result['message']);
    }

    #[Test]
    public function discoverRejectsDisallowedHostAndReturnsFallback(): void
    {
        $subject = new ModelDiscovery(
            $this->vaultStub,
            $this->createSecureHttpClientFactoryMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->loggerStub,
        );

        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'http://127.0.0.1:8080/v1',
            suggestedName: 'Loopback SSRF',
        );

        // The host gate rejects the loopback target before any request is sent;
        // discover() fails soft to the static fallback list rather than leaking.
        $models = $subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models);
        self::assertContains('gpt-5.3', $modelIds);
    }

    // ==================== discover tests ====================

    #[Test]
    public function discoverOpenAiReturnsModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => 'gpt-5.2'],
                ['id' => 'gpt-4o'],
                ['id' => 'o4-mini'],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
    }

    #[Test]
    public function discoverOpenAiReturnsFallbackOnError(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(500, '{}'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        self::assertInstanceOf(DiscoveredModel::class, $models[0]);
    }

    #[Test]
    public function discoverAnthropicReturnsStaticModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com',
            suggestedName: 'Anthropic',
        );

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('claude-opus-4-5-20251101', $modelIds);
        self::assertContains('claude-sonnet-4-5-20250929', $modelIds);
        self::assertContains('claude-haiku-4-5-20251001', $modelIds);
    }

    #[Test]
    public function discoverGeminiReturnsModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'models' => [
                [
                    'name' => 'models/gemini-3-flash',
                    'displayName' => 'Gemini 3 Flash',
                    'description' => 'Fast model',
                    'inputTokenLimit' => 1000000,
                    'outputTokenLimit' => 65536,
                ],
                [
                    'name' => 'models/gemini-2.5-flash',
                    'displayName' => 'Gemini 2.5 Flash',
                    'description' => 'Previous gen',
                    'inputTokenLimit' => 1000000,
                    'outputTokenLimit' => 8192,
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('gemini-3-flash', $modelIds);
    }

    #[Test]
    public function discoverGeminiDoesNotLeakApiKeyInRequestUrl(): void
    {
        // The Gemini API key must travel in the `x-goog-api-key` request header,
        // never as a `?key=<secret>` query parameter (which leaks into server,
        // proxy and referrer logs). Capture the URL the request was built with.
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            suggestedName: 'Google Gemini',
        );

        $capturedUri = null;
        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturnCallback(function (string $method, string $uri) use (&$capturedUri): RequestInterface {
                $capturedUri = $uri;

                return $this->createRequestMock($method, $uri);
            });

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{"models": []}'));

        $this->subject->discover($provider, 'AIzaSecretKey123');

        self::assertIsString($capturedUri);
        self::assertStringNotContainsString('key=', $capturedUri);
        self::assertStringNotContainsString('AIzaSecretKey123', $capturedUri);
    }

    #[Test]
    public function discoverGeminiReturnsFallbackOnError(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(500, '{}'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
    }

    #[Test]
    public function discoverOllamaReturnsLocalModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        // First call: /api/tags, second call: /api/show
        $tagsResponse = (string)json_encode([
            'models' => [
                ['name' => 'llama3:latest'],
                ['name' => 'qwen:latest'],
            ],
        ]);

        $showResponse = (string)json_encode([
            'model_info' => [
                'context_length' => 32768,
            ],
            'parameters' => 'num_ctx 32768',
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('llama3:latest', $modelIds);
    }

    #[Test]
    public function discoverOllamaReturnsEmptyOnError(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(500, '{}'));

        $models = $this->subject->discover($provider, '');

        self::assertEmpty($models);
    }

    #[Test]
    public function discoverOpenRouterReturnsModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openrouter',
            endpoint: 'https://openrouter.ai/api',
            suggestedName: 'OpenRouter',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                [
                    'id' => 'anthropic/claude-3-opus',
                    'name' => 'Claude 3 Opus',
                    'description' => 'Most capable',
                    'context_length' => 200000,
                    'pricing' => [
                        'prompt' => 0.000015,
                        'completion' => 0.000075,
                    ],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        self::assertEquals('anthropic/claude-3-opus', $models[0]->modelId);
    }

    #[Test]
    public function discoverMistralReturnsModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'mistral',
            endpoint: 'https://api.mistral.ai',
            suggestedName: 'Mistral AI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => 'mistral-large-latest'],
                ['id' => 'mistral-medium-latest'],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
    }

    #[Test]
    public function discoverMistralReturnsFallbackOnError(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'mistral',
            endpoint: 'https://api.mistral.ai',
            suggestedName: 'Mistral AI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(500, '{}'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('mistral-large-latest', $modelIds);
    }

    #[Test]
    public function discoverGroqReturnsModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'groq',
            endpoint: 'https://api.groq.com',
            suggestedName: 'Groq',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                [
                    'id' => 'llama-3.1-70b-versatile',
                    'context_window' => 32768,
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        self::assertEquals('llama-3.1-70b-versatile', $models[0]->modelId);
    }

    #[Test]
    public function discoverGroqReturnsEmptyOnError(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'groq',
            endpoint: 'https://api.groq.com',
            suggestedName: 'Groq',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(500, '{}'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertEmpty($models);
    }

    #[Test]
    public function discoverUnknownAdapterReturnsDefaultModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'unknown-provider',
            endpoint: 'https://example.com',
            suggestedName: 'Unknown',
        );

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        self::assertEquals('default', $models[0]->modelId);
        self::assertStringContainsString('unknown-provider', $models[0]->description);
    }

    // ==================== OpenAI model filtering tests ====================

    #[Test]
    public function discoverOpenAiFiltersIrrelevantModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => 'gpt-5.2'],
                ['id' => 'text-davinci-003'], // Should be filtered out
                ['id' => 'text-embedding-3-large'], // Should be filtered out
                ['id' => 'gpt-3.5-turbo-instruct'], // Should be filtered out
                ['id' => 'gpt-image-1'], // Should be included
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('gpt-5.2', $modelIds);
        self::assertContains('gpt-image-1', $modelIds);
        self::assertNotContains('text-davinci-003', $modelIds);
        self::assertNotContains('text-embedding-3-large', $modelIds);
        self::assertNotContains('gpt-3.5-turbo-instruct', $modelIds);
    }

    #[Test]
    public function discoverOpenAiIncludesSpecializedModelsWithMatchingCapabilities(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => 'gpt-5.5'],
                ['id' => 'dall-e-3'],
                ['id' => 'gpt-image-2'],
                ['id' => 'tts-1'],
                ['id' => 'tts-1-hd'],
                ['id' => 'whisper-1'],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        $byId = [];
        foreach ($models as $model) {
            $byId[$model->modelId] = $model;
        }

        // Capability values must match the ModelCapability enum
        assert(isset($byId['dall-e-3'], $byId['gpt-image-2'], $byId['tts-1'], $byId['tts-1-hd'], $byId['whisper-1']));
        self::assertSame(['image'], $byId['dall-e-3']->capabilities);
        self::assertSame(['image'], $byId['gpt-image-2']->capabilities);
        self::assertSame(['text_to_speech'], $byId['tts-1']->capabilities);
        self::assertSame(['text_to_speech'], $byId['tts-1-hd']->capabilities);
        self::assertSame(['transcription'], $byId['whisper-1']->capabilities);

        // Specialized models carry no token-based specs
        self::assertSame(0, $byId['tts-1']->contextLength);
        self::assertSame(0, $byId['tts-1']->maxOutputTokens);

        // A live result is not flagged as fallback
        self::assertFalse($this->subject->wasLastDiscoveryFromFallback());
    }

    #[Test]
    public function getOpenAiFallbackModelsIncludeGpt55AndSpecializedModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        // 401 (e.g. invalid API key) → static fallback catalog
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(401, ''));

        $models = $this->subject->discover($provider, 'test-key');

        $byId = [];
        foreach ($models as $model) {
            $byId[$model->modelId] = $model;
        }

        assert(isset($byId['gpt-5.5'], $byId['gpt-image-2'], $byId['tts-1'], $byId['tts-1-hd'], $byId['whisper-1']));
        // $5 / $30 per 1M tokens, stored as cents per 1M
        self::assertSame(500, $byId['gpt-5.5']->costInput);
        self::assertSame(3000, $byId['gpt-5.5']->costOutput);
        self::assertTrue($byId['gpt-5.5']->recommended);
        self::assertSame(['image'], $byId['gpt-image-2']->capabilities);
        self::assertSame(['text_to_speech'], $byId['tts-1']->capabilities);
        self::assertSame(['text_to_speech'], $byId['tts-1-hd']->capabilities);
        self::assertSame(['transcription'], $byId['whisper-1']->capabilities);

        self::assertTrue($this->subject->wasLastDiscoveryFromFallback());
    }

    #[Test]
    public function wasLastDiscoveryFromFallbackResetsBetweenCalls(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $liveResponse = $this->createJsonResponseStubForDiscovery(
            200,
            (string)json_encode(['data' => [['id' => 'gpt-5.5']]]),
        );
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(401, ''),
                $liveResponse,
            );

        $this->subject->discover($provider, 'test-key');
        self::assertTrue($this->subject->wasLastDiscoveryFromFallback());

        $this->subject->discover($provider, 'test-key');
        self::assertFalse($this->subject->wasLastDiscoveryFromFallback());
    }

    #[Test]
    public function discoverOpenAiHandlesInvalidDataList(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => 'invalid', // Not an array
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        // Should return fallback models
        self::assertNotEmpty($models);
    }

    // ==================== Ollama model capability detection ====================

    #[Test]
    public function discoverOllamaDetectsVisionCapabilities(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [
                ['name' => 'llava:latest'],
            ],
        ]);

        $showResponse = (string)json_encode([
            'model_info' => [],
            'parameters' => '',
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertNotEmpty($models);
        self::assertContains('vision', $models[0]->capabilities);
    }

    #[Test]
    public function discoverOllamaDetectsToolCapabilities(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [
                ['name' => 'qwen2:latest'],
            ],
        ]);

        $showResponse = (string)json_encode([
            'model_info' => [],
            'parameters' => 'num_ctx 32768',
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertNotEmpty($models);
        self::assertContains('tools', $models[0]->capabilities);
    }

    // ==================== DiscoveredModel tests ====================

    #[Test]
    public function discoveredModelHasCorrectProperties(): void
    {
        $model = new DiscoveredModel(
            modelId: 'test-model',
            name: 'Test Model',
            description: 'A test model',
            capabilities: ['chat', 'vision'],
            contextLength: 100000,
            maxOutputTokens: 8192,
            costInput: 100,
            costOutput: 300,
            recommended: true,
        );

        self::assertEquals('test-model', $model->modelId);
        self::assertEquals('Test Model', $model->name);
        self::assertEquals('A test model', $model->description);
        self::assertEquals(['chat', 'vision'], $model->capabilities);
        self::assertEquals(100000, $model->contextLength);
        self::assertEquals(8192, $model->maxOutputTokens);
        self::assertEquals(100, $model->costInput);
        self::assertEquals(300, $model->costOutput);
        self::assertTrue($model->recommended);
    }

    #[Test]
    public function discoveredModelToArrayWorks(): void
    {
        $model = new DiscoveredModel(
            modelId: 'test-model',
            name: 'Test Model',
            description: 'A test model',
            capabilities: ['chat'],
            recommended: true,
        );

        $array = $model->toArray();

        self::assertEquals('test-model', $array['modelId']);
        self::assertEquals('Test Model', $array['name']);
        self::assertEquals('A test model', $array['description']);
        self::assertEquals(['chat'], $array['capabilities']);
        self::assertTrue($array['recommended']);
    }

    // ==================== DetectedProvider tests ====================

    #[Test]
    public function detectedProviderHasCorrectProperties(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        self::assertEquals('openai', $provider->adapterType);
        self::assertEquals('https://api.openai.com', $provider->endpoint);
        self::assertEquals('OpenAI', $provider->suggestedName);
    }

    // ==================== discoverOpenAI edge cases ====================

    #[Test]
    public function discoverOpenAiReturnsFallbackWhenResponseBodyIsNotValidJson(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        // Body decodes to a string, not array
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '"not an array"'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
    }

    #[Test]
    public function discoverOpenAiReturnsFallbackWhenResponseBodyDecodesFalse(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, 'invalid-json'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
    }

    #[Test]
    public function discoverOpenAiReturnsFallbackOnException(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection refused'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        // Fallback models should include current GPT models
        self::assertContains('gpt-5.3', $modelIds);
    }

    #[Test]
    public function discoverOpenAiSkipsNonArrayModelItems(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                'not-an-array',  // Should be skipped
                ['id' => 'gpt-5.3'],
                null,
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('gpt-5.3', $modelIds);
    }

    // ==================== discoverAnthropic API-based discovery ====================

    #[Test]
    public function discoverAnthropicFromApiWhenSuccessful(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                [
                    'id' => 'claude-opus-4-5-20251101',
                    'display_name' => 'Claude Opus 4.5',
                    'type' => 'model',
                ],
                [
                    'id' => 'claude-sonnet-4-5-20250929',
                    'display_name' => 'Claude Sonnet 4.5',
                    'type' => 'model',
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('claude-opus-4-5-20251101', $modelIds);
        self::assertContains('claude-sonnet-4-5-20250929', $modelIds);
    }

    #[Test]
    public function discoverAnthropicReturnsFallbackOnApiError(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(401, '{"error": "unauthorized"}'));

        $models = $this->subject->discover($provider, 'bad-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('claude-opus-4-5-20251101', $modelIds);
    }

    #[Test]
    public function discoverAnthropicReturnsFallbackWhenBodyIsNotArray(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '"just a string"'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('claude-opus-4-5-20251101', $modelIds);
    }

    #[Test]
    public function discoverAnthropicReturnsFallbackWhenModelListIsEmpty(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{"data": []}'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('claude-opus-4-5-20251101', $modelIds);
    }

    #[Test]
    public function discoverAnthropicReturnsFallbackWhenModelListIsNotArray(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{"data": "not an array"}'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
    }

    #[Test]
    public function discoverAnthropicSkipsNonArrayModelItems(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                'not-an-array',
                ['id' => 'claude-opus-4-5-20251101', 'display_name' => 'Claude Opus 4.5'],
                null,
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        // The valid entry after the bad ones must still be processed LIVE —
        // if the loop had stopped early, the result would be the fallback
        // catalog (which contains the same id), so pin the fallback flag.
        self::assertFalse($this->subject->wasLastDiscoveryFromFallback());
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('claude-opus-4-5-20251101', $modelIds);
    }

    #[Test]
    public function discoverAnthropicReturnsFallbackOnException(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection timeout'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('claude-opus-4-5-20251101', $modelIds);
    }

    #[Test]
    public function enrichAnthropicModelUsesPrefixMatchForDatedVersions(): void
    {
        // Dated model IDs like 'claude-opus-4-5-20251101' should match 'claude-opus-4-5' prefix
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => 'claude-opus-4-5-20251101'],  // should match 'claude-opus-4-5' prefix
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $model = $models[0];
        self::assertSame('claude-opus-4-5-20251101', $model->modelId);
        // Description should come from the spec (prefix match)
        self::assertStringContainsString('Most intelligent', $model->description);
        self::assertTrue($model->recommended);
    }

    #[Test]
    public function enrichAnthropicModelUsesDisplayNameFromApiWhenAvailable(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                [
                    'id' => 'claude-opus-4-5-20251101',
                    'display_name' => 'Claude Opus 4.5 (Display Name From API)',
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        self::assertSame('Claude Opus 4.5 (Display Name From API)', $models[0]->name);
    }

    #[Test]
    public function enrichAnthropicModelHandlesUnknownModel(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => 'claude-future-model-x'],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $model = $models[0];
        self::assertSame('claude-future-model-x', $model->modelId);
        // Unknown model uses modelId as name and default description
        self::assertSame('Anthropic model', $model->description);
        self::assertFalse($model->recommended);
    }

    // ==================== discoverGemini edge cases ====================

    #[Test]
    public function discoverGeminiReturnsFallbackWhenBodyIsNotArray(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '"not an array"'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('gemini-3-flash', $modelIds);
    }

    #[Test]
    public function discoverGeminiReturnsFallbackWhenModelListIsNotArray(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{"models": "not-an-array"}'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('gemini-3-flash', $modelIds);
    }

    #[Test]
    public function discoverGeminiReturnsFallbackOnException(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection refused'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('gemini-3-flash', $modelIds);
    }

    #[Test]
    public function discoverGeminiSkipsNonArrayModelItems(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'models' => [
                'not-an-array',
                ['name' => 'models/gemini-3-flash', 'displayName' => 'Gemini 3 Flash'],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('gemini-3-flash', $modelIds);
    }

    #[Test]
    public function discoverGeminiSkipsModelsWithNonStringName(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'models' => [
                ['name' => 12345],  // Non-string name, should be skipped
                ['name' => 'models/gemini-3-flash'],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('gemini-3-flash', $modelIds);
    }

    #[Test]
    public function discoverGeminiFiltersIrrelevantModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'models' => [
                ['name' => 'models/gemini-3-flash'],
                ['name' => 'models/text-embedding-004'],  // embedding, excluded
                ['name' => 'models/gemini-1.0-pro'],       // old version, excluded
                ['name' => 'models/gemini-pro'],            // old version, excluded
                ['name' => 'models/not-gemini-model'],      // not gemini, excluded
                ['name' => 'models/'],                      // empty ID after strip, excluded
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertCount(1, $models);
        self::assertSame('gemini-3-flash', $models[0]->modelId);
    }

    #[Test]
    public function enrichGeminiModelUsesApiDataWhenNoKnownSpec(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'models' => [
                [
                    'name' => 'models/gemini-future-model',
                    'displayName' => 'Gemini Future Model',
                    'description' => 'An upcoming model from Google',
                    'inputTokenLimit' => 500000,
                    'outputTokenLimit' => 16384,
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $model = $models[0];
        self::assertSame('gemini-future-model', $model->modelId);
        // Should use displayName from API since no spec found
        self::assertSame('Gemini Future Model', $model->name);
        self::assertSame('An upcoming model from Google', $model->description);
        self::assertSame(500000, $model->contextLength);
        self::assertSame(16384, $model->maxOutputTokens);
    }

    #[Test]
    public function enrichGeminiModelUsesDefaultsWhenApiDataHasWrongTypes(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        // inputTokenLimit is a string instead of int — should use default 1000000
        $apiResponse = (string)json_encode([
            'models' => [
                [
                    'name' => 'models/gemini-future-model',
                    'displayName' => 42,       // non-string displayName -> use modelId
                    'description' => ['array'], // non-string description -> default
                    'inputTokenLimit' => '500k', // non-int -> default 1000000
                    'outputTokenLimit' => 'big',  // non-int -> default 8192
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $model = $models[0];
        // No spec found, displayName is not a string → falls back to modelId
        self::assertSame('gemini-future-model', $model->name);
        self::assertSame(1000000, $model->contextLength);  // default
        self::assertSame(8192, $model->maxOutputTokens);  // default
    }

    // ==================== discoverOllama edge cases ====================

    #[Test]
    public function discoverOllamaReturnsEmptyWhenBodyIsNotArray(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '"not an array"'));

        $models = $this->subject->discover($provider, '');

        // Invalid body → modelList is [] → no models → empty result
        self::assertEmpty($models);
    }

    #[Test]
    public function discoverOllamaReturnsEmptyOnException(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection refused'));

        $models = $this->subject->discover($provider, '');

        self::assertEmpty($models);
    }

    #[Test]
    public function discoverOllamaSkipsModelsWithEmptyName(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [
                ['name' => ''],      // empty name, skipped
                ['name' => 123],     // non-string, skipped (name = '')
                ['not-name' => 'x'], // no name key, skipped
                ['name' => 'valid-model:latest'],
            ],
        ]);

        $showResponse = (string)json_encode([
            'model_info' => [],
            'parameters' => '',
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertCount(1, $models);
        self::assertSame('valid-model:latest', $models[0]->modelId);
    }

    #[Test]
    public function getOllamaModelDetailsReturnsFallbackWhenShowReturnsError(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [['name' => 'llama3:latest']],
        ]);

        // /api/show returns 404
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(404, '{}'),
            );

        $models = $this->subject->discover($provider, '');

        // Model is still added, but with fallback details
        self::assertCount(1, $models);
        self::assertSame('llama3:latest', $models[0]->modelId);
        self::assertSame(0, $models[0]->contextLength);
    }

    #[Test]
    public function getOllamaModelDetailsReturnsFallbackWhenShowBodyIsNotArray(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [['name' => 'llama3:latest']],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, '"not an array"'),
            );

        $models = $this->subject->discover($provider, '');

        self::assertCount(1, $models);
        self::assertSame(0, $models[0]->contextLength);
    }

    #[Test]
    public function getOllamaModelDetailsExtractsContextLengthFromModelInfo(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [['name' => 'llama3:latest']],
        ]);

        // context length via model_info key containing 'context'
        $showResponse = (string)json_encode([
            'model_info' => [
                'llama.context_length' => 32768,
            ],
            'parameters' => '',
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertCount(1, $models);
        self::assertSame(32768, $models[0]->contextLength);
    }

    #[Test]
    public function getOllamaModelDetailsFallsBackToParametersStringForContextLength(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [['name' => 'llama3:latest']],
        ]);

        // No model_info context key, but parameters string has num_ctx
        $showResponse = (string)json_encode([
            'model_info' => ['some_other_key' => 42],
            'parameters' => 'stop "<|start_header_id|>"\nnum_ctx 16384\ntemperature 0',
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertCount(1, $models);
        self::assertSame(16384, $models[0]->contextLength);
    }

    #[Test]
    public function getOllamaModelDetailsReturnsFallbackOnException(): void
    {
        // When streamFactory->createStream() throws inside getOllamaModelDetails,
        // the catch(Throwable) returns the fallback defaults.
        $tagsResponse = (string)json_encode([
            'models' => [['name' => 'llama3:latest']],
        ]);

        $tagsStream = self::createStub(StreamInterface::class);
        $tagsStream->method('getContents')->willReturn($tagsResponse);
        $tagsResponseObj = self::createStub(ResponseInterface::class);
        $tagsResponseObj->method('getStatusCode')->willReturn(200);
        $tagsResponseObj->method('getBody')->willReturn($tagsStream);

        // HttpClient returns tags on first call; /show is never reached due to streamFactory throwing
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($tagsResponseObj);

        // streamFactory->createStream() throws on the call inside getOllamaModelDetails
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory
            ->method('createStream')
            ->willThrowException(new RuntimeException('Stream creation failed'));

        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($this->createRequestMock());

        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $subject = new ModelDiscovery(
            $this->vaultStub,
            $this->createSecureHttpClientFactoryMock(),
            $requestFactory,
            $streamFactory,
            $this->loggerStub,
        );
        $subject->setHttpClient($httpClient);
        $models = $subject->discover($provider, '');

        // Model is still added with fallback details (contextLength = 0)
        self::assertCount(1, $models);
        self::assertSame('llama3:latest', $models[0]->modelId);
        self::assertSame(0, $models[0]->contextLength);
    }

    // ==================== estimateOllamaMaxOutput model families ====================

    #[Test]
    public function discoverOllamaEstimatesMaxOutputForKnownModelFamilies(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [
                ['name' => 'qwen2:latest'],
                ['name' => 'codellama:latest'],
                ['name' => 'phi3:latest'],
                ['name' => 'gemma2:latest'],
                ['name' => 'deepseek-coder:latest'],
            ],
        ]);

        $showResponse = (string)json_encode([
            'model_info' => [],
            'parameters' => 'num_ctx 32768',
        ]);

        // Each model triggers one /show call
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse), // qwen
                $this->createJsonResponseStubForDiscovery(200, $showResponse), // codellama
                $this->createJsonResponseStubForDiscovery(200, $showResponse), // phi
                $this->createJsonResponseStubForDiscovery(200, $showResponse), // gemma
                $this->createJsonResponseStubForDiscovery(200, $showResponse), // deepseek
            );

        $models = $this->subject->discover($provider, '');

        self::assertCount(5, $models);

        // Find models by ID
        $byId = [];
        foreach ($models as $m) {
            $byId[$m->modelId] = $m;
        }

        // qwen → 8192 (known limit)
        self::assertSame(8192, $byId['qwen2:latest']->maxOutputTokens);
        // codellama → 16384 (code models)
        self::assertSame(16384, $byId['codellama:latest']->maxOutputTokens);
        // phi → 4096 (small models)
        self::assertSame(4096, $byId['phi3:latest']->maxOutputTokens);
        // gemma → 8192
        self::assertSame(8192, $byId['gemma2:latest']->maxOutputTokens);
        // deepseek → 8192
        self::assertSame(8192, $byId['deepseek-coder:latest']->maxOutputTokens);
    }

    #[Test]
    public function discoverOllamaEstimatesMaxOutputFromContextLengthWhenFamilyUnknown(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [
                ['name' => 'unknown-model:latest'],  // not a known family
            ],
        ]);

        // context length = 65536, so max output = 65536/4 = 16384 (capped)
        $showResponse = (string)json_encode([
            'model_info' => [],
            'parameters' => 'num_ctx 65536',
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertCount(1, $models);
        self::assertSame(16384, $models[0]->maxOutputTokens);
    }

    #[Test]
    public function discoverOllamaEstimatesMaxOutputAsDefaultWhenContextLengthIsZero(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [
                ['name' => 'unknown-model:latest'],
            ],
        ]);

        // No context info → contextLength stays 0 → fallback to 4096
        $showResponse = (string)json_encode([
            'model_info' => [],
            'parameters' => '',
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertCount(1, $models);
        self::assertSame(4096, $models[0]->maxOutputTokens);
    }

    #[Test]
    public function discoverOllamaEstimatesMaxOutputForLlama3Family(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [
                ['name' => 'llama3.1:latest'],
                ['name' => 'llama-3.2:latest'],
            ],
        ]);

        $showResponse = (string)json_encode([
            'model_info' => [],
            'parameters' => 'num_ctx 8192',
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertCount(2, $models);
        // llama3.1 contains 'llama3' → 8192
        self::assertSame(8192, $models[0]->maxOutputTokens);
        // llama-3.2 contains 'llama-3' → 8192
        self::assertSame(8192, $models[1]->maxOutputTokens);
    }

    // ==================== discoverOpenRouter edge cases ====================

    #[Test]
    public function discoverOpenRouterReturnsEmptyOnException(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openrouter',
            endpoint: 'https://openrouter.ai/api',
            suggestedName: 'OpenRouter',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection failed'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertEmpty($models);
    }

    #[Test]
    public function discoverOpenRouterSkipsModelsWithEmptyId(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openrouter',
            endpoint: 'https://openrouter.ai/api',
            suggestedName: 'OpenRouter',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => ''],   // empty ID, skipped
                ['id' => 123],  // non-string, gives empty string, skipped
                [               // no id key, skipped
                    'name' => 'No ID Model',
                ],
                [
                    'id' => 'valid-model',
                    'name' => 'Valid Model',
                    'description' => 'Works',
                    'context_length' => 100000,
                    'pricing' => ['prompt' => 0.00001, 'completion' => 0.00003],
                ],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertCount(1, $models);
        self::assertSame('valid-model', $models[0]->modelId);
    }

    #[Test]
    public function discoverOpenRouterSkipsNonArrayModelItems(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openrouter',
            endpoint: 'https://openrouter.ai/api',
            suggestedName: 'OpenRouter',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                'not-an-array',  // skipped
                ['id' => 'valid-model', 'name' => 'Valid'],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertCount(1, $models);
    }

    // ==================== discoverMistral edge cases ====================

    #[Test]
    public function discoverMistralSkipsModelsWithEmptyId(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'mistral',
            endpoint: 'https://api.mistral.ai',
            suggestedName: 'Mistral AI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => ''],  // empty, skipped
                ['id' => 'mistral-large-latest'],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertCount(1, $models);
        self::assertSame('mistral-large-latest', $models[0]->modelId);
    }

    #[Test]
    public function discoverMistralReturnsFallbackOnException(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'mistral',
            endpoint: 'https://api.mistral.ai',
            suggestedName: 'Mistral AI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection refused'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('mistral-large-latest', $modelIds);
    }

    #[Test]
    public function discoverMistralSkipsNonArrayItems(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'mistral',
            endpoint: 'https://api.mistral.ai',
            suggestedName: 'Mistral AI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                'not-an-array',
                null,
                ['id' => 'mistral-large-latest'],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertCount(1, $models);
        self::assertSame('mistral-large-latest', $models[0]->modelId);
    }

    // ==================== discoverGroq edge cases ====================

    #[Test]
    public function discoverGroqSkipsModelsWithEmptyId(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'groq',
            endpoint: 'https://api.groq.com',
            suggestedName: 'Groq',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => '', 'context_window' => 32768],  // empty ID, skipped
                ['id' => 'llama-3.3-70b-versatile', 'context_window' => 131072],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertCount(1, $models);
        self::assertSame('llama-3.3-70b-versatile', $models[0]->modelId);
    }

    #[Test]
    public function discoverGroqSkipsNonArrayItems(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'groq',
            endpoint: 'https://api.groq.com',
            suggestedName: 'Groq',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                'not-an-array',
                null,
                ['id' => 'llama-3.3-70b-versatile', 'context_window' => 131072],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertCount(1, $models);
    }

    #[Test]
    public function discoverGroqUsesContextWindowWhenAvailable(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'groq',
            endpoint: 'https://api.groq.com',
            suggestedName: 'Groq',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => 'model-with-ctx', 'context_window' => 131072],
                ['id' => 'model-without-ctx'],  // no context_window → 0
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertCount(2, $models);
        // Find by modelId
        $byId = [];
        foreach ($models as $m) {
            $byId[$m->modelId] = $m;
        }
        self::assertSame(131072, $byId['model-with-ctx']->contextLength);
        self::assertSame(0, $byId['model-without-ctx']->contextLength);
    }

    #[Test]
    public function discoverGroqReturnsFallbackOnException(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'groq',
            endpoint: 'https://api.groq.com',
            suggestedName: 'Groq',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Groq API down'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertEmpty($models);
    }

    // ==================== Anthropic: skips models with empty/missing id ====================

    #[Test]
    public function discoverAnthropicSkipsModelItemsWithEmptyOrMissingId(): void
    {
        // Covers line 420: continue when model['id'] is not a non-empty string
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'data' => [
                ['id' => ''],                                      // empty string → skipped
                ['id' => 123],                                     // non-string → skipped
                ['display_name' => 'No ID'],                       // missing id key → skipped
                ['id' => 'claude-opus-4-5-20251101', 'display_name' => 'Claude Opus 4.5'],
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        // The invalid ids must be SKIPPED (not break the loop, not blow up
        // with a TypeError that degrades to the fallback catalog).
        self::assertFalse($this->subject->wasLastDiscoveryFromFallback());
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('claude-opus-4-5-20251101', $modelIds);
        // None of the invalid entries should appear
        self::assertNotContains('', $modelIds);
    }

    // ==================== Gemini: filters embedding models with gemini- prefix ====================

    #[Test]
    public function discoverGeminiFiltersEmbeddingModelsWithGeminiPrefix(): void
    {
        // Covers line 638: return false when gemini-* model contains 'embedding'
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = (string)json_encode([
            'models' => [
                ['name' => 'models/gemini-embedding-exp-03-07'],  // gemini-* + 'embedding' → filtered
                ['name' => 'models/gemini-1.5-embedding'],         // gemini-* + 'embedding' → filtered
                ['name' => 'models/gemini-2.5-flash'],             // valid model → included
            ],
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        // Embedding models (gemini-* prefix with 'embedding') must be excluded
        self::assertNotContains('gemini-embedding-exp-03-07', $modelIds);
        self::assertNotContains('gemini-1.5-embedding', $modelIds);
        self::assertContains('gemini-2.5-flash', $modelIds);
    }

    // ==================== Ollama: non-array model item in tags list ====================

    #[Test]
    public function discoverOllamaSkipsNonArrayModelItemsInTagsList(): void
    {
        // Covers line 789: continue when model item in /api/tags is not an array
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [
                'not-an-array',          // non-array item → skipped (covers line 789)
                null,                    // null → skipped
                42,                      // integer → skipped
                ['name' => 'llama3:latest'],  // valid → included
            ],
        ]);

        $showResponse = (string)json_encode([
            'model_info' => [],
            'parameters' => 'num_ctx 32768',
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertCount(1, $models);
        self::assertSame('llama3:latest', $models[0]->modelId);
    }

    // ==================== Ollama getOllamaModelDetails: missing model_info and parameters ====================

    #[Test]
    public function discoverOllamaHandlesShowResponseWithoutModelInfoOrParameters(): void
    {
        // Covers lines 855 and 861: fallback values when model_info and parameters keys are missing
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamStub = self::createStub(StreamInterface::class);
        $this->streamFactoryStub
            ->method('createStream')
            ->willReturn($streamStub);

        $tagsResponse = (string)json_encode([
            'models' => [
                ['name' => 'llama3:latest'],
            ],
        ]);

        // Show response with neither model_info nor parameters → both fallback to empty
        $showResponse = (string)json_encode([
            'license' => 'MIT',  // irrelevant field; model_info and parameters are absent
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseStubForDiscovery(200, $tagsResponse),
                $this->createJsonResponseStubForDiscovery(200, $showResponse),
            );

        $models = $this->subject->discover($provider, '');

        self::assertCount(1, $models);
        // Without model_info or parameters, contextLength = 0
        self::assertSame(0, $models[0]->contextLength);
    }

    // ==================== OpenRouter: non-200 response ====================

    #[Test]
    public function discoverOpenRouterReturnsEmptyOnNon200Response(): void
    {
        // Covers line 962: return [] when response status is not 200
        $provider = new DetectedProvider(
            adapterType: 'openrouter',
            endpoint: 'https://openrouter.ai/api',
            suggestedName: 'OpenRouter',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(401, '{"error":"Unauthorized"}'));

        $models = $this->subject->discover($provider, 'invalid-key');

        self::assertEmpty($models);
    }

    // ==================== OpenRouter: response missing 'data' key ====================

    #[Test]
    public function discoverOpenRouterReturnsEmptyWhenDataKeyMissing(): void
    {
        // Covers line 970: modelList = [] when $data has no 'data' key
        $provider = new DetectedProvider(
            adapterType: 'openrouter',
            endpoint: 'https://openrouter.ai/api',
            suggestedName: 'OpenRouter',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        // Response does not contain 'data' key
        $apiResponse = (string)json_encode([
            'models' => ['gpt-5.2'],  // wrong key name
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertEmpty($models);
    }

    // ==================== Mistral: response missing 'data' key ====================

    #[Test]
    public function discoverMistralReturnsEmptyWhenDataKeyMissing(): void
    {
        // Covers line 1037: modelList = [] when $data has no 'data' key
        $provider = new DetectedProvider(
            adapterType: 'mistral',
            endpoint: 'https://api.mistral.ai',
            suggestedName: 'Mistral AI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        // Response does not contain 'data' key
        $apiResponse = (string)json_encode([
            'object' => 'list',
            'items' => [['id' => 'mistral-large-latest']],  // wrong key
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        // No 'data' key → modelList is [] → no API models found
        // Mistral falls back to static models on empty discovery
        self::assertNotEmpty($models);
        $modelIds = array_map(fn(DiscoveredModel $m) => $m->modelId, $models);
        self::assertContains('mistral-large-latest', $modelIds);
    }

    // ==================== Groq: response missing 'data' key ====================

    #[Test]
    public function discoverGroqReturnsEmptyWhenDataKeyMissing(): void
    {
        // Covers line 1122: modelList = [] when $data has no 'data' key
        $provider = new DetectedProvider(
            adapterType: 'groq',
            endpoint: 'https://api.groq.com',
            suggestedName: 'Groq',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        // Response does not contain 'data' key
        $apiResponse = (string)json_encode([
            'models' => [['id' => 'llama-3.3-70b-versatile']],  // wrong key
        ]);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        // No 'data' key → modelList is [] → no models found
        self::assertEmpty($models);
    }

    // ==================== outgoing-request pinning (URL / method / headers) ====================

    /**
     * Configure the request-factory stub to record the exact method, URI and
     * every header set on the outgoing request, so a test can assert the exact
     * request the service built (endpoint construction, HTTP verb, auth headers).
     *
     * @param array{method: ?string, uri: ?string, headers: array<string, string>} $captured
     */
    private function configureCapturingRequestFactory(array &$captured): void
    {
        $captured = ['method' => null, 'uri' => null, 'headers' => []];

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturnCallback(function (string $method, string $uri) use (&$captured): RequestInterface {
                $captured['method'] = $method;
                $captured['uri'] = $uri;

                $request = self::createStub(RequestInterface::class);
                $request->method('withHeader')->willReturnCallback(
                    function (string $name, mixed $value) use (&$captured, $request): RequestInterface {
                        $captured['headers'][$name] = is_array($value) ? implode(', ', $value) : (is_string($value) ? $value : '');

                        return $request;
                    },
                );
                $request->method('withBody')->willReturnCallback(static fn(): RequestInterface => $request);
                $request->method('getMethod')->willReturn($method);

                return $request;
            });
    }

    #[Test]
    public function testConnectionBuildsModelsUrlWithBearerAuthForOpenAi(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $captured = [];
        $this->configureCapturingRequestFactory($captured);
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{}'));

        $this->subject->testConnection($provider, 'test-api-key');

        self::assertSame('GET', $captured['method']);
        self::assertSame('https://api.openai.com/models', $captured['uri']);
        self::assertSame('Bearer test-api-key', $captured['headers']['Authorization'] ?? null);
    }

    #[Test]
    public function testConnectionStripsTrailingSlashFromEndpoint(): void
    {
        // A user-entered trailing slash must not produce "https://…//models".
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com/',
            suggestedName: 'OpenAI',
        );

        $captured = [];
        $this->configureCapturingRequestFactory($captured);
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{}'));

        $this->subject->testConnection($provider, 'test-api-key');

        self::assertSame('https://api.openai.com/models', $captured['uri']);
    }

    #[Test]
    public function testConnectionSendsAnthropicKeyAndVersionHeaders(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com',
            suggestedName: 'Anthropic',
        );

        $captured = [];
        $this->configureCapturingRequestFactory($captured);
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{}'));

        $this->subject->testConnection($provider, 'test-api-key');

        self::assertSame('https://api.anthropic.com/models', $captured['uri']);
        self::assertSame('test-api-key', $captured['headers']['x-api-key'] ?? null);
        self::assertSame('2023-06-01', $captured['headers']['anthropic-version'] ?? null);
        // Anthropic must NOT get a Bearer Authorization header.
        self::assertArrayNotHasKey('Authorization', $captured['headers']);
    }

    #[Test]
    public function testConnectionSendsGeminiApiKeyHeader(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com',
            suggestedName: 'Google Gemini',
        );

        $captured = [];
        $this->configureCapturingRequestFactory($captured);
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{}'));

        $this->subject->testConnection($provider, 'test-api-key');

        self::assertSame('https://generativelanguage.googleapis.com/models', $captured['uri']);
        self::assertSame('test-api-key', $captured['headers']['x-goog-api-key'] ?? null);
        self::assertArrayNotHasKey('Authorization', $captured['headers']);
    }

    #[Test]
    public function testConnectionUsesTagsEndpointAndNoAuthForOllama(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $captured = [];
        $this->configureCapturingRequestFactory($captured);
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{}'));

        $this->subject->testConnection($provider, '');

        self::assertSame('http://localhost:11434/api/tags', $captured['uri']);
        // Ollama needs no authentication — no headers at all.
        self::assertSame([], $captured['headers']);
    }

    #[Test]
    public function testConnectionStripsTrailingApiSegmentForOllama(): void
    {
        // A legacy/user-entered trailing "/api" must be stripped so the tags URL
        // does not become ".../api/api/tags".
        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434/api',
            suggestedName: 'Ollama',
        );

        $captured = [];
        $this->configureCapturingRequestFactory($captured);
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{}'));

        $this->subject->testConnection($provider, '');

        self::assertSame('http://localhost:11434/api/tags', $captured['uri']);
    }

    #[Test]
    public function testConnectionTreatsStatus300AsFailure(): void
    {
        // The success window is 200–299; 300 must be reported as a failure.
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(300, '{}'));

        $result = $this->subject->testConnection($provider, 'test-key');

        self::assertFalse($result['success']);
        self::assertStringContainsString('status code 300', $result['message']);
    }

    #[Test]
    public function discoverOpenAiBuildsModelsUrlWithBearerAndContentTypeHeaders(): void
    {
        // Trailing slash also exercises discover()'s rtrim: the URL must be a
        // single ".../models", never ".../…//models".
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com/',
            suggestedName: 'OpenAI',
        );

        $captured = [];
        $this->configureCapturingRequestFactory($captured);
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{"data": []}'));

        $this->subject->discover($provider, 'test-key');

        self::assertSame('GET', $captured['method']);
        self::assertSame('https://api.openai.com/models', $captured['uri']);
        self::assertSame('Bearer test-key', $captured['headers']['Authorization'] ?? null);
        self::assertSame('application/json', $captured['headers']['Content-Type'] ?? null);
    }

    // ==================== discoverOpenAI transformation pinning ====================

    #[Test]
    public function discoverOpenAiReturnsFallbackOnNonOkStatusEvenWithParseableBody(): void
    {
        // A non-200 status must short-circuit to the fallback catalog and NOT
        // parse the (otherwise valid) body — proven by a body whose model id is
        // absent from the fallback list.
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                500,
                (string)json_encode(['data' => [['id' => 'gpt-4.1']]]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertTrue($this->subject->wasLastDiscoveryFromFallback());
        $modelIds = array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models);
        self::assertContains('gpt-5.5', $modelIds);
        self::assertNotContains('gpt-4.1', $modelIds);
    }

    #[Test]
    public function discoverOpenAiSortsRecommendedModelsFirst(): void
    {
        // Insertion order is [non-recommended, recommended]; the usort must move
        // the recommended model to the front. Kills both the removed-sort and the
        // flipped-comparison mutants.
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                200,
                (string)json_encode(['data' => [['id' => 'gpt-4o'], ['id' => 'gpt-5.5']]]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertSame('gpt-5.5', $models[0]->modelId);
        self::assertTrue($models[0]->recommended);
    }

    #[Test]
    public function discoverOpenAiContinuesPastNonArrayItemsWithoutStopping(): void
    {
        // A non-array entry must be skipped (continue), NOT terminate the loop
        // (break). A trailing, fallback-absent model id proves the loop kept
        // going after the bad entry.
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                200,
                (string)json_encode(['data' => ['not-an-array', ['id' => 'gpt-4.1']]]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertFalse($this->subject->wasLastDiscoveryFromFallback());
        $modelIds = array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models);
        self::assertContains('gpt-4.1', $modelIds);
    }

    #[Test]
    public function discoverOpenAiEnrichesEverySpecWithExactValues(): void
    {
        // Pin every field of the June-2026 OpenAI spec table so that a change to
        // any name/description/capability/context/token/cost/recommended value is
        // caught. Values mirror ModelDiscovery::openAIModelSpecs().
        // [name, description, capabilities, contextLength, maxOutputTokens, costInput, costOutput, recommended]
        $expected = [
            'gpt-5.5' => ['GPT-5.5', 'Latest flagship model with enhanced reasoning', ['chat', 'vision', 'tools', 'streaming', 'reasoning'], 400000, 128000, 500, 3000, true],
            'gpt-5.3' => ['GPT-5.3', 'Flagship model with enhanced reasoning', ['chat', 'vision', 'tools', 'streaming', 'reasoning'], 400000, 128000, 175, 1400, true],
            'gpt-5.3-chat-latest' => ['GPT-5.3 Chat', 'Fast responses for interactive use', ['chat', 'vision', 'tools', 'streaming'], 400000, 32000, 100, 400, true],
            'gpt-5.3-mini' => ['GPT-5.3 Mini', 'Small, fast, cost-effective', ['chat', 'vision', 'tools', 'streaming'], 200000, 32000, 30, 120, true],
            'gpt-5.2' => ['GPT-5.2 Thinking', 'Flagship model for coding, reasoning, and agentic tasks', ['chat', 'vision', 'tools', 'streaming', 'reasoning'], 400000, 128000, 175, 1400, false],
            'gpt-5.2-pro' => ['GPT-5.2 Pro', 'Extended thinking for complex tasks', ['chat', 'vision', 'tools', 'streaming', 'reasoning'], 400000, 128000, 350, 2800, false],
            'gpt-5.2-chat-latest' => ['GPT-5.2 Instant', 'Fast responses for interactive use', ['chat', 'vision', 'tools', 'streaming'], 400000, 32000, 100, 400, false],
            'gpt-5' => ['GPT-5', 'Previous generation flagship model', ['chat', 'vision', 'tools', 'streaming', 'reasoning'], 200000, 64000, 150, 600, false],
            'gpt-5-mini' => ['GPT-5 Mini', 'Smaller, faster, cost-effective', ['chat', 'vision', 'tools', 'streaming'], 128000, 32000, 30, 120, false],
            'o4-mini' => ['O4 Mini', 'Fast reasoning for math, coding, visual tasks', ['chat', 'vision', 'tools', 'reasoning'], 200000, 100000, 110, 440, false],
            'o3' => ['O3', 'Advanced reasoning model', ['chat', 'vision', 'tools', 'reasoning'], 200000, 100000, 200, 800, false],
            'gpt-4o' => ['GPT-4o', 'Legacy multimodal model', ['chat', 'vision', 'tools', 'streaming'], 128000, 16384, 250, 1000, false],
            'gpt-4.1' => ['GPT-4.1', 'Coding and instruction-following model', ['chat', 'vision', 'tools', 'streaming'], 1047576, 32768, 200, 800, false],
            'gpt-4.1-mini' => ['GPT-4.1 Mini', 'Fast coding model', ['chat', 'vision', 'tools', 'streaming'], 1047576, 32768, 40, 160, false],
            'gpt-image-2' => ['GPT Image 2', 'Image generation model', ['image'], 0, 0, 0, 0, false],
            'tts-1' => ['TTS-1', 'Text-to-speech model optimized for speed', ['text_to_speech'], 0, 0, 0, 0, false],
            'tts-1-hd' => ['TTS-1 HD', 'Text-to-speech model optimized for quality', ['text_to_speech'], 0, 0, 0, 0, false],
            'whisper-1' => ['Whisper', 'Speech-to-text transcription model', ['transcription'], 0, 0, 0, 0, false],
        ];

        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $data = array_map(static fn(string $id): array => ['id' => $id], array_keys($expected));
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                200,
                (string)json_encode(['data' => $data]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        $byId = [];
        foreach ($models as $model) {
            $byId[$model->modelId] = $model;
        }

        foreach ($expected as $id => $spec) {
            self::assertArrayHasKey($id, $byId, sprintf('Model %s missing from discovery result', $id));
            $model = $byId[$id];
            self::assertSame($spec[0], $model->name, $id . ' name');
            self::assertSame($spec[1], $model->description, $id . ' description');
            self::assertSame($spec[2], $model->capabilities, $id . ' capabilities');
            self::assertSame($spec[3], $model->contextLength, $id . ' contextLength');
            self::assertSame($spec[4], $model->maxOutputTokens, $id . ' maxOutputTokens');
            self::assertSame($spec[5], $model->costInput, $id . ' costInput');
            self::assertSame($spec[6], $model->costOutput, $id . ' costOutput');
            self::assertSame($spec[7], $model->recommended, $id . ' recommended');
        }
    }

    #[Test]
    public function discoverOpenAiEnrichesUnspecifiedModelWithDefaults(): void
    {
        // A relevant model with no spec entry falls back to modelId-as-name,
        // a generic description, chat capability and zeroed numeric fields.
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                200,
                (string)json_encode(['data' => [['id' => 'chatgpt-4o-latest']]]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        $model = $models[0];
        self::assertSame('chatgpt-4o-latest', $model->modelId);
        self::assertSame('chatgpt-4o-latest', $model->name);
        self::assertSame('OpenAI model', $model->description);
        self::assertSame(['chat'], $model->capabilities);
        self::assertSame(0, $model->contextLength);
        self::assertSame(0, $model->maxOutputTokens);
        self::assertSame(0, $model->costInput);
        self::assertSame(0, $model->costOutput);
        self::assertFalse($model->recommended);
    }

    #[Test]
    public function discoverOpenAiDerivesDefaultCapabilitiesFromModelIdShape(): void
    {
        // Unspecified models get their capability from the id shape
        // (defaultOpenAICapabilities): image / text_to_speech / transcription.
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                200,
                (string)json_encode(['data' => [
                    ['id' => 'dall-e-3'],
                    ['id' => 'gpt-image-1'],
                    ['id' => 'gpt-4o-mini-tts'],
                    ['id' => 'gpt-4o-transcribe'],
                ]]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        $byId = [];
        foreach ($models as $model) {
            $byId[$model->modelId] = $model;
        }

        self::assertSame(['image'], $byId['dall-e-3']->capabilities);
        self::assertSame(['image'], $byId['gpt-image-1']->capabilities);
        self::assertSame(['text_to_speech'], $byId['gpt-4o-mini-tts']->capabilities);
        self::assertSame(['transcription'], $byId['gpt-4o-transcribe']->capabilities);
    }

    // ==================== diagnostic logging pinning ====================

    private function makeSubjectWithLogger(LoggerInterface $logger): ModelDiscovery
    {
        $subject = new ModelDiscovery(
            $this->vaultStub,
            $this->httpClientFactory,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
            $logger,
        );
        $subject->setHttpClient($this->httpClientStub);

        return $subject;
    }

    #[Test]
    public function discoverOpenAiLogsHttpErrorWithProviderAndStatus(): void
    {
        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(503, '{}'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'LLM model discovery returned an unexpected HTTP status',
                self::callback(static fn(array $ctx): bool => ($ctx['provider'] ?? null) === 'openai' && ($ctx['status'] ?? null) === 503),
            );

        $subject = $this->makeSubjectWithLogger($logger);
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $subject->discover($provider, 'test-key');
    }

    #[Test]
    public function discoverOpenAiLogsRequestFailureWithExceptionClass(): void
    {
        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'LLM model discovery request failed',
                self::callback(static fn(array $ctx): bool => ($ctx['provider'] ?? null) === 'openai'
                    && ($ctx['exception'] ?? null) === RuntimeException::class
                    && ($ctx['message'] ?? null) === 'boom'),
            );

        $subject = $this->makeSubjectWithLogger($logger);
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $subject->discover($provider, 'test-key');
    }

    #[Test]
    public function testConnectionLogsSanitizedExceptionWithProvider(): void
    {
        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('network detail'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'LLM setup-wizard connection test failed',
                self::callback(static fn(array $ctx): bool => ($ctx['provider'] ?? null) === 'openai'
                    && array_key_exists('exception', $ctx)),
            );

        $subject = $this->makeSubjectWithLogger($logger);
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $subject->testConnection($provider, 'test-key');
    }

    #[Test]
    public function discoverOpenAiLogsMalformedJsonWithProviderAndBodySample(): void
    {
        // The malformed-JSON warning records the provider and a 200-char sample
        // taken from the START of the body.
        $body = 'A' . str_repeat('x', 300);

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $body));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'LLM model discovery received a malformed JSON response',
                self::callback(static fn(array $ctx): bool => ($ctx['provider'] ?? null) === 'openai'
                    && ($ctx['sample'] ?? null) === substr($body, 0, 200)
                    && is_string($ctx['message'] ?? null)
                    && ($ctx['message'] ?? '') !== ''),
            );

        $subject = $this->makeSubjectWithLogger($logger);
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $subject->discover($provider, 'test-key');
    }

    // ==================== decodeModelListBody JSON depth boundary ====================

    #[Test]
    public function discoverOpenAiDecodesBodyNestedExactlyAtJsonDepthLimit(): void
    {
        // decodeModelListBody() decodes with the PHP-default maximum depth of
        // 512. This body needs exactly depth 512 (object wrapper + 510 nested
        // arrays around a scalar — boundary verified against json_decode): it
        // must decode and yield a LIVE result. A lower limit would reject it
        // as malformed and silently degrade to the fallback catalog, which
        // contains no gpt-4.1 entry.
        $pad = str_repeat('[', 510) . '1' . str_repeat(']', 510);
        $body = '{"data":[{"id":"gpt-4.1"}],"pad":' . $pad . '}';

        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $body));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertFalse($this->subject->wasLastDiscoveryFromFallback());
        $modelIds = array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models);
        self::assertContains('gpt-4.1', $modelIds);
    }

    #[Test]
    public function discoverOpenAiFallsBackWhenBodyExceedsJsonDepthLimit(): void
    {
        // One nesting level beyond the depth limit (511 nested arrays inside
        // the object wrapper → depth 513) must be rejected as malformed JSON
        // and degrade to the fallback catalog. A higher limit would decode the
        // body and surface the live gpt-4.1 entry instead.
        $pad = str_repeat('[', 511) . '1' . str_repeat(']', 511);
        $body = '{"data":[{"id":"gpt-4.1"}],"pad":' . $pad . '}';

        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, $body));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertTrue($this->subject->wasLastDiscoveryFromFallback());
        $modelIds = array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models);
        self::assertNotContains('gpt-4.1', $modelIds);
        self::assertContains('gpt-5.5', $modelIds);
    }

    // ==================== discoverOpenAI data-shape edge cases ====================

    #[Test]
    public function discoverOpenAiReturnsFallbackWhenDataKeyMissing(): void
    {
        // An array body WITHOUT a 'data' key must yield an empty data list
        // (→ fallback) without touching the missing key — accessing
        // $data['data'] unguarded would raise an undefined-array-key warning
        // (failOnWarning turns that into a failure).
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{"object":"list"}'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertTrue($this->subject->wasLastDiscoveryFromFallback());
        $modelIds = array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models);
        self::assertContains('gpt-5.5', $modelIds);
    }

    #[Test]
    public function discoverOpenAiSkipsNonStringModelIdWithoutFailing(): void
    {
        // A non-string id must be skipped BEFORE the relevance check — passing
        // the int into isRelevantOpenAIModel() would raise a TypeError that
        // degrades the whole discovery to the fallback catalog (which contains
        // no gpt-4.1).
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                200,
                (string)json_encode(['data' => [['id' => 123], ['id' => 'gpt-4.1']]]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertFalse($this->subject->wasLastDiscoveryFromFallback());
        $modelIds = array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models);
        self::assertContains('gpt-4.1', $modelIds);
    }

    #[Test]
    public function discoverOpenAiExcludesRealtimeModels(): void
    {
        // 'gpt-realtime' matches none of the include patterns and carries the
        // 'realtime' exclusion marker on its own (no '-search'), so it must be
        // filtered — the realtime arm must exclude independently of the
        // '-search' arm.
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                200,
                (string)json_encode(['data' => [['id' => 'gpt-realtime'], ['id' => 'gpt-4.1']]]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        $modelIds = array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models);
        self::assertNotContains('gpt-realtime', $modelIds);
        self::assertContains('gpt-4.1', $modelIds);
    }

    #[Test]
    public function discoverOpenAiDerivesTextToSpeechCapabilityForDatedTtsVariant(): void
    {
        // 'tts-1-1106' (dated TTS release) has no spec entry and does NOT end
        // in '-tts', so its capability must come from the 'tts-' PREFIX arm of
        // defaultOpenAICapabilities().
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                200,
                (string)json_encode(['data' => [['id' => 'tts-1-1106']]]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertCount(1, $models);
        self::assertSame(['text_to_speech'], $models[0]->capabilities);
    }

    // ==================== discoverAnthropic transformation pinning ====================

    #[Test]
    public function discoverAnthropicBuildsModelsUrlWithAuthHeaders(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $captured = [];
        $this->configureCapturingRequestFactory($captured);
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{"data": []}'));

        $this->subject->discover($provider, 'test-api-key');

        self::assertSame('GET', $captured['method']);
        self::assertSame('https://api.anthropic.com/v1/models', $captured['uri']);
        self::assertSame('test-api-key', $captured['headers']['x-api-key'] ?? null);
        self::assertSame('2023-06-01', $captured['headers']['anthropic-version'] ?? null);
        // Anthropic must NOT get a Bearer Authorization header.
        self::assertArrayNotHasKey('Authorization', $captured['headers']);
    }

    #[Test]
    public function discoverAnthropicReturnsFallbackOnNonOkStatusEvenWithParseableBody(): void
    {
        // A non-200 status must short-circuit to the fallback catalog and NOT
        // parse the (otherwise valid) body — proven by a body whose model id
        // is absent from the fallback list.
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                401,
                (string)json_encode(['data' => [['id' => 'claude-3-haiku-20240307']]]),
            ));

        $models = $this->subject->discover($provider, 'bad-key');

        self::assertTrue($this->subject->wasLastDiscoveryFromFallback());
        $modelIds = array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models);
        self::assertNotContains('claude-3-haiku-20240307', $modelIds);
        self::assertContains('claude-opus-4-5-20251101', $modelIds);
    }

    #[Test]
    public function discoverAnthropicReturnsFallbackWhenDataKeyMissing(): void
    {
        // An array body WITHOUT a 'data' key must yield an empty model list
        // (→ fallback) without touching the missing key (see the matching
        // OpenAI test for the warning rationale).
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(200, '{"object":"list"}'));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertTrue($this->subject->wasLastDiscoveryFromFallback());
        $modelIds = array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models);
        self::assertContains('claude-opus-4-5-20251101', $modelIds);
    }

    #[Test]
    public function discoverAnthropicSortsRecommendedModelsFirst(): void
    {
        // Insertion order is [non-recommended, recommended]; the usort must
        // move the recommended model to the front. Kills both the removed-sort
        // and the flipped-comparison mutants.
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                200,
                (string)json_encode(['data' => [
                    ['id' => 'claude-sonnet-4-20250514'],
                    ['id' => 'claude-opus-4-5-20251101'],
                ]]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertCount(2, $models);
        self::assertSame('claude-opus-4-5-20251101', $models[0]->modelId);
        self::assertTrue($models[0]->recommended);
        self::assertSame('claude-sonnet-4-20250514', $models[1]->modelId);
    }

    #[Test]
    public function discoverAnthropicEnrichesEverySpecWithExactValues(): void
    {
        // Pin every field of the Anthropic spec table (enrichAnthropicModel)
        // via the dated model ids the API actually returns — each resolves its
        // spec through the prefix match, and without a display_name the name
        // must come from the spec. Values mirror ModelDiscovery's spec table.
        // [name, description, costInput, costOutput, recommended]
        $expected = [
            'claude-opus-4-5-20251101' => ['Claude Opus 4.5', 'Most intelligent, best for coding, agents, and computer use', 500, 2500, true],
            'claude-sonnet-4-5-20250929' => ['Claude Sonnet 4.5', 'Balanced performance and cost', 300, 1500, true],
            'claude-haiku-4-5-20251001' => ['Claude Haiku 4.5', 'Fast and cost-effective for simple tasks', 100, 500, true],
            'claude-opus-4-20250514' => ['Claude Opus 4', 'Previous generation Opus', 1500, 7500, false],
            'claude-sonnet-4-20250514' => ['Claude Sonnet 4', 'Previous generation Sonnet', 300, 1500, false],
        ];

        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $data = array_map(static fn(string $id): array => ['id' => $id], array_keys($expected));
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(
                200,
                (string)json_encode(['data' => $data]),
            ));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertFalse($this->subject->wasLastDiscoveryFromFallback());
        $byId = [];
        foreach ($models as $model) {
            $byId[$model->modelId] = $model;
        }

        foreach ($expected as $id => $spec) {
            self::assertArrayHasKey($id, $byId, sprintf('Model %s missing from discovery result', $id));
            $model = $byId[$id];
            self::assertSame($spec[0], $model->name, $id . ' name');
            self::assertSame($spec[1], $model->description, $id . ' description');
            self::assertSame(['chat', 'vision', 'tools', 'streaming'], $model->capabilities, $id . ' capabilities');
            self::assertSame(200000, $model->contextLength, $id . ' contextLength');
            self::assertSame(32000, $model->maxOutputTokens, $id . ' maxOutputTokens');
            self::assertSame($spec[2], $model->costInput, $id . ' costInput');
            self::assertSame($spec[3], $model->costOutput, $id . ' costOutput');
            self::assertSame($spec[4], $model->recommended, $id . ' recommended');
        }
    }

    #[Test]
    public function discoverAnthropicLogsHttpErrorWithProviderAndStatus(): void
    {
        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseStubForDiscovery(503, '{}'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'LLM model discovery returned an unexpected HTTP status',
                self::callback(static fn(array $ctx): bool => ($ctx['provider'] ?? null) === 'anthropic' && ($ctx['status'] ?? null) === 503),
            );

        $subject = $this->makeSubjectWithLogger($logger);
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $subject->discover($provider, 'test-key');
    }

    #[Test]
    public function discoverAnthropicLogsRequestFailureWithExceptionClass(): void
    {
        $this->requestFactoryStub
            ->method('createRequest')
            ->willReturn($this->createRequestMock());
        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('anthropic down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'LLM model discovery request failed',
                self::callback(static fn(array $ctx): bool => ($ctx['provider'] ?? null) === 'anthropic'
                    && ($ctx['exception'] ?? null) === RuntimeException::class
                    && ($ctx['message'] ?? null) === 'anthropic down'),
            );

        $subject = $this->makeSubjectWithLogger($logger);
        $provider = new DetectedProvider(
            adapterType: 'anthropic',
            endpoint: 'https://api.anthropic.com/v1',
            suggestedName: 'Anthropic',
        );

        $subject->discover($provider, 'test-key');
    }
}
