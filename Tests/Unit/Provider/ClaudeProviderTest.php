<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(ClaudeProvider::class)]
class ClaudeProviderTest extends AbstractUnitTestCase
{
    private ClaudeProvider $subject;
    private ClientInterface&Stub $httpClientStub;

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
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather for a location',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['location' => ['type' => 'string']],
                    ],
                ],
            ]),
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
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall->name);
        self::assertEquals(['location' => 'San Francisco'], $toolCall->arguments);
    }

    #[Test]
    public function chatCompletionWithToolsHandlesToolChoice(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]])];
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
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]])];

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
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]])];

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
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]])];
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
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe this image']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png']]),
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
            VisionContent::fromArray(['type' => 'text', 'text' => 'What is this?']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/4AAQSkZJRg==']]),
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
        $content = [VisionContent::fromArray(['type' => 'text', 'text' => 'Describe'])];
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
        self::assertNotContains('pdf', $formats);
    }

    #[Test]
    public function supportsDocumentsReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsDocuments());
    }

    #[Test]
    public function getSupportedDocumentFormatsReturnsPdf(): void
    {
        $formats = $this->subject->getSupportedDocumentFormats();

        self::assertContains('pdf', $formats);
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
        $response->method('getStatusCode')->willReturn(200);
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
        $response->method('getStatusCode')->willReturn(200);
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
        $response->method('getStatusCode')->willReturn(200);
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

    /**
     * Anthropic rejects temperature > 1.0 with an HTTP 400, but the
     * configuration layer clamps to the OpenAI-style [0.0, 2.0] range. A
     * configured value in (1.0, 2.0] must therefore be clamped down to 1.0
     * before it reaches the API.
     */
    #[Test]
    public function chatCompletionClampsTemperatureToAnthropicMaximum(): void
    {
        $payload = $this->captureChatPayload(['temperature' => 1.8]);

        $this->assertPayloadTemperature($payload, 1.0);
    }

    #[Test]
    public function chatCompletionKeepsInRangeTemperatureUnchanged(): void
    {
        $payload = $this->captureChatPayload(['temperature' => 0.3]);

        $this->assertPayloadTemperature($payload, 0.3);
    }

    /**
     * The tool-calling path must honour the configured sampling parameters,
     * just like the plain chat path (a Claude tool run previously ignored the
     * configuration's temperature entirely).
     */
    #[Test]
    public function chatCompletionWithToolsAppliesClampedTemperature(): void
    {
        $payload = $this->captureChatPayload(
            ['temperature' => 1.8],
            [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]])],
        );

        $this->assertPayloadTemperature($payload, 1.0);
    }

    /**
     * Assert the (JSON-decoded) payload carries the expected temperature.
     * json_encode() serialises a whole float such as 1.0 as `1`, so the
     * decoded value may be an int — compare numerically after narrowing.
     *
     * @param array<string, mixed> $payload
     */
    private function assertPayloadTemperature(array $payload, float $expected): void
    {
        self::assertArrayHasKey('temperature', $payload);
        $temperature = $payload['temperature'];
        self::assertIsNumeric($temperature);
        self::assertEqualsWithDelta($expected, (float)$temperature, 1e-9);
    }

    /**
     * Run a Claude completion with a stream factory that captures the encoded
     * request payload and return it decoded. When $tools is non-empty the
     * tool-calling path is exercised instead of plain chat.
     *
     * @param array<string, mixed> $options
     * @param list<ToolSpec>       $tools
     *
     * @return array<string, mixed>
     */
    private function captureChatPayload(array $options, array $tools = []): array
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

        $subject = new ClaudeProvider(
            $this->createRequestFactoryMock(),
            $streamFactory,
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
        $subject->setHttpClient($this->httpClientStub);

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

        $messages = [['role' => 'user', 'content' => 'test']];
        if ($tools === []) {
            $subject->chatCompletion($messages, $options);
        } else {
            $subject->chatCompletionWithTools($messages, $tools, $options);
        }

        self::assertIsString($capturedBody);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    #[Test]
    public function analyzeImageHandlesMultipleContentBlocks(): void
    {
        $content = [VisionContent::fromArray(['type' => 'text', 'text' => 'Describe'])];

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
        $content = [VisionContent::fromArray(['type' => 'text', 'text' => 'What is this?'])];
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

    // ===== Thinking extraction tests =====

    #[Test]
    public function chatCompletionExtractsNativeThinkingBlocks(): void
    {
        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Let me reason about this'],
                ['type' => 'text', 'text' => 'The answer is 42'],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([
            ['role' => 'user', 'content' => 'test'],
        ]);

        self::assertEquals('The answer is 42', $result->content);
        self::assertTrue($result->hasThinking());
        self::assertEquals('Let me reason about this', $result->thinking);
    }

    #[Test]
    public function chatCompletionExtractsInlineThinkTags(): void
    {
        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => '<think>Inline reasoning</think>Clean answer'],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([
            ['role' => 'user', 'content' => 'test'],
        ]);

        self::assertEquals('Clean answer', $result->content);
        self::assertTrue($result->hasThinking());
        self::assertEquals('Inline reasoning', $result->thinking);
    }

    #[Test]
    public function chatCompletionReturnsNullThinkingWhenNoThinkingPresent(): void
    {
        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'Plain response'],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([
            ['role' => 'user', 'content' => 'test'],
        ]);

        self::assertEquals('Plain response', $result->content);
        self::assertFalse($result->hasThinking());
        self::assertNull($result->thinking);
    }

    #[Test]
    public function chatCompletionWithToolsExtractsThinking(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]])];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Tool reasoning'],
                ['type' => 'text', 'text' => 'Tool result'],
                ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'test', 'input' => []],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertEquals('Tool result', $result->content);
        self::assertTrue($result->hasThinking());
        self::assertEquals('Tool reasoning', $result->thinking);
        self::assertTrue($result->hasToolCalls());
    }

    // ===== Multimodal content tests =====

    #[Test]
    public function chatCompletionHandlesMultimodalContentArray(): void
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Describe this image'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
                ],
            ],
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'This is an image of a diagram.']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('This is an image of a diagram.', $result->content);
    }

    #[Test]
    public function chatCompletionWithToolsHandlesMultimodalContent(): void
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'What is in this image?'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,/9j/4AAQ']],
                ],
            ],
        ];
        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'describe_image',
                    'description' => 'Describe an image',
                    'parameters' => ['type' => 'object'],
                ],
            ]),
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Image description']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Image description', $result->content);
    }

    #[Test]
    public function chatCompletionPreservesStringContentBackwardCompatible(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello there'],
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hi!']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Hi!', $result->content);
    }

    #[Test]
    public function chatCompletionHandlesDocumentBlocks(): void
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Summarize this document'],
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => 'JVBERi0xLjQ=',
                        ],
                    ],
                ],
            ],
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Document summary']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 10],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Document summary', $result->content);
    }

    #[Test]
    public function chatCompletionWithToolsConvertsToolResultMessages(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'What is the weather?'],
            [
                'role' => 'assistant',
                'content' => 'Let me check.',
                'tool_calls' => [
                    [
                        'id' => 'call_123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => '{"location":"Berlin"}',
                        ],
                    ],
                ],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_123',
                'content' => '{"temp": 20, "condition": "sunny"}',
            ],
        ];

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather for a location',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['location' => ['type' => 'string']],
                    ],
                ],
            ]),
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'The weather in Berlin is 20C and sunny.']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 15],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('The weather in Berlin is 20C and sunny.', $result->content);
    }

    #[Test]
    public function chatCompletionWithToolsConvertsAssistantToolCalls(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Check the weather'],
            [
                'role' => 'assistant',
                'content' => 'I will check.',
                'tool_calls' => [
                    [
                        'id' => 'call_456',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => '{"location":"Munich"}',
                        ],
                    ],
                ],
            ],
            [
                'role' => 'tool',
                'tool_call_id' => 'call_456',
                'content' => '{"temp": 15}',
            ],
        ];

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather',
                    'parameters' => ['type' => 'object'],
                ],
            ]),
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'It is 15C in Munich.']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 40, 'output_tokens' => 10],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('It is 15C in Munich.', $result->content);
    }

    #[Test]
    public function chatCompletionWithToolsConvertsConsecutiveToolResults(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Check weather in two cities'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'call_a',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"location":"Berlin"}'],
                    ],
                    [
                        'id' => 'call_b',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"location":"Munich"}'],
                    ],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_a', 'content' => '{"temp": 20}'],
            ['role' => 'tool', 'tool_call_id' => 'call_b', 'content' => '{"temp": 15}'],
        ];

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'description' => 'Get weather', 'parameters' => ['type' => 'object']],
            ]),
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Berlin 20C, Munich 15C.']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 60, 'output_tokens' => 10],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Berlin 20C, Munich 15C.', $result->content);
    }

    #[Test]
    public function chatCompletionSkipsSystemArrayContent(): void
    {
        $messages = [
            ['role' => 'system', 'content' => ['type' => 'text', 'text' => 'Array system']],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Response']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        // Should not crash - array system content is ignored (set to null)
        $result = $this->subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Response', $result->content);
    }

    #[Test]
    public function testConnectionReturnsSuccessWithModelList(): void
    {
        $apiResponse = [
            'data' => [
                ['id' => 'claude-opus-4-5-20251124', 'type' => 'model'],
                ['id' => 'claude-sonnet-4-5-20250929', 'type' => 'model'],
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->testConnection();

        self::assertTrue($result['success']);
        self::assertStringContainsString('Connection successful', $result['message']);
        self::assertStringContainsString('2 models', $result['message']);
        self::assertArrayHasKey('models', $result);
        assert(isset($result['models']));
        self::assertArrayHasKey('claude-opus-4-5-20251124', $result['models']);
    }

    #[Test]
    public function testConnectionThrowsOnHttpError(): void
    {
        // A static-list provider must NOT silently report success on an
        // unreachable / unauthorized endpoint: the real HTTP call surfaces
        // the typed exception instead.
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(401, 'invalid x-api-key'));

        $this->expectException(ProviderResponseException::class);

        $this->subject->testConnection();
    }

    // ===== Request-payload transformation tests =====

    /**
     * Capture the JSON-encoded request body produced by $invoke and return it
     * decoded. Mirrors captureChatPayload() but works for any endpoint (chat,
     * tools, vision) by delegating the provider call to the caller.
     *
     * @param callable(ClaudeProvider): void $invoke
     *
     * @return array<string, mixed>
     */
    private function captureRequestPayload(callable $invoke): array
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

        $subject = new ClaudeProvider(
            $this->createRequestFactoryMock(),
            $streamFactory,
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
        $subject->setHttpClient($this->httpClientStub);

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

        $invoke($subject);

        self::assertIsString($capturedBody);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Pin down the exact chat request payload: model, default max_tokens, the
     * top-level system field, the converted messages list and the merged
     * sampling params (temperature + stop_sequences).
     */
    #[Test]
    public function chatCompletionRequestPayloadHasExactShape(): void
    {
        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s): void {
                $s->chatCompletion(
                    [
                        ['role' => 'system', 'content' => 'You are helpful.'],
                        ['role' => 'user', 'content' => 'Hello'],
                    ],
                    ['temperature' => 0.4, 'stop_sequences' => ['END']],
                );
            },
        );

        self::assertSame('claude-sonnet-4-20250514', $payload['model']);
        self::assertSame(4096, $payload['max_tokens']);
        self::assertSame('You are helpful.', $payload['system']);
        self::assertSame([['role' => 'user', 'content' => 'Hello']], $payload['messages']);
        self::assertArrayHasKey('temperature', $payload);
        self::assertIsNumeric($payload['temperature']);
        self::assertEqualsWithDelta(0.4, (float)$payload['temperature'], 1e-9);
        self::assertSame(['END'], $payload['stop_sequences']);
    }

    #[Test]
    public function chatCompletionHonoursExplicitMaxTokens(): void
    {
        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s): void {
                $s->chatCompletion(
                    [['role' => 'user', 'content' => 'Hello']],
                    ['max_tokens' => 2048],
                );
            },
        );

        self::assertSame(2048, $payload['max_tokens']);
    }

    #[Test]
    public function chatCompletionOmitsSystemKeyWithoutSystemMessage(): void
    {
        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s): void {
                $s->chatCompletion([['role' => 'user', 'content' => 'Hello']]);
            },
        );

        self::assertArrayNotHasKey('system', $payload);
    }

    #[Test]
    public function chatCompletionConvertsChatMessageObjectsToArrays(): void
    {
        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s): void {
                $s->chatCompletion([ChatMessage::user('Hello from VO')]);
            },
        );

        self::assertSame([['role' => 'user', 'content' => 'Hello from VO']], $payload['messages']);
    }

    #[Test]
    public function chatCompletionCombinesNativeAndInlineThinking(): void
    {
        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'native reason'],
                ['type' => 'text', 'text' => '<think>inline reason</think>Answer'],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);

        self::assertSame('Answer', $result->content);
        self::assertSame("native reason\ninline reason", $result->thinking);
    }

    /**
     * Pin down the exact tool-calling request payload: model, default
     * max_tokens, system field, converted messages, the Claude-shaped tools
     * (name/description/input_schema) and the merged sampling params.
     */
    #[Test]
    public function chatCompletionWithToolsRequestPayloadHasExactShape(): void
    {
        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get weather',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['location' => ['type' => 'string']],
                    ],
                ],
            ]),
        ];

        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s) use ($tools): void {
                $s->chatCompletionWithTools(
                    [
                        ['role' => 'system', 'content' => 'You are helpful.'],
                        ['role' => 'user', 'content' => 'Weather?'],
                    ],
                    $tools,
                    ['temperature' => 0.4, 'stop_sequences' => ['STOP']],
                );
            },
        );

        self::assertSame('claude-sonnet-4-20250514', $payload['model']);
        self::assertSame(4096, $payload['max_tokens']);
        self::assertSame('You are helpful.', $payload['system']);
        self::assertSame([['role' => 'user', 'content' => 'Weather?']], $payload['messages']);
        self::assertSame([
            [
                'name'         => 'get_weather',
                'description'  => 'Get weather',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => ['location' => ['type' => 'string']],
                ],
            ],
        ], $payload['tools']);
        self::assertArrayHasKey('temperature', $payload);
        self::assertIsNumeric($payload['temperature']);
        self::assertEqualsWithDelta(0.4, (float)$payload['temperature'], 1e-9);
        self::assertSame(['STOP'], $payload['stop_sequences']);
    }

    #[Test]
    public function chatCompletionWithToolsConvertsChatMessageObjectsToArrays(): void
    {
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]])];

        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s) use ($tools): void {
                $s->chatCompletionWithTools([ChatMessage::user('VO message')], $tools);
            },
        );

        self::assertSame([['role' => 'user', 'content' => 'VO message']], $payload['messages']);
    }

    #[Test]
    public function chatCompletionWithToolsConcatenatesTextBlocks(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]])];

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
            'usage' => ['input_tokens' => 10, 'output_tokens' => 15],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertSame('First part. Second part.', $result->content);
    }

    #[Test]
    public function chatCompletionWithToolsCombinesNativeAndInlineThinking(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'description' => 'Test', 'parameters' => []]])];

        $apiResponse = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'native reason'],
                ['type' => 'text', 'text' => '<think>inline reason</think>Answer'],
                ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'test', 'input' => []],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletionWithTools($messages, $tools);

        self::assertSame('Answer', $result->content);
        self::assertSame("native reason\ninline reason", $result->thinking);
    }

    #[Test]
    public function embeddingsExceptionCarriesStableCode(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionCode(8109610521);

        $this->subject->embeddings('test text');
    }

    /**
     * Pin down the exact vision request payload: the hardcoded default model,
     * default max_tokens, the user message envelope, the converted vision
     * content blocks (text + plain-URL image) and the system prompt.
     */
    #[Test]
    public function analyzeImageRequestPayloadHasExactShape(): void
    {
        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe this']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/x.png']]),
        ];

        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s) use ($content): void {
                $s->analyzeImage($content, ['system_prompt' => 'Be brief']);
            },
        );

        self::assertSame('claude-sonnet-4-5-20250929', $payload['model']);
        self::assertSame(4096, $payload['max_tokens']);
        self::assertSame('Be brief', $payload['system']);
        self::assertSame([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Describe this'],
                    [
                        'type' => 'image',
                        'source' => ['type' => 'url', 'url' => 'https://example.com/x.png'],
                    ],
                ],
            ],
        ], $payload['messages']);
    }

    #[Test]
    public function analyzeImageOmitsSystemKeyWithoutSystemPrompt(): void
    {
        $content = [VisionContent::fromArray(['type' => 'text', 'text' => 'Describe'])];

        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s) use ($content): void {
                $s->analyzeImage($content);
            },
        );

        self::assertArrayNotHasKey('system', $payload);
    }

    #[Test]
    public function analyzeImageConvertsBase64DataUrlToImageBlock(): void
    {
        $content = [
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']]),
        ];

        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s) use ($content): void {
                $s->analyzeImage($content);
            },
        );

        self::assertSame([
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type'       => 'base64',
                            'media_type' => 'image/png',
                            'data'       => 'iVBORw0KGgo=',
                        ],
                    ],
                ],
            ],
        ], $payload['messages']);
    }

    #[Test]
    public function analyzeImageSkipsNonImageDataUrl(): void
    {
        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'What is this?']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'data:application/pdf;base64,JVBERi0=']]),
        ];

        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s) use ($content): void {
                $s->analyzeImage($content);
            },
        );

        self::assertSame([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'What is this?'],
                ],
            ],
        ], $payload['messages']);
    }

    // ===== Streaming request/parse tests =====

    /**
     * Drive streamChatCompletion() against a stubbed SSE response while
     * capturing the request URL, the encoded JSON request payload, the
     * yielded chunks and the byte counts requested from the response stream.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $options
     * @param list<string>                           $sseChunks raw SSE data served one element per read() call
     *
     * @return array{url: string, payload: array<string, mixed>, chunks: list<string>, readLengths: list<int>}
     */
    private function runStreamingCapture(
        array $messages,
        array $options = [],
        array $sseChunks = ["data: {\"type\":\"message_stop\"}\n"],
        string $baseUrl = '',
        int $statusCode = 200,
    ): array {
        $capturedUrl = null;
        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturnCallback(
            function (string $method, string $uri) use (&$capturedUrl): RequestInterface {
                $capturedUrl = $uri;

                return $this->createRequestMock($method, $uri);
            },
        );

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

        $subject = new ClaudeProvider(
            $requestFactory,
            $streamFactory,
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'claude-sonnet-4-20250514',
            'baseUrl' => $baseUrl,
            'timeout' => 30,
        ]);

        $remainingChunks = $sseChunks;
        /** @var list<int> $readLengths */
        $readLengths = [];
        $responseStream = self::createStub(StreamInterface::class);
        $responseStream->method('eof')->willReturnCallback(
            static function () use (&$remainingChunks): bool {
                return $remainingChunks === [];
            },
        );
        $responseStream->method('read')->willReturnCallback(
            static function (int $length) use (&$remainingChunks, &$readLengths): string {
                $readLengths[] = $length;

                return array_shift($remainingChunks) ?? '';
            },
        );
        $responseStream->method('__toString')->willReturn(implode('', $sseChunks));

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($responseStream);

        $httpClient = $this->createHttpClientMock();
        $httpClient->method('sendRequest')->willReturn($response);
        $subject->setHttpClient($httpClient);

        $chunks = [];
        foreach ($subject->streamChatCompletion($messages, $options) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertIsString($capturedUrl);
        self::assertIsString($capturedBody);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);

        return ['url' => $capturedUrl, 'payload' => $payload, 'chunks' => $chunks, 'readLengths' => $readLengths];
    }

    /**
     * The streaming endpoint URL is the trimmed base URL plus '/messages';
     * a trailing slash on the configured base URL must not double up.
     */
    #[Test]
    public function streamChatCompletionBuildsMessagesEndpointUrlFromBaseUrl(): void
    {
        $result = $this->runStreamingCapture(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            ["data: {\"type\":\"message_stop\"}\n"],
            'https://claude.example/v1/',
        );

        self::assertSame('https://claude.example/v1/messages', $result['url']);
    }

    /**
     * Pin down the exact streaming request payload: model, converted
     * ChatMessage objects, default max_tokens, the stream flag, the top-level
     * system field and the merged (clamped) sampling params.
     */
    #[Test]
    public function streamChatCompletionRequestPayloadHasExactShape(): void
    {
        $result = $this->runStreamingCapture(
            [
                ['role' => 'system', 'content' => 'Be helpful'],
                ChatMessage::user('Hello'),
            ],
            ['temperature' => 1.8],
        );

        $payload = $result['payload'];
        self::assertSame('claude-sonnet-4-20250514', $payload['model']);
        self::assertSame([['role' => 'user', 'content' => 'Hello']], $payload['messages']);
        self::assertSame(4096, $payload['max_tokens']);
        self::assertTrue($payload['stream']);
        self::assertSame('Be helpful', $payload['system']);
        $this->assertPayloadTemperature($payload, 1.0);
    }

    /**
     * JSON_INVALID_UTF8_SUBSTITUTE must be in effect: a non-UTF-8 byte in the
     * message content is replaced with U+FFFD instead of failing the encode.
     */
    #[Test]
    public function streamChatCompletionSubstitutesInvalidUtf8InPayload(): void
    {
        $result = $this->runStreamingCapture([['role' => 'user', 'content' => "caf\xE9"]]);

        self::assertSame([['role' => 'user', 'content' => "caf\u{FFFD}"]], $result['payload']['messages']);
    }

    #[Test]
    public function streamChatCompletionThrowsOnHttpErrorStatus(): void
    {
        $this->expectException(ProviderResponseException::class);

        $this->runStreamingCapture(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            ['{"error":{"message":"bad request"}}'],
            '',
            400,
        );
    }

    #[Test]
    public function streamChatCompletionValidatesConfigurationBeforeRequest(): void
    {
        $subject = new ClaudeProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'claude-sonnet-4-20250514',
            'baseUrl' => '',
            'timeout' => 30,
        ]);
        $subject->setHttpClient($this->createHttpClientMock());

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionCode(1307337100);

        $subject->streamChatCompletion([['role' => 'user', 'content' => 'test']])->current();
    }

    /**
     * A data line split across two read() calls must be re-assembled from the
     * buffer (append, not replace) before parsing. Also pins the 1024-byte
     * chunk size requested per read.
     */
    #[Test]
    public function streamChatCompletionAccumulatesPartialLinesAcrossChunkReads(): void
    {
        $result = $this->runStreamingCapture(
            [['role' => 'user', 'content' => 'Hi']],
            [],
            [
                'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hel',
                "lo\"}}\ndata: {\"type\":\"message_stop\"}\n",
            ],
        );

        self::assertSame(['Hello'], $result['chunks']);
        self::assertSame([1024, 1024], $result['readLengths']);
    }

    /**
     * SSE events separated by a single newline (no blank line) must all be
     * consumed — the buffer advances exactly one byte past each newline.
     */
    #[Test]
    public function streamChatCompletionParsesSingleNewlineSeparatedEvents(): void
    {
        $streamData = "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"A\"}}\n"
            . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"B\"}}\n"
            . "data: {\"type\":\"message_stop\"}\n";

        $result = $this->runStreamingCapture([['role' => 'user', 'content' => 'Hi']], [], [$streamData]);

        self::assertSame(['A', 'B'], $result['chunks']);
    }

    /**
     * Lines without the exact 'data: ' prefix are skipped entirely (even when
     * their tail happens to be valid delta JSON), and unknown event types
     * (e.g. ping) neither yield text nor terminate the stream.
     */
    #[Test]
    public function streamChatCompletionIgnoresNonDataPrefixedAndUnknownEventLines(): void
    {
        $streamData = "data: {\"type\":\"ping\"}\n"
            . "ddata: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"EVIL\"}}\n"
            . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n"
            . "data: {\"type\":\"message_stop\"}\n";

        $result = $this->runStreamingCapture([['role' => 'user', 'content' => 'Hi']], [], [$streamData]);

        self::assertSame(['Hi'], $result['chunks']);
    }

    #[Test]
    public function streamChatCompletionStopsAtMessageStopAndIgnoresLaterDeltas(): void
    {
        $streamData = "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"A\"}}\n"
            . "data: {\"type\":\"message_stop\"}\n"
            . "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_delta\",\"text\":\"B\"}}\n";

        $result = $this->runStreamingCapture([['role' => 'user', 'content' => 'Hi']], [], [$streamData]);

        self::assertSame(['A'], $result['chunks']);
    }

    // ===== Message-conversion payload tests =====

    /**
     * Pin the exact conversation transformation for a full tool round-trip:
     * assistant text + tool_use blocks (JSON-string arguments decoded), tool
     * results accumulated into a user message and flushed before the next
     * non-tool message, later messages preserved, and a trailing tool result
     * flushed as a final user message.
     */
    #[Test]
    public function chatCompletionWithToolsConversationPayloadHasExactShape(): void
    {
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'get_weather', 'description' => 'Get weather', 'parameters' => ['type' => 'object']]])];

        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s) use ($tools): void {
                $s->chatCompletionWithTools(
                    [
                        ['role' => 'user', 'content' => 'What is the weather?'],
                        [
                            'role' => 'assistant',
                            'content' => 'Let me check.',
                            'tool_calls' => [
                                [
                                    'id' => 'call_123',
                                    'type' => 'function',
                                    'function' => ['name' => 'get_weather', 'arguments' => '{"location":"Berlin"}'],
                                ],
                            ],
                        ],
                        ['role' => 'tool', 'tool_call_id' => 'call_123', 'content' => '{"temp":20}'],
                        ['role' => 'user', 'content' => 'And tomorrow?'],
                        ['role' => 'tool', 'tool_call_id' => 'call_999', 'content' => 'late result'],
                    ],
                    $tools,
                );
            },
        );

        self::assertSame([
            ['role' => 'user', 'content' => 'What is the weather?'],
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Let me check.'],
                    ['type' => 'tool_use', 'id' => 'call_123', 'name' => 'get_weather', 'input' => ['location' => 'Berlin']],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'call_123', 'content' => '{"temp":20}'],
                ],
            ],
            ['role' => 'user', 'content' => 'And tomorrow?'],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'call_999', 'content' => 'late result'],
                ],
            ],
        ], $payload['messages']);
    }

    /**
     * A plain assistant text message (no tool_calls key) passes through
     * unchanged — and must not probe the absent tool_calls key (that would
     * raise an undefined-array-key warning under failOnWarning).
     */
    #[Test]
    public function chatCompletionPassesPlainAssistantMessageThrough(): void
    {
        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s): void {
                $s->chatCompletion([
                    ['role' => 'user', 'content' => 'Hi'],
                    ['role' => 'assistant', 'content' => 'Hello there.'],
                    ['role' => 'user', 'content' => 'Thanks'],
                ]);
            },
        );

        self::assertSame([
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'Hello there.'],
            ['role' => 'user', 'content' => 'Thanks'],
        ], $payload['messages']);
    }

    /**
     * Pin the exact multimodal conversion: text pass-through, base64 data URL
     * split into media_type + data, plain URL as url source, and Claude-native
     * document blocks forwarded unchanged.
     */
    #[Test]
    public function chatCompletionMultimodalRequestPayloadHasExactShape(): void
    {
        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s): void {
                $s->chatCompletion([
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Look at these'],
                            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgo=']],
                            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/pic.jpg']],
                            ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'JVBERi0=']],
                        ],
                    ],
                ]);
            },
        );

        self::assertSame([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Look at these'],
                    [
                        'type' => 'image',
                        'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'iVBORw0KGgo='],
                    ],
                    [
                        'type' => 'image',
                        'source' => ['type' => 'url', 'url' => 'https://example.com/pic.jpg'],
                    ],
                    ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => 'JVBERi0=']],
                ],
            ],
        ], $payload['messages']);
    }

    /**
     * MIME-style wrapped base64 contains raw newlines; `.` does not match
     * them, so the end-anchored regex must reject the URL instead of
     * forwarding a silently truncated image payload.
     */
    #[Test]
    public function chatCompletionDropsBase64DataUrlWithEmbeddedNewline(): void
    {
        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s): void {
                $s->chatCompletion([
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Broken image'],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:image/png;base64,iVBOR\nw0KGgo="]],
                        ],
                    ],
                ]);
            },
        );

        self::assertSame([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Broken image'],
                ],
            ],
        ], $payload['messages']);
    }

    /**
     * Same newline-in-data-URL rejection on the vision path: the anchored
     * regex must fail the match, so the image block is skipped entirely.
     */
    #[Test]
    public function analyzeImageSkipsDataUrlWithEmbeddedNewline(): void
    {
        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'What is this?']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => "data:image/png;base64,iVBOR\nw0KGgo="]]),
        ];

        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s) use ($content): void {
                $s->analyzeImage($content);
            },
        );

        self::assertSame([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'What is this?'],
                ],
            ],
        ], $payload['messages']);
    }

    /**
     * Pin the exact Claude tool_choice mapping for every supported input
     * shape (string shortcuts, named tool, array pass-through).
     *
     * @param array<string, string> $expected
     */
    #[Test]
    #[DataProvider('toolChoicePayloadProvider')]
    public function chatCompletionWithToolsMapsToolChoicePayload(mixed $toolChoice, array $expected): void
    {
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'get_weather', 'description' => 'Get weather', 'parameters' => ['type' => 'object']]])];

        $payload = $this->captureRequestPayload(
            static function (ClaudeProvider $s) use ($tools, $toolChoice): void {
                $s->chatCompletionWithTools(
                    [['role' => 'user', 'content' => 'Weather?']],
                    $tools,
                    ['tool_choice' => $toolChoice],
                );
            },
        );

        self::assertSame($expected, $payload['tool_choice']);
    }

    /**
     * @return array<string, array{0: mixed, 1: array<string, string>}>
     */
    public static function toolChoicePayloadProvider(): array
    {
        return [
            'auto maps to type auto' => ['auto', ['type' => 'auto']],
            'none maps to type none' => ['none', ['type' => 'none']],
            'required maps to type any' => ['required', ['type' => 'any']],
            'tool name maps to named tool' => ['get_weather', ['type' => 'tool', 'name' => 'get_weather']],
            'array passes through unchanged' => [['type' => 'tool', 'name' => 'lookup'], ['type' => 'tool', 'name' => 'lookup']],
        ];
    }
}
