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
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
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
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
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
    public function chatCompletionMapsJsonResponseFormatToJsonObject(): void
    {
        $payload = $this->captureChatCompletionPayload(['response_format' => 'json']);

        self::assertArrayHasKey('response_format', $payload);
        self::assertSame(['type' => 'json_object'], $payload['response_format']);
    }

    #[Test]
    public function chatCompletionOmitsResponseFormatForTextAndWhenUnset(): void
    {
        self::assertArrayNotHasKey(
            'response_format',
            $this->captureChatCompletionPayload(['response_format' => 'text']),
        );
        self::assertArrayNotHasKey(
            'response_format',
            $this->captureChatCompletionPayload([]),
        );
    }

    #[Test]
    public function chatCompletionMapsStopSequencesOptionToStopPayloadKey(): void
    {
        // ChatOptions::toArray() emits stop sequences under `stop_sequences`;
        // the OpenAI-compatible API expects them under `stop`.
        $payload = $this->captureChatCompletionPayload(['stop_sequences' => ['END', '###']]);

        self::assertArrayHasKey('stop', $payload);
        self::assertSame(['END', '###'], $payload['stop']);
    }

    #[Test]
    public function chatCompletionOmitsStopForEmptyOrUnsetStopSequences(): void
    {
        self::assertArrayNotHasKey('stop', $this->captureChatCompletionPayload(['stop_sequences' => []]));
        self::assertArrayNotHasKey('stop', $this->captureChatCompletionPayload([]));
    }

    /**
     * Run chatCompletion with the given options and return the JSON request body the
     * provider hands to the stream factory, so payload shaping can be asserted directly.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function captureChatCompletionPayload(array $options): array
    {
        $captured = null;
        $streamFactory = self::createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content) use (&$captured): StreamInterface {
                $captured = $content;
                $stream = self::createStub(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);
                $stream->method('getContents')->willReturn($content);
                return $stream;
            },
        );

        $subject = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $streamFactory,
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

        $httpClientStub = $this->createHttpClientMock();
        $httpClientStub->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'id' => 'chatcmpl-test',
            'choices' => [['message' => ['content' => '{}'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]));
        $subject->setHttpClient($httpClientStub);

        $subject->chatCompletion([['role' => 'user', 'content' => 'reply in json']], $options);

        self::assertIsString($captured);
        $decoded = json_decode($captured, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall->name);
        self::assertEquals(['location' => 'Tokyo'], $toolCall->arguments);
    }

    #[Test]
    public function chatCompletionWithToolsAndToolChoice(): void
    {
        $messages = [['role' => 'user', 'content' => 'Get weather']];
        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'parameters' => []],
            ]),
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
    public function chatCompletionWithToolsSendsTypedToolTurnsOnTheWire(): void
    {
        // Transport-path proof for #345: an assistantToolCalls + toolResult
        // ChatMessage pair must reach the HTTP payload intact — nothing between
        // the public API and the request body may flatten them to role+content.
        $captured = null;
        $streamFactory = self::createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content) use (&$captured): StreamInterface {
                $captured = $content;
                $stream = self::createStub(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);
                $stream->method('getContents')->willReturn($content);

                return $stream;
            },
        );

        $subject = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $streamFactory,
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

        $httpClientStub = $this->createHttpClientMock();
        $httpClientStub->method('sendRequest')->willReturn($this->createJsonResponseMock([
            'id' => 'chatcmpl-test',
            'choices' => [['message' => ['content' => 'Sunny, 20 °C.'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]));
        $subject->setHttpClient($httpClientStub);

        $messages = [
            ChatMessage::user('What is the weather in Leipzig?'),
            ChatMessage::assistantToolCalls([new ToolCall('call_1', 'get_weather', ['location' => 'Leipzig'])]),
            ChatMessage::toolResult('call_1', '{"temp": 20}'),
        ];
        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'parameters' => ['type' => 'object', 'properties' => []]],
            ]),
        ];

        $subject->chatCompletionWithTools($messages, $tools);

        self::assertIsString($captured);
        $payload = json_decode($captured, true);
        self::assertIsArray($payload);
        assert(isset($payload['messages']));

        self::assertSame([
            [
                'role' => 'user',
                'content' => 'What is the weather in Leipzig?',
            ],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => '{"location":"Leipzig"}',
                        ],
                    ],
                ],
            ],
            [
                'role' => 'tool',
                'content' => '{"temp": 20}',
                'tool_call_id' => 'call_1',
            ],
        ], $payload['messages']);
    }

    #[Test]
    public function analyzeImageReturnsVisionResponse(): void
    {
        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'What is in this image?']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.jpg']]),
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
            VisionContent::fromArray(['type' => 'text', 'text' => 'Describe this']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,abc123']]),
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
        $response->method('getStatusCode')->willReturn(200);
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
            ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'parameters' => []]]),
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
            ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'get_weather', 'parameters' => []]]),
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
        $toolCall = $result->toolCalls[0];
        // Invalid JSON arguments should fall back to empty array
        self::assertEquals([], $toolCall->arguments);
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
        $response->method('getStatusCode')->willReturn(200);
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
        $response->method('getStatusCode')->willReturn(200);
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
            VisionContent::fromArray(['type' => 'text', 'text' => 'What is this?']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/img.jpg']]),
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

    // ===== Thinking extraction tests =====

    #[Test]
    public function chatCompletionExtractsThinkingFromContent(): void
    {
        $apiResponse = [
            'id' => 'chatcmpl-test',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => '<think>Let me reason about this</think>The answer is 42.',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([
            ['role' => 'user', 'content' => 'test'],
        ]);

        self::assertEquals('The answer is 42.', $result->content);
        self::assertTrue($result->hasThinking());
        self::assertEquals('Let me reason about this', $result->thinking);
    }

    #[Test]
    public function chatCompletionReturnsNullThinkingWhenNoTags(): void
    {
        $apiResponse = [
            'id' => 'chatcmpl-test',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Plain response without thinking.',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([
            ['role' => 'user', 'content' => 'test'],
        ]);

        self::assertEquals('Plain response without thinking.', $result->content);
        self::assertFalse($result->hasThinking());
        self::assertNull($result->thinking);
    }

    // ===== Multimodal content tests =====

    #[Test]
    public function chatCompletionAcceptsMultimodalContentWithoutError(): void
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
            'id' => 'test',
            'choices' => [
                [
                    'message' => ['content' => 'This is an image of a diagram.'],
                    'finish_reason' => 'stop',
                ],
            ],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'total_tokens' => 110],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('This is an image of a diagram.', $result->content);
        // OpenAI provider passes messages verbatim — no conversion needed.
        // Payload assertion deferred: the stub-based test pattern does not support
        // request capture. The test verifies multimodal input is accepted without errors.
    }

    // ===== Request payload shaping (chatCompletion) =====

    #[Test]
    public function chatCompletionSendsModelMessagesTokensAndTemperature(): void
    {
        $payload = $this->captureChatCompletionPayload([]);

        // Configured defaultModel is `gpt-4o`; captureChatCompletionPayload() sends a
        // single user message with the literal content below.
        self::assertSame('gpt-4o', $payload['model']);
        self::assertSame([['role' => 'user', 'content' => 'reply in json']], $payload['messages']);

        // Default max token budget is 4096.
        self::assertArrayHasKey('max_completion_tokens', $payload);
        self::assertSame(4096, $payload['max_completion_tokens']);

        // `gpt-4o` is not a reasoning model, so sampling params are spread in with the
        // default temperature of 0.7.
        self::assertArrayHasKey('temperature', $payload);
        self::assertSame(0.7, $payload['temperature']);
    }

    #[Test]
    public function chatCompletionMergesAllSamplingParamsFlatIntoPayload(): void
    {
        // buildSamplingParams() returns temperature plus top_p; the spread must merge
        // BOTH flat into the payload (not just the first entry, not nested).
        $payload = $this->captureChatCompletionPayload(['top_p' => 0.9]);

        self::assertArrayHasKey('temperature', $payload);
        self::assertSame(0.7, $payload['temperature']);
        self::assertArrayHasKey('top_p', $payload);
        self::assertSame(0.9, $payload['top_p']);
    }

    #[Test]
    public function chatCompletionStripsSamplingParamsForReasoningModel(): void
    {
        // `gpt-5.2` is a reasoning model: buildSamplingParams() returns [] so no
        // temperature key is emitted, while the model itself is still sent.
        $payload = $this->captureChatCompletionPayload(['model' => 'gpt-5.2']);

        self::assertSame('gpt-5.2', $payload['model']);
        self::assertArrayNotHasKey('temperature', $payload);
    }

    // ===== Request payload shaping (chatCompletionWithTools) =====

    #[Test]
    public function chatCompletionWithToolsSendsModelToolsTokensAndTemperature(): void
    {
        $payload = $this->captureToolsPayload(
            [['role' => 'user', 'content' => 'hi']],
            [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'get_time', 'parameters' => []]])],
            [],
        );

        self::assertSame('gpt-4o', $payload['model']);
        self::assertSame([['role' => 'user', 'content' => 'hi']], $payload['messages']);

        // ToolSpec::toArray() shape with defaulted description ('') and parameters ([]).
        self::assertSame([
            ['type' => 'function', 'function' => ['name' => 'get_time', 'description' => '', 'parameters' => []]],
        ], $payload['tools']);

        self::assertArrayHasKey('max_completion_tokens', $payload);
        self::assertSame(4096, $payload['max_completion_tokens']);
        self::assertArrayHasKey('temperature', $payload);
        self::assertSame(0.7, $payload['temperature']);
    }

    #[Test]
    public function chatCompletionWithToolsMapsJsonResponseFormat(): void
    {
        $payload = $this->captureToolsPayload(
            [['role' => 'user', 'content' => 'hi']],
            [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'get_time', 'parameters' => []]])],
            ['response_format' => 'json'],
        );

        self::assertArrayHasKey('response_format', $payload);
        self::assertSame(['type' => 'json_object'], $payload['response_format']);
    }

    // ===== Request payload shaping (embeddings) =====

    #[Test]
    public function embeddingsSendsDefaultModelAndWrapsStringInputAsList(): void
    {
        $payload = $this->captureEmbeddingsPayload('hello', []);

        // embeddings() falls back to DEFAULT_EMBEDDING_MODEL (not the configured chat model).
        self::assertSame('text-embedding-3-small', $payload['model']);
        // A scalar string input is wrapped into a single-element list.
        self::assertSame(['hello'], $payload['input']);
    }

    #[Test]
    public function embeddingsSendsArrayInputVerbatim(): void
    {
        $payload = $this->captureEmbeddingsPayload(['a', 'b'], []);

        self::assertSame(['a', 'b'], $payload['input']);
    }

    #[Test]
    public function embeddingsCastsEmbeddingValuesToFloatAndZeroesCompletionTokens(): void
    {
        $apiResponse = [
            'object' => 'list',
            'data' => [
                ['embedding' => [0, 1, 0.5], 'index' => 0],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->embeddings('test');

        // Integer JSON values (0, 1) must be coerced to float, not left as int.
        self::assertSame([0.0, 1.0, 0.5], $result->embeddings[0]);
        // completionTokens is hard-wired to 0 for embeddings; totalTokens == promptTokens.
        self::assertSame(0, $result->usage->completionTokens);
        self::assertSame(7, $result->usage->totalTokens);
    }

    // ===== Request payload shaping (analyzeImage) =====

    #[Test]
    public function analyzeImageSendsSingleUserMessageWithModelAndTokens(): void
    {
        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'What is this?']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/i.jpg']]),
        ];

        $payload = $this->captureVisionPayload($content, []);

        $messages = $payload['messages'];
        self::assertIsArray($messages);
        self::assertCount(1, $messages);
        $userMessage = $messages[0];
        self::assertIsArray($userMessage);
        self::assertSame('user', $userMessage['role']);

        // analyzeImage() defaults the model to the literal 'gpt-5.2'.
        self::assertSame('gpt-5.2', $payload['model']);
        self::assertArrayHasKey('max_completion_tokens', $payload);
        self::assertSame(4096, $payload['max_completion_tokens']);
    }

    #[Test]
    public function analyzeImagePrependsSystemPromptMessage(): void
    {
        $content = [
            VisionContent::fromArray(['type' => 'text', 'text' => 'What is this?']),
            VisionContent::fromArray(['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/i.jpg']]),
        ];

        $payload = $this->captureVisionPayload($content, ['system_prompt' => 'be brief']);

        $messages = $payload['messages'];
        self::assertIsArray($messages);
        self::assertCount(2, $messages);
        // System prompt is unshifted ahead of the user turn as a full role/content pair.
        self::assertSame(['role' => 'system', 'content' => 'be brief'], $messages[0]);
        $userMessage = $messages[1];
        self::assertIsArray($userMessage);
        self::assertSame('user', $userMessage['role']);
    }

    // ===== Request shaping (streamChatCompletion) =====

    #[Test]
    public function streamChatCompletionSendsExpectedUrlAndPayload(): void
    {
        $capturedUrl  = null;
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

        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturnCallback(
            function (string $method, string $uri) use (&$capturedUrl): RequestInterface {
                $capturedUrl = $uri;
                $request = self::createStub(RequestInterface::class);
                $request->method('withHeader')->willReturnCallback(fn(): RequestInterface => $request);
                $request->method('withBody')->willReturnCallback(fn(): RequestInterface => $request);

                return $request;
            },
        );

        $subject = new OpenAiProvider(
            $requestFactory,
            $streamFactory,
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        // Trailing slash on the base URL makes the rtrim() + '/' concatenation observable.
        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gpt-4o',
            'baseUrl' => 'https://api.example.test/v1/',
            'timeout' => 30,
        ]);

        $responseStream = self::createStub(StreamInterface::class);
        $eofCallCount = 0;
        $responseStream->method('eof')->willReturnCallback(function () use (&$eofCallCount): bool {
            return ++$eofCallCount > 1;
        });
        $responseStream->method('read')->willReturn("data: [DONE]\n\n");

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($responseStream);

        $httpClient = $this->createHttpClientMock();
        $httpClient->method('sendRequest')->willReturn($response);
        $subject->setHttpClient($httpClient);

        foreach ($subject->streamChatCompletion([['role' => 'user', 'content' => 'hi']]) as $ignored) {
            // Drain the generator so the request is built and dispatched.
            unset($ignored);
        }

        self::assertSame('https://api.example.test/v1/chat/completions', $capturedUrl);

        self::assertIsString($capturedBody);
        $payload = json_decode($capturedBody, true);
        self::assertIsArray($payload);

        self::assertSame('gpt-4o', $payload['model']);
        self::assertSame([['role' => 'user', 'content' => 'hi']], $payload['messages']);
        self::assertArrayHasKey('max_completion_tokens', $payload);
        self::assertSame(4096, $payload['max_completion_tokens']);
        self::assertArrayHasKey('stream', $payload);
        self::assertTrue($payload['stream']);
        self::assertArrayHasKey('temperature', $payload);
        self::assertSame(0.7, $payload['temperature']);
    }

    #[Test]
    public function streamChatCompletionValidatesConfigurationBeforeStreaming(): void
    {
        // No configure() call: the API key identifier stays empty, so
        // validateConfiguration() must throw before any streaming begins.
        $subject = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        // A benign response so that, if the validation guard were removed, the
        // generator would complete silently and this test would fail instead.
        $responseStream = self::createStub(StreamInterface::class);
        $responseStream->method('eof')->willReturn(true);
        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($responseStream);
        $httpClient = $this->createHttpClientMock();
        $httpClient->method('sendRequest')->willReturn($response);
        $subject->setHttpClient($httpClient);

        $this->expectException(ProviderConfigurationException::class);

        foreach ($subject->streamChatCompletion([['role' => 'user', 'content' => 'hi']]) as $ignored) {
            unset($ignored);
        }
    }

    #[Test]
    public function streamChatCompletionThrowsOnErrorStatus(): void
    {
        $errorBody = json_encode(['error' => ['message' => 'bad stream request']], JSON_THROW_ON_ERROR);

        $responseStream = self::createStub(StreamInterface::class);
        $responseStream->method('__toString')->willReturn($errorBody);
        $responseStream->method('eof')->willReturn(true);
        $responseStream->method('read')->willReturn('');

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getBody')->willReturn($responseStream);

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($response);

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('bad stream request');

        foreach ($this->subject->streamChatCompletion([['role' => 'user', 'content' => 'hi']]) as $ignored) {
            unset($ignored);
        }
    }

    #[Test]
    public function streamChatCompletionStopsAtDoneMarker(): void
    {
        // Content deliberately follows the [DONE] marker: it must NOT be yielded,
        // proving the marker is detected (substr offset) and the loop returns.
        $streamData = "data: {\"choices\":[{\"delta\":{\"content\":\"A\"}}]}\n\n"
            . "data: [DONE]\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"B\"}}]}\n\n";

        $stream = self::createStub(StreamInterface::class);
        $eofCallCount = 0;
        $stream->method('eof')->willReturnCallback(function () use (&$eofCallCount): bool {
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

        self::assertSame(['A'], $chunks);
    }

    #[Test]
    public function streamChatCompletionReassemblesLinesSplitAcrossReads(): void
    {
        // A single SSE line arrives split across two reads; the buffer must
        // accumulate (concatenate) across iterations to reassemble it.
        $reads = [
            'data: {"choices":[{"delta":{"content":"Hel',
            "lo\"}}]}\n\ndata: [DONE]\n\n",
        ];
        $readIndex = 0;

        $stream = self::createStub(StreamInterface::class);
        $eofCallCount = 0;
        $stream->method('eof')->willReturnCallback(function () use (&$eofCallCount): bool {
            return ++$eofCallCount > 2;
        });
        $stream->method('read')->willReturnCallback(function () use (&$readIndex, $reads): string {
            return $reads[$readIndex++] ?? '';
        });

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

        self::assertSame(['Hello'], $chunks);
    }

    #[Test]
    public function streamChatCompletionSubstitutesInvalidUtf8InPayload(): void
    {
        // A message carrying an invalid UTF-8 byte must be JSON-encoded with the
        // substitute flag rather than aborting the stream setup.
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

        $subject = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $streamFactory,
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

        $responseStream = self::createStub(StreamInterface::class);
        $responseStream->method('eof')->willReturn(true);
        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($responseStream);
        $httpClient = $this->createHttpClientMock();
        $httpClient->method('sendRequest')->willReturn($response);
        $subject->setHttpClient($httpClient);

        foreach ($subject->streamChatCompletion([['role' => 'user', 'content' => "bad \xB1 byte"]]) as $ignored) {
            unset($ignored);
        }

        // Encoding succeeded (substitute flag), so a JSON body reached the factory.
        self::assertIsString($capturedBody);
        $payload = json_decode($capturedBody, true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('messages', $payload);
    }

    /**
     * Build a provider whose JSON request body is captured into $captured (by
     * reference), with the HTTP client stubbed to return $apiResponse.
     *
     * @param array<string, mixed> $apiResponse
     */
    private function createCapturingSubject(?string &$captured, array $apiResponse): OpenAiProvider
    {
        $streamFactory = self::createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content) use (&$captured): StreamInterface {
                $captured = $content;
                $stream = self::createStub(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);
                $stream->method('getContents')->willReturn($content);

                return $stream;
            },
        );

        $subject = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $streamFactory,
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

        $httpClientStub = $this->createHttpClientMock();
        $httpClientStub->method('sendRequest')->willReturn($this->createJsonResponseMock($apiResponse));
        $subject->setHttpClient($httpClientStub);

        return $subject;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeCaptured(?string $captured): array
    {
        self::assertIsString($captured);
        $decoded = json_decode($captured, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Run chatCompletionWithTools and return the decoded JSON request body.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec>                         $tools
     * @param array<string, mixed>                   $options
     *
     * @return array<string, mixed>
     */
    private function captureToolsPayload(array $messages, array $tools, array $options): array
    {
        $captured = null;
        $subject  = $this->createCapturingSubject($captured, [
            'id' => 'chatcmpl-test',
            'choices' => [['message' => ['content' => '{}'], 'finish_reason' => 'stop']],
            'model' => 'gpt-4o',
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]);

        $subject->chatCompletionWithTools($messages, $tools, $options);

        return $this->decodeCaptured($captured);
    }

    /**
     * Run embeddings and return the decoded JSON request body.
     *
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     *
     * @return array<string, mixed>
     */
    private function captureEmbeddingsPayload(string|array $input, array $options): array
    {
        $captured = null;
        $subject  = $this->createCapturingSubject($captured, [
            'object' => 'list',
            'data' => [['embedding' => [0.1], 'index' => 0]],
            'model' => 'text-embedding-3-small',
            'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
        ]);

        $subject->embeddings($input, $options);

        return $this->decodeCaptured($captured);
    }

    /**
     * Run analyzeImage and return the decoded JSON request body.
     *
     * @param list<VisionContent>  $content
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function captureVisionPayload(array $content, array $options): array
    {
        $captured = null;
        $subject  = $this->createCapturingSubject($captured, [
            'id' => 'chatcmpl-test',
            'model' => 'gpt-5.2',
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]);

        $subject->analyzeImage($content, $options);

        return $this->decodeCaptured($captured);
    }
}
