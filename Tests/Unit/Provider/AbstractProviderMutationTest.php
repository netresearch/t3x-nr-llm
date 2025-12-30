<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Exception;
use GuzzleHttp\Psr7\HttpFactory;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;

/**
 * Mutation-killing tests for AbstractProvider.
 *
 * These tests specifically target escaped mutants in sendRequest(),
 * extractErrorMessage(), and related methods.
 */
#[CoversClass(AbstractProvider::class)]
class AbstractProviderMutationTest extends AbstractUnitTestCase
{
    // ===== Tests for supportsFeature() =====

    private function createProvider(): GeminiProvider
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        return $provider;
    }

    #[Test]
    public function supportsFeatureReturnsTrueForSupportedFeature(): void
    {
        $provider = $this->createProvider();

        // GeminiProvider supports chat
        self::assertTrue($provider->supportsFeature('chat'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForUnsupportedFeature(): void
    {
        $provider = $this->createProvider();

        // Random non-existent feature
        self::assertFalse($provider->supportsFeature('non_existent_feature'));
    }

    #[Test]
    public function supportsFeatureUsesStrictComparison(): void
    {
        $provider = $this->createProvider();

        // Case-sensitive - "Chat" should not match "chat"
        self::assertFalse($provider->supportsFeature('Chat'));
        self::assertFalse($provider->supportsFeature('CHAT'));
    }

    // ===== Tests for sendRequest() status code boundaries =====

    #[Test]
    #[DataProvider('successfulStatusCodeProvider')]
    public function sendRequestReturnsDataForSuccessfulStatusCodes(int $statusCode): void
    {
        $expectedResponse = ['content' => 'test response'];

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($expectedResponse, $statusCode));

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        // Use reflection to test sendRequest directly
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $result = $method->invoke($provider, '/test', []);

        self::assertEquals($expectedResponse, $result);
    }

    public static function successfulStatusCodeProvider(): array
    {
        return [
            'exact 200' => [200],
            'boundary 201' => [201],
            'mid-range 250' => [250],
            'boundary 299' => [299],
        ];
    }

    #[Test]
    #[DataProvider('clientErrorStatusCodeProvider')]
    public function sendRequestThrowsProviderResponseExceptionForClientErrors(int $statusCode): void
    {
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'Client error']], $statusCode));

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        $this->expectException(ProviderResponseException::class);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $method->invoke($provider, '/test', []);
    }

    public static function clientErrorStatusCodeProvider(): array
    {
        return [
            'exact 400' => [400],
            'boundary 401' => [401],
            'forbidden 403' => [403],
            'not found 404' => [404],
            'boundary 499' => [499],
        ];
    }

    #[Test]
    #[DataProvider('serverErrorStatusCodeProvider')]
    public function sendRequestThrowsConnectionExceptionForServerErrors(int $statusCode): void
    {
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => 'Server error'], $statusCode));

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'maxRetries' => 1,
        ]);

        $this->expectException(ProviderConnectionException::class);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $method->invoke($provider, '/test', []);
    }

    public static function serverErrorStatusCodeProvider(): array
    {
        return [
            'exact 500' => [500],
            'boundary 501' => [501],
            'service unavailable 503' => [503],
        ];
    }

    // ===== Tests for URL building in sendRequest() =====

    #[Test]
    public function sendRequestTrimsTrailingSlashFromBaseUrl(): void
    {
        $capturedUrl = null;

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedUrl) {
                $capturedUrl = (string)$request->getUri();

                return $this->createJsonResponseMock(['ok' => true]);
            });

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'baseUrl' => 'https://api.example.com/',
        ]);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $method->invoke($provider, '/endpoint', []);

        // Should not have double slashes
        self::assertStringNotContainsString('//', str_replace('https://', '', $capturedUrl));
    }

    #[Test]
    public function sendRequestAddsLeadingSlashToEndpoint(): void
    {
        $capturedUrl = null;

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedUrl) {
                $capturedUrl = (string)$request->getUri();

                return $this->createJsonResponseMock(['ok' => true]);
            });

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'baseUrl' => 'https://api.example.com',
        ]);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $method->invoke($provider, 'endpoint', []);

        self::assertStringContainsString('/endpoint', $capturedUrl);
    }

    // ===== Tests for retry logic =====

    #[Test]
    public function sendRequestRetriesOnConnectionFailure(): void
    {
        $attempts = 0;

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function () use (&$attempts) {
                $attempts++;
                if ($attempts < 3) {
                    throw new class ('Connection failed', 6712573549) extends Exception implements ClientExceptionInterface {};
                }

                return $this->createJsonResponseMock(['ok' => true]);
            });

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'maxRetries' => 3,
        ]);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $result = $method->invoke($provider, '/test', []);

        self::assertEquals(3, $attempts);
        self::assertEquals(['ok' => true], $result);
    }

    #[Test]
    public function sendRequestStopsRetryingAfterMaxRetries(): void
    {
        $attempts = 0;

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function () use (&$attempts): void {
                $attempts++;
                throw new class ('Connection failed', 9141046435) extends Exception implements ClientExceptionInterface {};
            });

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'maxRetries' => 2,
        ]);

        try {
            $reflection = new ReflectionClass($provider);
            $method = $reflection->getMethod('sendRequest');
            $method->invoke($provider, '/test', []);
            self::fail('Expected ProviderConnectionException');
        } catch (ProviderConnectionException $e) {
            self::assertEquals(2, $attempts);
            self::assertStringContainsString('Failed to connect to provider after 2 attempts', $e->getMessage());
        }
    }

    #[Test]
    public function sendRequestDoesNotRetryOnClientErrors(): void
    {
        $attempts = 0;

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function () use (&$attempts) {
                $attempts++;

                return $this->createJsonResponseMock(['error' => ['message' => 'Bad request']], 400);
            });

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'maxRetries' => 3,
        ]);

        try {
            $reflection = new ReflectionClass($provider);
            $method = $reflection->getMethod('sendRequest');
            $method->invoke($provider, '/test', []);
            self::fail('Expected ProviderResponseException');
        } catch (ProviderResponseException) {
            // Client errors (4xx) should NOT retry
            self::assertEquals(1, $attempts);
        }
    }

    // Note: Tests for POST payload encoding are covered by integration tests
    // as they require full HTTP client integration

    // ===== Tests for extractErrorMessage() =====

    #[Test]
    #[DataProvider('errorMessageProvider')]
    public function extractErrorMessageParsesVariousFormats(array $errorData, string $expectedMessage): void
    {
        $provider = $this->createProvider();

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('extractErrorMessage');
        $result = $method->invoke($provider, $errorData);

        self::assertEquals($expectedMessage, $result);
    }

    public static function errorMessageProvider(): array
    {
        return [
            'OpenAI nested format' => [
                ['error' => ['message' => 'Rate limit exceeded']],
                'Rate limit exceeded',
            ],
            'nested error with string value fallback' => [
                // When error is a non-empty array without message but error key also has a string
                // This won't match because getArray returns [] for string values
                ['error' => ['code' => 500], 'message' => 'Fallback message'],
                'Fallback message',
            ],
            'direct message field' => [
                ['message' => 'Direct error message'],
                'Direct error message',
            ],
            'empty error array' => [
                ['error' => []],
                'Unknown provider error',
            ],
            'no error info' => [
                [],
                'Unknown provider error',
            ],
            'nested error with no message' => [
                ['error' => ['code' => 500]],
                'Unknown provider error',
            ],
        ];
    }

    // ===== Tests for validateConfiguration() =====

    #[Test]
    public function validateConfigurationThrowsWhenApiKeyEmpty(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => '',
        ]);

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage('API key is required');

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('validateConfiguration');
        $method->invoke($provider);
    }

    #[Test]
    public function validateConfigurationDoesNotThrowWhenApiKeyProvided(): void
    {
        $provider = $this->createProvider();
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        // Should not throw
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('validateConfiguration');
        $method->invoke($provider);

        self::assertTrue(true);
    }

    // ===== Tests for createUsageStatistics() =====

    #[Test]
    public function createUsageStatisticsCalculatesTotalCorrectly(): void
    {
        $provider = $this->createProvider();

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('createUsageStatistics');
        /** @var UsageStatistics $result */
        $result = $method->invoke($provider, 100, 50);

        self::assertEquals(100, $result->promptTokens);
        self::assertEquals(50, $result->completionTokens);
        self::assertEquals(150, $result->totalTokens);
    }

    #[Test]
    public function createUsageStatisticsHandlesZeroValues(): void
    {
        $provider = $this->createProvider();

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('createUsageStatistics');
        /** @var UsageStatistics $result */
        $result = $method->invoke($provider, 0, 0);

        self::assertEquals(0, $result->promptTokens);
        self::assertEquals(0, $result->completionTokens);
        self::assertEquals(0, $result->totalTokens);
    }

    // ===== Tests for createCompletionResponse() =====

    #[Test]
    public function createCompletionResponseUsesDefaultFinishReason(): void
    {
        $provider = $this->createProvider();

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('createCompletionResponse');
        $usage = new UsageStatistics(10, 20, 30);
        /** @var CompletionResponse $result */
        $result = $method->invoke($provider, 'content', 'model', $usage, null);

        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function createCompletionResponseUsesProvidedFinishReason(): void
    {
        $provider = $this->createProvider();

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('createCompletionResponse');
        $usage = new UsageStatistics(10, 20, 30);
        /** @var CompletionResponse $result */
        $result = $method->invoke($provider, 'content', 'model', $usage, 'length');

        self::assertEquals('length', $result->finishReason);
    }

    #[Test]
    public function createCompletionResponseIncludesProviderIdentifier(): void
    {
        $provider = $this->createProvider();

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('createCompletionResponse');
        $usage = new UsageStatistics(10, 20, 30);
        /** @var CompletionResponse $result */
        $result = $method->invoke($provider, 'content', 'model', $usage, null);

        self::assertEquals($provider->getIdentifier(), $result->provider);
    }

    // ===== Tests for createEmbeddingResponse() =====

    #[Test]
    public function createEmbeddingResponseIncludesProviderIdentifier(): void
    {
        $provider = $this->createProvider();

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('createEmbeddingResponse');
        $usage = new UsageStatistics(10, 0, 10);
        /** @var EmbeddingResponse $result */
        $result = $method->invoke($provider, [[0.1, 0.2, 0.3]], 'model', $usage);

        self::assertEquals($provider->getIdentifier(), $result->provider);
        self::assertEquals([[0.1, 0.2, 0.3]], $result->embeddings);
    }

    // ===== Tests for isAvailable() boundary =====

    #[Test]
    public function isAvailableReturnsFalseForEmptyApiKey(): void
    {
        $provider = $this->createProvider();

        $provider->configure(['apiKey' => '']);

        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsTrueForNonEmptyApiKey(): void
    {
        $provider = $this->createProvider();

        $provider->configure(['apiKey' => 'a']);

        self::assertTrue($provider->isAvailable());
    }

    // Note: Tests for complete() delegation are covered by integration tests
    // as they require full HTTP client integration

    // ===== Additional tests for mutation coverage =====

    #[Test]
    public function sendRequestSetsAuthorizationHeaderWithBearerPrefix(): void
    {
        $capturedHeaders = [];
        $httpFactory = new HttpFactory();

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedHeaders) {
                $capturedHeaders = $request->getHeaders();

                return $this->createJsonResponseMock(['ok' => true]);
            });

        // Use OpenAiProvider because GeminiProvider removes Authorization header
        $provider = new OpenAiProvider(
            $httpFactory,
            $httpFactory,
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => 'test-api-key-123',
        ]);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $method->invoke($provider, '/test', []);

        self::assertArrayHasKey('Authorization', $capturedHeaders);
        self::assertStringStartsWith('Bearer ', $capturedHeaders['Authorization'][0]);
        self::assertStringContainsString('test-api-key-123', $capturedHeaders['Authorization'][0]);
    }

    #[Test]
    public function sendRequestDoesNotSetBodyForGetRequest(): void
    {
        $capturedBody = 'initial';
        $httpFactory = new HttpFactory();

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedBody) {
                $capturedBody = (string)$request->getBody();

                return $this->createJsonResponseMock(['ok' => true]);
            });

        $provider = new GeminiProvider(
            $httpFactory,
            $httpFactory,
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $method->invoke($provider, '/test', ['data' => 'value'], 'GET');

        // GET requests should not have body even if payload is provided
        self::assertEmpty($capturedBody);
    }

    #[Test]
    public function sendRequestDoesNotSetBodyForEmptyPayload(): void
    {
        $capturedBody = 'initial';
        $httpFactory = new HttpFactory();

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedBody) {
                $capturedBody = (string)$request->getBody();

                return $this->createJsonResponseMock(['ok' => true]);
            });

        $provider = new GeminiProvider(
            $httpFactory,
            $httpFactory,
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $method->invoke($provider, '/test', []);

        // Empty payload should not set body
        self::assertEmpty($capturedBody);
    }

    #[Test]
    public function sendRequestSetsBodyForPostWithPayload(): void
    {
        $capturedBody = '';
        $httpFactory = new HttpFactory();

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$capturedBody) {
                $capturedBody = (string)$request->getBody();

                return $this->createJsonResponseMock(['ok' => true]);
            });

        $provider = new GeminiProvider(
            $httpFactory,
            $httpFactory,
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $method->invoke($provider, '/test', ['key' => 'value']);

        // POST with payload should have body
        self::assertNotEmpty($capturedBody);
        $decoded = json_decode($capturedBody, true);
        self::assertEquals(['key' => 'value'], $decoded);
    }

    #[Test]
    public function sendRequestErrorMessageContainsRetryCount(): void
    {
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willThrowException(new class ('Network error', 4892619012) extends Exception implements ClientExceptionInterface {});

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'maxRetries' => 5,
        ]);

        try {
            $reflection = new ReflectionClass($provider);
            $method = $reflection->getMethod('sendRequest');
            $method->invoke($provider, '/test', []);
            self::fail('Expected ProviderConnectionException');
        } catch (ProviderConnectionException $e) {
            // Must contain the specific retry count
            self::assertStringContainsString('5 attempts', $e->getMessage());
            // And the original error message
            self::assertStringContainsString('Network error', $e->getMessage());
        }
    }

    #[Test]
    public function sendRequestHandlesNullLastExceptionMessage(): void
    {
        $attempts = 0;
        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function () use (&$attempts) {
                $attempts++;
                // Return a server error that causes retry
                return $this->createJsonResponseMock(['error' => 'Server error'], 500);
            });

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'maxRetries' => 2,
        ]);

        try {
            $reflection = new ReflectionClass($provider);
            $method = $reflection->getMethod('sendRequest');
            $method->invoke($provider, '/test', []);
            self::fail('Expected ProviderConnectionException');
        } catch (ProviderConnectionException $e) {
            // When we get a 500 status, lastException is set from the status code error
            // The message should contain 'Server returned status 500'
            self::assertStringContainsString('2 attempts', $e->getMessage());
        }
    }

    #[Test]
    public function sendRequestRetriesExactlyMaxRetriesTimes(): void
    {
        $attempts = 0;

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function () use (&$attempts): void {
                $attempts++;
                throw new class ('Fail', 2103857921) extends Exception implements ClientExceptionInterface {};
            });

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'maxRetries' => 4,
        ]);

        try {
            $reflection = new ReflectionClass($provider);
            $method = $reflection->getMethod('sendRequest');
            $method->invoke($provider, '/test', []);
        } catch (ProviderConnectionException) {
            // Should have tried exactly 4 times
            self::assertEquals(4, $attempts);
        }
    }

    #[Test]
    public function sendRequestSucceedsOnFirstAttemptWithSingleRetry(): void
    {
        $attempts = 0;

        $httpClient = self::createStub(ClientInterface::class);
        $httpClient
            ->method('sendRequest')
            ->willReturnCallback(function () use (&$attempts) {
                $attempts++;

                return $this->createJsonResponseMock(['success' => true]);
            });

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($httpClient);
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'maxRetries' => 1,
        ]);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('sendRequest');
        $result = $method->invoke($provider, '/test', []);

        // Should succeed on first attempt
        self::assertEquals(1, $attempts);
        self::assertEquals(['success' => true], $result);
    }
}
