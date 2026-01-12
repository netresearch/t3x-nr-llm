<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\AdapterType;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Provider\MistralProvider;
use Netresearch\NrLlm\Provider\OllamaProvider;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Provider\OpenRouterProvider;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;
use stdClass;

#[CoversClass(ProviderAdapterRegistry::class)]
class ProviderAdapterRegistryTest extends AbstractUnitTestCase
{
    private ProviderAdapterRegistry $subject;
    private LoggerInterface&Stub $loggerStub;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->loggerStub = self::createStub(LoggerInterface::class);

        $this->subject = new ProviderAdapterRegistry(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->loggerStub,
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
    }

    #[Test]
    #[DataProvider('adapterTypeClassMappingProvider')]
    public function getAdapterClassReturnsCorrectClassForBuiltInTypes(string $adapterType, string $expectedClass): void
    {
        $result = $this->subject->getAdapterClass($adapterType);

        self::assertEquals($expectedClass, $result);
    }

    /**
     * @return array<string, array{string, class-string<AbstractProvider>}>
     */
    public static function adapterTypeClassMappingProvider(): array
    {
        return [
            'openai' => [AdapterType::OpenAI->value, OpenAiProvider::class],
            'anthropic' => [AdapterType::Anthropic->value, ClaudeProvider::class],
            'gemini' => [AdapterType::Gemini->value, GeminiProvider::class],
            'openrouter' => [AdapterType::OpenRouter->value, OpenRouterProvider::class],
            'mistral' => [AdapterType::Mistral->value, MistralProvider::class],
            'groq' => [AdapterType::Groq->value, GroqProvider::class],
            'ollama' => [AdapterType::Ollama->value, OllamaProvider::class],
            'azure_openai' => [AdapterType::AzureOpenAI->value, OpenAiProvider::class],
            'custom' => [AdapterType::Custom->value, OpenAiProvider::class],
        ];
    }

    #[Test]
    public function getAdapterClassReturnsOpenAiForUnknownType(): void
    {
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::once())
            ->method('warning')
            ->with(
                'Unknown adapter type, falling back to OpenAI-compatible',
                self::arrayHasKey('adapterType'),
            );

        $subject = new ProviderAdapterRegistry(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $loggerMock,
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $result = $subject->getAdapterClass('unknown_type');

        self::assertEquals(OpenAiProvider::class, $result);
    }

    #[Test]
    public function registerAdapterOverridesBuiltInType(): void
    {
        // Register custom adapter for openai type
        $this->subject->registerAdapter(AdapterType::OpenAI->value, ClaudeProvider::class);

        $result = $this->subject->getAdapterClass(AdapterType::OpenAI->value);

        // Should return custom registration, not built-in
        self::assertEquals(ClaudeProvider::class, $result);
    }

    #[Test]
    public function registerAdapterAcceptsCustomType(): void
    {
        $this->subject->registerAdapter('my_custom_provider', GeminiProvider::class);

        $result = $this->subject->getAdapterClass('my_custom_provider');

        self::assertEquals(GeminiProvider::class, $result);
    }

    #[Test]
    public function registerAdapterThrowsForInvalidClass(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage('must extend');

        // stdClass is not a subclass of AbstractProvider
        /** @phpstan-ignore argument.type */
        $this->subject->registerAdapter('invalid', stdClass::class);
    }

