<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Tests for AbstractProvider::configure() method to kill escaped mutants.
 */
#[CoversClass(AbstractProvider::class)]
class AbstractProviderConfigureTest extends AbstractUnitTestCase
{
    #[Test]
    public function configureUsesDefaultTimeoutOf30WhenNotProvided(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        // Access the timeout via reflection to verify default
        $reflection = new ReflectionClass($provider);
        $timeout = $reflection->getProperty('timeout');

        self::assertEquals(30, $timeout->getValue($provider));
    }

    #[Test]
    public function configureUsesDefaultMaxRetriesOf3WhenNotProvided(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        $reflection = new ReflectionClass($provider);
        $maxRetries = $reflection->getProperty('maxRetries');

        self::assertEquals(3, $maxRetries->getValue($provider));
    }

    #[Test]
    public function configureUsesProvidedTimeoutValue(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => 60,
        ]);

        $reflection = new ReflectionClass($provider);
        $timeout = $reflection->getProperty('timeout');

        self::assertEquals(60, $timeout->getValue($provider));
    }

    #[Test]
    public function configureUsesProvidedMaxRetriesValue(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'maxRetries' => 5,
        ]);

        $reflection = new ReflectionClass($provider);
        $maxRetries = $reflection->getProperty('maxRetries');

        self::assertEquals(5, $maxRetries->getValue($provider));
    }

    #[Test]
    public function configureUsesEmptyStringApiKeyWhenNotProvided(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        // Configure with empty config should NOT throw (lazy validation)
        $provider->configure([]);

        // Provider should report as not available
        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function configureUsesProvidedBaseUrl(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $customBaseUrl = 'https://custom-api.example.com';
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'baseUrl' => $customBaseUrl,
        ]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');

        self::assertEquals($customBaseUrl, $baseUrl->getValue($provider));
    }

    #[Test]
    public function configureUsesDefaultBaseUrlWhenNotProvided(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');

        // GeminiProvider has a specific default base URL
        self::assertNotEmpty($baseUrl->getValue($provider));
        self::assertStringContainsString('generativelanguage.googleapis.com', $baseUrl->getValue($provider));
    }

    #[Test]
    public function configureUsesProvidedDefaultModel(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $customModel = 'gemini-custom-model';
        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => $customModel,
        ]);

        self::assertEquals($customModel, $provider->getDefaultModel());
    }

    #[Test]
    public function configureUsesProviderDefaultModelWhenNotProvided(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
        ]);

        // Should use provider's default model
        self::assertNotEmpty($provider->getDefaultModel());
    }

    #[Test]
    #[DataProvider('timeoutValuesProvider')]
    public function configureAcceptsVariousTimeoutValues(int $timeout): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'timeout' => $timeout,
        ]);

        $reflection = new ReflectionClass($provider);
        $timeoutProp = $reflection->getProperty('timeout');

        self::assertEquals($timeout, $timeoutProp->getValue($provider));
    }

    public static function timeoutValuesProvider(): array
    {
        return [
            'minimum' => [1],
            'default minus one' => [29],
            'default' => [30],
            'default plus one' => [31],
            'high' => [120],
        ];
    }

    #[Test]
    #[DataProvider('maxRetriesValuesProvider')]
    public function configureAcceptsVariousMaxRetriesValues(int $maxRetries): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'maxRetries' => $maxRetries,
        ]);

        $reflection = new ReflectionClass($provider);
        $maxRetriesProp = $reflection->getProperty('maxRetries');

        self::assertEquals($maxRetries, $maxRetriesProp->getValue($provider));
    }

    public static function maxRetriesValuesProvider(): array
    {
        return [
            'minimum' => [1],
            'default minus one' => [2],
            'default' => [3],
            'default plus one' => [4],
            'high' => [10],
        ];
    }

    #[Test]
    public function configureWithEmptyApiKeyMarksProviderAsNotAvailable(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        // Configure with empty API key should NOT throw (lazy validation)
        $provider->configure([
            'apiKey' => '',
        ]);

        // Provider should report as not available
        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function configureCastsNullApiKeyToEmptyString(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        // Passing null should be cast to empty string (lazy validation)
        $provider->configure([
            'apiKey' => null,
        ]);

        // Provider should report as not available due to empty API key
        self::assertFalse($provider->isAvailable());
    }
}
