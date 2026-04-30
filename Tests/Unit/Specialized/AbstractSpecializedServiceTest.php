<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\AbstractSpecializedService;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\MultipartBodyBuilderTrait;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(AbstractSpecializedService::class)]
final class AbstractSpecializedServiceTest extends AbstractUnitTestCase
{
    #[Test]
    public function isAvailableReturnsFalseWhenApiKeyIsEmpty(): void
    {
        $subject = $this->createSubject(apiKey: '');

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyIsConfigured(): void
    {
        $subject = $this->createSubject(apiKey: 'test-key');

        self::assertTrue($subject->isAvailable());
    }

    #[Test]
    public function ensureAvailableThrowsWhenNotConfigured(): void
    {
        $subject = $this->createSubject(apiKey: '');

        $this->expectException(ServiceUnavailableException::class);

        $subject->callEnsureAvailable();
    }

    #[Test]
    public function ensureAvailableIsNoOpWhenConfigured(): void
    {
        $subject = $this->createSubject(apiKey: 'test-key');

        $subject->callEnsureAvailable();

        // Reaching here = no exception = pass.
        self::assertTrue($subject->isAvailable());
    }

    #[Test]
    public function buildEndpointUrlConcatenatesWithSingleSlash(): void
    {
        $subject = $this->createSubject(baseUrl: 'https://api.example.test/v1');

        self::assertSame('https://api.example.test/v1/foo', $subject->callBuildEndpointUrl('foo'));
        self::assertSame('https://api.example.test/v1/foo', $subject->callBuildEndpointUrl('/foo'));
    }

    #[Test]
    public function buildEndpointUrlHandlesTrailingSlashOnBase(): void
    {
        $subject = $this->createSubject(baseUrl: 'https://api.example.test/v1/');

        self::assertSame('https://api.example.test/v1/foo', $subject->callBuildEndpointUrl('foo'));
    }

    #[Test]
    public function buildEndpointUrlReturnsBaseWhenEndpointEmpty(): void
    {
        // TTS posts directly to the base URL — endpoint is empty.
        $subject = $this->createSubject(baseUrl: 'https://api.example.test/v1/audio/speech');

        self::assertSame('https://api.example.test/v1/audio/speech', $subject->callBuildEndpointUrl(''));
    }

    #[Test]
    public function sendJsonRequestReturnsDecodedSuccessResponse(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['result' => 'ok'], 200));

        $subject = $this->createSubject(apiKey: 'k', baseUrl: 'https://api.test/v1', httpClient: $httpClient);

        $result = $subject->callSendJsonRequest('endpoint', ['foo' => 'bar']);

