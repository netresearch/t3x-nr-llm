<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\MistralProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;

#[CoversClass(MistralProvider::class)]
class MistralProviderTest extends AbstractUnitTestCase
{
    private MistralProvider $subject;
    private ClientInterface&Stub $httpClientStub;

    #[Override]
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
            [
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
            ],
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
        self::assertEquals('get_weather', $result->toolCalls[0]['function']['name']);
        self::assertEquals(['location' => 'Paris'], $result->toolCalls[0]['function']['arguments']);
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
            [
                'type' => 'function',
                'function' => [
                    'name' => 'test_function',
                    'description' => 'A test function',
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ],
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
            [
                'type' => 'function',
                'function' => [
                    'name' => 'test_function',
                    'description' => 'Test',
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ],
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
        self::assertEquals([], $result->toolCalls[0]['function']['arguments']);
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

        $streamMock = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $streamMock->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamMock->method('read')->willReturn($streamContent);

        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $responseMock->method('getBody')->willReturn($streamMock);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseMock);

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

        $streamMock = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $streamMock->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamMock->method('read')->willReturn($streamContent);

        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $responseMock->method('getBody')->willReturn($streamMock);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseMock);

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

        $streamMock = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $streamMock->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamMock->method('read')->willReturn($streamContent);

        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $responseMock->method('getBody')->willReturn($streamMock);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseMock);

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

        $streamMock = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $streamMock->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamMock->method('read')->willReturn($streamContent);

        $responseMock = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $responseMock->method('getBody')->willReturn($streamMock);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseMock);

        $subject->setHttpClient($httpClientMock);

        $messages = [['role' => 'user', 'content' => 'Test']];
        $generator = $subject->streamChatCompletion($messages, ['temperature' => 0.5, 'max_tokens' => 1000]);

        $chunks = iterator_to_array($generator);

        self::assertEquals(['Test'], $chunks);
    }
}