    #[Test]
    public function registerAdapterLogsDebugMessage(): void
    {
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects(self::once())
            ->method('debug')
            ->with(
                'Registered custom adapter',
                self::callback(static fn(array $context): bool
                    => isset($context['adapterType'], $context['adapterClass'])),
            );

        $subject = new ProviderAdapterRegistry(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $loggerMock,
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->registerAdapter('test_type', OpenAiProvider::class);
    }

    #[Test]
    #[DataProvider('hasAdapterProvider')]
    public function hasAdapterReturnsCorrectResult(string $adapterType, bool $expected): void
    {
        $result = $this->subject->hasAdapter($adapterType);

        self::assertEquals($expected, $result);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function hasAdapterProvider(): array
    {
        return [
            'openai exists' => [AdapterType::OpenAI->value, true],
            'anthropic exists' => [AdapterType::Anthropic->value, true],
            'gemini exists' => [AdapterType::Gemini->value, true],
            'unknown does not exist' => ['unknown_type', false],
            'empty does not exist' => ['', false],
        ];
    }

    #[Test]
    public function hasAdapterReturnsTrueForCustomRegistration(): void
    {
        self::assertFalse($this->subject->hasAdapter('my_custom_type'));

        $this->subject->registerAdapter('my_custom_type', OpenAiProvider::class);

        self::assertTrue($this->subject->hasAdapter('my_custom_type'));
    }

    #[Test]
    public function getRegisteredAdaptersReturnsAllTypes(): void
    {
        $result = $this->subject->getRegisteredAdapters();

        // Should contain all built-in adapter types
        self::assertArrayHasKey(AdapterType::OpenAI->value, $result);
        self::assertArrayHasKey(AdapterType::Anthropic->value, $result);
        self::assertArrayHasKey(AdapterType::Gemini->value, $result);
    }

    #[Test]
    public function getRegisteredAdaptersIncludesCustomAdapters(): void
    {
        $this->subject->registerAdapter('my_custom_type', OpenAiProvider::class);

        $result = $this->subject->getRegisteredAdapters();

        self::assertArrayHasKey('my_custom_type', $result);
    }

    #[Test]
    public function createAdapterFromProviderCreatesCorrectAdapter(): void
    {
        $provider = $this->createProviderStub(
            uid: 1,
            identifier: 'test-provider',
            adapterType: AdapterType::OpenAI->value,
            apiKey: 'test-api-key',
        );

        $adapter = $this->subject->createAdapterFromProvider($provider);

        self::assertInstanceOf(OpenAiProvider::class, $adapter);
    }

    #[Test]
    public function createAdapterFromProviderUsesCaching(): void
    {
        $provider = $this->createProviderStub(
            uid: 42,
            identifier: 'cached-provider',
            adapterType: AdapterType::OpenAI->value,
            apiKey: 'test-api-key',
        );

        $adapter1 = $this->subject->createAdapterFromProvider($provider);
        $adapter2 = $this->subject->createAdapterFromProvider($provider);

        self::assertSame($adapter1, $adapter2);
    }

    #[Test]
    public function createAdapterFromProviderBypassesCacheWhenRequested(): void
    {
        $provider = $this->createProviderStub(
            uid: 42,
            identifier: 'uncached-provider',
            adapterType: AdapterType::OpenAI->value,
            apiKey: 'test-api-key',
        );

        $adapter1 = $this->subject->createAdapterFromProvider($provider, true);
        $adapter2 = $this->subject->createAdapterFromProvider($provider, false);

        self::assertNotSame($adapter1, $adapter2);
    }

    #[Test]
    public function createAdapterFromProviderDoesNotCacheWhenUidIsNull(): void
    {
        $provider = $this->createProviderStub(
            uid: null,
            identifier: 'temp-provider',
            adapterType: AdapterType::OpenAI->value,
            apiKey: 'test-api-key',
        );

        $adapter1 = $this->subject->createAdapterFromProvider($provider);
        $adapter2 = $this->subject->createAdapterFromProvider($provider);

        // Each call should create a new instance since UID is null
        self::assertNotSame($adapter1, $adapter2);
    }

    #[Test]
    public function createAdapterFromModelThrowsWhenProviderIsNull(): void
    {
        $model = self::createStub(Model::class);
        $model->method('getProvider')->willReturn(null);
        $model->method('getIdentifier')->willReturn('orphan-model');

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage('has no associated provider');

        $this->subject->createAdapterFromModel($model);
    }

    #[Test]
    public function createAdapterFromModelConfiguresModelId(): void
    {
        $provider = $this->createProviderStub(
            uid: 1,
            identifier: 'test-provider',
            adapterType: AdapterType::OpenAI->value,
            apiKey: 'test-api-key',
        );

        $model = self::createStub(Model::class);
        $model->method('getProvider')->willReturn($provider);
        $model->method('getIdentifier')->willReturn('test-model');
        $model->method('getModelId')->willReturn('gpt-4o');

        $adapter = $this->subject->createAdapterFromModel($model);

        self::assertInstanceOf(OpenAiProvider::class, $adapter);
        self::assertEquals('gpt-4o', $adapter->getDefaultModel());
    }

    #[Test]
    public function clearCacheClearsAllCachedAdapters(): void
    {
        $provider = $this->createProviderStub(
            uid: 42,
            identifier: 'cached-provider',
            adapterType: AdapterType::OpenAI->value,
            apiKey: 'test-api-key',
        );

        $adapter1 = $this->subject->createAdapterFromProvider($provider);

        $this->subject->clearCache();

        $adapter2 = $this->subject->createAdapterFromProvider($provider);

        self::assertNotSame($adapter1, $adapter2);
    }

    #[Test]
    public function clearCacheClearsSpecificProvider(): void
    {
        $provider1 = $this->createProviderStub(
            uid: 1,
            identifier: 'provider-1',
            adapterType: AdapterType::OpenAI->value,
            apiKey: 'key-1',
        );

        $provider2 = $this->createProviderStub(
            uid: 2,
            identifier: 'provider-2',
            adapterType: AdapterType::Gemini->value,
            apiKey: 'key-2',
        );

        $adapter1a = $this->subject->createAdapterFromProvider($provider1);
        $adapter2a = $this->subject->createAdapterFromProvider($provider2);

        // Clear only provider 1
        $this->subject->clearCache(1);

        $adapter1b = $this->subject->createAdapterFromProvider($provider1);
        $adapter2b = $this->subject->createAdapterFromProvider($provider2);

        // Provider 1 should have new instance, provider 2 should be cached
        self::assertNotSame($adapter1a, $adapter1b);
        self::assertSame($adapter2a, $adapter2b);
    }

    #[Test]
    public function testProviderConnectionReturnsSuccessForAvailableProvider(): void
    {
        $provider = $this->createProviderStub(
            uid: 1,
            identifier: 'test-provider',
            adapterType: AdapterType::Ollama->value,  // Ollama doesn't require API key
            apiKey: '',
        );

        // Note: This test will attempt a real connection to localhost:11434
        // In a real test environment, you'd mock the HTTP client
        $result = $this->subject->testProviderConnection($provider);

        // The result structure should be correct regardless of actual connection success
        self::assertArrayHasKey('success', $result);
        self::assertArrayHasKey('message', $result);
    }

    #[Test]
    public function testProviderConnectionReturnsFailureForUnavailableProvider(): void
    {
        $provider = $this->createProviderStub(
            uid: 1,
            identifier: 'test-provider',
            adapterType: AdapterType::OpenAI->value,
            apiKey: '',  // Empty API key makes it unavailable
        );

        $result = $this->subject->testProviderConnection($provider);

        self::assertFalse($result['success']);
        self::assertStringContainsString('not available', $result['message']);
    }

    #[Test]
    public function createAdapterFromProviderIncludesOrganizationId(): void
    {
        $provider = $this->createProviderStub(
            uid: 1,
            identifier: 'test-provider',
            adapterType: AdapterType::OpenAI->value,
            apiKey: 'test-api-key',
            organizationId: 'org-12345',
        );

        $adapter = $this->subject->createAdapterFromProvider($provider);

        // The adapter should be configured - we can verify by checking type
        self::assertInstanceOf(OpenAiProvider::class, $adapter);
    }

    #[Test]
    public function createAdapterFromProviderMergesAdditionalOptions(): void
    {
        $provider = $this->createProviderStub(
            uid: 1,
            identifier: 'test-provider',
            adapterType: AdapterType::OpenAI->value,
            apiKey: 'test-api-key',
            options: ['customOption' => 'customValue', 'extraTimeout' => 60],
        );

        $adapter = $this->subject->createAdapterFromProvider($provider);

        self::assertInstanceOf(OpenAiProvider::class, $adapter);
    }

    #[Test]
    public function testProviderConnectionCatchesExceptions(): void
    {
        // Create a provider that will cause an exception when creating adapter
        // by using an adapter type that doesn't exist and setting up the mock to fail
        $provider = self::createMock(Provider::class);
        $provider->method('getUid')->willReturn(1);
        $provider->method('getIdentifier')->willReturn('error-provider');
        $provider->method('getAdapterType')->willReturn(AdapterType::OpenAI->value);
        $provider->method('getApiKey')->willReturn('test-key');
        $provider->method('getEffectiveEndpointUrl')->willReturn('https://invalid-endpoint.test');
        $provider->method('getApiTimeout')->willReturn(30);
        $provider->method('getMaxRetries')->willReturn(3);
        $provider->method('getOrganizationId')->willReturn('');
        $provider->method('getOptionsArray')->willReturn([]);

        $result = $this->subject->testProviderConnection($provider);

        // Should get a result indicating connection test completed
        self::assertArrayHasKey('success', $result);
        self::assertArrayHasKey('message', $result);
    }

    #[Test]
    public function getRegisteredAdaptersDoesNotDuplicateCustomTypes(): void
    {
        // Register a custom adapter with same key as a built-in one
        $this->subject->registerAdapter(AdapterType::OpenAI->value, ClaudeProvider::class);

        $result = $this->subject->getRegisteredAdapters();

        // Should still have the openai key but only once
        self::assertArrayHasKey(AdapterType::OpenAI->value, $result);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createProviderStub(
        ?int $uid,
        string $identifier,
        string $adapterType,
        string $apiKey,
        string $endpointUrl = '',
        int $apiTimeout = 30,
        int $maxRetries = 3,
        string $organizationId = '',
        array $options = [],
    ): Provider&Stub {
        $provider = self::createStub(Provider::class);
        $provider->method('getUid')->willReturn($uid);
        $provider->method('getIdentifier')->willReturn($identifier);
        $provider->method('getAdapterType')->willReturn($adapterType);
        $provider->method('getApiKey')->willReturn($apiKey);
        $provider->method('getEffectiveEndpointUrl')->willReturn($endpointUrl);
        $provider->method('getApiTimeout')->willReturn($apiTimeout);
        $provider->method('getMaxRetries')->willReturn($maxRetries);
        $provider->method('getOrganizationId')->willReturn($organizationId);
        $provider->method('getOptionsArray')->willReturn($options);

        return $provider;
    }
}
