<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\OllamaProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
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

    /**
     * An api-key-less provider (Ollama) pointed at a cloud-metadata / private
     * IP literal must be rejected by the SSRF host gate before any request is
     * dispatched — the raw factory-client path used for keyless providers has
     * no per-request isHostAllowed() gate, and the DNS-pin middleware skips IP
     * literals. Without the endpoint-host gate this SSRF target would slip
     * through. No HTTP client is injected here so the real keyless code path
     * (getHttpClient → assertEndpointHostAllowed) runs.
     */
    #[Test]
    public function keylessProviderRejectsMetadataIpEndpoint(): void
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
            'baseUrl' => 'http://169.254.169.254:11434',
            'timeout' => 30,
        ]);

        $this->expectException(ProviderConfigurationException::class);

        $subject->complete('hello');
    }

    /**
     * A schemeless endpoint (no http:// prefix) must not slip past the SSRF
     * gate: parse_url yields no host without a scheme, so the gate normalises
     * one before parsing and still rejects the metadata IP.
     */
    #[Test]
    public function keylessProviderRejectsSchemelessMetadataIpEndpoint(): void
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
            'baseUrl' => '169.254.169.254:11434',
            'timeout' => 30,
        ]);

        $this->expectException(ProviderConfigurationException::class);

        $subject->complete('hello');
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

    /**
     * REC #11 (audit 2026-04-30): the catch arm that returns hardcoded
     * defaults on transport failure must surface a `warning` log so
     * operators see when their endpoint isn't responding instead of
     * silently inheriting a stale model picker.
     *
     * The log payload's `baseUrl` field must be sanitised — userinfo
     * and query/fragment stripped — so credentials accidentally
     * embedded in a misconfigured baseUrl (`https://user:pass@host`)
     * cannot leak into sys_log.
     */
    #[Test]
    public function getAvailableModelsLogsWarningWithSanitisedBaseUrlOnFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $logger,
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel'     => 'llama3.2',
            'baseUrl'          => 'https://leaky-user:leaky-pass@ollama.example:11434/v1?token=secret#frag',
            'timeout'          => 30,
        ]);
        $httpClient = $this->createHttpClientMock();
        $httpClient
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('Connection refused'));
        $subject->setHttpClient($httpClient);

        $logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('getAvailableModels failed'),
                self::callback(static function (array $context): bool {
                    self::assertArrayHasKey('baseUrl', $context);
                    self::assertArrayHasKey('exception', $context);
                    self::assertIsString($context['baseUrl']);

                    self::assertSame('https://ollama.example:11434/v1', $context['baseUrl']);
                    self::assertStringNotContainsString('leaky-user', $context['baseUrl']);
                    self::assertStringNotContainsString('leaky-pass', $context['baseUrl']);
                    self::assertStringNotContainsString('token=secret', $context['baseUrl']);
                    self::assertStringNotContainsString('#frag', $context['baseUrl']);

                    return true;
                }),
            );

        $models = $subject->getAvailableModels();

        // Defaults are still returned — operational fallback unchanged.
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
        self::assertArrayHasKey('models', $result);
        /** @var array{success: true, message: string, models: array<string, string>} $result */
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
        // Token counts of every input are summed into the prompt total
        // (init 0, `+=` accumulation): 5 + 6 = 11.
        self::assertSame(11, $result->usage->promptTokens);
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
        /** @var array{success: true, message: string, models: array<string, string>} $result */
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

        $streamStub = self::createStub(StreamInterface::class);
        $streamStub->method('eof')->willReturnOnConsecutiveCalls(false, false, false, true);
        $streamStub->method('read')->willReturnOnConsecutiveCalls(
            "{\"message\":{\"content\":\"Hello\"},\"done\":false}\n",
            "{\"message\":{\"content\":\" world\"},\"done\":false}\n",
            "{\"message\":{\"content\":\"!\"},\"done\":true}\n",
        );

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
        $responseStub->method('getStatusCode')->willReturn(200);
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

    // ------------------------------------------------------------------
    // Request-shape characterisation tests.
    //
    // The tests above assert the parsed response; the ones below pin the
    // exact request the provider puts on the wire — endpoint URL, HTTP
    // method, and every JSON body key/value — by capturing the body handed
    // to the stream factory and the URI/method of the request handed to the
    // HTTP client. Values asserted are derived from the fixed subject setUp
    // (baseUrl http://localhost:11434, defaultModel llama3.2) plus the
    // options each test passes.
    // ------------------------------------------------------------------

    /**
     * Stream factory stub that records the JSON body passed to createStream().
     */
    private function capturingStreamFactory(string &$capturedBody): StreamFactoryInterface
    {
        $streamFactory = self::createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content) use (&$capturedBody): StreamInterface {
                $capturedBody = $content;
                $stream       = self::createStub(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);
                $stream->method('getContents')->willReturn($content);

                return $stream;
            },
        );

        return $streamFactory;
    }

    /**
     * Build a keyless Ollama subject whose outgoing request body is captured
     * by the given stream factory.
     */
    private function configuredSubject(
        StreamFactoryInterface $streamFactory,
        string $baseUrl = 'http://localhost:11434',
    ): OllamaProvider {
        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $streamFactory,
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel'     => 'llama3.2',
            'baseUrl'          => $baseUrl,
            'timeout'          => 30,
        ]);

        return $subject;
    }

    /**
     * Build a subject that captures the request body, URL and method sent
     * through the sendRequest() path (chat / tools / embeddings) and answers
     * with the given decoded JSON response.
     *
     * @param array<string, mixed> $apiResponse
     */
    private function subjectCapturingRequest(
        array $apiResponse,
        string &$capturedBody,
        string &$capturedUrl,
        string &$capturedMethod,
        string $baseUrl = 'http://localhost:11434',
    ): OllamaProvider {
        $subject = $this->configuredSubject($this->capturingStreamFactory($capturedBody), $baseUrl);

        $httpClient = $this->createHttpClientWithExpectations();
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(
                function (RequestInterface $request) use (&$capturedUrl, &$capturedMethod, $apiResponse): ResponseInterface {
                    $capturedUrl    = (string)$request->getUri();
                    $capturedMethod = $request->getMethod();

                    return $this->createJsonResponseMock($apiResponse);
                },
            );
        $subject->setHttpClient($httpClient);

        return $subject;
    }

    /**
     * A response stub whose body yields exactly one newline-terminated chunk
     * before signalling EOF — enough for the streaming reader to consume.
     */
    private function streamingResponseStub(string $body, int $status = 200): ResponseInterface
    {
        $stream = self::createStub(StreamInterface::class);
        $stream->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $stream->method('read')->willReturn($body);
        $stream->method('__toString')->willReturn($body);
        $stream->method('getContents')->willReturn($body);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    /**
     * Decode a captured request body into an associative array.
     *
     * @return array<string, mixed>
     */
    private function decodeCapturedPayload(string $body): array
    {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function payloadArray(array $payload, string $key): array
    {
        self::assertArrayHasKey($key, $payload);
        self::assertIsArray($payload[$key]);

        /** @var array<string, mixed> $sub */
        $sub = $payload[$key];

        return $sub;
    }

    #[Test]
    public function chatCompletionSendsExpectedRequestWithoutOptions(): void
    {
        $capturedBody   = '';
        $capturedUrl    = '';
        $capturedMethod = '';

        $apiResponse = [
            'model'   => 'llama3.2',
            'message' => ['role' => 'assistant', 'content' => 'Hi'],
            'done'    => true,
        ];

        $subject = $this->subjectCapturingRequest($apiResponse, $capturedBody, $capturedUrl, $capturedMethod);

        // ChatMessage input forces the array_map(toArray) translation (a bare
        // array would make the map an identity no-op and hide its removal).
        $subject->chatCompletion([ChatMessage::user('Hello capture')]);

        $payload = $this->decodeCapturedPayload($capturedBody);

        self::assertSame('POST', $capturedMethod);
        self::assertSame('http://localhost:11434/api/chat', $capturedUrl);
        self::assertSame('llama3.2', $payload['model']);
        self::assertFalse($payload['stream']);
        self::assertSame([['role' => 'user', 'content' => 'Hello capture']], $payload['messages']);
        // No options given: neither an `options` block nor a `think` flag is
        // emitted (kills the "add empty options" / "add num_predict default"
        // mutants that fire when the guards are inverted).
        self::assertArrayNotHasKey('options', $payload);
        self::assertArrayNotHasKey('think', $payload);
    }

    #[Test]
    public function chatCompletionSendsNumPredictOption(): void
    {
        $capturedBody   = '';
        $capturedUrl    = '';
        $capturedMethod = '';

        $apiResponse = [
            'model'   => 'llama3.2',
            'message' => ['role' => 'assistant', 'content' => 'Hi'],
            'done'    => true,
        ];

        $subject = $this->subjectCapturingRequest($apiResponse, $capturedBody, $capturedUrl, $capturedMethod);

        $subject->chatCompletion([['role' => 'user', 'content' => 'Test']], ['num_predict' => 1000]);

        $payload = $this->decodeCapturedPayload($capturedBody);
        $options = $this->payloadArray($payload, 'options');

        self::assertSame(1000, $options['num_predict']);
    }

    #[Test]
    public function chatCompletionSendsTemperatureTopPAndThink(): void
    {
        $capturedBody   = '';
        $capturedUrl    = '';
        $capturedMethod = '';

        $apiResponse = [
            'model'   => 'llama3.2',
            'message' => ['role' => 'assistant', 'content' => 'Hi'],
            'done'    => true,
        ];

        $subject = $this->subjectCapturingRequest($apiResponse, $capturedBody, $capturedUrl, $capturedMethod);

        $subject->chatCompletion(
            [['role' => 'user', 'content' => 'Test']],
            ['temperature' => 0.7, 'top_p' => 0.9, 'think' => true],
        );

        $payload = $this->decodeCapturedPayload($capturedBody);
        $options = $this->payloadArray($payload, 'options');

        self::assertSame(0.7, $options['temperature']);
        self::assertSame(0.9, $options['top_p']);
        // `think` is a top-level field (not an `options` entry) and is only
        // emitted when it is a real bool.
        self::assertTrue($payload['think']);
    }

    #[Test]
    public function chatCompletionNormalisesToolTurnKeepsFollowingMessages(): void
    {
        $capturedBody   = '';
        $capturedUrl    = '';
        $capturedMethod = '';

        $apiResponse = [
            'model'   => 'llama3.2',
            'message' => ['role' => 'assistant', 'content' => 'Hi'],
            'done'    => true,
        ];

        $subject = $this->subjectCapturingRequest($apiResponse, $capturedBody, $capturedUrl, $capturedMethod);

        $subject->chatCompletion([
            ['role' => 'tool', 'tool_call_id' => 'call_9', 'content' => '42'],
            ['role' => 'user', 'content' => 'and now'],
        ]);

        $payload  = $this->decodeCapturedPayload($capturedBody);
        $messages = $this->payloadArray($payload, 'messages');

        // The tool turn is normalised (tool_call_id dropped) and the loop
        // CONTINUES so the following user turn survives (a `break` mutant would
        // truncate the list to a single message).
        self::assertCount(2, $messages);
        self::assertArrayHasKey(0, $messages);
        self::assertIsArray($messages[0]);
        self::assertArrayNotHasKey('tool_call_id', $messages[0]);
        self::assertSame(['role' => 'tool', 'content' => '42'], $messages[0]);
        self::assertSame(['role' => 'user', 'content' => 'and now'], $messages[1]);
    }

    #[Test]
    public function chatCompletionLeavesNonObjectToolCallArgumentsUntouched(): void
    {
        $capturedBody   = '';
        $capturedUrl    = '';
        $capturedMethod = '';

        $apiResponse = [
            'model'   => 'llama3.2',
            'message' => ['role' => 'assistant', 'content' => 'Hi'],
            'done'    => true,
        ];

        $subject = $this->subjectCapturingRequest($apiResponse, $capturedBody, $capturedUrl, $capturedMethod);

        // A replayed assistant turn whose function.arguments decodes to a
        // scalar (not object/array): decodeToolCallArguments must leave the
        // raw JSON string in place. Inverting either half of the
        // `is_object || is_array` guard would rewrite it to the decoded scalar.
        $subject->chatCompletion([
            [
                'role'       => 'assistant',
                'content'    => '',
                'tool_calls' => [
                    ['id' => 'call_0', 'type' => 'function', 'function' => ['name' => 'foo', 'arguments' => '"scalar"']],
                ],
            ],
        ]);

        $payload  = $this->decodeCapturedPayload($capturedBody);
        $messages = $this->payloadArray($payload, 'messages');

        self::assertArrayHasKey(0, $messages);
        self::assertIsArray($messages[0]);
        self::assertArrayHasKey('tool_calls', $messages[0]);
        self::assertIsArray($messages[0]['tool_calls']);
        self::assertIsArray($messages[0]['tool_calls'][0]);
        self::assertIsArray($messages[0]['tool_calls'][0]['function']);
        self::assertSame('"scalar"', $messages[0]['tool_calls'][0]['function']['arguments']);
    }

    #[Test]
    public function chatCompletionWithToolsSendsExpectedRequestAndIndexesCalls(): void
    {
        $capturedBody   = '';
        $capturedUrl    = '';
        $capturedMethod = '';

        $tool = ToolSpec::function(
            'get_weather',
            'Get weather',
            ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
        );

        $apiResponse = [
            'model'   => 'llama3.2',
            'message' => [
                'role'       => 'assistant',
                'content'    => '',
                'tool_calls' => [
                    ['function' => ['name' => 'get_weather', 'arguments' => ['city' => 'Berlin']]],
                    ['function' => ['name' => 'get_time', 'arguments' => []]],
                ],
            ],
            'done'        => true,
            'done_reason' => 'stop',
        ];

        $subject = $this->subjectCapturingRequest($apiResponse, $capturedBody, $capturedUrl, $capturedMethod);

        // ChatMessage input forces the array_map(toArray) translation.
        $result = $subject->chatCompletionWithTools([ChatMessage::user('Weather?')], [$tool]);

        $payload = $this->decodeCapturedPayload($capturedBody);

        self::assertSame('http://localhost:11434/api/chat', $capturedUrl);
        self::assertSame([['role' => 'user', 'content' => 'Weather?']], $payload['messages']);
        self::assertSame('llama3.2', $payload['model']);
        self::assertFalse($payload['stream']);
        self::assertArrayNotHasKey('options', $payload);

        $tools = $this->payloadArray($payload, 'tools');
        self::assertCount(1, $tools);
        // Each ToolSpec is serialised via toArray() (kept identical by its
        // JsonSerializable::jsonSerialize()).
        self::assertArrayHasKey(0, $tools);
        self::assertSame($tool->toArray(), $tools[0]);

        // Ollama returns no call id; a stable `call_<index>` is synthesised,
        // the index incrementing 0, 1, … across the returned calls.
        self::assertNotNull($result->toolCalls);
        self::assertCount(2, $result->toolCalls);
        $first  = $result->toolCalls[0];
        $second = $result->toolCalls[1];
        self::assertInstanceOf(ToolCall::class, $first);
        self::assertInstanceOf(ToolCall::class, $second);
        self::assertSame('call_0', $first->id);
        self::assertSame('call_1', $second->id);
        self::assertSame('get_weather', $first->name);
    }

    #[Test]
    public function chatCompletionWithToolsSendsNumPredictOption(): void
    {
        $capturedBody   = '';
        $capturedUrl    = '';
        $capturedMethod = '';

        $tool = ToolSpec::function('noop', 'No op', ['type' => 'object', 'properties' => []]);

        $apiResponse = [
            'model'   => 'llama3.2',
            'message' => ['role' => 'assistant', 'content' => 'ok'],
            'done'    => true,
        ];

        $subject = $this->subjectCapturingRequest($apiResponse, $capturedBody, $capturedUrl, $capturedMethod);

        $subject->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'Test']],
            [$tool],
            ['num_predict' => 500],
        );

        $payload = $this->decodeCapturedPayload($capturedBody);
        $options = $this->payloadArray($payload, 'options');

        self::assertSame(500, $options['num_predict']);
    }

    #[Test]
    public function embeddingsSendsExpectedRequestAndCastsValuesToFloat(): void
    {
        $capturedBody   = '';
        $capturedUrl    = '';
        $capturedMethod = '';

        // Whole-number JSON components decode to ints; the (float) cast must
        // turn them into floats (assertSame is type-strict: 0 !== 0.0).
        $apiResponse = [
            'embedding'         => [0, 1, -1],
            'prompt_eval_count' => 5,
        ];

        $subject = $this->subjectCapturingRequest($apiResponse, $capturedBody, $capturedUrl, $capturedMethod);

        $result = $subject->embeddings('Test text');

        $payload = $this->decodeCapturedPayload($capturedBody);

        self::assertSame('http://localhost:11434/api/embeddings', $capturedUrl);
        self::assertSame('nomic-embed-text', $payload['model']);
        self::assertSame('Test text', $payload['prompt']);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertSame([[0.0, 1.0, -1.0]], $result->embeddings);
        // totalTokens starts at 0 and accumulates prompt_eval_count.
        self::assertSame(5, $result->usage->promptTokens);
    }

    #[Test]
    public function embeddingsSumsPresentCountsAndDefaultsMissingToZero(): void
    {
        ['subject' => $subject, 'httpClient' => $httpClientMock] = $this->createSubjectWithMockHttpClient();

        $httpClientMock
            ->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMock(['embedding' => [0.1], 'prompt_eval_count' => 5]),
                // Second input's prompt_eval_count is absent -> defaults to 0.
                $this->createJsonResponseMock(['embedding' => [0.2]]),
            );

        $result = $subject->embeddings(['first', 'second']);

        // 5 (present) + 0 (missing default) = 5. Pins the 0 default and the
        // `+=` accumulation past a missing second count (a -1/+1 default or a
        // `=`/`-=` mutant would shift this).
        self::assertSame(5, $result->usage->promptTokens);
        // completionTokens is the fixed literal 0.
        self::assertSame(0, $result->usage->completionTokens);
    }

    #[Test]
    public function streamChatCompletionSendsExpectedRequest(): void
    {
        $capturedBody = '';
        $capturedUrl  = '';

        $subject = $this->configuredSubject($this->capturingStreamFactory($capturedBody));

        $httpClient = $this->createHttpClientWithExpectations();
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(
                function (RequestInterface $request) use (&$capturedUrl): ResponseInterface {
                    $capturedUrl = (string)$request->getUri();

                    return $this->streamingResponseStub("{\"message\":{\"content\":\"ok\"},\"done\":true}\n");
                },
            );
        $subject->setHttpClient($httpClient);

        $chunks = iterator_to_array(
            $subject->streamChatCompletion(
                [['role' => 'user', 'content' => 'Test']],
                ['temperature' => 0.5, 'think' => true],
            ),
        );

        self::assertSame(['ok'], $chunks);

        $payload = $this->decodeCapturedPayload($capturedBody);

        self::assertSame('http://localhost:11434/api/chat', $capturedUrl);
        self::assertSame('llama3.2', $payload['model']);
        self::assertTrue($payload['stream']);
        self::assertTrue($payload['think']);

        $options = $this->payloadArray($payload, 'options');
        self::assertSame(0.5, $options['temperature']);
    }

    #[Test]
    public function streamChatCompletionTrimsTrailingSlashFromBaseUrl(): void
    {
        $capturedBody = '';
        $capturedUrl  = '';

        // Trailing slash on the base URL exercises the rtrim() in the URL
        // assembly — without it the URL would carry a double slash.
        $subject = $this->configuredSubject($this->capturingStreamFactory($capturedBody), 'http://localhost:11434/');

        $httpClient = $this->createHttpClientWithExpectations();
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(
                function (RequestInterface $request) use (&$capturedUrl): ResponseInterface {
                    $capturedUrl = (string)$request->getUri();

                    return $this->streamingResponseStub("{\"message\":{\"content\":\"ok\"},\"done\":true}\n");
                },
            );
        $subject->setHttpClient($httpClient);

        iterator_to_array($subject->streamChatCompletion([['role' => 'user', 'content' => 'Test']]));

        self::assertSame('http://localhost:11434/api/chat', $capturedUrl);
    }

    #[Test]
    public function streamChatCompletionValidatesConfigurationBeforeStreaming(): void
    {
        $capturedBody = '';
        $capturedUrl  = '';

        $subject = $this->configuredSubject($this->capturingStreamFactory($capturedBody));

        // Force an empty base URL AFTER configure(): the streaming path must
        // call validateConfiguration() first, which restores the default —
        // otherwise the URL would collapse to "/api/chat".
        $reflection = new ReflectionClass($subject);
        $reflection->getProperty('baseUrl')->setValue($subject, '');

        $httpClient = $this->createHttpClientWithExpectations();
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(
                function (RequestInterface $request) use (&$capturedUrl): ResponseInterface {
                    $capturedUrl = (string)$request->getUri();

                    return $this->streamingResponseStub("{\"message\":{\"content\":\"ok\"},\"done\":true}\n");
                },
            );
        $subject->setHttpClient($httpClient);

        iterator_to_array($subject->streamChatCompletion([['role' => 'user', 'content' => 'Test']]));

        self::assertSame('http://localhost:11434/api/chat', $capturedUrl);
    }

    #[Test]
    public function streamChatCompletionSubstitutesInvalidUtf8InPayload(): void
    {
        $capturedBody = '';

        $subject = $this->configuredSubject($this->capturingStreamFactory($capturedBody));

        $httpClient = $this->createHttpClientWithExpectations();
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->streamingResponseStub("{\"message\":{\"content\":\"ok\"},\"done\":true}\n"));
        $subject->setHttpClient($httpClient);

        // The lone 0xB1 byte is invalid UTF-8; JSON_INVALID_UTF8_SUBSTITUTE
        // must let json_encode degrade it instead of returning false (which a
        // bitwise-& of the flags would cause, breaking createStream()).
        $chunks = iterator_to_array(
            $subject->streamChatCompletion([['role' => 'user', 'content' => "bad\xB1byte"]]),
        );

        self::assertSame(['ok'], $chunks);
        self::assertStringContainsString('"stream":true', $capturedBody);
    }

    #[Test]
    public function streamChatCompletionThrowsOnErrorStatus(): void
    {
        $capturedBody = '';

        $subject = $this->configuredSubject($this->capturingStreamFactory($capturedBody));

        $httpClient = $this->createHttpClientWithExpectations();
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->streamingResponseStub('{"error":"boom"}', 404));
        $subject->setHttpClient($httpClient);

        $this->expectException(ProviderResponseException::class);

        // The streaming path must assert the response is OK before yielding;
        // dropping that assertion would silently yield an empty stream.
        iterator_to_array($subject->streamChatCompletion([['role' => 'user', 'content' => 'Test']]));
    }
}
