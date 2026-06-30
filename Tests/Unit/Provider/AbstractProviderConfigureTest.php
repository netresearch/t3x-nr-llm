<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $customBaseUrl = 'https://custom-api.example.com';
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
        ]);

        $reflection = new ReflectionClass($provider);
        $baseUrl = $reflection->getProperty('baseUrl');

        // GeminiProvider has a specific default base URL
        $baseUrlValue = $baseUrl->getValue($provider);
        self::assertIsString($baseUrlValue);
        self::assertNotEmpty($baseUrlValue);
        self::assertStringContainsString('generativelanguage.googleapis.com', $baseUrlValue);
    }

    #[Test]
    public function configureUsesProvidedDefaultModel(): void
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $customModel = 'gemini-custom-model';
        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'timeout' => $timeout,
        ]);

        $reflection = new ReflectionClass($provider);
        $timeoutProp = $reflection->getProperty('timeout');

        self::assertEquals($timeout, $timeoutProp->getValue($provider));
    }

    /**
     * @return array<string, array{int}>
     */
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'maxRetries' => $maxRetries,
        ]);

        $reflection = new ReflectionClass($provider);
        $maxRetriesProp = $reflection->getProperty('maxRetries');

        self::assertEquals($maxRetries, $maxRetriesProp->getValue($provider));
    }

    /**
     * @return array<string, array{int}>
     */
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        // Configure with empty API key should NOT throw (lazy validation)
        $provider->configure([
            'apiKeyIdentifier' => '',
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
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        // Passing null should be cast to empty string (lazy validation)
        $provider->configure([
            'apiKeyIdentifier' => null,
        ]);

        // Provider should report as not available due to empty API key
        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function configureWithEmptyArrayAppliesAllDefaults(): void
    {
        // An entirely empty config must leave every field at its safe default:
        // empty api key (⇒ not available), timeout 30, maxRetries 3, the
        // provider's own default base URL and model. No exception is thrown
        // (validation is lazy, deferred to the first real request).
        $provider = $this->geminiProvider();

        $provider->configure([]);

        self::assertFalse($provider->isAvailable());
        self::assertSame(30, $this->intProperty($provider, 'timeout'));
        self::assertSame(3, $this->intProperty($provider, 'maxRetries'));

        $baseUrl = $this->stringProperty($provider, 'baseUrl');
        self::assertStringContainsString('generativelanguage.googleapis.com', $baseUrl);
        self::assertNotEmpty($provider->getDefaultModel());
    }

    #[Test]
    public function configureWithOnlyApiKeyLeavesEverythingElseAtDefaults(): void
    {
        // A partial config carrying just the api key identifier: the provider
        // becomes available, but the unspecified numeric fields keep their
        // defaults rather than collapsing to zero.
        $provider = $this->geminiProvider();

        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        self::assertTrue($provider->isAvailable());
        self::assertSame(30, $this->intProperty($provider, 'timeout'));
        self::assertSame(3, $this->intProperty($provider, 'maxRetries'));
        self::assertNotEmpty($provider->getDefaultModel());
    }

    #[Test]
    public function configureCoercesNumericStringTimeoutAndMaxRetriesToInt(): void
    {
        // A numeric string (e.g. from a YAML/ext-conf scalar) is coerced to int
        // by the SafeCast getter rather than discarded.
        $provider = $this->geminiProvider();

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'timeout' => '60',
            'maxRetries' => '5',
        ]);

        self::assertSame(60, $this->intProperty($provider, 'timeout'));
        self::assertSame(5, $this->intProperty($provider, 'maxRetries'));
    }

    #[Test]
    public function configureFallsBackToDefaultsForNonNumericStringNumerics(): void
    {
        // A non-numeric string cannot be coerced and must fall back to the
        // default (30 / 3) rather than casting to 0, which would disable the
        // timeout / retry behaviour.
        $provider = $this->geminiProvider();

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'timeout' => 'soon',
            'maxRetries' => 'lots',
        ]);

        self::assertSame(30, $this->intProperty($provider, 'timeout'));
        self::assertSame(3, $this->intProperty($provider, 'maxRetries'));
    }

    #[Test]
    public function configureFallsBackToDefaultBaseUrlForNonStringValue(): void
    {
        // A type-mismatched baseUrl (an array, not a string) must fall back to
        // the provider's default base URL — never an empty or malformed URL.
        $provider = $this->geminiProvider();

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'baseUrl' => ['not', 'a', 'string'],
        ]);

        $baseUrl = $this->stringProperty($provider, 'baseUrl');
        self::assertStringContainsString('generativelanguage.googleapis.com', $baseUrl);
    }

    #[Test]
    public function reconfiguringResetsTheConfiguredHttpClient(): void
    {
        // configure() resets the lazily-built HTTP client (so a re-config with a
        // new key/base URL does not keep dispatching through the old client).
        // Assert the reset directly on the private property: a client injected
        // after the first configure() is cleared by the second configure().
        $provider = $this->geminiProvider();
        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);
        $provider->setHttpClient($this->createHttpClientMock());

        self::assertNotNull($this->configuredHttpClient($provider), 'client present after setHttpClient');

        $provider->configure(['apiKeyIdentifier' => $this->randomApiKey()]);

        self::assertNull(
            $this->configuredHttpClient($provider),
            'reconfiguration must clear the previously configured HTTP client',
        );
    }

    private function geminiProvider(): GeminiProvider
    {
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        return $provider;
    }

    private function intProperty(GeminiProvider $provider, string $name): int
    {
        $value = (new ReflectionClass($provider))->getProperty($name)->getValue($provider);
        self::assertIsInt($value);

        return $value;
    }

    private function stringProperty(GeminiProvider $provider, string $name): string
    {
        $value = (new ReflectionClass($provider))->getProperty($name)->getValue($provider);
        self::assertIsString($value);

        return $value;
    }

    private function configuredHttpClient(GeminiProvider $provider): mixed
    {
        // configuredHttpClient is a private property declared on AbstractProvider;
        // a child-class ReflectionClass does not expose a parent's private prop,
        // so reflect on the declaring class.
        return (new ReflectionClass(AbstractProvider::class))
            ->getProperty('configuredHttpClient')
            ->getValue($provider);
    }
}
