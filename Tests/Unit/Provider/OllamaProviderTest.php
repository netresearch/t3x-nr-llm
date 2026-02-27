<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\OllamaProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionClass;
use RuntimeException;

#[CoversClass(OllamaProvider::class)]
class OllamaProviderTest extends AbstractUnitTestCase
{
    private OllamaProvider $subject;
    private ClientInterface&Stub $httpClientStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientStub = $this->createHttpClientMock();

        $this->subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $this->subject->configure([
            'apiKeyIdentifier' => '',  // Ollama doesn't require API key
            'defaultModel' => 'llama3.2',
            'baseUrl' => 'http://localhost:11434',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $this->subject->setHttpClient($this->httpClientStub);
    }

    /**
     * Create a provider with a mock HTTP client for expectation testing.
     *
     * @return array{subject: OllamaProvider, httpClient: ClientInterface&MockObject}
     */
    private function createSubjectWithMockHttpClient(): array
    {
        $httpClientMock = $this->createHttpClientWithExpectations();

        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'llama3.2',
            'baseUrl' => 'http://localhost:11434',
            'timeout' => 30,
        ]);

        // setHttpClient must be called AFTER configure() since configure() resets the client
        $subject->setHttpClient($httpClientMock);

        return ['subject' => $subject, 'httpClient' => $httpClientMock];
    }

    #[Test]
    public function getNameReturnsOllama(): void
    {
        self::assertEquals('Ollama', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsOllama(): void
    {
        self::assertEquals('ollama', $this->subject->getIdentifier());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenBaseUrlConfigured(): void
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
            'model' => 'llama3.2',
            'message' => [
                'role' => 'assistant',
                'content' => 'Ollama response content',
            ],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 10,
            'eval_count' => 20,
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Ollama response content', $result->content);
        self::assertEquals('llama3.2', $result->model);
        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function chatCompletionThrowsProviderResponseExceptionOnError(): void
    {
        $errorResponse = [
            'error' => 'model "nonexistent" not found',
        ];

        $this->httpClientStub
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($errorResponse, 404));

        $this->expectException(ProviderResponseException::class);

        $this->subject->chatCompletion([['role' => 'user', 'content' => 'test']]);
    }

    #[Test]
    public function getAvailableModelsReturnsModelsFromServer(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'models' => [
                ['name' => 'llama3.2:latest'],
                ['name' => 'mistral:latest'],
                ['name' => 'codellama:latest'],
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $models = $subject->getAvailableModels();

        self::assertArrayHasKey('llama3.2:latest', $models);
        self::assertArrayHasKey('mistral:latest', $models);
        self::assertArrayHasKey('codellama:latest', $models);
    }

    #[Test]
    public function getAvailableModelsReturnsDefaultModelsOnConnectionError(): void
    {
        $this->httpClientStub
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection refused'));

        $models = $this->subject->getAvailableModels();

        // Should return default models when server is unavailable
        self::assertNotEmpty($models);
        self::assertArrayHasKey('llama3.2', $models);
    }

    #[Test]
    public function supportsStreamingReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsStreaming());
    }

    #[Test]
    public function embeddingsReturnsValidResponse(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'embedding' => [0.1, 0.2, 0.3, 0.4, 0.5],
            'prompt_eval_count' => 5,
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
    public function getDefaultModelReturnsConfiguredModel(): void
    {
        self::assertEquals('llama3.2', $this->subject->getDefaultModel());
    }

    #[Test]
    public function testConnectionReturnsSuccessWithModels(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'models' => [
                ['name' => 'llama3.2:latest'],
                ['name' => 'mistral:latest'],
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->testConnection();

        self::assertTrue($result['success']);
        self::assertStringContainsString('2 models', $result['message']);
        /** @var array{success: true, message: string, models: array<string, string>} $result */
        self::assertArrayHasKey('models', $result);
        self::assertCount(2, $result['models']);
    }

    #[Test]
    public function isAvailableReturnsTrueWithDefaultBaseUrl(): void
    {
        // Create provider without explicit baseUrl
        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        // Omit baseUrl key to use the default from getDefaultBaseUrl()
        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'llama3.2',
            'timeout' => 30,
        ]);

        // isAvailable should return true because default base URL is used
        self::assertTrue($subject->isAvailable());
    }

    #[Test]
    public function chatCompletionWithAllOptions(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [['role' => 'user', 'content' => 'Test']];

        $apiResponse = [
            'model' => 'llama3.2',
            'message' => [
                'role' => 'assistant',
                'content' => 'Response',
            ],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 10,
            'eval_count' => 20,
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $options = [
            'model' => 'llama3.2',
            'temperature' => 0.7,
            'top_p' => 0.9,
            'max_tokens' => 2048,
        ];

        $result = $subject->chatCompletion($messages, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Response', $result->content);
    }

    #[Test]
    public function chatCompletionWithNumPredict(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $messages = [['role' => 'user', 'content' => 'Test']];

        $apiResponse = [
            'model' => 'llama3.2',
            'message' => ['role' => 'assistant', 'content' => 'Response'],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 10,
            'eval_count' => 20,
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $options = [
            'num_predict' => 1000,
        ];

        $result = $subject->chatCompletion($messages, $options);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function embeddingsWithMultipleInputs(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $httpClientMock
            ->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMock([
                    'embedding' => [0.1, 0.2, 0.3],
                    'prompt_eval_count' => 5,
                ]),
                $this->createJsonResponseMock([
                    'embedding' => [0.4, 0.5, 0.6],
                    'prompt_eval_count' => 6,
                ]),
            );

        $result = $subject->embeddings(['First text', 'Second text']);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(2, $result->embeddings);
        self::assertEquals([0.1, 0.2, 0.3], $result->embeddings[0]);
        self::assertEquals([0.4, 0.5, 0.6], $result->embeddings[1]);
    }

    #[Test]
    public function getDefaultModelReturnsConstantWhenEmptyString(): void
    {
        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => '',
            'baseUrl' => 'http://localhost:11434',
            'timeout' => 30,
        ]);

        self::assertEquals('llama3.2', $subject->getDefaultModel());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenEmptyBaseUrl(): void
    {
        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        // Set empty base URL manually to test isAvailable
        $reflection = new ReflectionClass($subject);
        $property = $reflection->getProperty('baseUrl');
        $property->setValue($subject, '');

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function validateConfigurationSetsDefaultBaseUrl(): void
    {
        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        // Don't include baseUrl in config to trigger default URL
        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'llama3.2',
            'timeout' => 30,
        ]);

        // After configure, baseUrl should be set to default
        self::assertTrue($subject->isAvailable());
    }

    #[Test]
    public function getAvailableModelsFiltersEmptyNames(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'models' => [
                ['name' => 'llama3.2:latest'],
                ['name' => ''],
                ['name' => 'mistral:latest'],
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $models = $subject->getAvailableModels();

        self::assertCount(2, $models);
        self::assertArrayHasKey('llama3.2:latest', $models);
        self::assertArrayHasKey('mistral:latest', $models);
        self::assertArrayNotHasKey('', $models);
    }

    #[Test]
    public function testConnectionFiltersEmptyModelNames(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'models' => [
                ['name' => 'llama3.2:latest'],
                ['name' => ''],
                ['name' => 'mistral:latest'],
            ],
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->testConnection();

        self::assertTrue($result['success']);
        /** @var array{success: true, models: array<string, string>} $result */
        self::assertCount(2, $result['models']);
        self::assertArrayNotHasKey('', $result['models']);
    }

    #[Test]
    public function streamChatCompletionYieldsContent(): void
    {
        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'llama3.2',
            'baseUrl' => 'http://localhost:11434',
            'timeout' => 30,
        ]);

        // Create streaming response with JSON lines
        $streamContent = "{\"message\":{\"content\":\"Hello\"},\"done\":false}\n"
                         . "{\"message\":{\"content\":\" world\"},\"done\":false}\n"
                         . "{\"message\":{\"content\":\"!\"},\"done\":true}\n";

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, false, false, true);
        $streamStub->method('read')->willReturnOnConsecutiveCalls(
            "{\"message\":{\"content\":\"Hello\"},\"done\":false}\n",
            "{\"message\":{\"content\":\" world\"},\"done\":false}\n",
            "{\"message\":{\"content\":\"!\"},\"done\":true}\n",
        );

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $subject->setHttpClient($httpClientMock);

        $messages = [['role' => 'user', 'content' => 'Test']];
        $generator = $subject->streamChatCompletion($messages);

        $chunks = iterator_to_array($generator);

        self::assertEquals(['Hello', ' world', '!'], $chunks);
    }

    #[Test]
    public function streamChatCompletionWithTemperatureOption(): void
    {
        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'llama3.2',
            'baseUrl' => 'http://localhost:11434',
            'timeout' => 30,
        ]);

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn("{\"message\":{\"content\":\"Test\"},\"done\":true}\n");

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $subject->setHttpClient($httpClientMock);

        $messages = [['role' => 'user', 'content' => 'Test']];
        $generator = $subject->streamChatCompletion($messages, ['temperature' => 0.7]);

        $chunks = iterator_to_array($generator);

        self::assertEquals(['Test'], $chunks);
    }

    #[Test]
    public function streamChatCompletionSkipsEmptyLines(): void
    {
        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'llama3.2',
            'baseUrl' => 'http://localhost:11434',
            'timeout' => 30,
        ]);

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn("\n\n{\"message\":{\"content\":\"Test\"},\"done\":true}\n");

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $subject->setHttpClient($httpClientMock);

        $messages = [['role' => 'user', 'content' => 'Test']];
        $generator = $subject->streamChatCompletion($messages);

        $chunks = iterator_to_array($generator);

        self::assertEquals(['Test'], $chunks);
    }

    #[Test]
    public function streamChatCompletionSkipsMalformedJson(): void
    {
        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'llama3.2',
            'baseUrl' => 'http://localhost:11434',
            'timeout' => 30,
        ]);

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn("invalid-json\n{\"message\":{\"content\":\"Valid\"},\"done\":true}\n");

        $responseStub = self::createStub(ResponseInterface::class);
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
        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel' => 'llama3.2',
            'baseUrl' => 'http://localhost:11434',
            'timeout' => 30,
        ]);

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $streamStub->method('read')->willReturn("{\"message\":{\"content\":\"\"},\"done\":false}\n{\"message\":{\"content\":\"Test\"},\"done\":true}\n");

        $responseStub = self::createStub(ResponseInterface::class);
        $responseStub->method('getBody')->willReturn($streamStub);

        $httpClientMock = $this->createHttpClientWithExpectations();
        $httpClientMock->expects(self::once())
            ->method('sendRequest')
            ->willReturn($responseStub);

        $subject->setHttpClient($httpClientMock);

        $messages = [['role' => 'user', 'content' => 'Test']];
        $generator = $subject->streamChatCompletion($messages);

        $chunks = iterator_to_array($generator);

        self::assertEquals(['Test'], $chunks);
    }

    #[Test]
    public function embeddingsWithModelOption(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'embedding' => [0.1, 0.2, 0.3],
            'prompt_eval_count' => 5,
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->embeddings('Test', ['model' => 'custom-embed']);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertEquals('custom-embed', $result->model);
    }

    #[Test]
    public function chatCompletionWithoutOptions(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $apiResponse = [
            'model' => 'llama3.2',
            'message' => ['role' => 'assistant', 'content' => 'Response'],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 10,
            'eval_count' => 20,
        ];

        $httpClientMock
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $subject->chatCompletion([['role' => 'user', 'content' => 'Test']]);

        self::assertInstanceOf(CompletionResponse::class, $result);
    }
}
