<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use GuzzleHttp\Psr7\HttpFactory;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Exception\ProviderAuthenticationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderRateLimitException;
use Netresearch\NrLlm\Provider\OpenRouterProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

#[CoversClass(OpenRouterProvider::class)]
class OpenRouterProviderTest extends AbstractUnitTestCase
{
    private OpenRouterProvider $subject;

    /** @var ClientInterface&Stub */
    private ClientInterface $httpClientMock;

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
            ->willReturnCallback(function (RequestInterface $request) use ($modelsResponse, $chatResponse) {
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
    public function chatCompletionSendsCustomHeaders(): void
    {
        $capturedRequest = null;
        $httpFactory = new HttpFactory();

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $request;

                return $this->createJsonResponseMock([
                    'id' => 'gen-test',
                    'model' => 'anthropic/claude-3.5-sonnet',
                    'choices' => [
                        [
                            'index' => 0,
                            'message' => ['role' => 'assistant', 'content' => 'ok'],
                            'finish_reason' => 'stop',
                        ],
                    ],
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
                ]);
            });

        $subject = new OpenRouterProvider(
            $httpFactory,
            $httpFactory,
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'anthropic/claude-3.5-sonnet',
            'siteUrl' => 'https://example.com',
            'appName' => 'Test App',
            'customHeaders' => ['X-Custom-Header' => 'custom-value'],
        ]);
        $subject->setHttpClient($httpClient);

        $subject->chatCompletion([['role' => 'user', 'content' => 'hi']]);

