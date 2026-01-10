<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use BadMethodCallException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;

#[CoversClass(GroqProvider::class)]
class GroqProviderTest extends AbstractUnitTestCase
{
    private GroqProvider $subject;
    private ClientInterface&Stub $httpClientStub;

    #[Override]
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
        /** @var array{function: array{name: string, arguments: array<string, string>}} $toolCall */
        $toolCall = $result->toolCalls[0];
        self::assertEquals('get_weather', $toolCall['function']['name']);
        self::assertEquals(['location' => 'London'], $toolCall['function']['arguments']);
    }

    #[Test]
    public function embeddingsThrowsBadMethodCallException(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Groq does not support embeddings');

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
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_data',
                    'description' => 'Get data',
                    'parameters' => ['type' => 'object'],
                ],
            ],
        ];

        $result = $subject->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'Get data']],
            $tools,
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertNotNull($result->toolCalls);
        // Invalid JSON should result in empty array for arguments
        /** @var array{function: array{arguments: array<mixed>}} $toolCall */
        $toolCall = $result->toolCalls[0];
        self::assertEquals([], $toolCall['function']['arguments']);
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

        $streamStub = self::createStub(\Psr\Http\Message\StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);

        $responseStub = self::createStub(\Psr\Http\Message\ResponseInterface::class);
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

        $streamStub = self::createStub(\Psr\Http\Message\StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);

        $responseStub = self::createStub(\Psr\Http\Message\ResponseInterface::class);
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

        $streamStub = self::createStub(\Psr\Http\Message\StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);

        $responseStub = self::createStub(\Psr\Http\Message\ResponseInterface::class);
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

        $streamStub = self::createStub(\Psr\Http\Message\StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);

        $responseStub = self::createStub(\Psr\Http\Message\ResponseInterface::class);
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

        $streamStub = self::createStub(\Psr\Http\Message\StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn($streamContent);

        $responseStub = self::createStub(\Psr\Http\Message\ResponseInterface::class);
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
}
