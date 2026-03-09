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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
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
    private ModelDiscovery $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = self::createStub(ClientInterface::class);
        $this->requestFactoryStub = self::createStub(RequestFactoryInterface::class);
        $this->streamFactoryStub = self::createStub(StreamFactoryInterface::class);

        $this->subject = new ModelDiscovery(
            $this->httpClientStub,
            $this->requestFactoryStub,
            $this->streamFactoryStub,
        );
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
        self::assertStringContainsString('Network error', $result['message']);
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
                ['id' => 'whisper-1'], // Should be filtered out
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
        self::assertNotContains('whisper-1', $modelIds);
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
        $tagsResponseObj = self::createStub(\Psr\Http\Message\ResponseInterface::class);
        $tagsResponseObj->method('getStatusCode')->willReturn(200);
        $tagsResponseObj->method('getBody')->willReturn($tagsStream);

        // HttpClient returns tags on first call; /show is never reached due to streamFactory throwing
        $httpClient = $this->createMock(\Psr\Http\Client\ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($tagsResponseObj);

        // streamFactory->createStream() throws on the call inside getOllamaModelDetails
        $streamFactory = $this->createMock(\Psr\Http\Message\StreamFactoryInterface::class);
        $streamFactory
            ->method('createStream')
            ->willThrowException(new RuntimeException('Stream creation failed'));

        $requestFactory = self::createStub(\Psr\Http\Message\RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($this->createRequestMock());

        $provider = new DetectedProvider(
            adapterType: 'ollama',
            endpoint: 'http://localhost:11434',
            suggestedName: 'Ollama',
        );

        $subject = new ModelDiscovery($httpClient, $requestFactory, $streamFactory);
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
}
