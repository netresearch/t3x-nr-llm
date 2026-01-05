<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use Faker\Factory as FakerFactory;
use Faker\Generator as Faker;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Abstract base class for unit tests.
 *
 * Provides common mocking utilities and test helpers.
 */
abstract class AbstractUnitTestCase extends TestCase
{
    protected Faker $faker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faker = FakerFactory::create();
    }

    /**
     * Create a stub HTTP client.
     * Use createHttpClientWithExpectations() if you need expects().
     */
    protected function createHttpClientMock(): ClientInterface&Stub
    {
        return self::createStub(ClientInterface::class);
    }

    /**
     * Create a mock HTTP client that supports expectations.
     * Use this when your test needs expects($this->once()) etc.
     *
     * @return ClientInterface&MockObject
     */
    protected function createHttpClientWithExpectations(): ClientInterface
    {
        return $this->createMock(ClientInterface::class);
    }

    /**
     * Create a stub request factory.
     */
    protected function createRequestFactoryMock(): RequestFactoryInterface
    {
        $stub = self::createStub(RequestFactoryInterface::class);
        $stub->method('createRequest')
            ->willReturnCallback(fn(string $method, string $uri): RequestInterface => $this->createRequestMock($method, $uri));
        return $stub;
    }

    /**
     * Create a stub HTTP request with proper chaining support.
     */
    protected function createRequestMock(string $method = 'GET', string $uri = 'https://example.com'): RequestInterface
    {
        $uriStub = self::createStub(UriInterface::class);
        $uriStub->method('__toString')->willReturn($uri);
        $uriStub->method('getHost')->willReturn(parse_url($uri, PHP_URL_HOST) ?? '');
        $uriStub->method('getPath')->willReturn(parse_url($uri, PHP_URL_PATH) ?? '');

        // Create a stub with explicit return callback for proper type handling
        $request = self::createStub(RequestInterface::class);

        // Use callback to return the same stub for chaining methods
        $request->method('withHeader')->willReturnCallback(fn() => $request);
        $request->method('withBody')->willReturnCallback(fn() => $request);
        $request->method('withoutHeader')->willReturnCallback(fn() => $request);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uriStub);

        return $request;
    }

    /**
     * Create a stub stream factory.
     */
    protected function createStreamFactoryMock(): StreamFactoryInterface
    {
        $stub = self::createStub(StreamFactoryInterface::class);
        $stub->method('createStream')
            ->willReturnCallback(function (string $content) {
                $stream = $this->createStub(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);
                $stream->method('getContents')->willReturn($content);
                return $stream;
            });
        return $stub;
    }

    /**
     * Create a stub extension configuration.
     *
     * @param array<string, mixed> $config
     */
    protected function createExtensionConfigurationMock(array $config = []): ExtensionConfiguration
    {
        $stub = self::createStub(ExtensionConfiguration::class);
        $stub->method('get')
            ->willReturn($config);
        return $stub;
    }

    /**
     * Create a stub logger.
     */
    protected function createLoggerMock(): LoggerInterface
    {
        return self::createStub(LoggerInterface::class);
    }

    /**
     * Create a stub VaultService.
     *
     * @param array<string, string>|null $secrets Map of identifier => secret value.
     *                                            If null (default), all keys are considered to exist.
     *                                            If empty array, no keys exist.
     */
    protected function createVaultServiceMock(?array $secrets = null): VaultServiceInterface
    {
        $vaultHttpClient = self::createStub(VaultHttpClientInterface::class);
        $vaultHttpClient->method('withAuthentication')->willReturn($vaultHttpClient);
        $vaultHttpClient->method('withReason')->willReturn($vaultHttpClient);
        $vaultHttpClient->method('sendRequest')->willReturnCallback(fn() => $this->createHttpResponseMock(200, '{}'));

        $stub = self::createStub(VaultServiceInterface::class);
        $stub->method('retrieve')->willReturnCallback(fn(string $id) => $secrets[$id] ?? 'test-secret');

        // If secrets is null, assume all keys exist (for simpler tests)
        // If secrets is an array, check if the key exists in the array
        if ($secrets === null) {
            $stub->method('exists')->willReturn(true);
        } else {
            $stub->method('exists')->willReturnCallback(fn(string $id) => isset($secrets[$id]));
        }

        $stub->method('http')->willReturn($vaultHttpClient);

        return $stub;
    }

    /**
     * Create a SecureHttpClientFactory for tests.
     *
     * Since SecureHttpClientFactory is final and tests always use setHttpClient()
     * to inject a mock client, we simply return a real instance here.
     * The factory's create() method is never called in tests.
     */
    protected function createSecureHttpClientFactoryMock(): SecureHttpClientFactory
    {
        return new SecureHttpClientFactory();
    }

    /**
     * Create a stub HTTP response.
     *
     * @param array<string, list<string>> $headers
     */
    protected function createHttpResponseMock(
        int $statusCode,
        string $body,
        array $headers = [],
    ): ResponseInterface {
        $stream = self::createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $stream->method('getContents')->willReturn($body);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeader')->willReturnCallback(
            fn(string $name) => $headers[$name] ?? [],
        );
        $response->method('hasHeader')->willReturnCallback(
            fn(string $name) => isset($headers[$name]),
        );

        return $response;
    }

    /**
     * Create a successful JSON response mock.
     *
     * @param array<string, mixed> $data
     */
    protected function createJsonResponseMock(array $data, int $statusCode = 200): ResponseInterface
    {
        return $this->createHttpResponseMock(
            $statusCode,
            json_encode($data, JSON_THROW_ON_ERROR),
            ['Content-Type' => ['application/json']],
        );
    }

    /**
     * Create an error response mock.
     */
    protected function createErrorResponseMock(
        int $statusCode,
        string $message,
        string $type = 'error',
    ): ResponseInterface {
        return $this->createJsonResponseMock([
            'error' => [
                'message' => $message,
                'type' => $type,
            ],
        ], $statusCode);
    }

    /**
     * Generate random prompt.
     */
    protected function randomPrompt(): string
    {
        return $this->faker->sentence(10);
    }

    /**
     * Generate random model name.
     */
    protected function randomModel(): string
    {
        $models = [
            'gpt-4o',
            'gpt-4o-mini',
            'claude-sonnet-4-20250514',
            'gemini-2.0-flash',
            'mistral-large-latest',
            'llama-3.3-70b-versatile',
        ];

        return $models[array_rand($models)];
    }

    /**
     * Generate random API key.
     */
    protected function randomApiKey(): string
    {
        return 'sk-' . $this->faker->regexify('[a-zA-Z0-9]{48}');
    }
}