        // OpenRouter chat goes through the private sendOpenRouterRequest(),
        // which bypasses AbstractProvider::sendRequest() — the custom header
        // must be applied there, and the attribution headers must survive.
        self::assertInstanceOf(RequestInterface::class, $capturedRequest);
        self::assertSame('custom-value', $capturedRequest->getHeaderLine('X-Custom-Header'));
        self::assertSame('https://example.com', $capturedRequest->getHeaderLine('HTTP-Referer'));
        self::assertSame('Test App', $capturedRequest->getHeaderLine('X-Title'));
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
            ToolSpec::fromArray([
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
            ]),
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
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall->name);
        self::assertEquals(['location' => 'San Francisco'], $toolCall->arguments);
    }

    #[Test]
    public function chatCompletionWithToolsHandlesToolChoice(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test']])];
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
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe this image']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png']]),
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
        $content = [VisionContent::fromArray(['type' => 'text', 'text' => 'What is this?'])];
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

    #[Test]
    public function openRouterMaps401ToProviderAuthenticationException(): void
    {
        // ADR-080: realigned from ProviderConfigurationException.
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'bad key']], 401));

        try {
            $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
            self::fail('Expected ProviderAuthenticationException was not thrown');
        } catch (ProviderAuthenticationException $e) {
            self::assertSame(401, $e->getCode());
        }
    }

    #[Test]
    public function openRouterMaps429ToProviderRateLimitException(): void
    {
        // ADR-080: realigned from ProviderConnectionException; getCode() stays 429.
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'slow down']], 429));

        try {
            $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
            self::fail('Expected ProviderRateLimitException was not thrown');
        } catch (ProviderRateLimitException $e) {
            self::assertSame(429, $e->getCode());
        }
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
            '403 forbidden' => [403, 'Bad request'],
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

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
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

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
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

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
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
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test']])];

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

        $content = [VisionContent::fromArray(['type' => 'text', 'text' => 'Describe this'])];
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

    #[Test]
    public function testConnectionReturnsSuccessWithModelList(): void
    {
        $apiResponse = [
            'data' => [
                ['id' => 'anthropic/claude-sonnet-4-5', 'name' => 'Claude Sonnet 4.5'],
                ['id' => 'openai/gpt-5.2', 'name' => 'GPT-5.2'],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->testConnection();

        self::assertTrue($result['success']);
        self::assertStringContainsString('Connection successful', $result['message']);
        self::assertStringContainsString('2 models', $result['message']);
        self::assertArrayHasKey('models', $result);
        assert(isset($result['models']));
        self::assertArrayHasKey('anthropic/claude-sonnet-4-5', $result['models']);
    }

    #[Test]
    public function testConnectionThrowsOnHttpError(): void
    {
        // A static-list provider must NOT silently report success on an
        // unreachable / unauthorized endpoint: the real HTTP call surfaces
        // the typed exception instead.
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(401, 'No auth credentials found'));

        $this->expectException(ProviderException::class);

        $this->subject->testConnection();
    }

    // ---------------------------------------------------------------------
    // Request-transformation assertions
    //
    // The tests below capture the outgoing PSR-7 request and assert the exact
    // URL, method, headers and decoded JSON payload the provider constructs,
    // plus the exact fields it parses back from the response. This pins down
    // the request/response transformation that the happy-path tests above only
    // exercise without asserting.
    // ---------------------------------------------------------------------

    /**
     * Build a request factory that records every outgoing request
     * (method, URI, headers, JSON body) into $captured.
     *
     * @param list<array{method: string, uri: string, headers: array<string, string>, body: string}> $captured
     */
    private function createCapturingRequestFactory(array &$captured): RequestFactoryInterface
    {
        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturnCallback(
            function (string $method, string $uri) use (&$captured): RequestInterface {
                $index            = count($captured);
                $captured[$index] = ['method' => $method, 'uri' => $uri, 'headers' => [], 'body' => ''];

                $uriStub = self::createStub(UriInterface::class);
                $uriStub->method('__toString')->willReturn($uri);

                $request = self::createStub(RequestInterface::class);
                $request->method('getMethod')->willReturn($method);
                $request->method('getUri')->willReturn($uriStub);
                $request->method('withoutHeader')->willReturnCallback(static fn(): RequestInterface => $request);
                $request->method('withHeader')->willReturnCallback(
                    function (string $name, $value) use (&$captured, $index, $request): RequestInterface {
                        $captured[$index]['headers'][$name] = is_array($value)
                            ? implode(',', $value)
                            : (is_string($value) ? $value : '');
                        return $request;
                    },
                );
                $request->method('withBody')->willReturnCallback(
                    function (StreamInterface $body) use (&$captured, $index, $request): RequestInterface {
                        $captured[$index]['body'] = $body->getContents();
                        return $request;
                    },
                );

                return $request;
            },
        );

        return $requestFactory;
    }

    /**
     * @param array<string, mixed>                                                                   $config
     * @param list<array{method: string, uri: string, headers: array<string, string>, body: string}> $captured
     */
    private function createCapturingProviderWithClient(
        array $config,
        ClientInterface $httpClient,
        array &$captured,
    ): OpenRouterProvider {
        $provider = new OpenRouterProvider(
            $this->createCapturingRequestFactory($captured),
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

        $provider->configure(array_merge($defaultConfig, $config));
        $provider->setHttpClient($httpClient);

        return $provider;
    }

    /**
     * Build a provider whose request factory records every outgoing request
     * (method, URI, headers, JSON body) into $captured.
     *
     * @param array<string, mixed>                                                                   $config
     * @param list<array{method: string, uri: string, headers: array<string, string>, body: string}> $captured
     */
    private function createCapturingProvider(
        array $config,
        ResponseInterface $response,
        array &$captured,
    ): OpenRouterProvider {
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        return $this->createCapturingProviderWithClient($config, $httpClient, $captured);
    }

    /**
     * Capturing provider whose HTTP client answers the /models endpoint with
     * $modelsResponse and every other endpoint with $chatResponse.
     *
     * @param array<string, mixed>                                                                   $config
     * @param array<string, mixed>                                                                   $modelsResponse
     * @param list<array{method: string, uri: string, headers: array<string, string>, body: string}> $captured
     */
    private function createCapturingRoutingProvider(
        array $config,
        array $modelsResponse,
        ResponseInterface $chatResponse,
        array &$captured,
    ): OpenRouterProvider {
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(
            fn(RequestInterface $request): ResponseInterface => str_contains((string)$request->getUri(), '/models')
                    ? $this->createJsonResponseMock($modelsResponse)
                    : $chatResponse,
        );

        return $this->createCapturingProviderWithClient($config, $httpClient, $captured);
    }

    /**
     * Build a streaming (SSE) response stub that serves the given reads in
     * order and reports EOF once they are exhausted.
     *
     * @param list<string> $reads
     */
    private function createSseResponse(array $reads, int $statusCode = 200): ResponseInterface
    {
        $readCount = 0;

        $stream = self::createStub(StreamInterface::class);
        $stream->method('eof')->willReturnCallback(
            static function () use (&$readCount, $reads): bool {
                return $readCount >= count($reads);
            },
        );
        $stream->method('read')->willReturnCallback(
            static function () use (&$readCount, $reads): string {
                return $reads[$readCount++] ?? '';
            },
        );

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    /**
     * Live-model-list entry fixture for the /models endpoint.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function modelEntry(string $id, float $prompt, float $completion, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'name' => $id,
            'context_length' => 8000,
            'architecture' => ['modality' => 'text'],
            'pricing' => ['prompt' => $prompt, 'completion' => $completion],
            'supports_function_calling' => false,
        ];
    }

    /**
     * Run a chat completion through a capturing routing provider and return
     * the model id the provider actually put into the request payload.
     *
     * @param array<string, mixed>       $config
     * @param list<array<string, mixed>> $modelEntries
     * @param array<string, mixed>       $chatOptions
     */
    private function capturedRoutedModel(array $config, array $modelEntries, array $chatOptions = []): string
    {
        $captured = [];
        $provider = $this->createCapturingRoutingProvider(
            $config,
            ['data' => $modelEntries],
            $this->chatResponse(),
            $captured,
        );

        $provider->chatCompletion([['role' => 'user', 'content' => 'Hi']], $chatOptions);

        self::assertArrayHasKey(1, $captured);
        $body = $this->decodeCapturedBody($captured[1]['body']);
        self::assertIsString($body['model']);

        return $body['model'];
    }

    /**
     * Run analyzeImage through a capturing routing provider and return the
     * model id the provider actually put into the request payload.
     *
     * @param array<string, mixed>       $config
     * @param list<array<string, mixed>> $modelEntries
     */
    private function capturedVisionModel(array $config, array $modelEntries): string
    {
        $captured = [];
        $provider = $this->createCapturingRoutingProvider(
            $config,
            ['data' => $modelEntries],
            $this->chatResponse(),
            $captured,
        );

        $provider->analyzeImage([VisionContent::fromArray(['type' => 'text', 'text' => 'Hi'])]);

        self::assertArrayHasKey(1, $captured);
        $body = $this->decodeCapturedBody($captured[1]['body']);
        self::assertIsString($body['model']);

        return $body['model'];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function chatResponse(array $response = []): ResponseInterface
    {
        return $this->createJsonResponseMock($response + [
            'id' => 'gen-test',
            'model' => 'test-model',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeCapturedBody(string $body): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    #[Test]
    public function getAvailableModelsReturnsExactCuratedList(): void
    {
        self::assertSame(
            [
                'anthropic/claude-opus-4-5' => 'Claude Opus 4.5 (Anthropic)',
                'anthropic/claude-sonnet-4-5' => 'Claude Sonnet 4.5 (Anthropic)',
                'anthropic/claude-opus-4-1' => 'Claude Opus 4.1 (Anthropic)',
                'openai/gpt-5.2' => 'GPT-5.2 (OpenAI)',
                'openai/gpt-5.2-pro' => 'GPT-5.2 Pro (OpenAI)',
                'openai/o3' => 'O3 Reasoning (OpenAI)',
                'openai/o4-mini' => 'O4 Mini (OpenAI)',
                'google/gemini-3-flash' => 'Gemini 3 Flash (Google)',
                'google/gemini-3-pro' => 'Gemini 3 Pro (Google)',
                'google/gemini-2.5-flash' => 'Gemini 2.5 Flash (Google)',
                'meta-llama/llama-3.3-70b-instruct' => 'Llama 3.3 70B (Meta)',
                'meta-llama/llama-3.1-405b-instruct' => 'Llama 3.1 405B (Meta)',
                'mistralai/mistral-large' => 'Mistral Large (Mistral AI)',
                'mistralai/pixtral-large' => 'Pixtral Large (Mistral AI)',
                'cohere/command-r-plus' => 'Command R+ (Cohere)',
            ],
            $this->subject->getAvailableModels(),
        );
    }

    #[Test]
    public function chatCompletionBuildsExactRequest(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider(
            [
                'siteUrl' => 'https://example.com',
                'appName' => 'Test App',
            ],
            $this->chatResponse(),
            $captured,
        );

        $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'openai/gpt-4o'],
        );

        self::assertCount(1, $captured);
        $request = $captured[0];

        self::assertSame('POST', $request['method']);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $request['uri']);
        self::assertSame('application/json', $request['headers']['Content-Type'] ?? null);
        self::assertSame('https://example.com', $request['headers']['HTTP-Referer'] ?? null);
        self::assertSame('Test App', $request['headers']['X-Title'] ?? null);

        $body = $this->decodeCapturedBody($request['body']);

        self::assertSame('openai/gpt-4o', $body['model']);
        self::assertSame([['role' => 'user', 'content' => 'Hi']], $body['messages']);
        self::assertSame(0.7, $body['temperature']);
        self::assertSame(4096, $body['max_tokens']);
        self::assertSame('fallback', $body['route']);
        self::assertArrayNotHasKey('models', $body);
        self::assertArrayNotHasKey('stop', $body);
        self::assertArrayNotHasKey('transforms', $body);
        self::assertArrayNotHasKey('top_p', $body);
    }

    #[Test]
    public function chatCompletionPayloadHonorsProvidedOptions(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider([], $this->chatResponse(), $captured);

        $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            [
                'model' => 'openai/gpt-4o',
                'temperature' => 0.2,
                'max_tokens' => 123,
                'top_p' => 0.9,
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
                'stop' => ['STOP'],
                'transforms' => ['middle-out'],
            ],
        );

        $body = $this->decodeCapturedBody($captured[0]['body']);

        self::assertSame(0.2, $body['temperature']);
        self::assertSame(123, $body['max_tokens']);
        self::assertSame(0.9, $body['top_p']);
        self::assertSame(0.5, $body['frequency_penalty']);
        self::assertSame(0.3, $body['presence_penalty']);
        self::assertSame(['STOP'], $body['stop']);
        self::assertSame(['middle-out'], $body['transforms']);
    }

    #[Test]
    public function chatCompletionMapsStopSequencesToStop(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider([], $this->chatResponse(), $captured);

        $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'openai/gpt-4o', 'stop_sequences' => ['END']],
        );

        $body = $this->decodeCapturedBody($captured[0]['body']);

        self::assertSame(['END'], $body['stop']);
    }

    #[Test]
    public function chatCompletionOmitsStopForEmptyStopSequences(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider([], $this->chatResponse(), $captured);

        $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'openai/gpt-4o', 'stop_sequences' => []],
        );

        $body = $this->decodeCapturedBody($captured[0]['body']);

        self::assertArrayNotHasKey('stop', $body);
    }

    #[Test]
    public function chatCompletionIncludesFallbackModelsInPayload(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider(
            [
                'autoFallback' => true,
                'fallbackModels' => 'openai/gpt-4o, anthropic/claude-3-opus',
            ],
            $this->chatResponse(),
            $captured,
        );

        $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        );

        $body = $this->decodeCapturedBody($captured[0]['body']);

        self::assertSame('fallback', $body['route']);
        self::assertSame(
            ['primary/model', 'openai/gpt-4o', 'anthropic/claude-3-opus'],
            $body['models'],
        );
    }

    #[Test]
    public function chatCompletionOmitsRouteWhenAutoFallbackDisabled(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider(
            ['autoFallback' => false, 'fallbackModels' => 'openai/gpt-4o'],
            $this->chatResponse(),
            $captured,
        );

        $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        );

        $body = $this->decodeCapturedBody($captured[0]['body']);

        self::assertArrayNotHasKey('route', $body);
        self::assertArrayNotHasKey('models', $body);
    }

    #[Test]
    public function chatCompletionParsesResponseFields(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider(
            [],
            $this->createJsonResponseMock([
                'id' => 'gen-test',
                'model' => 'resolved/model',
                'choices' => [[
                    'message' => ['content' => 'the answer'],
                    'finish_reason' => 'length',
                ]],
                'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 22, 'total_tokens' => 33],
            ]),
            $captured,
        );

        $result = $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'requested/model'],
        );

        self::assertSame('the answer', $result->content);
        self::assertSame('resolved/model', $result->model);
        self::assertSame('length', $result->finishReason);
        self::assertSame(11, $result->usage->promptTokens);
        self::assertSame(22, $result->usage->completionTokens);
    }

    #[Test]
    public function chatCompletionDefaultsModelAndFinishReasonWhenAbsent(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider(
            [],
            $this->createJsonResponseMock([
                'id' => 'gen-test',
                'choices' => [['message' => ['content' => 'x']]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
            $captured,
        );

        $result = $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'requested/model'],
        );

        self::assertSame('requested/model', $result->model);
        self::assertSame('stop', $result->finishReason);
    }

    #[Test]
    public function chatCompletionWithToolsBuildsExactRequest(): void
    {
        $spec = ToolSpec::fromArray([
            'type' => 'function',
            'function' => ['name' => 'get_weather', 'description' => 'w'],
        ]);

        $captured = [];
        $provider = $this->createCapturingProvider(
            [],
            $this->chatResponse(['provider' => 'anthropic']),
            $captured,
        );

        $provider->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'Hi']],
            [$spec],
            ['model' => 'openai/gpt-4o', 'tool_choice' => 'auto'],
        );

        self::assertSame('POST', $captured[0]['method']);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $captured[0]['uri']);

        $body = $this->decodeCapturedBody($captured[0]['body']);

        self::assertSame('openai/gpt-4o', $body['model']);
        self::assertSame([['role' => 'user', 'content' => 'Hi']], $body['messages']);
        self::assertSame([$spec->toArray()], $body['tools']);
        self::assertSame(0.7, $body['temperature']);
        self::assertSame(4096, $body['max_tokens']);
        self::assertSame('auto', $body['tool_choice']);
        self::assertSame('fallback', $body['route']);
    }

    #[Test]
    public function chatCompletionWithToolsMetadataIncludesActualProvider(): void
    {
        $spec = ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'f']]);

        $captured = [];
        $provider = $this->createCapturingProvider(
            [],
            $this->createJsonResponseMock([
                'id' => 'gen-test',
                'model' => 'test-model',
                'provider' => 'openai',
                'total_cost' => 0.0042,
                'choices' => [['message' => ['content' => 'x', 'tool_calls' => []], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
            $captured,
        );

        $result = $provider->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'Hi']],
            [$spec],
            ['model' => 'openai/gpt-4o'],
        );

        /** @var array{actual_provider: string, cost: float} $metadata */
        $metadata = $result->metadata;
        self::assertSame('openai', $metadata['actual_provider']);
        self::assertSame(0.0042, $metadata['cost']);
    }

    #[Test]
    public function chatCompletionWithToolsMetadataDefaultsActualProviderToUnknown(): void
    {
        $spec = ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'f']]);

        $captured = [];
        $provider = $this->createCapturingProvider(
            [],
            $this->createJsonResponseMock([
                'id' => 'gen-test',
                'model' => 'test-model',
                'choices' => [['message' => ['content' => 'x', 'tool_calls' => []], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
            $captured,
        );

        $result = $provider->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'Hi']],
            [$spec],
            ['model' => 'openai/gpt-4o'],
        );

        /** @var array{actual_provider: string} $metadata */
        $metadata = $result->metadata;
        self::assertSame('unknown', $metadata['actual_provider']);
    }

    #[Test]
    public function embeddingsBuildsExactRequestForStringInput(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider(
            [],
            $this->createJsonResponseMock([
                'model' => 'openai/text-embedding-3-small',
                'data' => [['index' => 0, 'embedding' => [0.1, 0.2]]],
                'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
            ]),
            $captured,
        );

        $provider->embeddings('hello', ['dimensions' => 256]);

        self::assertSame('POST', $captured[0]['method']);
        self::assertSame('https://openrouter.ai/api/v1/embeddings', $captured[0]['uri']);

        $body = $this->decodeCapturedBody($captured[0]['body']);

        self::assertSame('openai/text-embedding-3-small', $body['model']);
        self::assertSame(['hello'], $body['input']);
        self::assertSame(256, $body['dimensions']);
    }

    #[Test]
    public function fetchAvailableModelsParsesEveryFieldExactly(): void
    {
        $modelsResponse = [
            'data' => [
                [
                    'id' => 'anthropic/claude-x',
                    'name' => 'Claude X',
                    'context_length' => 200000,
                    'architecture' => ['modality' => 'multimodal'],
                    'pricing' => ['prompt' => 0.003, 'completion' => 0.015],
                    'supports_function_calling' => true,
                ],
                [
                    // No name, no pricing keys, text modality, no function calling.
                    'id' => 'openai/gpt',
                    'context_length' => 8000,
                    'architecture' => ['modality' => 'text'],
                ],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($modelsResponse));

        $result = $this->subject->fetchAvailableModels();

        self::assertCount(2, $result);

        self::assertSame(
            [
                'name' => 'Claude X',
                'context_length' => 200000,
                'pricing' => ['prompt' => 0.003, 'completion' => 0.015],
                'capabilities' => ['vision' => true, 'function_calling' => true],
                'provider' => 'anthropic',
            ],
            $result['anthropic/claude-x'],
        );

        self::assertSame(
            [
                'name' => 'openai/gpt',
                'context_length' => 8000,
                'pricing' => ['prompt' => 0.0, 'completion' => 0.0],
                'capabilities' => ['vision' => false, 'function_calling' => false],
                'provider' => 'openai',
            ],
            $result['openai/gpt'],
        );
    }

    #[Test]
    public function fetchAvailableModelsReturnsCachedResultWithoutRefetching(): void
    {
        $firstResponse = [
            'data' => [[
                'id' => 'first/model',
                'name' => 'First',
                'context_length' => 8000,
                'architecture' => ['modality' => 'text'],
                'pricing' => ['prompt' => 0.001, 'completion' => 0.002],
                'supports_function_calling' => false,
            ]],
        ];
        $secondResponse = [
            'data' => [[
                'id' => 'second/model',
                'name' => 'Second',
                'context_length' => 8000,
                'architecture' => ['modality' => 'text'],
                'pricing' => ['prompt' => 0.001, 'completion' => 0.002],
                'supports_function_calling' => false,
            ]],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMock($firstResponse),
                $this->createJsonResponseMock($secondResponse),
            );

        $first  = $this->subject->fetchAvailableModels();
        $second = $this->subject->fetchAvailableModels();

        self::assertArrayHasKey('first/model', $first);
        // The second call must hit the cache, not the (differing) second response.
        self::assertArrayHasKey('first/model', $second);
        self::assertArrayNotHasKey('second/model', $second);
        self::assertSame($first, $second);
    }

    #[Test]
    public function getCreditsDefaultsMissingFieldsToZero(): void
    {
        $creditsResponse = [
            'data' => [
                'is_free_tier' => true,
                'rate_limit' => ['requests' => 200, 'interval' => '10s'],
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($creditsResponse));

        $result = $this->subject->getCredits();

        self::assertSame(0.0, $result['balance']);
        self::assertSame(0.0, $result['usage']);
        self::assertTrue($result['is_free_tier']);
        self::assertSame(['requests' => 200, 'interval' => '10s'], $result['rate_limit']);
    }

    // ---------------------------------------------------------------------
    // Streaming request/parsing pins
    // ---------------------------------------------------------------------

    #[Test]
    public function streamChatCompletionBuildsExactRequestAndPayload(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider(
            [
                'siteUrl' => 'https://example.com',
                'appName' => 'Test App',
                'fallbackModels' => 'openai/gpt-4o, anthropic/claude-3-opus',
            ],
            $this->createSseResponse(["data: [DONE]\n"]),
            $captured,
        );

        $chunks = iterator_to_array($provider->streamChatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        ));

        self::assertSame([], $chunks);
        self::assertCount(1, $captured);

        self::assertSame('POST', $captured[0]['method']);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $captured[0]['uri']);
        self::assertSame('application/json', $captured[0]['headers']['Content-Type'] ?? null);
        self::assertSame('text/event-stream', $captured[0]['headers']['Accept'] ?? null);
        self::assertSame('https://example.com', $captured[0]['headers']['HTTP-Referer'] ?? null);
        self::assertSame('Test App', $captured[0]['headers']['X-Title'] ?? null);

        self::assertSame(
            [
                'model' => 'primary/model',
                'messages' => [['role' => 'user', 'content' => 'Hi']],
                'temperature' => 0.7,
                'max_tokens' => 4096,
                'stream' => true,
                'route' => 'fallback',
                'models' => ['primary/model', 'openai/gpt-4o', 'anthropic/claude-3-opus'],
            ],
            $this->decodeCapturedBody($captured[0]['body']),
        );
    }

    #[Test]
    public function streamChatCompletionTrimsTrailingSlashFromBaseUrl(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider(
            ['baseUrl' => 'https://openrouter.ai/api/v1/'],
            $this->createSseResponse(["data: [DONE]\n"]),
            $captured,
        );

        $chunks = iterator_to_array($provider->streamChatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        ));

        self::assertSame([], $chunks);
        self::assertCount(1, $captured);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $captured[0]['uri']);
    }

    #[Test]
    public function chatCompletionTrimsTrailingSlashFromBaseUrl(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider(
            ['baseUrl' => 'https://openrouter.ai/api/v1/'],
            $this->chatResponse(),
            $captured,
        );

        $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        );

        self::assertCount(1, $captured);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $captured[0]['uri']);
    }

    #[Test]
    public function streamChatCompletionValidatesConfigurationBeforeStreaming(): void
    {
        $provider = $this->createProviderWithConfig(['apiKeyIdentifier' => '']);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createSseResponse(["data: [DONE]\n"]));

        $this->expectException(ProviderConfigurationException::class);

        $provider->streamChatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        )->current();
    }

    #[Test]
    public function streamChatCompletionThrowsOnHttpErrorStatus(): void
    {
        $provider = $this->createProviderWithConfig([]);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createSseResponse([], 500));

        $this->expectException(ProviderConnectionException::class);
        $this->expectExceptionMessage('Server returned status 500');

        $provider->streamChatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        )->current();
    }

    #[Test]
    public function streamChatCompletionIgnoresDataAfterDoneMarker(): void
    {
        $provider = $this->createProviderWithConfig([]);

        $streamData = "data: {\"choices\":[{\"delta\":{\"content\":\"before\"}}]}\n"
            . "data: [DONE]\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"after\"}}]}\n";

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createSseResponse([$streamData]));

        $chunks = iterator_to_array($provider->streamChatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        ));

        self::assertSame(['before'], $chunks);
    }

    #[Test]
    public function streamChatCompletionConcatenatesPartialReads(): void
    {
        $provider = $this->createProviderWithConfig([]);

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createSseResponse([
                'data: {"choices":[{"delta":{"content":"Hel',
                "lo\"}}]}\ndata: [DONE]\n",
            ]));

        $chunks = iterator_to_array($provider->streamChatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        ));

        self::assertSame(['Hello'], $chunks);
    }

    #[Test]
    public function streamChatCompletionParsesSingleNewlineSeparatedEvents(): void
    {
        $provider = $this->createProviderWithConfig([]);

        $streamData = "data: {\"choices\":[{\"delta\":{\"content\":\"A\"}}]}\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"B\"}}]}\n"
            . "data: [DONE]\n";

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createSseResponse([$streamData]));

        $chunks = iterator_to_array($provider->streamChatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        ));

        self::assertSame(['A', 'B'], $chunks);
    }

    #[Test]
    public function streamChatCompletionSubstitutesInvalidUtf8InPayload(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider(
            [],
            $this->createSseResponse(["data: [DONE]\n"]),
            $captured,
        );

        $chunks = iterator_to_array($provider->streamChatCompletion(
            [['role' => 'user', 'content' => "Caf\xE9!"]],
            ['model' => 'primary/model'],
        ));

        self::assertSame([], $chunks);
        self::assertCount(1, $captured);
        $body = $this->decodeCapturedBody($captured[0]['body']);
        self::assertSame([['role' => 'user', 'content' => "Caf\u{FFFD}!"]], $body['messages']);
    }

    #[Test]
    public function chatCompletionSubstitutesInvalidUtf8InPayload(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider([], $this->chatResponse(), $captured);

        $provider->chatCompletion(
            [['role' => 'user', 'content' => "Caf\xE9!"]],
            ['model' => 'primary/model'],
        );

        self::assertCount(1, $captured);
        $body = $this->decodeCapturedBody($captured[0]['body']);
        self::assertSame([['role' => 'user', 'content' => "Caf\u{FFFD}!"]], $body['messages']);
    }

    // ---------------------------------------------------------------------
    // Embeddings / vision transformation pins
    // ---------------------------------------------------------------------

    #[Test]
    public function embeddingsCastsValuesToFloatAndReportsZeroCompletionTokens(): void
    {
        $apiResponse = [
            'model' => 'openai/text-embedding-3-small',
            'data' => [['index' => 0, 'embedding' => [1, '0.5', 0.25]]],
            'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->embeddings('test');

        self::assertSame([[1.0, 0.5, 0.25]], $result->embeddings);
        self::assertSame(7, $result->usage->promptTokens);
        self::assertSame(0, $result->usage->completionTokens);
        self::assertSame(7, $result->usage->totalTokens);
    }

    #[Test]
    public function analyzeImageBuildsExactRequest(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider([], $this->chatResponse(), $captured);

        $provider->analyzeImage(
            [
                VisionContent::fromArray(['type' => 'text', 'text' => 'Describe']),
                VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/i.png']]),
            ],
            ['model' => 'vision/model'],
        );

        // Request 0 is the vision-model discovery (models list), request 1 the completion.
        self::assertCount(2, $captured);
        self::assertArrayHasKey(1, $captured);
        self::assertSame('POST', $captured[1]['method']);

        self::assertSame(
            [
                'model' => 'vision/model',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Describe'],
                            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/i.png']],
                        ],
                    ],
                ],
                'max_tokens' => 4096,
                'route' => 'fallback',
            ],
            $this->decodeCapturedBody($captured[1]['body']),
        );
    }

    #[Test]
    public function analyzeImagePrependsSystemPromptMessage(): void
    {
        $captured = [];
        $provider = $this->createCapturingProvider([], $this->chatResponse(), $captured);

        $provider->analyzeImage(
            [VisionContent::fromArray(['type' => 'text', 'text' => 'Hi'])],
            ['model' => 'vision/model', 'system_prompt' => 'Be nice'],
        );

        self::assertArrayHasKey(1, $captured);
        $body = $this->decodeCapturedBody($captured[1]['body']);

        self::assertSame(
            [
                ['role' => 'system', 'content' => 'Be nice'],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ],
            $body['messages'],
        );
    }

    #[Test]
    public function analyzeImageMetadataIncludesActualProviderAndCost(): void
    {
        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'vision/model',
            'provider' => 'anthropic',
            'total_cost' => 0.5,
            'choices' => [['message' => ['content' => 'a cat'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->analyzeImage(
            [VisionContent::fromArray(['type' => 'text', 'text' => 'Hi'])],
            ['model' => 'vision/model'],
        );

        self::assertSame(
            ['actual_provider' => 'anthropic', 'cost' => 0.5],
            $result->metadata,
        );
    }

    #[Test]
    public function chatCompletionWithToolsIncludesFallbackModelsInPayload(): void
    {
        $spec = ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'f']]);

        $captured = [];
        $provider = $this->createCapturingProvider(
            ['fallbackModels' => 'openai/gpt-4o, anthropic/claude-3-opus'],
            $this->chatResponse(),
            $captured,
        );

        $provider->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'Hi']],
            [$spec],
            ['model' => 'primary/model'],
        );

        self::assertCount(1, $captured);
        $body = $this->decodeCapturedBody($captured[0]['body']);

        self::assertSame('fallback', $body['route']);
        self::assertSame(
            ['primary/model', 'openai/gpt-4o', 'anthropic/claude-3-opus'],
            $body['models'],
        );
    }

    // ---------------------------------------------------------------------
    // Routing pins: assert the model id the provider actually REQUESTS,
    // not the model id the mocked response echoes back.
    // ---------------------------------------------------------------------

    #[Test]
    public function costOptimizedRoutingRequestsCheapestModelByAverage(): void
    {
        // Averages: 0.0255 / 0.0205 / 0.02 — mid/model is cheapest only when
        // BOTH pricing components enter the (prompt + completion) / 2 average.
        $model = $this->capturedRoutedModel(
            ['routingStrategy' => 'cost_optimized'],
            [
                self::modelEntry('prompt-heavy/model', 0.05, 0.001),
                self::modelEntry('completion-heavy/model', 0.001, 0.04),
                self::modelEntry('mid/model', 0.02, 0.02),
            ],
        );

        self::assertSame('mid/model', $model);
    }

    #[Test]
    public function costOptimizedRoutingPrefersFirstModelOnPriceTie(): void
    {
        $model = $this->capturedRoutedModel(
            ['routingStrategy' => 'cost_optimized'],
            [
                self::modelEntry('first/model', 0.01, 0.01),
                self::modelEntry('second/model', 0.01, 0.01),
            ],
        );

        self::assertSame('first/model', $model);
    }

    #[Test]
    public function performanceRoutingRequestsFastKeywordModel(): void
    {
        $model = $this->capturedRoutedModel(
            ['routingStrategy' => 'performance'],
            [
                self::modelEntry('slow/opus', 0.01, 0.03),
                self::modelEntry('quick/flash', 0.001, 0.002),
            ],
        );

        self::assertSame('quick/flash', $model);
    }

    #[Test]
    public function balancedRoutingRequestsBalancedKeywordModel(): void
    {
        $model = $this->capturedRoutedModel(
            ['routingStrategy' => 'balanced'],
            [
                self::modelEntry('fast/haiku', 0.0001, 0.0001),
                self::modelEntry('steady/sonnet', 0.003, 0.015),
            ],
        );

        self::assertSame('steady/sonnet', $model);
    }

    #[Test]
    public function explicitRoutingSkipsModelDiscovery(): void
    {
        $captured = [];
        $provider = $this->createCapturingRoutingProvider(
            ['routingStrategy' => 'explicit'],
            ['data' => [self::modelEntry('steady/sonnet', 0.003, 0.015)]],
            $this->chatResponse(),
            $captured,
        );

        $provider->chatCompletion([['role' => 'user', 'content' => 'Hi']]);

        // Explicit routing must not fetch the live model list at all.
        self::assertCount(1, $captured);
        $body = $this->decodeCapturedBody($captured[0]['body']);
        self::assertSame('anthropic/claude-3.5-sonnet', $body['model']);
    }

    #[Test]
    public function minContextFilterKeepsBoundaryModel(): void
    {
        // A model whose context length EQUALS min_context must survive the
        // filter (>=), while smaller ones are dropped.
        $model = $this->capturedRoutedModel(
            ['routingStrategy' => 'cost_optimized'],
            [
                self::modelEntry('tiny/context', 0.00001, 0.00001, ['context_length' => 4000]),
                self::modelEntry('exact/context', 0.0001, 0.0001, ['context_length' => 100000]),
                self::modelEntry('huge/context', 0.01, 0.01, ['context_length' => 128000]),
            ],
            ['min_context' => 100000],
        );

        self::assertSame('exact/context', $model);
    }

    #[Test]
    public function routingWithoutFilterOptionsKeepsIncapableModels(): void
    {
        // Without vision_required / function_calling options the capability
        // filters must NOT run: the cheap incapable model stays eligible.
        $model = $this->capturedRoutedModel(
            ['routingStrategy' => 'cost_optimized'],
            [
                self::modelEntry('plain/cheap', 0.0001, 0.0001),
                self::modelEntry('able/model', 0.01, 0.01, [
                    'architecture' => ['modality' => 'multimodal'],
                    'supports_function_calling' => true,
                ]),
            ],
        );

        self::assertSame('plain/cheap', $model);
    }

    #[Test]
    public function visionRequiredFilterRoutesToVisionCapableModel(): void
    {
        $model = $this->capturedRoutedModel(
            ['routingStrategy' => 'cost_optimized'],
            [
                self::modelEntry('text/cheap', 0.0001, 0.0001),
                self::modelEntry('vision/pricier', 0.01, 0.01, [
                    'architecture' => ['modality' => 'multimodal'],
                ]),
            ],
            ['vision_required' => true],
        );

        self::assertSame('vision/pricier', $model);
    }

    #[Test]
    public function functionCallingFilterRoutesToToolCapableModel(): void
    {
        $model = $this->capturedRoutedModel(
            ['routingStrategy' => 'cost_optimized'],
            [
                self::modelEntry('plain/cheap', 0.0001, 0.0001),
                self::modelEntry('tools/pricier', 0.01, 0.01, [
                    'supports_function_calling' => true,
                ]),
            ],
            ['function_calling' => true],
        );

        self::assertSame('tools/pricier', $model);
    }

    // ---------------------------------------------------------------------
    // Vision-model selection pins
    // ---------------------------------------------------------------------

    #[Test]
    public function analyzeImageUsesVisionCapableDefaultModel(): void
    {
        $model = $this->capturedVisionModel(
            [],
            [self::modelEntry('anthropic/claude-3.5-sonnet', 0.003, 0.015, [
                'architecture' => ['modality' => 'multimodal'],
            ])],
        );

        self::assertSame('anthropic/claude-3.5-sonnet', $model);
    }

    #[Test]
    public function analyzeImageFallsBackToFirstListedVisionModel(): void
    {
        // The default model is absent from the live list; the FIRST entry of
        // the curated vision-model list that is available must be selected.
        $model = $this->capturedVisionModel(
            [],
            [self::modelEntry('anthropic/claude-sonnet-4-5', 0.003, 0.015)],
        );

        self::assertSame('anthropic/claude-sonnet-4-5', $model);
    }

    #[Test]
    public function analyzeImageSelectsListedVisionModelFromAvailableModels(): void
    {
        // Only a later curated entry is available: it must be picked over the
        // hardcoded 'openai/gpt-5.2' fallback and over unavailable entries.
        $model = $this->capturedVisionModel(
            [],
            [self::modelEntry('google/gemini-3-flash', 0.001, 0.002)],
        );

        self::assertSame('google/gemini-3-flash', $model);
    }

    #[Test]
    public function handleOpenRouterErrorIncludesApiErrorMessage(): void
    {
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(
                ['error' => ['message' => 'upstream exploded']],
                500,
            ));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenRouter API error (500): upstream exploded');

        $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'primary/model'],
        );
    }
}
