<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(OpenAiProvider::class)]
class OpenAiProviderTest extends AbstractUnitTestCase
{
    private OpenAiProvider $subject;
    private ClientInterface&Stub $httpClientStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = $this->createHttpClientMock();

        $this->subject = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $this->subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gpt-4o',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $this->subject->setHttpClient($this->httpClientStub);
    }

    /**
     * Create a provider with a mock HTTP client for expectation testing.
     *
     * @return array{subject: OpenAiProvider, httpClient: ClientInterface&MockObject}
     */
    private function createSubjectWithMockHttpClient(): array
    {
        $httpClientMock = $this->createHttpClientWithExpectations();

        $subject = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gpt-4o',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $subject->setHttpClient($httpClientMock);

        return ['subject' => $subject, 'httpClient' => $httpClientMock];
    }

    #[Test]
    public function getNameReturnsOpenAI(): void
    {
        self::assertEquals('OpenAI', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsOpenai(): void
    {
        self::assertEquals('openai', $this->subject->getIdentifier());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyConfigured(): void
    {
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenNoApiKey(): void
    {
        // Use empty secrets array to simulate no API key existing in vault
        $provider = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock([]), // Empty = no keys exist
            $this->createSecureHttpClientFactoryMock(),
        );

        // Without calling configure(), provider has no API key
        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function chatCompletionReturnsValidResponse(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [
            ['role' => 'user', 'content' => $this->randomPrompt()],
        ];

        $apiResponse = [
            'id' => 'chatcmpl-' . $this->faker->uuid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Test response content',
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

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Test response content', $result->content);
        self::assertEquals('gpt-4o', $result->model);
        self::assertEquals('stop', $result->finishReason);
        self::assertEquals(30, $result->usage->totalTokens);
    }

    #[Test]
    public function chatCompletionWithSystemPromptIncludesIt(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1, 'total_tokens' => 6],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertEquals('Hi', $result->content);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOn401(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(401, 'Invalid API key'));

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('Invalid API key');

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOn429(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(429, 'Rate limit exceeded'));

        $this->expectException(ProviderResponseException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function chatCompletionThrowsProviderExceptionOnServerError(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(500, 'Internal server error'));

        $this->expectException(ProviderException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function embeddingsReturnsValidResponse(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $text = $this->randomPrompt();

        $apiResponse = [
            'object' => 'list',
            'data' => [
                [
                    'object' => 'embedding',
                    'index' => 0,
                    'embedding' => array_fill(0, 1536, 0.1),
                ],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => [
                'prompt_tokens' => 10,
                'total_tokens' => 10,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings($text);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(1536, $result->embeddings[0]);
        self::assertEquals('text-embedding-3-small', $result->model);
    }

    #[Test]
    public function embeddingsWithMultipleTextsReturnsMultipleVectors(): void
    {
        $texts = [$this->randomPrompt(), $this->randomPrompt()];

        $apiResponse = [
            'object' => 'list',
            'data' => [
                ['embedding' => array_fill(0, 1536, 0.1), 'index' => 0],
                ['embedding' => array_fill(0, 1536, 0.2), 'index' => 1],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 20, 'total_tokens' => 20],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->embeddings($texts);

        self::assertCount(2, $result->embeddings);
    }

    #[Test]
    public function getAvailableModelsReturnsArray(): void
    {
        $models = $this->subject->getAvailableModels();

        self::assertNotEmpty($models);
        // Models are returned as key => label pairs
        self::assertArrayHasKey('gpt-5.2', $models);
        self::assertArrayHasKey('gpt-5.2-pro', $models);
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
    #[DataProvider('temperatureValidationProvider')]
    public function chatCompletionAcceptsValidTemperature(float $temperature): void
    {
        $apiResponse = [
            'id' => 'test',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['temperature' => $temperature],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function temperatureValidationProvider(): array
    {
        return [
            'zero' => [0.0],
            'mid' => [1.0],
            'max' => [2.0],
        ];
    }

    #[Test]
    public function chatCompletionHandlesEmptyChoices(): void
    {
        $apiResponse = [
            'id' => 'test',
            'choices' => [],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 0, 'total_tokens' => 1],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        // Provider returns empty content when no choices available
        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('', $result->content);
    }

    #[Test]
    public function chatCompletionWithCustomModelUsesIt(): void
    {
        $customModel = 'gpt-4-turbo';

        $apiResponse = [
            'id' => 'test',
            'choices' => [['message' => ['content' => 'test'], 'finish_reason' => 'stop']],
            'model' => $customModel,
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['model' => $customModel],
        );

        self::assertEquals($customModel, $result->model);
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
                        'properties' => ['location' => ['type' => 'string']],
                    ],
                ],
            ],
        ];

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'message' => [
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"location": "Tokyo"}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 15, 'total_tokens' => 35],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
        /** @var array{function: array{name: string, arguments: array<string, mixed>}} $toolCall */
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall['function']['name']);
        self::assertEquals(['location' => 'Tokyo'], $toolCall['function']['arguments']);
    }

    #[Test]
    public function chatCompletionWithToolsAndToolChoice(): void
    {
        $messages = [['role' => 'user', 'content' => 'Get weather']];
        $tools = [
            [
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'parameters' => []],
            ],
        ];

        $apiResponse = [
            'id' => 'test',
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'message' => ['content' => '', 'tool_calls' => []],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools, ['tool_choice' => 'auto']);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function analyzeImageReturnsVisionResponse(): void
    {
        $content = [
            ['type' => 'text', 'text' => 'What is in this image?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.jpg']],
        ];

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'model' => 'gpt-5.2',
            'choices' => [
                [
                    'message' => ['content' => 'This image shows a cat sitting on a couch.'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->analyzeImage($content);

        self::assertInstanceOf(VisionResponse::class, $result);
        self::assertEquals('This image shows a cat sitting on a couch.', $result->description);
        self::assertEquals('gpt-5.2', $result->model);
    }

    #[Test]
    public function analyzeImageWithSystemPrompt(): void
    {
        $content = [
            ['type' => 'text', 'text' => 'Describe this'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,abc123']],
        ];

        $apiResponse = [
            'id' => 'test',
            'model' => 'gpt-5.2',
            'choices' => [
                [
                    'message' => ['content' => 'A detailed description'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 30, 'total_tokens' => 180],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->analyzeImage($content, ['system_prompt' => 'You are an art critic.']);

        self::assertInstanceOf(VisionResponse::class, $result);
        self::assertEquals('A detailed description', $result->description);
    }

    #[Test]
    public function streamChatCompletionYieldsChunks(): void
    {
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
        $response->method('getBody')->willReturn($stream);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($response);

        $chunks = [];
        foreach ($this->subject->streamChatCompletion([['role' => 'user', 'content' => 'test']]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Hello', ' World'], $chunks);
    }

    #[Test]
    public function streamChatCompletionHandlesMalformedJson(): void
    {
        $streamData = "data: {invalid json}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Valid\"}}]}\n\n"
            . "data: [DONE]\n\n";

        $stream = self::createStub(StreamInterface::class);
        $eofCallCount = 0;
        $stream->method('eof')->willReturnCallback(function () use (&$eofCallCount) {
            return ++$eofCallCount > 1;
        });
        $stream->method('read')->willReturn($streamData);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($response);

        $chunks = [];
        foreach ($this->subject->streamChatCompletion([['role' => 'user', 'content' => 'test']]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Valid'], $chunks);
    }

    #[Test]
    public function getSupportedImageFormatsReturnsExpectedFormats(): void
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
    public function embeddingsWithDimensionsOption(): void
    {
        $apiResponse = [
            'data' => [
                ['embedding' => array_fill(0, 256, 0.1), 'index' => 0],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->embeddings('test text', ['dimensions' => 256]);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(256, $result->embeddings[0]);
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModel(): void
    {
        self::assertEquals('gpt-4o', $this->subject->getDefaultModel());
    }

    #[Test]
    public function chatCompletionWithMaxTokensOption(): void
    {
        $apiResponse = [
            'id' => 'test',
            'choices' => [['message' => ['content' => 'response'], 'finish_reason' => 'length']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 100, 'total_tokens' => 105],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['max_tokens' => 100],
        );

        self::assertEquals('length', $result->finishReason);
    }

    #[Test]
    public function chatCompletionThrowsOnBadRequest(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(400, 'Invalid request format'));

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('Invalid request format');

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function chatCompletionThrowsOn503ServiceUnavailable(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(503, 'Service temporarily unavailable'));

        $this->expectException(ProviderException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function chatCompletionExtractsNestedErrorMessage(): void
    {
        // Test error extraction with nested error.message (standard OpenAI format)
        $errorResponse = json_encode(['error' => ['message' => 'Model not found', 'type' => 'invalid_request_error']]);

        $stream = self::createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($errorResponse);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('getBody')->willReturn($stream);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($response);

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('Model not found');

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function chatCompletionExtractsDirectMessageError(): void
    {
        // Test error extraction with direct message field (no nested error object)
        $errorResponse = json_encode(['message' => 'Rate limit exceeded']);

        $stream = self::createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($errorResponse);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(429);
        $response->method('getBody')->willReturn($stream);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($response);

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function chatCompletionHandlesUnknownErrorFormat(): void
    {
        // Test error extraction when neither error nor message is present
        $errorResponse = json_encode(['code' => 'UNKNOWN']);

        $stream = self::createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($errorResponse);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getBody')->willReturn($stream);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($response);

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('Unknown provider error');

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function supportsFeatureReturnsTrueForChat(): void
    {
        self::assertTrue($this->subject->supportsFeature('chat'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForUnsupportedFeature(): void
    {
        self::assertFalse($this->subject->supportsFeature('unsupported_feature'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForVision(): void
    {
        self::assertTrue($this->subject->supportsFeature('vision'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForTools(): void
    {
        self::assertTrue($this->subject->supportsFeature('tools'));
    }

    #[Test]
    public function supportsFeatureReturnsTrueForEmbeddings(): void
    {
        self::assertTrue($this->subject->supportsFeature('embeddings'));
    }

    #[Test]
    public function testConnectionReturnsSuccessWithModelList(): void
    {
        $apiResponse = [
            'object' => 'list',
            'data' => [
                ['id' => 'gpt-5.2', 'object' => 'model'],
                ['id' => 'gpt-4o', 'object' => 'model'],
                ['id' => 'gpt-4o-mini', 'object' => 'model'],
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->testConnection();

        self::assertTrue($result['success']);
        self::assertStringContainsString('Connection successful', $result['message']);
        self::assertStringContainsString('3 models', $result['message']);
        self::assertArrayHasKey('models', $result);
        assert(isset($result['models']));
        self::assertArrayHasKey('gpt-5.2', $result['models']);
        self::assertArrayHasKey('gpt-4o', $result['models']);
    }

    #[Test]
    public function testConnectionReturnsEmptyModelsWhenDataIsEmpty(): void
    {
        $apiResponse = [
            'object' => 'list',
            'data' => [],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->testConnection();

        self::assertTrue($result['success']);
        self::assertStringContainsString('0 models', $result['message']);
        self::assertArrayHasKey('models', $result);
        assert(isset($result['models']));
        self::assertEmpty($result['models']);
    }

    #[Test]
    public function testConnectionSkipsModelsWithoutId(): void
    {
        $apiResponse = [
            'object' => 'list',
            'data' => [
                ['id' => 'gpt-5.2', 'object' => 'model'],
                ['object' => 'model'], // No id field
                ['id' => '', 'object' => 'model'], // Empty id
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->testConnection();

        self::assertTrue($result['success']);
        self::assertArrayHasKey('models', $result);
        assert(isset($result['models']));
        self::assertCount(1, $result['models']);
        self::assertArrayHasKey('gpt-5.2', $result['models']);
    }

    #[Test]
    public function testConnectionThrowsProviderResponseExceptionOn401(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(401, 'Invalid API key'));

        $this->expectException(ProviderResponseException::class);

        $this->subject->testConnection();
    }

    #[Test]
    public function chatCompletionWithToolsReturnsNullToolCallsWhenNoneInResponse(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $tools = [
            ['type' => 'function', 'function' => ['name' => 'test', 'parameters' => []]],
        ];

        // Response has no tool_calls key at all (not even empty)
        $apiResponse = [
            'id' => 'chatcmpl-test',
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'message' => ['content' => 'Hello! How can I help?'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 8, 'total_tokens' => 13],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertNull($result->toolCalls);
        self::assertEquals('Hello! How can I help?', $result->content);
    }

    #[Test]
    public function chatCompletionWithToolsWithInvalidJsonArgumentsUsesEmptyArray(): void
    {
        $messages = [['role' => 'user', 'content' => 'Get weather']];
        $tools = [
            ['type' => 'function', 'function' => ['name' => 'get_weather', 'parameters' => []]],
        ];

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'message' => [
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_abc',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{invalid json}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertNotNull($result->toolCalls);
        /** @var array{function: array{name: string, arguments: array<string, mixed>}} $toolCall */
        $toolCall = $result->toolCalls[0];
        // Invalid JSON arguments should fall back to empty array
        self::assertEquals([], $toolCall['function']['arguments']);
    }

    #[Test]
    public function embeddingsWithArrayInputReturnsMultipleVectors(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $texts = ['First text', 'Second text', 'Third text'];

        $apiResponse = [
            'object' => 'list',
            'data' => [
                ['embedding' => array_fill(0, 1536, 0.1), 'index' => 0],
                ['embedding' => array_fill(0, 1536, 0.2), 'index' => 1],
                ['embedding' => array_fill(0, 1536, 0.3), 'index' => 2],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 30, 'total_tokens' => 30],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings($texts);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(3, $result->embeddings);
        self::assertEquals('text-embedding-3-small', $result->model);
    }

    #[Test]
    public function streamChatCompletionHandlesEmptyContentInDelta(): void
    {
        // SSE stream with a chunk that has no content (e.g., role delta)
        $streamData = "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
            . "data: [DONE]\n\n";

        $stream = self::createStub(StreamInterface::class);
        $eofCallCount = 0;
        $stream->method('eof')->willReturnCallback(function () use (&$eofCallCount) {
            return ++$eofCallCount > 1;
        });
        $stream->method('read')->willReturn($streamData);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($response);

        $chunks = [];
        foreach ($this->subject->streamChatCompletion([['role' => 'user', 'content' => 'test']]) as $chunk) {
            $chunks[] = $chunk;
        }

        // Only chunks with non-empty content should be yielded
        self::assertEquals(['Hello'], $chunks);
    }

    #[Test]
    public function streamChatCompletionHandlesNonDataLines(): void
    {
        // SSE stream with comment line and event line (not data:)
        $streamData = ": keep-alive\n\n"
            . "event: message\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"World\"}}]}\n\n"
            . "data: [DONE]\n\n";

        $stream = self::createStub(StreamInterface::class);
        $eofCallCount = 0;
        $stream->method('eof')->willReturnCallback(function () use (&$eofCallCount) {
            return ++$eofCallCount > 1;
        });
        $stream->method('read')->willReturn($streamData);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($response);

        $chunks = [];
        foreach ($this->subject->streamChatCompletion([['role' => 'user', 'content' => 'test']]) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['World'], $chunks);
    }

    #[Test]
    public function chatCompletionWithTopPOption(): void
    {
        $apiResponse = [
            'id' => 'test',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['top_p' => 0.9],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionWithFrequencyPenaltyOption(): void
    {
        $apiResponse = [
            'id' => 'test',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['frequency_penalty' => 0.5],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionWithPresencePenaltyOption(): void
    {
        $apiResponse = [
            'id' => 'test',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['presence_penalty' => 0.3],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionWithStopOption(): void
    {
        $apiResponse = [
            'id' => 'test',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion(
            [['role' => 'user', 'content' => 'test']],
            ['stop' => ['END', 'STOP']],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function analyzeImageWithCustomModel(): void
    {
        $content = [
            ['type' => 'text', 'text' => 'What is this?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/img.jpg']],
        ];

        $apiResponse = [
            'id' => 'test',
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'message' => ['content' => 'An image of something'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->analyzeImage($content, ['model' => 'gpt-4o', 'max_tokens' => 100]);

        self::assertInstanceOf(VisionResponse::class, $result);
        self::assertEquals('An image of something', $result->description);
    }
}
