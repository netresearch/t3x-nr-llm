<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

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
    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerIsNotAvailableWithoutApiKey(string $providerClass, string $providerName): void
    {
        $provider = new $providerClass(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        // Provider without configure() call should not have API key
        $this->assertFalse($provider->isAvailable());
    }

    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerIsAvailableWithApiKey(string $providerClass, string $providerName): void
    {
        $provider = new $providerClass(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => 'test-model',
            'timeout' => 30,
        ]);

        $this->assertTrue($provider->isAvailable());
    }

    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerReturnsCorrectName(string $providerClass, string $providerName): void
    {
        $provider = new $providerClass(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $this->assertEquals($providerName, $provider->getName());
    }

    #[Test]
    #[DataProvider('providerConfigProvider')]
    public function providerReturnsNonEmptyModelList(string $providerClass, string $providerName): void
    {
        $provider = new $providerClass(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );

        $provider->configure([
            'apiKey' => $this->randomApiKey(),
            'defaultModel' => 'test-model',
            'timeout' => 30,
        ]);

        $models = $provider->getAvailableModels();

        $this->assertIsArray($models);
        $this->assertNotEmpty($models);
    }

    public static function providerConfigProvider(): array
    {
        return [
            'Gemini' => [GeminiProvider::class, 'Google Gemini'],
            'Mistral' => [MistralProvider::class, 'Mistral AI'],
            'Groq' => [GroqProvider::class, 'Groq'],
        ];
    }
}
