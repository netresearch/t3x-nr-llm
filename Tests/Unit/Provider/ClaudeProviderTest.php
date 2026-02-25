<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(ClaudeProvider::class)]
class ClaudeProviderTest extends AbstractUnitTestCase
{
    private ClaudeProvider $subject;
    private ClientInterface&Stub $httpClientStub;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = $this->createHttpClientMock();

        $this->subject = new ClaudeProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $this->subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'claude-sonnet-4-20250514',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $this->subject->setHttpClient($this->httpClientStub);
    }

    /**
     * Create a provider with a mock HTTP client for expectation testing.
     *
     * @return array{subject: ClaudeProvider, httpClient: ClientInterface&MockObject}
     */
    private function createSubjectWithMockHttpClient(): array
    {
        $httpClientMock = $this->createHttpClientWithExpectations();

        $subject = new ClaudeProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'claude-sonnet-4-20250514',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $subject->setHttpClient($httpClientMock);

        return ['subject' => $subject, 'httpClient' => $httpClientMock];
    }

    #[Test]
    public function getNameReturnsAnthropicClaude(): void
    {
        self::assertEquals('Anthropic Claude', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsClaude(): void
    {
        self::assertEquals('claude', $this->subject->getIdentifier());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyConfigured(): void
    {
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function chatCompletionReturnsValidResponse(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [
            ['role' => 'user', 'content' => $this->randomPrompt()],
        ];

        $apiResponse = [
            'id' => 'msg_' . $this->faker->uuid(),
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Claude response content',
                ],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 20,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Claude response content', $result->content);
        self::assertEquals('claude-sonnet-4-20250514', $result->model);
        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function chatCompletionWithSystemPromptSendsItSeparately(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hi there']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertEquals('Hi there', $result->content);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOn401(): void
    {
        $errorResponse = [
            'type' => 'error',
            'error' => [
                'type' => 'authentication_error',
                'message' => 'Invalid API key',
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($errorResponse, 401));

        $this->expectException(ProviderResponseException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOn429(): void
    {
        $errorResponse = [
            'type' => 'error',
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($errorResponse, 429));

        $this->expectException(ProviderResponseException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function getAvailableModelsReturnsClaudeModels(): void
    {
        $models = $this->subject->getAvailableModels();

        self::assertNotEmpty($models);
        // Models are returned as key => label pairs
        self::assertArrayHasKey('claude-sonnet-4-20250514', $models);
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
    public function chatCompletionHandlesMultipleContentBlocks(): void
    {
        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'First part. '],
                ['type' => 'text', 'text' => 'Second part.'],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('First part. Second part.', $result->content);
    }

    #[Test]
    public function chatCompletionMapsEndTurnToStop(): void
    {
        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Test']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModel(): void
    {
        self::assertEquals('claude-sonnet-4-20250514', $this->subject->getDefaultModel());
    }

    #[Test]
    public function getDefaultModelReturnsFallbackWhenNotConfigured(): void
    {
        $provider = new ClaudeProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure(['apiKeyIdentifier' => 'test', 'defaultModel' => '']);

        self::assertEquals('claude-sonnet-4-5-20250929', $provider->getDefaultModel());
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
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_123',
                    'name' => 'get_weather',
                    'input' => ['location' => 'San Francisco'],
                ],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
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
        self::assertEquals(['location' => 'San Francisco'], $toolCall['function']['arguments']);
    }

    #[Test]
    public function chatCompletionWithToolsHandlesToolChoice(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]]];
        $options = ['tool_choice' => 'auto'];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Response']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionWithToolsHandlesSystemMessage(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'test'],
        ];
        $tools = [['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]]];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Response']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionWithToolsMixedContentBlocks(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]]];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'Let me help. '],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_123',
                    'name' => 'test',
                    'input' => [],
                ],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 15],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertEquals('Let me help. ', $result->content);
        self::assertNotNull($result->toolCalls);
    }

    #[Test]
    #[DataProvider('toolChoiceProvider')]
    public function chatCompletionWithToolsMapsDifferentToolChoices(mixed $toolChoice): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]]];
        $options = ['tool_choice' => $toolChoice];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Response']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function toolChoiceProvider(): array
    {
        return [
            'auto string' => ['auto'],
            'none string' => ['none'],
            'required string' => ['required'],
            'specific tool name' => ['get_weather'],
            'array format' => [['type' => 'tool', 'name' => 'test']],
        ];
    }

    #[Test]
    public function embeddingsThrowsUnsupportedFeatureException(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('Anthropic Claude does not support embeddings');

        $this->subject->embeddings('test text');
    }

    #[Test]
    public function analyzeImageReturnsVisionResponse(): void
    {
        $content = [
            ['type' => 'text', 'text' => 'Describe this image'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png']],
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'This is a cat']],
            'model' => 'claude-sonnet-4-5-20250929',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->analyzeImage($content);

        self::assertInstanceOf(VisionResponse::class, $result);
        self::assertEquals('This is a cat', $result->description);
    }

    #[Test]
    public function analyzeImageHandlesBase64DataUrl(): void
    {
        $content = [
            ['type' => 'text', 'text' => 'What is this?'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==']],
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Image analysis']],
            'model' => 'claude-sonnet-4-5-20250929',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->analyzeImage($content);

        self::assertInstanceOf(VisionResponse::class, $result);
    }

    #[Test]
    public function analyzeImageWithSystemPrompt(): void
    {
        $content = [['type' => 'text', 'text' => 'Describe']];
        $options = ['system_prompt' => 'Be concise'];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Short description']],
            'model' => 'claude-sonnet-4-5-20250929',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 5],
        ];

        $this->httpClientStub
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
    public function streamChatCompletionYieldsChunks(): void
    {
        $streamData = "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Hello\"}}\n\n"
            . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\" World\"}}\n\n"
            . "data: {\"type\":\"message_stop\"}\n\n";

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
    public function streamChatCompletionWithSystemMessage(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'Be helpful'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $streamData = "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\n"
            . "data: {\"type\":\"message_stop\"}\n\n";

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

        $chunks = iterator_to_array($this->subject->streamChatCompletion($messages));

        self::assertEquals(['Hi'], $chunks);
    }

    #[Test]
    public function streamChatCompletionHandlesMalformedJson(): void
    {
        $streamData = "data: {invalid json}\n\n"
            . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"valid\"}}\n\n"
            . "data: {\"type\":\"message_stop\"}\n\n";

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

        $chunks = iterator_to_array($this->subject->streamChatCompletion([['role' => 'user', 'content' => 'test']]));

        self::assertEquals(['valid'], $chunks);
    }

    #[Test]
    #[DataProvider('stopReasonProvider')]
    public function chatCompletionMapsStopReasons(string $stopReason, string $expectedFinishReason): void
    {
        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Test']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertEquals($expectedFinishReason, $result->finishReason);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function stopReasonProvider(): array
    {
        return [
            'end_turn maps to stop' => ['end_turn', 'stop'],
            'max_tokens maps to length' => ['max_tokens', 'length'],
            'stop_sequence maps to stop' => ['stop_sequence', 'stop'],
            'tool_use maps to tool_calls' => ['tool_use', 'tool_calls'],
            'unknown reason passes through' => ['unknown', 'unknown'],
        ];
    }

    #[Test]
    public function chatCompletionWithOptionalParameters(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $options = [
            'temperature' => 0.5,
            'top_p' => 0.9,
            'stop_sequences' => ['END'],
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Response']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function analyzeImageHandlesMultipleContentBlocks(): void
    {
        $content = [['type' => 'text', 'text' => 'Describe']];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'First part. '],
                ['type' => 'text', 'text' => 'Second part.'],
            ],
            'model' => 'claude-sonnet-4-5-20250929',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->analyzeImage($content);

        self::assertEquals('First part. Second part.', $result->description);
    }

    #[Test]
    public function analyzeImageWithExplicitModel(): void
    {
        $content = [['type' => 'text', 'text' => 'What is this?']];
        $options = ['model' => 'claude-opus-4-5-20251124'];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Analysis']],
            'model' => 'claude-opus-4-5-20251124',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 5],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->analyzeImage($content, $options);

        self::assertEquals('claude-opus-4-5-20251124', $result->model);
    }
}
