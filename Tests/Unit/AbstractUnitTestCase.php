<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use Faker\Factory as FakerFactory;
use Faker\Generator as Faker;
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
     * Create a mock HTTP client.
     */
    protected function createHttpClientMock(): ClientInterface
    {
        return $this->createMock(ClientInterface::class);
    }

    /**
     * Create a mock request factory.
     */
    protected function createRequestFactoryMock(): RequestFactoryInterface
    {
        $mock = $this->createMock(RequestFactoryInterface::class);
        $mock->method('createRequest')
            ->willReturnCallback(function (string $method, string $uri): RequestInterface {
                return $this->createRequestMock($method, $uri);
            });
        return $mock;
    }

    /**
     * Create a mock HTTP request with proper chaining support.
     */
    protected function createRequestMock(string $method = 'GET', string $uri = 'https://example.com'): RequestInterface
    {
        $uriMock = $this->createMock(UriInterface::class);
        $uriMock->method('__toString')->willReturn($uri);
        $uriMock->method('getHost')->willReturn(parse_url($uri, PHP_URL_HOST) ?? '');
        $uriMock->method('getPath')->willReturn(parse_url($uri, PHP_URL_PATH) ?? '');

        // Create a mock with explicit return callback for proper type handling
        $request = $this->createMock(RequestInterface::class);

        // Use callback to return the same mock for chaining methods
        $request->method('withHeader')->willReturnCallback(fn() => $request);
        $request->method('withBody')->willReturnCallback(fn() => $request);
        $request->method('withoutHeader')->willReturnCallback(fn() => $request);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uriMock);

        return $request;
    }

    /**
     * Create a mock stream factory.
     */
    protected function createStreamFactoryMock(): StreamFactoryInterface
    {
        $mock = $this->createMock(StreamFactoryInterface::class);
        $mock->method('createStream')
            ->willReturnCallback(function (string $content) {
                $stream = $this->createMock(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);
                $stream->method('getContents')->willReturn($content);
                return $stream;
            });
        return $mock;
    }

    /**
     * Create a mock extension configuration.
     */
    protected function createExtensionConfigurationMock(array $config = []): ExtensionConfiguration
    {
        $mock = $this->createMock(ExtensionConfiguration::class);
        $mock->method('get')
            ->with('nr_llm')
            ->willReturn($config);
        return $mock;
    }

    /**
     * Create a mock logger.
     */
    protected function createLoggerMock(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    /**
     * Create a mock HTTP response.
     */
    protected function createHttpResponseMock(
        int $statusCode,
        string $body,
        array $headers = []
    ): ResponseInterface {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $stream->method('getContents')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeader')->willReturnCallback(
            fn(string $name) => $headers[$name] ?? []
        );
        $response->method('hasHeader')->willReturnCallback(
            fn(string $name) => isset($headers[$name])
        );

        return $response;
    }

    /**
     * Create a successful JSON response mock.
     */
    protected function createJsonResponseMock(array $data, int $statusCode = 200): ResponseInterface
    {
        return $this->createHttpResponseMock(
            $statusCode,
            json_encode($data, JSON_THROW_ON_ERROR),
            ['Content-Type' => ['application/json']]
        );
    }

    /**
     * Create an error response mock.
     */
    protected function createErrorResponseMock(
        int $statusCode,
        string $message,
        string $type = 'error'
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
        return $this->faker->randomElement([
            'gpt-4o',
            'gpt-4o-mini',
            'claude-sonnet-4-20250514',
            'gemini-2.0-flash',
            'mistral-large-latest',
            'llama-3.3-70b-versatile',
        ]);
    }

    /**
     * Generate random API key.
     */
    protected function randomApiKey(): string
    {
        return 'sk-' . $this->faker->regexify('[a-zA-Z0-9]{48}');
    }
}