        self::assertSame(['result' => 'ok'], $result);
    }

    #[Test]
    public function sendJsonRequestAddsAuthHeaderFromBuildAuthHeaders(): void
    {
        $captured = null;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$captured) {
                $captured = $request;
                return $this->createJsonResponseMock([], 200);
            });

        $subject = $this->createSubject(apiKey: 'sekret', baseUrl: 'https://api.test/v1', httpClient: $httpClient);

        $subject->callSendJsonRequest('endpoint', []);

        self::assertNotNull($captured);
        self::assertSame('TestableScheme sekret', $captured->getHeaderLine('Authorization'));
        self::assertSame('application/json', $captured->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function executeRequestThrowsConfigurationExceptionOn401(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'invalid key']], 401));

        $subject = $this->createSubject(apiKey: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        $this->expectException(ServiceConfigurationException::class);

        $subject->callSendJsonRequest('endpoint', []);
    }

    #[Test]
    public function executeRequestThrowsConfigurationExceptionOn403(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'forbidden']], 403));

        $subject = $this->createSubject(apiKey: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        $this->expectException(ServiceConfigurationException::class);

        $subject->callSendJsonRequest('endpoint', []);
    }

    #[Test]
    public function executeRequestThrowsRateLimitOn429(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'too many requests']], 429));

        $subject = $this->createSubject(apiKey: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        try {
            $subject->callSendJsonRequest('endpoint', []);
            self::fail('Expected ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('rate limit', $e->getMessage());
        }
    }

    #[Test]
    public function executeRequestExtractsErrorMessageFromOpenAiShape(): void
    {
        // `{"error": {"message": "..."}}` is the most common shape;
        // `decodeErrorMessage()` handles it by default. This is the
        // fallback for any subclass that doesn't override.
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'bad request — prompt empty']], 400));

        $subject = $this->createSubject(apiKey: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        try {
            $subject->callSendJsonRequest('endpoint', []);
            self::fail('Expected ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('bad request — prompt empty', $e->getMessage());
        }
    }

    #[Test]
    public function executeRequestWrapsTransportExceptionAsServiceUnavailable(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('connection reset'));

        $subject = $this->createSubject(apiKey: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        try {
            $subject->callSendJsonRequest('endpoint', []);
            self::fail('Expected ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('Failed to connect', $e->getMessage());
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function executeRequestReturnsEmptyArrayForEmpty2xxBody(): void
    {
        // Some endpoints (e.g. TTS) return binary or empty bodies on
        // success — the JSON decode path must not blow up.
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createHttpResponseMock(204, ''));

        $subject = $this->createSubject(apiKey: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        $result = $subject->callSendJsonRequest('endpoint', []);

        self::assertSame([], $result);
    }

    #[Test]
    public function loadConfigurationFailsSafelyOnException(): void
    {
        // Throws inside ExtensionConfiguration->get() — the service
        // must come up uncrashed with `isAvailable() === false` so
        // callers get a graceful unavailable error rather than a
        // bootstrap fatal.
        $extConf = self::createStub(ExtensionConfiguration::class);
        $extConf->method('get')->willThrowException(new RuntimeException('boom'));

        $subject = new TestableSpecializedService(
            httpClient: self::createStub(ClientInterface::class),
            requestFactory: $this->passthroughRequestFactory(),
            streamFactory: $this->passthroughStreamFactory(),
            extensionConfiguration: $extConf,
            usageTracker: self::createStub(UsageTrackerServiceInterface::class),
            logger: self::createStub(LoggerInterface::class),
        );

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function multipartTraitBuildsExpectedBoundaryAndBody(): void
    {
        $subject = $this->createSubject(apiKey: 'k', baseUrl: 'https://api.test');

        $body = $subject->callEncodeMultipartBody([
            ['name' => 'file', 'filename' => 'a.bin', 'content' => 'BIN', 'contentType' => 'application/octet-stream'],
            ['name' => 'model', 'value' => 'whisper-1'],
        ], 'BOUNDARY');

        self::assertStringContainsString('--BOUNDARY', $body);
        self::assertStringContainsString('Content-Disposition: form-data; name="file"; filename="a.bin"', $body);
        self::assertStringContainsString('Content-Type: application/octet-stream', $body);
        self::assertStringContainsString('BIN', $body);
        self::assertStringContainsString('Content-Disposition: form-data; name="model"', $body);
        self::assertStringContainsString('whisper-1', $body);
        self::assertStringEndsWith("--BOUNDARY--\r\n", $body);
    }

    #[Test]
    public function multipartTraitSkipsPartsMissingName(): void
    {
        // Defensive: a caller that hands us a malformed part dict
        // shouldn't poison the entire body. The part is silently
        // skipped (rather than throwing) so the surrounding parts
        // still produce a valid body.
        $subject = $this->createSubject(apiKey: 'k', baseUrl: 'https://api.test');

        $body = $subject->callEncodeMultipartBody([
            ['name' => 'good', 'value' => 'x'],
            ['value' => 'orphan'],          // missing name → skipped
            ['name' => 'also-good', 'value' => 'y'],
        ], 'B');

        self::assertStringContainsString('name="good"', $body);
        self::assertStringContainsString('name="also-good"', $body);
        self::assertStringNotContainsString('orphan', $body);
    }

    private function createSubject(
        string $apiKey = 'test-key',
        string $baseUrl = 'https://api.example.test',
        ?ClientInterface $httpClient = null,
    ): TestableSpecializedService {
        $extConf = self::createStub(ExtensionConfiguration::class);
        $extConf->method('get')->willReturn(['apiKey' => $apiKey, 'baseUrl' => $baseUrl]);

        return new TestableSpecializedService(
            httpClient: $httpClient ?? self::createStub(ClientInterface::class),
            requestFactory: $this->passthroughRequestFactory(),
            streamFactory: $this->passthroughStreamFactory(),
            extensionConfiguration: $extConf,
            usageTracker: self::createStub(UsageTrackerServiceInterface::class),
            logger: self::createStub(LoggerInterface::class),
        );
    }

    private function passthroughRequestFactory(): RequestFactoryInterface
    {
        $stub = self::createStub(RequestFactoryInterface::class);
        $stub->method('createRequest')
            ->willReturnCallback(static function (string $method, mixed $uri): RequestInterface {
                $uriString = is_string($uri) ? $uri : (is_object($uri) && method_exists($uri, '__toString') ? $uri->__toString() : '');
                return new TestableRequest($method, $uriString);
            });
        return $stub;
    }

    private function passthroughStreamFactory(): StreamFactoryInterface
    {
        $stub = self::createStub(StreamFactoryInterface::class);
        $stub->method('createStream')->willReturnCallback(function (string $content): StreamInterface {
            $stream = $this->createStub(StreamInterface::class);
            $stream->method('__toString')->willReturn($content);
            $stream->method('getContents')->willReturn($content);
            return $stream;
        });
        return $stub;
    }
}

/**
 * Concrete fixture exercising the abstract base. Public delegates
 * (`callX()`) expose protected members for assertion convenience.
 */
final class TestableSpecializedService extends AbstractSpecializedService
{
    use MultipartBodyBuilderTrait;

    public function callEnsureAvailable(): void
    {
        $this->ensureAvailable();
    }

    public function callBuildEndpointUrl(string $endpoint): string
    {
        return $this->buildEndpointUrl($endpoint);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function callSendJsonRequest(string $endpoint, array $payload): array
    {
        return $this->sendJsonRequest($endpoint, $payload);
    }

    /**
     * @param list<array<string, mixed>> $parts
     */
    public function callEncodeMultipartBody(array $parts, string $boundary): string
    {
        return $this->encodeMultipartBody($parts, $boundary);
    }

    protected function getServiceDomain(): string
    {
        return 'test';
    }

    protected function getServiceProvider(): string
    {
        return 'testable';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.example.test';
    }

    protected function getDefaultTimeout(): int
    {
        return 42;
    }

    protected function loadServiceConfiguration(array $config): void
    {
        $this->apiKey  = is_string($config['apiKey']  ?? null) ? $config['apiKey'] : '';
        $this->baseUrl = is_string($config['baseUrl'] ?? null) ? $config['baseUrl'] : $this->getDefaultBaseUrl();
    }

    protected function buildAuthHeaders(): array
    {
        return ['Authorization' => 'TestableScheme ' . $this->apiKey];
    }
}

/**
 * Real-ish RequestInterface implementation for tests — captures
 * headers / body so assertions can read them back. Trimmed to the
 * subset the base class exercises.
 */
final class TestableRequest implements RequestInterface
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    private ?StreamInterface $body = null;

    public function __construct(
        private readonly string $method,
        private readonly string $uri,
    ) {}

    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = array_values(is_array($value) ? array_map(strval(...), $value) : [(string)$value]);
        return $clone;
    }

    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->headers[$name] ?? []);
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getRequestTarget(): string
    {
        return $this->uri;
    }

    /**
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        return $this->headers[$name] ?? [];
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    public function getBody(): StreamInterface
    {
        if ($this->body === null) {
            throw new RuntimeException('No body set', 3134810639);
        }
        return $this->body;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }
    public function withProtocolVersion(string $version): static
    {
        return $this;
    }
    public function withAddedHeader(string $name, $value): static
    {
        return $this->withHeader($name, $value);
    }
    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        unset($clone->headers[$name]);
        return $clone;
    }
    public function withRequestTarget(string $requestTarget): static
    {
        return $this;
    }
    public function withMethod(string $method): static
    {
        return $this;
    }
    public function getUri(): UriInterface
    {
        throw new RuntimeException('Not implemented', 4146456712);
    }
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        return $this;
    }
}
