<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use GuzzleHttp\Client as GuzzleClient;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Tests for AbstractProvider HTTP client timeout behavior.
 *
 * Verifies that timeout configuration is properly applied when creating
 * HTTP clients, and that the connect_timeout cap works correctly.
 */
#[CoversClass(AbstractProvider::class)]
class AbstractProviderHttpClientTest extends AbstractUnitTestCase
{
    #[Test]
    public function getHttpClientCreatesGuzzleClientWhenNoneConfigured(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        $client = $this->invokeProtectedMethod($provider, 'getHttpClient');

        self::assertInstanceOf(GuzzleClient::class, $client);
    }

    #[Test]
    public function getHttpClientReturnsInjectedClientWhenSet(): void
    {
        $mockClient = $this->createHttpClientMock();

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($mockClient);

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        $client = $this->invokeProtectedMethod($provider, 'getHttpClient');

        // Should return the same instance we injected
        self::assertSame($mockClient, $client);
    }

    #[Test]
    public function getHttpClientCreatesClientWithConfiguredTimeout(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => 60,
        ]);

        $client = $this->invokeProtectedMethod($provider, 'getHttpClient');

        self::assertInstanceOf(GuzzleClient::class, $client);
        /** @var GuzzleClient $client */
        $config = $client->getConfig();
        self::assertIsArray($config);

        self::assertEquals(60, $config['timeout']);
    }

    #[Test]
    public function getHttpClientCapsConnectTimeoutAt10Seconds(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => 120, // High timeout
        ]);

        $client = $this->invokeProtectedMethod($provider, 'getHttpClient');

        self::assertInstanceOf(GuzzleClient::class, $client);
        /** @var GuzzleClient $client */
        $config = $client->getConfig();
        self::assertIsArray($config);

        // connect_timeout should be capped at 10
        self::assertEquals(10, $config['connect_timeout']);
        self::assertEquals(120, $config['timeout']);
    }

    #[Test]
    public function getHttpClientUsesTimeoutAsConnectTimeoutWhenBelowCap(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => 5, // Below the 10 second cap
        ]);

        $client = $this->invokeProtectedMethod($provider, 'getHttpClient');

        self::assertInstanceOf(GuzzleClient::class, $client);
        /** @var GuzzleClient $client */
        $config = $client->getConfig();
        self::assertIsArray($config);

        // connect_timeout should match the lower timeout
        self::assertEquals(5, $config['connect_timeout']);
        self::assertEquals(5, $config['timeout']);
    }

    #[Test]
    public function getHttpClientReusesSameClientWhenTimeoutUnchanged(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => 30,
        ]);

        $client1 = $this->invokeProtectedMethod($provider, 'getHttpClient');
        $client2 = $this->invokeProtectedMethod($provider, 'getHttpClient');

        self::assertSame($client1, $client2);
    }

    #[Test]
    public function getHttpClientCreatesNewClientWhenTimeoutChanges(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => 30,
        ]);

        $client1 = $this->invokeProtectedMethod($provider, 'getHttpClient');

        // Reconfigure with different timeout
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => 60,
        ]);

        $client2 = $this->invokeProtectedMethod($provider, 'getHttpClient');

        // Should be a new instance
        self::assertNotSame($client1, $client2);
    }

    #[Test]
    public function getHttpClientCreatesNewClientAfterSetHttpClientWhenTimeoutChanges(): void
    {
        $mockClient = $this->createHttpClientMock();

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => 30,
        ]);

        $provider->setHttpClient($mockClient);

        // Should return the mock client
        $client1 = $this->invokeProtectedMethod($provider, 'getHttpClient');
        self::assertSame($mockClient, $client1);

        // Reconfigure with different timeout
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => 60,
        ]);

        // Now it should create a new GuzzleClient
        $client2 = $this->invokeProtectedMethod($provider, 'getHttpClient');
        self::assertNotSame($mockClient, $client2);
        self::assertInstanceOf(GuzzleClient::class, $client2);
    }

    #[Test]
    #[DataProvider('connectTimeoutCapProvider')]
    public function getHttpClientAppliesConnectTimeoutCapCorrectly(int $timeout, int $expectedConnectTimeout): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => $timeout,
        ]);

        $client = $this->invokeProtectedMethod($provider, 'getHttpClient');

        self::assertInstanceOf(GuzzleClient::class, $client);
        /** @var GuzzleClient $client */
        $config = $client->getConfig();
        self::assertIsArray($config);

        self::assertEquals($expectedConnectTimeout, $config['connect_timeout']);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function connectTimeoutCapProvider(): array
    {
        return [
            '1 second - below cap' => [1, 1],
            '5 seconds - below cap' => [5, 5],
            '9 seconds - below cap' => [9, 9],
            '10 seconds - at cap' => [10, 10],
            '11 seconds - above cap' => [11, 10],
            '30 seconds - above cap' => [30, 10],
            '120 seconds - well above cap' => [120, 10],
        ];
    }

    #[Test]
    public function setHttpClientStoresConfiguredTimeout(): void
    {
        $mockClient = $this->createHttpClientMock();

        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        // Configure first to set the timeout
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => 45,
        ]);

        // Then set the mock client
        $provider->setHttpClient($mockClient);

        // Verify internal state via reflection on the parent class
        $reflection = new ReflectionClass(AbstractProvider::class);
        $configuredTimeout = $reflection->getProperty('configuredTimeout');

        self::assertEquals(45, $configuredTimeout->getValue($provider));
    }

    /**
     * Helper to invoke protected methods.
     */
    private function invokeProtectedMethod(object $object, string $methodName): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($methodName);

        return $method->invoke($object);
    }
}
