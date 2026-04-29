<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
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
}
