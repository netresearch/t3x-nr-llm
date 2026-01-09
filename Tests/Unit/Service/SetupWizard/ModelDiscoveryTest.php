<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscovery;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

#[CoversClass(ModelDiscovery::class)]
#[CoversClass(DiscoveredModel::class)]
#[CoversClass(DetectedProvider::class)]
class ModelDiscoveryTest extends AbstractUnitTestCase
{
    private ClientInterface&MockObject $httpClientMock;
    private RequestFactoryInterface&MockObject $requestFactoryMock;
    private StreamFactoryInterface&MockObject $streamFactoryMock;
    private ModelDiscovery $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientMock = $this->createMock(ClientInterface::class);
        $this->requestFactoryMock = $this->createMock(RequestFactoryInterface::class);
        $this->streamFactoryMock = $this->createMock(StreamFactoryInterface::class);

        $this->subject = new ModelDiscovery(
            $this->httpClientMock,
            $this->requestFactoryMock,
            $this->streamFactoryMock,
        );
    }

    private function createJsonResponseMockForDiscovery(int $statusCode, string $body): ResponseInterface&MockObject
    {
        $streamMock = $this->createMock(StreamInterface::class);
        $streamMock->method('getContents')->willReturn($body);

        $responseMock = $this->createMock(ResponseInterface::class);
        $responseMock->method('getStatusCode')->willReturn($statusCode);
        $responseMock->method('getBody')->willReturn($streamMock);

        return $responseMock;
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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, '{"data": []}'));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, '{}'));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, '{}'));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, '{}'));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(401, '{"error": "unauthorized"}'));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(500, '{"error": "server error"}'));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = json_encode([
            'data' => [
                ['id' => 'gpt-5.2'],
                ['id' => 'gpt-4o'],
                ['id' => 'o4-mini'],
            ],
        ]);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, $apiResponse));

        $models = $this->subject->discover($provider, 'test-key');

        self::assertNotEmpty($models);
        self::assertContainsOnlyInstancesOf(DiscoveredModel::class, $models);
    }

    #[Test]
    public function discoverOpenAiReturnsFallbackOnError(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'openai',
            endpoint: 'https://api.openai.com',
            suggestedName: 'OpenAI',
        );

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(500, '{}'));

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
        self::assertContains('claude-opus-4-5', $modelIds);
        self::assertContains('claude-sonnet-4-5', $modelIds);
        self::assertContains('claude-haiku-4-5', $modelIds);
    }

    #[Test]
    public function discoverGeminiReturnsModels(): void
    {
        $provider = new DetectedProvider(
            adapterType: 'gemini',
            endpoint: 'https://generativelanguage.googleapis.com',
            suggestedName: 'Google Gemini',
        );

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = json_encode([
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

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, $apiResponse));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(500, '{}'));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamMock = $this->createMock(StreamInterface::class);
        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($streamMock);

        // First call: /api/tags, second call: /api/show
        $tagsResponse = json_encode([
            'models' => [
                ['name' => 'llama3:latest'],
                ['name' => 'qwen:latest'],
            ],
        ]);

        $showResponse = json_encode([
            'model_info' => [
                'context_length' => 32768,
            ],
            'parameters' => 'num_ctx 32768',
        ]);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMockForDiscovery(200, $tagsResponse),
                $this->createJsonResponseMockForDiscovery(200, $showResponse),
                $this->createJsonResponseMockForDiscovery(200, $showResponse),
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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(500, '{}'));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = json_encode([
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

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, $apiResponse));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = json_encode([
            'data' => [
                ['id' => 'mistral-large-latest'],
                ['id' => 'mistral-medium-latest'],
            ],
        ]);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, $apiResponse));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(500, '{}'));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = json_encode([
            'data' => [
                [
                    'id' => 'llama-3.1-70b-versatile',
                    'context_window' => 32768,
                ],
            ],
        ]);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, $apiResponse));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(500, '{}'));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = json_encode([
            'data' => [
                ['id' => 'gpt-5.2'],
                ['id' => 'text-davinci-003'], // Should be filtered out
                ['id' => 'whisper-1'], // Should be filtered out
                ['id' => 'gpt-image-1'], // Should be included
            ],
        ]);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, $apiResponse));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $apiResponse = json_encode([
            'data' => 'invalid', // Not an array
        ]);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMockForDiscovery(200, $apiResponse));

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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamMock = $this->createMock(StreamInterface::class);
        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($streamMock);

        $tagsResponse = json_encode([
            'models' => [
                ['name' => 'llava:latest'],
            ],
        ]);

        $showResponse = json_encode([
            'model_info' => [],
            'parameters' => '',
        ]);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMockForDiscovery(200, $tagsResponse),
                $this->createJsonResponseMockForDiscovery(200, $showResponse),
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

        $this->requestFactoryMock
            ->method('createRequest')
            ->willReturn($this->createRequestMock());

        $streamMock = $this->createMock(StreamInterface::class);
        $this->streamFactoryMock
            ->method('createStream')
            ->willReturn($streamMock);

        $tagsResponse = json_encode([
            'models' => [
                ['name' => 'qwen2:latest'],
            ],
        ]);

        $showResponse = json_encode([
            'model_info' => [],
            'parameters' => 'num_ctx 32768',
        ]);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMockForDiscovery(200, $tagsResponse),
                $this->createJsonResponseMockForDiscovery(200, $showResponse),
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

        self::assertIsArray($array);
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
}
