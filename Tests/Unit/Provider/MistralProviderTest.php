<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\MistralProvider;
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

#[CoversClass(MistralProvider::class)]
class MistralProviderTest extends AbstractUnitTestCase
{
    private MistralProvider $subject;
    private ClientInterface&Stub $httpClientStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = $this->createHttpClientMock();

        $this->subject = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $this->subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $this->subject->setHttpClient($this->httpClientStub);
    }

    /**
     * Create a provider with a mock HTTP client for expectation testing.
     *
     * @return array{subject: MistralProvider, httpClient: ClientInterface&MockObject}
     */
    private function createSubjectWithMockHttpClient(): array
    {
        $httpClientMock = $this->createHttpClientWithExpectations();

        $subject = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
            'baseUrl' => '',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $subject->setHttpClient($httpClientMock);

        return ['subject' => $subject, 'httpClient' => $httpClientMock];
    }

    #[Test]
    public function getNameReturnsMistralAi(): void
    {
        self::assertEquals('Mistral AI', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsMistral(): void
    {
        self::assertEquals('mistral', $this->subject->getIdentifier());
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
            'id' => 'cmpl-' . $this->faker->uuid(),
            'object' => 'chat.completion',
            'model' => 'mistral-large-latest',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Mistral response content',
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
        self::assertEquals('Mistral response content', $result->content);
        self::assertEquals('mistral-large-latest', $result->model);
        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOn401(): void
    {
        $errorResponse = [
            'error' => [
                'message' => 'Invalid API Key',
                'type' => 'authentication_error',
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
    public function getAvailableModelsReturnsMistralModels(): void
    {
        $models = $this->subject->getAvailableModels();

        self::assertNotEmpty($models);
        self::assertArrayHasKey('mistral-large-latest', $models);
        self::assertArrayHasKey('mistral-small-latest', $models);
        self::assertArrayHasKey('codestral-latest', $models);
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
            ['role' => 'user', 'content' => 'What is the weather in Paris?'],
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
            'id' => 'cmpl-test',
            'object' => 'chat.completion',
            'model' => 'mistral-large-latest',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_xyz789',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_weather',
                                    'arguments' => '{"location": "Paris"}',
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
        self::assertEquals(['location' => 'Paris'], $toolCall->arguments);
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
            'id'     => 'cmpl-test',
            'object' => 'chat.completion',
            'model'  => 'mistral-large-latest',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role'       => 'assistant',
                        'content'    => '',
                        'tool_calls' => [
                            ['id' => 'call_bad', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '{}']],
                            ['id' => 'call_ok', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"location": "Paris"}']],
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
    public function embeddingsReturnsValidResponse(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'id' => 'emb-' . $this->faker->uuid(),
            'object' => 'list',
            'model' => 'mistral-embed',
            'data' => [
                [
                    'index' => 0,
                    'object' => 'embedding',
                    'embedding' => [0.1, 0.2, 0.3, 0.4, 0.5],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 5,
                'total_tokens' => 5,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings('Test text');

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(1, $result->embeddings);
        self::assertEquals([0.1, 0.2, 0.3, 0.4, 0.5], $result->embeddings[0]);
    }

    #[Test]
    public function embeddingsWithMultipleInputsReturnsMultipleEmbeddings(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'id' => 'emb-' . $this->faker->uuid(),
            'object' => 'list',
            'model' => 'mistral-embed',
            'data' => [
                [
                    'index' => 0,
                    'object' => 'embedding',
                    'embedding' => [0.1, 0.2, 0.3],
                ],
                [
                    'index' => 1,
                    'object' => 'embedding',
                    'embedding' => [0.4, 0.5, 0.6],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'total_tokens' => 10,
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings(['First text', 'Second text']);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(2, $result->embeddings);
    }

    #[Test]
    public function getDefaultModelReturnsConfiguredModel(): void
    {
        self::assertEquals('mistral-large-latest', $this->subject->getDefaultModel());
    }

    #[Test]
    public function getCodeModelReturnsCodestral(): void
    {
        self::assertEquals('codestral-latest', MistralProvider::getCodeModel());
    }

    #[Test]
    public function getSmallModelReturnsMistralSmall(): void
    {
        self::assertEquals('mistral-small-latest', MistralProvider::getSmallModel());
    }

    #[Test]
    public function chatCompletionWithAllOptions(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [['role' => 'user', 'content' => 'Test']];

        $apiResponse = [
            'id' => 'cmpl-test',
            'object' => 'chat.completion',
            'model' => 'mistral-large-latest',
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
            'model' => 'mistral-small-latest',
            'temperature' => 0.5,
            'max_tokens' => 2048,
            'top_p' => 0.9,
            'seed' => 42,
            'safe_prompt' => true,
        ];

        $result = $subject->chatCompletion($messages, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionWithToolsWithToolChoice(): void
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
            'id' => 'cmpl-test',
            'object' => 'chat.completion',
            'model' => 'mistral-large-latest',
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
        ];

        $result = $subject->chatCompletionWithTools($messages, $tools, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function embeddingsWithEncodingFormat(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'id' => 'emb-test',
            'object' => 'list',
            'model' => 'mistral-embed',
            'data' => [
                ['index' => 0, 'object' => 'embedding', 'embedding' => [0.1, 0.2]],
            ],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings('Test', ['encoding_format' => 'float']);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
    }

    #[Test]
    public function getDefaultModelReturnsConstantWhenEmptyString(): void
    {
        $subject = new MistralProvider(
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

        self::assertEquals('mistral-large-latest', $subject->getDefaultModel());
    }

    #[Test]
    public function chatCompletionWithToolsHandlesInvalidJsonArguments(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [['role' => 'user', 'content' => 'Test']];
        $tools = [
            ToolSpec::fromArray([
                'type' => 'function',
                'function' => [
                    'name' => 'test_function',
                    'description' => 'Test',
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ]),
        ];

        $apiResponse = [
            'id' => 'cmpl-test',
            'object' => 'chat.completion',
            'model' => 'mistral-large-latest',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            [
                                'id' => 'call_123',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'test_function',
                                    'arguments' => 'invalid-json',
                                ],
                            ],
                        ],
                    ],
                    'finish_reason' => 'tool_calls',
                ],
            ],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletionWithTools($messages, $tools);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
        // Invalid JSON should return empty array for arguments
        $toolCall = $result->toolCalls[0];
        self::assertEquals([], $toolCall->arguments);
    }

    // ==================== Streaming tests ====================

    #[Test]
    public function streamChatCompletionYieldsContent(): void
    {
        $subject = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
            'baseUrl' => 'https://api.mistral.ai/v1',
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
    public function streamChatCompletionSkipsMalformedJson(): void
    {
        $subject = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
            'baseUrl' => 'https://api.mistral.ai/v1',
            'timeout' => 30,
        ]);

        $streamContent = "data: not-valid-json\n\n"
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
    public function streamChatCompletionSkipsEmptyContent(): void
    {
        $subject = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
            'baseUrl' => 'https://api.mistral.ai/v1',
            'timeout' => 30,
        ]);

        $streamContent = "data: {\"choices\":[{\"delta\":{\"content\":\"\"}}]}\n\n"
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
    public function streamChatCompletionWithOptions(): void
    {
        $subject = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
            'baseUrl' => 'https://api.mistral.ai/v1',
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
        $generator = $subject->streamChatCompletion($messages, ['temperature' => 0.5, 'max_tokens' => 1000]);

        $chunks = iterator_to_array($generator);

        self::assertEquals(['Test'], $chunks);
    }

    #[Test]
    public function testConnectionReturnsSuccessWithModelList(): void
    {
        $apiResponse = [
            'object' => 'list',
            'data' => [
                ['id' => 'mistral-large-latest', 'object' => 'model'],
                ['id' => 'mistral-small-latest', 'object' => 'model'],
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
        self::assertArrayHasKey('mistral-large-latest', $result['models']);
    }

    #[Test]
    public function testConnectionThrowsOnHttpError(): void
    {
        // A static-list provider must NOT silently report success on an
        // unreachable / unauthorized endpoint: the real HTTP call surfaces
        // the typed exception instead.
        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createErrorResponseMock(401, 'Unauthorized'));

        $this->expectException(ProviderResponseException::class);

        $this->subject->testConnection();
    }

    // ==================== Request-shape (payload / URL) assertions ====================

    /**
     * Build a MistralProvider whose request/stream factories capture the
     * outgoing HTTP method, URL and JSON body so a test can assert the exact
     * transformation the provider performs.
     *
     * The captured variables MUST be initialised (to null) at the call site.
     *
     * @param string|null $capturedMethod receives the last createRequest() method
     * @param string|null $capturedUrl    receives the last createRequest() URL
     * @param string|null $capturedBody   receives the last createStream() JSON body
     *
     * @return array{subject: MistralProvider, httpClient: ClientInterface&MockObject}
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

        $subject = new MistralProvider(
            $requestFactory,
            $streamFactory,
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
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

        $apiResponse = [
            'model' => 'mistral-large-latest',
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $subject->chatCompletion([['role' => 'user', 'content' => 'Ping']]);

        self::assertSame('POST', $capturedMethod);
        self::assertSame('/chat/completions', $capturedUrl);

        $body = $this->decodeCapturedBody($capturedBody);

        // ArrayItemRemoval on 'model' / ArrayItem `=>`→`>` on temperature+max_tokens.
        self::assertArrayHasKey('model', $body);
        self::assertSame('mistral-large-latest', $body['model']);
        self::assertSame([['role' => 'user', 'content' => 'Ping']], $body['messages']);
        self::assertArrayHasKey('temperature', $body);
        self::assertSame(0.7, $body['temperature']);
        self::assertArrayHasKey('max_tokens', $body);
        self::assertSame(4096, $body['max_tokens']);
    }

    #[Test]
    public function chatCompletionSendsOverriddenOptionValues(): void
    {
        $capturedMethod = null;
        $capturedUrl    = null;
        $capturedBody   = null;

        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createCapturingSubject(
            $capturedMethod,
            $capturedUrl,
            $capturedBody,
        );

        $apiResponse = [
            'model' => 'mistral-small-latest',
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $subject->chatCompletion(
            [['role' => 'user', 'content' => 'Ping']],
            ['model' => 'mistral-small-latest', 'temperature' => 0.25, 'max_tokens' => 128],
        );

        $body = $this->decodeCapturedBody($capturedBody);

        self::assertSame('mistral-small-latest', $body['model']);
        self::assertSame(0.25, $body['temperature']);
        self::assertSame(128, $body['max_tokens']);
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

        $apiResponse = [
            'model' => 'mistral-large-latest',
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

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

        $apiResponse = [
            'model' => 'mistral-large-latest',
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

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

        $apiResponse = [
            'model' => 'mistral-large-latest',
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $subject->chatCompletionWithTools([['role' => 'user', 'content' => 'Ping']], [$spec]);

        self::assertSame('POST', $capturedMethod);
        self::assertSame('/chat/completions', $capturedUrl);

        $body = $this->decodeCapturedBody($capturedBody);

        self::assertArrayHasKey('model', $body);
        self::assertSame('mistral-large-latest', $body['model']);
        // Normalise the expected through the same JSON round-trip the body went
        // through (decodeCapturedBody uses assoc=true), so an empty `properties`
        // object ({}) compares equal on both sides rather than stdClass vs [].
        self::assertSame(json_decode((string)json_encode([$spec->toArray()]), true), $body['tools']);
        self::assertArrayHasKey('temperature', $body);
        self::assertSame(0.7, $body['temperature']);
        self::assertArrayHasKey('max_tokens', $body);
        self::assertSame(4096, $body['max_tokens']);
    }

    #[Test]
    public function embeddingsSendsExpectedRequestPayload(): void
    {
        $capturedMethod = null;
        $capturedUrl    = null;
        $capturedBody   = null;

        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createCapturingSubject(
            $capturedMethod,
            $capturedUrl,
            $capturedBody,
        );

        $apiResponse = [
            'model' => 'mistral-embed',
            'data' => [['index' => 0, 'object' => 'embedding', 'embedding' => [0.1, 0.2]]],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $subject->embeddings('Test text');

        self::assertSame('POST', $capturedMethod);
        self::assertSame('/embeddings', $capturedUrl);

        $body = $this->decodeCapturedBody($capturedBody);

        // A scalar input is wrapped into a single-element list (`[$input]`), not
        // dropped (`[]`) nor left bare (ternary swap).
        self::assertArrayHasKey('model', $body);
        self::assertSame('mistral-embed', $body['model']);
        self::assertSame(['Test text'], $body['input']);
    }

    #[Test]
    public function embeddingsParsesIntegerVectorsAsFloatsWithZeroCompletionTokens(): void
    {
        $capturedMethod = null;
        $capturedUrl    = null;
        $capturedBody   = null;

        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createCapturingSubject(
            $capturedMethod,
            $capturedUrl,
            $capturedBody,
        );

        // Integer vector components force the `array_map(asFloat)` cast to be
        // observable: without it the values stay ints and assertSame() fails.
        $apiResponse = [
            'model' => 'mistral-embed',
            'data' => [['index' => 0, 'object' => 'embedding', 'embedding' => [1, 2, 3]]],
            'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings('Test text');

        self::assertSame([1.0, 2.0, 3.0], $result->embeddings[0]);
        // completionTokens is hardcoded to 0 for embeddings.
        self::assertSame(0, $result->usage->completionTokens);
        self::assertSame(7, $result->usage->promptTokens);
    }

    // ==================== Streaming request-shape assertions ====================

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
            'https://api.mistral.ai/v1/',
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
        self::assertSame('https://api.mistral.ai/v1/chat/completions', $capturedUrl);

        $body = $this->decodeCapturedBody($capturedBody);

        self::assertArrayHasKey('model', $body);
        self::assertSame('mistral-large-latest', $body['model']);
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
            'https://api.mistral.ai/v1',
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
        self::assertStringContainsString('\ufffd', $capturedBody);
    }

    #[Test]
    public function streamChatCompletionValidatesConfigurationBeforeStreaming(): void
    {
        $subject = new MistralProvider(
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
            'defaultModel' => 'mistral-large-latest',
            'baseUrl' => 'https://api.mistral.ai/v1',
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
        $subject = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
            'baseUrl' => 'https://api.mistral.ai/v1',
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
        $subject = new MistralProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'mistral-large-latest',
            'baseUrl' => 'https://api.mistral.ai/v1',
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
}
