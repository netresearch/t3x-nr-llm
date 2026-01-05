<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Provider\MistralProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests common provider behavior across all implementations.
 */
class AbstractProviderTest extends AbstractUnitTestCase
{
    /**
     * @param class-string<AbstractProvider> $providerClass
     */
    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerIsNotAvailableWithoutApiKey(string $providerClass, string $providerName): void
    {
        /** @var AbstractProvider $provider */
        $provider = new $providerClass(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        // Provider without configure() call should not have API key
        self::assertFalse($provider->isAvailable());
    }

    /**
     * @param class-string<AbstractProvider> $providerClass
     */
    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerIsAvailableWithApiKey(string $providerClass, string $providerName): void
    {
        /** @var AbstractProvider $provider */
        $provider = new $providerClass(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'test-model',
            'timeout' => 30,
        ]);

        self::assertTrue($provider->isAvailable());
    }

    /**
     * @param class-string<AbstractProvider> $providerClass
     */
    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerReturnsCorrectName(string $providerClass, string $providerName): void
    {
        /** @var AbstractProvider $provider */
        $provider = new $providerClass(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        self::assertEquals($providerName, $provider->getName());
    }

    /**
     * @param class-string<AbstractProvider> $providerClass
     */
    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerReturnsNonEmptyModelList(string $providerClass, string $providerName): void
    {
        /** @var AbstractProvider $provider */
        $provider = new $providerClass(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->createHttpClientMock());

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'test-model',
            'timeout' => 30,
        ]);

        $models = $provider->getAvailableModels();

        // Type is already guaranteed by interface, just check not empty
        self::assertNotEmpty($models);
    }

    /**
     * @return array<string, array{class-string<AbstractProvider>, string}>
     */
    public static function providerConfigProvider(): array
    {
        return [
            'Gemini' => [GeminiProvider::class, 'Google Gemini'],
            'Mistral' => [MistralProvider::class, 'Mistral AI'],
            'Groq' => [GroqProvider::class, 'Groq'],
        ];
    }
}
