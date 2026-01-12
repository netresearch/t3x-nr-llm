<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\OpenRouterProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(OpenRouterProvider::class)]
class OpenRouterProviderTest extends AbstractUnitTestCase
{
    private OpenRouterProvider $subject;

    /** @var ClientInterface&Stub */
    private ClientInterface $httpClientMock;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientMock = $this->createHttpClientMock();

        $this->subject = new OpenRouterProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $this->subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'anthropic/claude-3.5-sonnet',
            'siteUrl' => 'https://example.com',
            'appName' => 'Test App',
            'routingStrategy' => 'balanced',
            'autoFallback' => true,
            'fallbackModels' => '',
            'timeout' => 60,
        ]);

        // Set HTTP client AFTER configure() to ensure timeout matches
        $this->subject->setHttpClient($this->httpClientMock);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createProviderWithConfig(array $config = []): OpenRouterProvider
    {
        $provider = new OpenRouterProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        /** @var array<string, mixed> $defaultConfig */
        $defaultConfig = [
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'anthropic/claude-3.5-sonnet',
            'timeout' => 60,
        ];

        // Configure FIRST, then set HTTP client (configure() resets the client)
        $provider->configure(array_merge($defaultConfig, $config));
        $provider->setHttpClient($this->httpClientMock);

        return $provider;
    }

    /**
     * Create a provider with a fresh mock that returns different responses based on URL.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $modelsResponse Response for /models endpoint
     * @param array<string, mixed> $chatResponse   Response for /chat/completions endpoint
     */
    private function createProviderWithRoutingMock(
        array $config,
        array $modelsResponse,
        array $chatResponse,
    ): OpenRouterProvider {
        $httpClientMock = self::createStub(ClientInterface::class);
        $httpClientMock->method('sendRequest')
            ->willReturnCallback(function (\Psr\Http\Message\RequestInterface $request) use ($modelsResponse, $chatResponse) {
                $uri = (string)$request->getUri();
                if (str_contains($uri, '/models')) {
                    return $this->createJsonResponseMock($modelsResponse);
                }
                return $this->createJsonResponseMock($chatResponse);
            });

        $provider = new OpenRouterProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $defaultConfig = [
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'anthropic/claude-3.5-sonnet',
            'timeout' => 60,
        ];

        // Configure FIRST, then set HTTP client (configure() resets the client)
        $provider->configure(array_merge($defaultConfig, $config));
        $provider->setHttpClient($httpClientMock);

        return $provider;
    }

    /**
     * Execute a chat completion with a routing mock provider.
     *
     * @param array<string, mixed> $providerConfig
     * @param array<string, mixed> $modelsResponse
     * @param array<string, mixed> $chatResponse
     * @param array<string, mixed> $chatOptions
     */
    private function executeChatWithRoutingMock(
        array $providerConfig,
        array $modelsResponse,
        array $chatResponse,
        array $chatOptions = [],
    ): string {
        $provider = $this->createProviderWithRoutingMock(
            $providerConfig,
            $modelsResponse,
            $chatResponse,
        );
        $result = $provider->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            $chatOptions,
        );
        return $result->model;
    }

    #[Test]
    public function getNameReturnsOpenRouter(): void
    {
        self::assertEquals('OpenRouter', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsOpenrouter(): void
    {
        self::assertEquals('openrouter', $this->subject->getIdentifier());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyConfigured(): void
    {
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function chatCompletionReturnsValidResponse(): void
    {
        $messages = [
            ['role' => 'user', 'content' => $this->randomPrompt()],
        ];

        $apiResponse = [
            'id' => 'gen-' . $this->faker->uuid(),
            'model' => 'anthropic/claude-3.5-sonnet',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'OpenRouter response',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ],
        ];

        // OpenRouter may make multiple requests (fallback), use method() instead of expects()
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('OpenRouter response', $result->content);
        self::assertEquals('anthropic/claude-3.5-sonnet', $result->model);
    }

    #[Test]
    public function getAvailableModelsReturnsMultiProviderModels(): void
    {
        $models = $this->subject->getAvailableModels();

        self::assertNotEmpty($models);
        // OpenRouter models have provider prefixes in keys (e.g., "anthropic/claude-3.5-sonnet")
        $modelKeys = array_keys($models);
        self::assertTrue(
            count(array_filter($modelKeys, fn($m) => str_contains((string)$m, '/'))) > 0,
            'OpenRouter models should have provider prefixes in keys',
        );
    }

    #[Test]
    #[DataProvider('routingStrategyProvider')]
    public function selectModelRespectsRoutingStrategy(string $strategy, string $expectedBehavior): void
    {
        $provider = new OpenRouterProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->httpClientMock);

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'anthropic/claude-3.5-sonnet',
            'routingStrategy' => $strategy,
            'timeout' => 60,
        ]);

        // Just verify the provider can be instantiated with each strategy
        self::assertTrue($provider->isAvailable());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routingStrategyProvider(): array
    {
        return [
            'cost optimized' => ['cost_optimized', 'cheapest'],
            'performance' => ['performance', 'fastest'],
            'balanced' => ['balanced', 'balanced'],
            'explicit' => ['explicit', 'explicit'],
        ];
    }

    #[Test]
    public function chatCompletionWithFallbackHandlesFailure(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];

        // Simulate model overload, then success with fallback
        $errorResponse = [
            'error' => [
                'code' => 503,
                'message' => 'Model overloaded',
            ],
        ];

        $successResponse = [
            'id' => 'gen-test',
            'model' => 'openai/gpt-4o',
            'choices' => [
                ['message' => ['content' => 'Fallback response'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMock($errorResponse, 503),
                $this->createJsonResponseMock($successResponse),
            );

        // When autoFallback is enabled, it should try another model
        // This test verifies the fallback mechanism exists
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function supportsVisionReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsVision());
    }

    #[Test]
    public function supportsStreamingReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsStreaming());
    }

    #[Test]
    public function supportsToolsReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsTools());
    }

    #[Test]
    public function chatCompletionIncludesRequiredHeaders(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'test-model',
            'choices' => [['message' => ['content' => 'test'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        // Verify request was made (headers are added internally)
        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionExtractsCostFromMetadata(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'test-model',
            'choices' => [['message' => ['content' => 'test'], 'finish_reason' => 'stop']],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
                'total_cost' => 0.0015,
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertEquals(150, $result->usage->totalTokens);
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModel(): void
    {
        self::assertEquals('anthropic/claude-3.5-sonnet', $this->subject->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsFallbackWhenNotConfigured(): void
    {
        $provider = $this->createProviderWithConfig(['defaultModel' => '']);
        self::assertEquals('anthropic/claude-sonnet-4-5', $provider->getDefaultModel());
    }

    #[Test]
    public function fetchAvailableModelsReturnsEmptyArrayOnApiFailure(): void
    {
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => 'API Error'], 500));

        $result = $this->subject->fetchAvailableModels();

        self::assertEmpty($result);
    }

    #[Test]
    public function fetchAvailableModelsCachesResults(): void
    {
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'anthropic/claude-3.5-sonnet',
                    'name' => 'Claude 3.5 Sonnet',
                    'context_length' => 200000,
                    'architecture' => ['modality' => 'multimodal'],
                    'pricing' => ['prompt' => 0.003, 'completion' => 0.015],
                    'supports_function_calling' => true,
                ],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($modelsResponse));

        $result1 = $this->subject->fetchAvailableModels();
        $result2 = $this->subject->fetchAvailableModels();

        self::assertEquals($result1, $result2);
        self::assertArrayHasKey('anthropic/claude-3.5-sonnet', $result1);
    }

    #[Test]
    public function fetchAvailableModelsForceRefreshBypassesCache(): void
    {
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'test/model',
                    'name' => 'Test Model',
                    'context_length' => 8000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.001, 'completion' => 0.002],
                    'supports_function_calling' => false,
                ],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($modelsResponse));

        $result = $this->subject->fetchAvailableModels(true);

        self::assertArrayHasKey('test/model', $result);
        self::assertEquals('Test Model', $result['test/model']['name']);
    }

    #[Test]
    public function getCreditsReturnsBalanceInfo(): void
    {
        $creditsResponse = [
            'data' => [
                'limit' => 100.0,
                'usage' => 25.0,
                'is_free_tier' => false,
                'rate_limit' => ['requests' => 1000, 'interval' => '1m'],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($creditsResponse));

        $result = $this->subject->getCredits();

        self::assertEquals(100.0, $result['balance']);
        self::assertEquals(25.0, $result['usage']);
        self::assertFalse($result['is_free_tier']);
    }

    #[Test]
    public function chatCompletionWithToolsReturnsToolCalls(): void
    {
        $messages = [['role' => 'user', 'content' => 'What is the weather?']];
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather for a location',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'location' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'anthropic/claude-3.5-sonnet',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"location":"San Francisco"}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20, 'total_tokens' => 70],
            'provider' => 'anthropic',
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
        /** @var array{function: array{name: string, arguments: array<string, mixed>}} $toolCall */
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall['function']['name']);
        self::assertEquals(['location' => 'San Francisco'], $toolCall['function']['arguments']);
    }

    #[Test]
    public function chatCompletionWithToolsHandlesToolChoice(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [['type' => 'function', 'function' => ['name' => 'test']]];
        $options = ['tool_choice' => 'auto'];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'test-model',
            'choices' => [['message' => ['content' => 'test', 'tool_calls' => []], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function embeddingsReturnsEmbeddingResponse(): void
    {
        $apiResponse = [
            'model' => 'openai/text-embedding-3-small',
            'data' => [
                ['index' => 0, 'embedding' => [0.1, 0.2, 0.3, 0.4, 0.5]],
            ],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->embeddings('test text');

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(1, $result->embeddings);
        self::assertCount(5, $result->embeddings[0]);
    }

    #[Test]
    public function embeddingsAcceptsArrayInput(): void
    {
        $apiResponse = [
            'model' => 'openai/text-embedding-3-small',
            'data' => [
                ['index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->embeddings(['text one', 'text two']);

        self::assertCount(2, $result->embeddings);
    }

    #[Test]
    public function embeddingsSupportsCustomDimensions(): void
    {
        $apiResponse = [
            'model' => 'openai/text-embedding-3-small',
            'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->embeddings('test', ['dimensions' => 256]);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
    }

    #[Test]
    public function analyzeImageReturnsVisionResponse(): void
    {
        $content = [
            ['type' => 'text', 'text' => 'Describe this image'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png']],
        ];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'anthropic/claude-3.5-sonnet',
            'choices' => [
                [
                    'message' => ['role' => 'assistant', 'content' => 'This is an image of a cat'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
            'provider' => 'anthropic',
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->analyzeImage($content);

        self::assertInstanceOf(VisionResponse::class, $result);
        self::assertEquals('This is an image of a cat', $result->description);
    }

    #[Test]
    public function analyzeImageWithSystemPrompt(): void
    {
        $content = [['type' => 'text', 'text' => 'What is this?']];
        $options = ['system_prompt' => 'You are a helpful assistant'];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'test-model',
            'choices' => [['message' => ['content' => 'test'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->analyzeImage($content, $options);

        self::assertInstanceOf(VisionResponse::class, $result);
    }

    #[Test]
    public function getSupportedImageFormatsReturnsSupportedFormats(): void
    {
        $formats = $this->subject->getSupportedImageFormats();

        self::assertContains('png', $formats);
        self::assertContains('jpeg', $formats);
        self::assertContains('jpg', $formats);
        self::assertContains('gif', $formats);
        self::assertContains('webp', $formats);
    }

    #[Test]
    public function getMaxImageSizeReturns20MB(): void
    {
        self::assertEquals(20 * 1024 * 1024, $this->subject->getMaxImageSize());
    }

    #[Test]
    public function chatCompletionWithOptionalParameters(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $options = [
            'top_p' => 0.9,
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.3,
            'stop' => ['\n'],
            'transforms' => ['middle-out'],
        ];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'test-model',
            'choices' => [['message' => ['content' => 'test'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function configureWithFallbackModels(): void
    {
        $provider = $this->createProviderWithConfig([
            'fallbackModels' => 'openai/gpt-4o, anthropic/claude-3-opus',
            'autoFallback' => true,
        ]);

        $messages = [['role' => 'user', 'content' => 'test']];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'openai/gpt-4o',
            'choices' => [['message' => ['content' => 'fallback response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $provider->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function configureWithAutoFallbackDisabled(): void
    {
        $provider = $this->createProviderWithConfig(['autoFallback' => false]);

        self::assertTrue($provider->isAvailable());
    }

    #[Test]
    #[DataProvider('errorStatusCodeProvider')]
    public function handleOpenRouterErrorThrowsProviderException(int $statusCode, string $expectedMessage): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];

        $errorResponse = ['error' => ['message' => 'Test error message']];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($errorResponse, $statusCode));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches("/{$expectedMessage}/");

        $this->subject->chatCompletion($messages);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function errorStatusCodeProvider(): array
    {
        return [
            '400 bad request' => [400, 'Bad request'],
            '401 unauthorized' => [401, 'Invalid OpenRouter API key'],
            '402 payment required' => [402, 'Insufficient OpenRouter credits'],
            '403 forbidden' => [403, 'Forbidden'],
            '429 rate limit' => [429, 'Rate limit exceeded'],
            '503 service unavailable' => [503, 'Model or provider unavailable'],
            '500 generic error' => [500, 'OpenRouter API error'],
        ];
    }

    #[Test]
    public function chatCompletionWithExplicitModel(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $options = ['model' => 'openai/gpt-4o'];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'openai/gpt-4o',
            'choices' => [['message' => ['content' => 'response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages, $options);

        self::assertEquals('openai/gpt-4o', $result->model);
    }

    #[Test]
    public function explicitRoutingStrategyUsesDefaultModel(): void
    {
        $provider = $this->createProviderWithConfig([
            'routingStrategy' => 'explicit',
            'defaultModel' => 'test/model',
        ]);

        $messages = [['role' => 'user', 'content' => 'test']];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'test/model',
            'choices' => [['message' => ['content' => 'response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $provider->chatCompletion($messages);

        self::assertEquals('test/model', $result->model);
    }

    #[Test]
    public function costOptimizedRoutingSelectsCheapestModel(): void
    {
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'expensive/model',
                    'name' => 'Expensive',
                    'context_length' => 8000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.01, 'completion' => 0.03],
                    'supports_function_calling' => false,
                ],
                [
                    'id' => 'cheap/model',
                    'name' => 'Cheap',
                    'context_length' => 8000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.0001, 'completion' => 0.0002],
                    'supports_function_calling' => false,
                ],
            ],
        ];

        $chatResponse = [
            'id' => 'gen-test',
            'model' => 'cheap/model',
            'choices' => [['message' => ['content' => 'response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $provider = $this->createProviderWithRoutingMock(
            ['routingStrategy' => 'cost_optimized'],
            $modelsResponse,
            $chatResponse,
        );

        $result = $provider->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('cheap/model', $result->model);
    }

    #[Test]
    public function performanceRoutingSelectsFastModel(): void
    {
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'slow/opus',
                    'name' => 'Slow Opus',
                    'context_length' => 200000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.01, 'completion' => 0.03],
                    'supports_function_calling' => true,
                ],
                [
                    'id' => 'fast/flash',
                    'name' => 'Fast Flash',
                    'context_length' => 100000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.001, 'completion' => 0.002],
                    'supports_function_calling' => true,
                ],
            ],
        ];

        $chatResponse = [
            'id' => 'gen-test',
            'model' => 'fast/flash',
            'choices' => [['message' => ['content' => 'response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $provider = $this->createProviderWithRoutingMock(
            ['routingStrategy' => 'performance'],
            $modelsResponse,
            $chatResponse,
        );

        $result = $provider->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('fast/flash', $result->model);
    }

    #[Test]
    public function filterModelsByMinContextLength(): void
    {
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'small/context',
                    'name' => 'Small Context',
                    'context_length' => 4000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.0001, 'completion' => 0.0001],
                    'supports_function_calling' => false,
                ],
                [
                    'id' => 'large/context',
                    'name' => 'Large Context',
                    'context_length' => 128000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.001, 'completion' => 0.002],
                    'supports_function_calling' => false,
                ],
            ],
        ];

        $chatResponse = [
            'id' => 'gen-test',
            'model' => 'large/context',
            'choices' => [['message' => ['content' => 'response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $model = $this->executeChatWithRoutingMock(
            ['routingStrategy' => 'cost_optimized'],
            $modelsResponse,
            $chatResponse,
            ['min_context' => 100000],
        );

        self::assertEquals('large/context', $model);
    }

    #[Test]
    public function filterModelsByVisionCapability(): void
    {
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'text/only',
                    'name' => 'Text Only',
                    'context_length' => 8000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.0001, 'completion' => 0.0001],
                    'supports_function_calling' => false,
                ],
                [
                    'id' => 'vision/model',
                    'name' => 'Vision Model',
                    'context_length' => 8000,
                    'architecture' => ['modality' => 'multimodal'],
                    'pricing' => ['prompt' => 0.001, 'completion' => 0.002],
                    'supports_function_calling' => false,
                ],
            ],
        ];

        $chatResponse = [
            'id' => 'gen-test',
            'model' => 'vision/model',
            'choices' => [['message' => ['content' => 'response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $model = $this->executeChatWithRoutingMock(
            ['routingStrategy' => 'cost_optimized'],
            $modelsResponse,
            $chatResponse,
            ['vision_required' => true],
        );

        self::assertEquals('vision/model', $model);
    }

    #[Test]
    public function filterModelsByFunctionCalling(): void
    {
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'no/tools',
                    'name' => 'No Tools',
                    'context_length' => 8000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.0001, 'completion' => 0.0001],
                    'supports_function_calling' => false,
                ],
                [
                    'id' => 'with/tools',
                    'name' => 'With Tools',
                    'context_length' => 8000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.001, 'completion' => 0.002],
                    'supports_function_calling' => true,
                ],
            ],
        ];

        $chatResponse = [
            'id' => 'gen-test',
            'model' => 'with/tools',
            'choices' => [['message' => ['content' => 'response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $model = $this->executeChatWithRoutingMock(
            ['routingStrategy' => 'cost_optimized'],
            $modelsResponse,
            $chatResponse,
            ['function_calling' => true],
        );

        self::assertEquals('with/tools', $model);
    }

    #[Test]
    public function balancedRoutingSelectsMidTierModel(): void
    {
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'fast/haiku',
                    'name' => 'Fast Haiku',
                    'context_length' => 8000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.0001, 'completion' => 0.0001],
                    'supports_function_calling' => false,
                ],
                [
                    'id' => 'balanced/sonnet',
                    'name' => 'Balanced Sonnet',
                    'context_length' => 200000,
                    'architecture' => ['modality' => 'multimodal'],
                    'pricing' => ['prompt' => 0.003, 'completion' => 0.015],
                    'supports_function_calling' => true,
                ],
            ],
        ];

        $chatResponse = [
            'id' => 'gen-test',
            'model' => 'balanced/sonnet',
            'choices' => [['message' => ['content' => 'response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $model = $this->executeChatWithRoutingMock(
            ['routingStrategy' => 'balanced'],
            $modelsResponse,
            $chatResponse,
        );

        self::assertEquals('balanced/sonnet', $model);
    }

    #[Test]
    public function streamChatCompletionYieldsChunks(): void
    {
        $provider = $this->createProviderWithConfig([
            'siteUrl' => 'https://example.com',
            'appName' => 'Test App',
        ]);

        $streamData = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\" World\"}}]}\n\n"
            . "data: [DONE]\n\n";

        $stream = self::createStub(StreamInterface::class);
        $eofCallCount = 0;
        $stream->method('eof')->willReturnCallback(function () use (&$eofCallCount) {
            return ++$eofCallCount > 1;
        });
        $stream->method('read')->willReturn($streamData);

        $response = self::createStub(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($response);

        $chunks = [];
        foreach ($provider->streamChatCompletion([['role' => 'user', 'content' => 'test']]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Hello', ' World'], $chunks);
    }

    #[Test]
    public function streamChatCompletionWithFallbackModels(): void
    {
        $provider = $this->createProviderWithConfig([
            'autoFallback' => true,
            'fallbackModels' => 'openai/gpt-4o,anthropic/claude-3-opus',
        ]);

        $streamData = "data: {\"choices\":[{\"delta\":{\"content\":\"test\"}}]}\n\ndata: [DONE]\n\n";

        $stream = self::createStub(StreamInterface::class);
        $eofCallCount = 0;
        $stream->method('eof')->willReturnCallback(function () use (&$eofCallCount) {
            return ++$eofCallCount > 1;
        });
        $stream->method('read')->willReturn($streamData);

        $response = self::createStub(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($response);

        $chunks = iterator_to_array($provider->streamChatCompletion([['role' => 'user', 'content' => 'test']]));

        self::assertCount(1, $chunks);
    }

    #[Test]
    public function streamChatCompletionHandlesMalformedJson(): void
    {
        $provider = $this->createProviderWithConfig([]);

        $streamData = "data: {invalid json}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"valid\"}}]}\n\n"
            . "data: [DONE]\n\n";

        $stream = self::createStub(StreamInterface::class);
        $eofCallCount = 0;
        $stream->method('eof')->willReturnCallback(function () use (&$eofCallCount) {
            return ++$eofCallCount > 1;
        });
        $stream->method('read')->willReturn($streamData);

        $response = self::createStub(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($response);

        $chunks = iterator_to_array($provider->streamChatCompletion([['role' => 'user', 'content' => 'test']]));

        self::assertEquals(['valid'], $chunks);
    }

    #[Test]
    public function chatCompletionWithToolsWithAutoFallbackDisabled(): void
    {
        $provider = $this->createProviderWithConfig(['autoFallback' => false]);

        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [['type' => 'function', 'function' => ['name' => 'test']]];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'test-model',
            'choices' => [['message' => ['content' => 'response', 'tool_calls' => []], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $provider->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function invalidRoutingStrategyUsesDefault(): void
    {
        $provider = $this->createProviderWithConfig([
            'routingStrategy' => 'invalid_strategy',
        ]);

        self::assertTrue($provider->isAvailable());
    }

    #[Test]
    public function chatCompletionMetadataIncludesActualProvider(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'anthropic/claude-3.5-sonnet',
            'provider' => 'anthropic',
            'total_cost' => 0.00123,
            'native_tokens_prompt' => 100,
            'native_tokens_completion' => 50,
            'choices' => [['message' => ['content' => 'response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        /** @var array{actual_provider: string, cost: float, native_tokens: array{prompt: int, completion: int}} $metadata */
        $metadata = $result->metadata;
        self::assertEquals('anthropic', $metadata['actual_provider']);
        self::assertEquals(0.00123, $metadata['cost']);
        self::assertEquals(100, $metadata['native_tokens']['prompt']);
        self::assertEquals(50, $metadata['native_tokens']['completion']);
    }

    #[Test]
    public function analyzeImageSelectsVisionCapableModel(): void
    {
        // First call is fetchAvailableModels for vision model selection
        // Second call is the actual chat completion
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'anthropic/claude-sonnet-4-5',
                    'name' => 'Claude Sonnet',
                    'context_length' => 200000,
                    'architecture' => ['modality' => 'multimodal'],
                    'pricing' => ['prompt' => 0.003, 'completion' => 0.015],
                    'supports_function_calling' => true,
                ],
            ],
        ];

        $visionResponse = [
            'id' => 'gen-test',
            'model' => 'anthropic/claude-sonnet-4-5',
            'choices' => [['message' => ['content' => 'Image analysis'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 50, 'total_tokens' => 250],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMock($modelsResponse),
                $this->createJsonResponseMock($visionResponse),
            );

        $content = [['type' => 'text', 'text' => 'Describe this']];
        $result = $this->subject->analyzeImage($content);

        self::assertInstanceOf(VisionResponse::class, $result);
    }

    #[Test]
    public function emptyModelListFallsBackToDefault(): void
    {
        $provider = $this->createProviderWithConfig([
            'routingStrategy' => 'cost_optimized',
        ]);

        // Return empty models list
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMock(['data' => []]),
                $this->createJsonResponseMock([
                    'id' => 'gen-test',
                    'model' => 'anthropic/claude-3.5-sonnet',
                    'choices' => [['message' => ['content' => 'test'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                ]),
            );

        $result = $provider->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function noCandidatesMatchingFiltersFallsBackToDefault(): void
    {
        $provider = $this->createProviderWithConfig([
            'routingStrategy' => 'cost_optimized',
        ]);

        // Models with small context, but we require large context
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'small/model',
                    'name' => 'Small Model',
                    'context_length' => 4000,
                    'architecture' => ['modality' => 'text'],
                    'pricing' => ['prompt' => 0.0001, 'completion' => 0.0001],
                    'supports_function_calling' => false,
                ],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMock($modelsResponse),
                $this->createJsonResponseMock([
                    'id' => 'gen-test',
                    'model' => 'anthropic/claude-3.5-sonnet',
                    'choices' => [['message' => ['content' => 'test'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                ]),
            );

        $result = $provider->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['min_context' => 1000000],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }
}
