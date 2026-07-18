<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(GroqProvider::class)]
class GroqProviderTest extends AbstractUnitTestCase
{
    private GroqProvider $subject;
    private ClientInterface&Stub $httpClientStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = $this->createHttpClientMock();

        $this->subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $this->subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $this->subject->setHttpClient($this->httpClientStub);
    }

    /**
     * Create a provider with a mock HTTP client for expectation testing.
     *
     * @return array{subject: GroqProvider, httpClient: ClientInterface&MockObject}
     */
    private function createSubjectWithMockHttpClient(): array
    {
        $httpClientMock = $this->createHttpClientWithExpectations();

        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $subject->setHttpClient($httpClientMock);

        return ['subject' => $subject, 'httpClient' => $httpClientMock];
    }

    #[Test]
    public function getNameReturnsGroq(): void
    {
        self::assertEquals('Groq', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsGroq(): void
    {
        self::assertEquals('groq', $this->subject->getIdentifier());
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
            'id' => 'chatcmpl-' . $this->faker->uuid(),
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Groq response content',
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
        self::assertEquals('Groq response content', $result->content);
        self::assertEquals('llama-3.3-70b-versatile', $result->model);
        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOn401(): void
    {
        $errorResponse = [
            'error' => [
                'message' => 'Invalid API Key',
                'type' => 'invalid_api_key',
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
            'error' => [
                'message' => 'Rate limit exceeded',
                'type' => 'rate_limit_error',
            ],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($errorResponse, 429));

        $this->expectException(ProviderResponseException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function getAvailableModelsReturnsGroqModels(): void
    {
        $models = $this->subject->getAvailableModels();

        self::assertNotEmpty($models);
        self::assertArrayHasKey('llama-3.3-70b-versatile', $models);
        self::assertArrayHasKey('llama-3.1-8b-instant', $models);
        self::assertArrayHasKey('mixtral-8x7b-32768', $models);
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
    public function chatCompletionWithToolsReturnsToolCalls(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [
            ['role' => 'user', 'content' => 'What is the weather in London?'],
        ];

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_weather',
                    'description' => 'Get current weather',
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
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_abc123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"location": "London"}',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 15,
                'completion_tokens' => 10,
                'total_tokens' => 25,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall->name);
        self::assertEquals(['location' => 'London'], $toolCall->arguments);
    }

    #[Test]
    public function chatCompletionWithToolsSkipsMalformedToolCall(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'parameters' => ['type' => 'object', 'properties' => []]],
            ]),
        ];

        // A tool call with an empty function name must be skipped, not crash the
        // whole completion (ToolCall::tryFromArray returns null for it).
        $apiResponse = [
            'id'     => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model'  => 'llama-3.3-70b-versatile',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role'       => 'assistant',
                        'content'    => '',
                        'tool_calls' => [
                            ['id' => 'call_bad', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '{}']],
                            ['id' => 'call_ok', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"location": "London"}']],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 10, 'total_tokens' => 25],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletionWithTools([['role' => 'user', 'content' => 'weather?']], $tools);

        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
        self::assertSame('get_weather', $result->toolCalls[0]->name);
    }

    #[Test]
    public function embeddingsThrowsUnsupportedFeatureException(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('Groq does not support embeddings');
        // Pin the exception code against Increment/DecrementInteger mutants.
        $this->expectExceptionCode(4840547720);

        $this->subject->embeddings('Test text');
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModel(): void
    {
        self::assertEquals('llama-3.3-70b-versatile', $this->subject->getDefaultModel());
    }

    #[Test]
    public function getFastModelReturnsInstantModel(): void
    {
        self::assertEquals('llama-3.1-8b-instant', GroqProvider::getFastModel());
    }

    #[Test]
    public function getQualityModelReturnsVersatileModel(): void
    {
        self::assertEquals('llama-3.3-70b-versatile', GroqProvider::getQualityModel());
    }

    #[Test]
    public function getVisionModelReturnsVisionPreview(): void
    {
        self::assertEquals('llama-3.2-90b-vision-preview', GroqProvider::getVisionModel());
    }

    #[Test]
    public function chatCompletionWithAllOptions(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [
            ['role' => 'user', 'content' => 'Test'],
        ];

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Response'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $options = [
            'model' => 'llama-3.1-8b-instant',
            'temperature' => 0.5,
            'max_tokens' => 2048,
            'top_p' => 0.9,
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.3,
            'stop' => ['END', '###'],
            'seed' => 42,
        ];

        $result = $subject->chatCompletion($messages, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionWithToolsWithAllOptions(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [['role' => 'user', 'content' => 'Test']];
        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'test_function',
                    'description' => 'A test function',
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ]),
        ];

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Response'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $options = [
            'tool_choice' => 'auto',
            'parallel_tool_calls' => true,
        ];

        $result = $subject->chatCompletionWithTools($messages, $tools, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function getDefaultModelReturnsEmptyStringDefault(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => '',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // Should return the constant default when empty string configured
        self::assertEquals('llama-3.3-70b-versatile', $subject->getDefaultModel());
    }

    #[Test]
    public function chatCompletionWithStopSequence(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Stopped content'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletion(
            [['role' => 'user', 'content' => 'Test']],
            ['stop' => ['END', 'STOP']],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionWithToolsHandlesInvalidJsonArguments(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'tool_call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_data',
                                    'arguments' => 'invalid json',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'get_data',
                    'description' => 'Get data',
                    'parameters' => ['type' => 'object'],
                ],
            ]),
        ];

        $result = $subject->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'Get data']],
            $tools,
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertNotNull($result->toolCalls);
        // Invalid JSON should result in empty array for arguments
        $toolCall = $result->toolCalls[0];
        self::assertEquals([], $toolCall->arguments);
    }

    #[Test]
    public function chatCompletionWithFrequencyPenalty(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Response'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletion(
            [['role' => 'user', 'content' => 'Test']],
            ['frequency_penalty' => 0.5],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionWithPresencePenalty(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Response'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletion(
            [['role' => 'user', 'content' => 'Test']],
            ['presence_penalty' => 0.3],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionWithEmptyChoices(): void
    {
        $apiResponse = [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 0],
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion([['role' => 'user', 'content' => 'Test']]);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('', $result->content);
    }

    #[Test]
    public function chatCompletionWithToolsWithEmptyToolCalls(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'No tools needed',
                        'tool_calls' => [],
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'Test']],
            [],
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('No tools needed', $result->content);
        self::assertNull($result->toolCalls);
    }

    // ==================== Streaming tests ====================

    #[Test]
    public function streamChatCompletionYieldsContent(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        $streamContent = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
                         . "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n"
                         . "data: [DONE]\n";

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $subject->setHttpClient($httpClientMock);

        $messages = [['role' => 'user', 'content' => 'Test']];
        $generator = $subject->streamChatCompletion($messages);

        $chunks = iterator_to_array($generator);

        self::assertEquals(['Hello', ' world'], $chunks);
    }

    #[Test]
    public function streamChatCompletionHandlesEmptyContent(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        $streamContent = "data: {\"choices\":[{\"delta\":{\"content\":\"\"}}]}\n\n"
                         . "data: {\"choices\":[{\"delta\":{\"content\":\"Valid\"}}]}\n\n"
                         . "data: [DONE]\n";

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $subject->setHttpClient($httpClientMock);

        $messages = [['role' => 'user', 'content' => 'Test']];
        $generator = $subject->streamChatCompletion($messages);

        $chunks = iterator_to_array($generator);

        // Empty content chunks should be skipped
        self::assertEquals(['Valid'], $chunks);
    }

    #[Test]
    public function streamChatCompletionSkipsMalformedJson(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        $streamContent = "data: invalid-json\n\n"
                         . "data: {\"choices\":[{\"delta\":{\"content\":\"Valid\"}}]}\n\n"
                         . "data: [DONE]\n";

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $subject->setHttpClient($httpClientMock);

        $messages = [['role' => 'user', 'content' => 'Test']];
        $generator = $subject->streamChatCompletion($messages);

        $chunks = iterator_to_array($generator);

        self::assertEquals(['Valid'], $chunks);
    }

    #[Test]
    public function streamChatCompletionWithTemperatureOption(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        $streamContent = "data: {\"choices\":[{\"delta\":{\"content\":\"Test\"}}]}\n\n"
                         . "data: [DONE]\n";

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $subject->setHttpClient($httpClientMock);

        $messages = [['role' => 'user', 'content' => 'Test']];
        $generator = $subject->streamChatCompletion($messages, ['temperature' => 0.5]);

        $chunks = iterator_to_array($generator);

        self::assertEquals(['Test'], $chunks);
    }

    #[Test]
    public function streamChatCompletionSkipsNonDataLines(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        $streamContent = "some-other-line\n"
                         . "data: {\"choices\":[{\"delta\":{\"content\":\"Content\"}}]}\n\n"
                         . "data: [DONE]\n";

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $subject->setHttpClient($httpClientMock);

        $messages = [['role' => 'user', 'content' => 'Test']];
        $generator = $subject->streamChatCompletion($messages);

        $chunks = iterator_to_array($generator);

        self::assertEquals(['Content'], $chunks);
    }

    #[Test]
    public function testConnectionReturnsSuccessWithModelList(): void
    {
        $apiResponse = [
            'object' => 'list',
            'data' => [
                ['id' => 'llama-3.3-70b-versatile', 'object' => 'model'],
                ['id' => 'mixtral-8x7b-32768', 'object' => 'model'],
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
        self::assertArrayHasKey('llama-3.3-70b-versatile', $result['models']);
    }

    #[Test]
    public function testConnectionThrowsOnHttpError(): void
    {
        // A static-list provider must NOT silently report success on an
        // unreachable / unauthorized endpoint: the real HTTP call surfaces
        // the typed exception instead.
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(401, 'Invalid API Key'));

        $this->expectException(ProviderResponseException::class);

        $this->subject->testConnection();
    }

    // ==================== Request-shape (payload / URL) assertions ====================

    /**
     * Build a GroqProvider whose request/stream factories capture the
     * outgoing HTTP method, URL and JSON body so a test can assert the exact
     * transformation the provider performs.
     *
     * The captured variables MUST be initialised (to null) at the call site.
     *
     * @param string|null $capturedMethod receives the last createRequest() method
     * @param string|null $capturedUrl    receives the last createRequest() URL
     * @param string|null $capturedBody   receives the last createStream() JSON body
     *
     * @return array{subject: GroqProvider, httpClient: ClientInterface&MockObject}
     */
    private function createCapturingSubject(
        ?string &$capturedMethod,
        ?string &$capturedUrl,
        ?string &$capturedBody,
        string $baseUrl = '',
    ): array {
        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturnCallback(
            function (string $method, string $uri) use (&$capturedMethod, &$capturedUrl): RequestInterface {
                $capturedMethod = $method;
                $capturedUrl    = $uri;

                return $this->createRequestMock($method, $uri);
            },
        );

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

        $httpClientMock = $this->createHttpClientWithExpectations();

        $subject = new GroqProvider(
            $requestFactory,
            $streamFactory,
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => $baseUrl,
            'timeout' => 30,
        ]);

        $subject->setHttpClient($httpClientMock);

        return ['subject' => $subject, 'httpClient' => $httpClientMock];
    }

    /**
     * Decode a captured JSON request body into an associative array.
     *
     * @return array<string, mixed>
     */
    private function decodeCapturedBody(?string $body): array
    {
        self::assertIsString($body, 'expected a JSON request body to have been captured');

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Build a chat completion API response fixture with a single choice.
     *
     * @return array<string, mixed>
     */
    private function chatApiResponseFixture(): array
    {
        return [
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];
    }

    /**
     * Build a stream response stub that emits the given SSE payload as a
     * single read.
     */
    private function createStreamResponseStub(string $streamContent, int $statusCode = 200): ResponseInterface
    {
        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);
        $streamStub->method('__toString')->willReturn($streamContent);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn($statusCode);
        $responseStub->method('getBody')->willReturn($streamStub);

        return $responseStub;
    }

    #[Test]
    public function chatCompletionSendsExpectedRequestPayload(): void
    {
        $capturedMethod = null;
        $capturedUrl    = null;
        $capturedBody   = null;

        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createCapturingSubject(
            $capturedMethod,
            $capturedUrl,
            $capturedBody,
        );

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($this->chatApiResponseFixture()));

        $subject->chatCompletion([['role' => 'user', 'content' => 'Ping']]);

        self::assertSame('POST', $capturedMethod);
        // Empty base URL in the fixture configuration produces a RELATIVE URI.
        self::assertSame('/chat/completions', $capturedUrl);

        $body = $this->decodeCapturedBody($capturedBody);

        // ArrayItemRemoval on 'model' / ArrayItem `=>`→`>` on temperature+max_tokens.
        self::assertArrayHasKey('model', $body);
        self::assertSame('llama-3.3-70b-versatile', $body['model']);
        self::assertSame([['role' => 'user', 'content' => 'Ping']], $body['messages']);
        self::assertArrayHasKey('temperature', $body);
        self::assertSame(0.7, $body['temperature']);
        self::assertArrayHasKey('max_tokens', $body);
        self::assertSame(4096, $body['max_tokens']);
        // Without stop/stop_sequences options no `stop` key may appear: pins
        // the `is_array(...)` guard against its `||` mutant (null would leak).
        self::assertArrayNotHasKey('stop', $body);
    }

    #[Test]
    public function chatCompletionMapsStopSequencesToStop(): void
    {
        $capturedMethod = null;
        $capturedUrl    = null;
        $capturedBody   = null;

        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createCapturingSubject(
            $capturedMethod,
            $capturedUrl,
            $capturedBody,
        );

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($this->chatApiResponseFixture()));

        $subject->chatCompletion(
            [['role' => 'user', 'content' => 'Ping']],
            ['stop_sequences' => ['STOP1', 'STOP2']],
        );

        $body = $this->decodeCapturedBody($capturedBody);

        // A non-empty stop_sequences array is copied verbatim to the `stop` key.
        self::assertArrayHasKey('stop', $body);
        self::assertSame(['STOP1', 'STOP2'], $body['stop']);
    }

    #[Test]
    public function chatCompletionOmitsStopForEmptyStopSequences(): void
    {
        $capturedMethod = null;
        $capturedUrl    = null;
        $capturedBody   = null;

        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createCapturingSubject(
            $capturedMethod,
            $capturedUrl,
            $capturedBody,
        );

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($this->chatApiResponseFixture()));

        // An empty stop_sequences array must NOT produce a `stop` key: this pins
        // the `&& $stopSequences !== []` guard against the `||` mutant, which
        // would emit `stop => []`.
        $subject->chatCompletion(
            [['role' => 'user', 'content' => 'Ping']],
            ['stop_sequences' => []],
        );

        $body = $this->decodeCapturedBody($capturedBody);

        self::assertArrayNotHasKey('stop', $body);
    }

    #[Test]
    public function chatCompletionWithToolsSendsExpectedRequestPayload(): void
    {
        $capturedMethod = null;
        $capturedUrl    = null;
        $capturedBody   = null;

        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createCapturingSubject(
            $capturedMethod,
            $capturedUrl,
            $capturedBody,
        );

        $spec = ToolSpec::fromArray([
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'description' => 'Get current weather',
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ]);

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($this->chatApiResponseFixture()));

        $subject->chatCompletionWithTools([['role' => 'user', 'content' => 'Ping']], [$spec]);

        self::assertSame('POST', $capturedMethod);
        self::assertSame('/chat/completions', $capturedUrl);

        $body = $this->decodeCapturedBody($capturedBody);

        self::assertArrayHasKey('model', $body);
        self::assertSame('llama-3.3-70b-versatile', $body['model']);
        self::assertSame([['role' => 'user', 'content' => 'Ping']], $body['messages']);
        // Normalise the expected through the same JSON round-trip the body went
        // through (decodeCapturedBody uses assoc=true), so an empty `properties`
        // object ({}) compares equal on both sides rather than stdClass vs [].
        self::assertSame(json_decode((string)json_encode([$spec->toArray()]), true), $body['tools']);
        self::assertArrayHasKey('temperature', $body);
        self::assertSame(0.7, $body['temperature']);
        self::assertArrayHasKey('max_tokens', $body);
        self::assertSame(4096, $body['max_tokens']);
    }

    // ==================== Streaming request-shape assertions ====================

    #[Test]
    public function streamChatCompletionSendsExpectedRequestAndUrl(): void
    {
        $capturedMethod = null;
        $capturedUrl    = null;
        $capturedBody   = null;

        // Trailing slash on the base URL makes the rtrim() observable: without
        // it the URL would contain a double slash.
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createCapturingSubject(
            $capturedMethod,
            $capturedUrl,
            $capturedBody,
            'https://api.groq.com/openai/v1/',
        );

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createStreamResponseStub(
                "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n"
                . "data: [DONE]\n",
            ));

        $chunks = iterator_to_array($subject->streamChatCompletion([['role' => 'user', 'content' => 'Test']]));

        self::assertSame(['Hi'], $chunks);

        self::assertSame('POST', $capturedMethod);
        self::assertSame('https://api.groq.com/openai/v1/chat/completions', $capturedUrl);

        $body = $this->decodeCapturedBody($capturedBody);

        self::assertArrayHasKey('model', $body);
        self::assertSame('llama-3.3-70b-versatile', $body['model']);
        self::assertSame([['role' => 'user', 'content' => 'Test']], $body['messages']);
        self::assertArrayHasKey('temperature', $body);
        self::assertSame(0.7, $body['temperature']);
        self::assertArrayHasKey('max_tokens', $body);
        self::assertSame(4096, $body['max_tokens']);
        self::assertArrayHasKey('stream', $body);
        self::assertTrue($body['stream']);
    }

    #[Test]
    public function streamChatCompletionSubstitutesInvalidUtf8InPayload(): void
    {
        $capturedMethod = null;
        $capturedUrl    = null;
        $capturedBody   = null;

        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createCapturingSubject(
            $capturedMethod,
            $capturedUrl,
            $capturedBody,
            'https://api.groq.com/openai/v1',
        );

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createStreamResponseStub(
                "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n"
                . "data: [DONE]\n",
            ));

        // An invalid UTF-8 byte must be substituted (JSON_INVALID_UTF8_SUBSTITUTE),
        // never abort the encode: the `|` flag combination is what enables this,
        // so the `&` mutant makes json_encode() return false and breaks the call.
        $chunks = iterator_to_array($subject->streamChatCompletion(
            [['role' => 'user', 'content' => "Bad \xB1 byte"]],
        ));

        self::assertSame(['Hi'], $chunks);

        self::assertIsString($capturedBody);
        // json_encode() (no JSON_UNESCAPED_UNICODE) escapes U+FFFD as the
        // literal six-character ASCII sequence backslash-u-f-f-f-d.
        self::assertStringContainsString('\\ufffd', $capturedBody);
    }

    #[Test]
    public function streamChatCompletionValidatesConfigurationBeforeStreaming(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        // Empty API key identifier: validateConfiguration() must throw before
        // any request is built.
        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->method('sendRequest')->willReturn($this->createStreamResponseStub(
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n"
            . "data: [DONE]\n",
        ));
        $subject->setHttpClient($httpClientMock);

        $this->expectException(ProviderConfigurationException::class);

        iterator_to_array($subject->streamChatCompletion([['role' => 'user', 'content' => 'Test']]));
    }

    #[Test]
    public function streamChatCompletionThrowsOnHttpErrorStatus(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        // A 4xx streaming response must surface a typed exception: this pins the
        // assertStreamingResponseOk() call against its MethodCallRemoval mutant.
        $errorStream = self::createStub(StreamInterface::class);
        $errorStream->method('eof')->willReturn(true);
        $errorStream->method('read')->willReturn('');
        $errorStream->method('__toString')->willReturn('{"error":{"message":"Unauthorized"}}');

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(401);
        $responseStub->method('getBody')->willReturn($errorStream);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);
        $subject->setHttpClient($httpClientMock);

        $this->expectException(ProviderResponseException::class);

        iterator_to_array($subject->streamChatCompletion([['role' => 'user', 'content' => 'Test']]));
    }

    #[Test]
    public function streamChatCompletionAccumulatesBufferAcrossReads(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        // A single SSE line split across two reads: the first chunk carries no
        // newline, so it is only usable once appended to the second chunk. This
        // pins `$buffer .= $chunk` against the `$buffer = $chunk` mutant, which
        // would discard the first chunk and yield nothing.
        $chunk1 = 'data: {"choices":[{"delta":{"content":"Hello"';
        $chunk2 = "}}]}\n\ndata: [DONE]\n";

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, false, true);
        $streamStub->method('read')->willReturnOnConsecutiveCalls($chunk1, $chunk2);

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);
        $subject->setHttpClient($httpClientMock);

        $chunks = iterator_to_array($subject->streamChatCompletion([['role' => 'user', 'content' => 'Test']]));

        self::assertSame(['Hello'], $chunks);
    }

    #[Test]
    public function streamChatCompletionReadsInKilobyteChunks(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        // Pin the SSE read granularity: the stream is consumed in 1024-byte
        // chunks (Increment/DecrementInteger mutants shift it to 1023/1025).
        $streamMock = $this->createMock(StreamInterface::class);
        $streamMock->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamMock->expects(self::once())
            ->method('read')
            ->with(1024)
            ->willReturn(
                "data: {\"choices\":[{\"delta\":{\"content\":\"Chunk\"}}]}\n\n"
                . "data: [DONE]\n",
            );

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getStatusCode')->willReturn(200);
        $responseStub->method('getBody')->willReturn($streamMock);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);
        $subject->setHttpClient($httpClientMock);

        $chunks = iterator_to_array($subject->streamChatCompletion([['role' => 'user', 'content' => 'Test']]));

        self::assertSame(['Chunk'], $chunks);
    }

    #[Test]
    public function streamChatCompletionStopsAtDoneMarker(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        // Content AFTER the [DONE] marker must never be yielded: this pins the
        // `return` at the marker (ReturnRemoval) and the exact `substr($line, 6)`
        // offset — the off-by-one mutant turns the marker into ` [DONE]`, misses
        // the comparison and keeps streaming.
        $streamContent = "data: {\"choices\":[{\"delta\":{\"content\":\"Before\"}}]}\n\n"
                         . "data: [DONE]\n"
                         . "data: {\"choices\":[{\"delta\":{\"content\":\"After\"}}]}\n\n";

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createStreamResponseStub($streamContent));
        $subject->setHttpClient($httpClientMock);

        $chunks = iterator_to_array($subject->streamChatCompletion([['role' => 'user', 'content' => 'Test']]));

        self::assertSame(['Before'], $chunks);
    }

    #[Test]
    public function streamChatCompletionSkipsChunksBeyondJsonDepthLimit(): void
    {
        $subject = new GroqProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'llama-3.3-70b-versatile',
            'baseUrl' => 'https://api.groq.com/openai/v1',
            'timeout' => 30,
        ]);

        // Pin the json_decode() depth limit of 512 from BOTH sides:
        // - a chunk with 510 nested arrays inside the object (total depth 511)
        //   decodes at depth 512 but fails at 511 → kills the DecrementInteger
        //   mutant (which would drop "at-limit");
        // - a chunk with 511 nested arrays (total depth 512+1) throws at 512
        //   but decodes at 513 → kills the IncrementInteger mutant (which
        //   would additionally yield "beyond-limit").
        $atLimit = '{"choices":[{"delta":{"content":"at-limit"}}],"pad":'
                   . str_repeat('[', 510) . '1' . str_repeat(']', 510) . '}';
        $beyondLimit = '{"choices":[{"delta":{"content":"beyond-limit"}}],"pad":'
                       . str_repeat('[', 511) . '1' . str_repeat(']', 511) . '}';

        $streamContent = 'data: ' . $atLimit . "\n\n"
                         . 'data: ' . $beyondLimit . "\n\n"
                         . "data: [DONE]\n";

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createStreamResponseStub($streamContent));
        $subject->setHttpClient($httpClientMock);

        $chunks = iterator_to_array($subject->streamChatCompletion([['role' => 'user', 'content' => 'Test']]));

        self::assertSame(['at-limit'], $chunks);
    }
}
