<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Exception;
use Generator;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(LlmServiceManager::class)]
class LlmServiceManagerTest extends AbstractUnitTestCase
{
    private LlmServiceManager $subject;
    private ExtensionConfiguration $extensionConfigStub;
    private LoggerInterface $loggerStub;
    private ProviderAdapterRegistry $adapterRegistryStub;
    private TestableProvider $provider;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $this->extensionConfigStub
            ->method('get')
            ->willReturn([
                'defaultProvider' => 'openai',
                'providers' => [],
            ]);

        $this->loggerStub = self::createStub(LoggerInterface::class);
        $this->adapterRegistryStub = self::createStub(ProviderAdapterRegistry::class);

        $this->subject = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $this->adapterRegistryStub,
        );

        // Create and register a testable provider
        $this->provider = new TestableProvider();
        $this->subject->registerProvider($this->provider);
    }

    #[Test]
    public function registerProviderAddsProviderToRegistry(): void
    {
        $providers = $this->subject->getProviderList();

        self::assertArrayHasKey('openai', $providers);
        self::assertEquals('OpenAI', $providers['openai']);
    }

    #[Test]
    public function getProviderReturnsRegisteredProvider(): void
    {
        $provider = $this->subject->getProvider('openai');

        self::assertSame($this->provider, $provider);
    }

    #[Test]
    public function getProviderUsesDefaultWhenNoneSpecified(): void
    {
        $provider = $this->subject->getProvider();

        self::assertSame($this->provider, $provider);
    }

    #[Test]
    public function getProviderThrowsWhenProviderNotFound(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Provider "nonexistent" not found');

        $this->subject->getProvider('nonexistent');
    }

    #[Test]
    public function getProviderThrowsWhenNoDefaultConfigured(): void
    {
        // Create manager without default provider
        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub
            ->method('get')
            ->willReturn(['providers' => []]);

        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No provider specified and no default provider configured');

        $manager->getProvider();
    }

    #[Test]
    public function chatDelegatesToProvider(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $this->provider->setNextResponse(new CompletionResponse(
            content: 'Hi there',
            model: 'gpt-4o',
            usage: new UsageStatistics(10, 5, 15),
            finishReason: 'stop',
            provider: 'openai',
        ));

        $result = $this->subject->chat($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('Hi there', $result->content);
    }

    #[Test]
    public function completeDelegatesToProvider(): void
    {
        $prompt = 'Hello, how are you?';
        $this->provider->setNextResponse(new CompletionResponse(
            content: 'I am fine, thank you!',
            model: 'gpt-4o',
            usage: new UsageStatistics(10, 5, 15),
            finishReason: 'stop',
            provider: 'openai',
        ));

        $result = $this->subject->complete($prompt);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('I am fine, thank you!', $result->content);
    }

    #[Test]
    public function embedDelegatesToProvider(): void
    {
        $text = 'Sample text for embedding';
        $embeddings = [array_fill(0, 1536, 0.1)];

        $this->provider->setNextEmbeddingResponse(new EmbeddingResponse(
            embeddings: $embeddings,
            model: 'text-embedding-3-small',
            usage: new UsageStatistics(10, 0, 10),
            provider: 'openai',
        ));

        $result = $this->subject->embed($text);

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(1536, $result->embeddings[0]);
    }

    #[Test]
    public function getAvailableProvidersReturnsOnlyAvailable(): void
    {
        // Add an unavailable provider
        $unavailableProvider = new TestableProvider('claude', 'Claude', false);
        $this->subject->registerProvider($unavailableProvider);

        $result = $this->subject->getAvailableProviders();

        self::assertArrayHasKey('openai', $result);
        self::assertArrayNotHasKey('claude', $result);
    }

    #[Test]
    public function getProviderListReturnsAllProviders(): void
    {
        $claudeProvider = new TestableProvider('claude', 'Claude', false);
        $this->subject->registerProvider($claudeProvider);

        $result = $this->subject->getProviderList();

        self::assertCount(2, $result);
        self::assertEquals('OpenAI', $result['openai']);
        self::assertEquals('Claude', $result['claude']);
    }

    #[Test]
    public function setDefaultProviderChangesDefault(): void
    {
        $claudeProvider = new TestableProvider('claude', 'Claude', true);
        $this->subject->registerProvider($claudeProvider);
        $this->subject->setDefaultProvider('claude');

        self::assertEquals('claude', $this->subject->getDefaultProvider());
    }

    #[Test]
    public function setDefaultProviderThrowsForNonexistent(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Cannot set default: Provider "nonexistent" not found');

        $this->subject->setDefaultProvider('nonexistent');
    }

    #[Test]
    public function supportsFeatureReturnsTrueWhenSupported(): void
    {
        self::assertTrue($this->subject->supportsFeature('chat', 'openai'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseWhenNotSupported(): void
    {
        self::assertFalse($this->subject->supportsFeature('unknown_feature', 'openai'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForNonexistentProvider(): void
    {
        self::assertFalse($this->subject->supportsFeature('chat', 'nonexistent'));
    }

    #[Test]
    public function chatPassesOptionsToProvider(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = new ChatOptions(
            temperature: 0.7,
            maxTokens: 1000,
            model: 'gpt-4-turbo',
        );

        $this->provider->setNextResponse(new CompletionResponse(
            content: 'Response',
            model: 'gpt-4-turbo',
            usage: new UsageStatistics(10, 5, 15),
            finishReason: 'stop',
            provider: 'openai',
        ));

        $this->subject->chat($messages, $options);

        $passedOptions = $this->provider->getLastOptions();
        self::assertEquals(0.7, $passedOptions['temperature']);
        self::assertEquals(1000, $passedOptions['max_tokens']);
        self::assertEquals('gpt-4-turbo', $passedOptions['model']);
    }

    #[Test]
    public function getProviderConfigurationReturnsConfig(): void
    {
        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub
            ->method('get')
            ->willReturn([
                'defaultProvider' => 'openai',
                'providers' => [
                    'openai' => [
                        'apiKeyIdentifier' => 'sk-test',
                        'defaultModel' => 'gpt-4o',
                    ],
                ],
            ]);

        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub);
        $config = $manager->getProviderConfiguration('openai');

        self::assertArrayHasKey('apiKeyIdentifier', $config);
        self::assertArrayHasKey('defaultModel', $config);
    }

    #[Test]
    public function getProviderConfigurationReturnsEmptyForUnknown(): void
    {
        $config = $this->subject->getProviderConfiguration('nonexistent');

        self::assertEmpty($config);
    }

    #[Test]
    public function configureProviderUpdatesConfig(): void
    {
        $newConfig = ['apiKeyIdentifier' => 'new-key', 'defaultModel' => 'new-model'];

        $this->subject->configureProvider('openai', $newConfig);

        self::assertEquals($newConfig, $this->provider->getLastConfiguration());
    }

    #[Test]
    public function configureProviderThrowsForNonexistent(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Provider "nonexistent" not found');

        $this->subject->configureProvider('nonexistent', ['key' => 'value']);
    }

    #[Test]
    public function hasAvailableProviderReturnsTrueWhenAvailable(): void
    {
        self::assertTrue($this->subject->hasAvailableProvider());
    }

    #[Test]
    public function hasAvailableProviderReturnsFalseWhenNoneAvailable(): void
    {
        // Create manager with only unavailable providers
        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub
            ->method('get')
            ->willReturn(['providers' => []]);

        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub);

        // Register only an unavailable provider
        $unavailableProvider = new TestableProvider('test', 'Test', false);
        $manager->registerProvider($unavailableProvider);

        self::assertFalse($manager->hasAvailableProvider());
    }

    #[Test]
    public function visionDelegatesToProvider(): void
    {
        // Create vision-capable provider
        $visionProvider = new TestableVisionProvider();
        $visionProvider->setNextVisionResponse(new VisionResponse(
            description: 'Image shows a cat',
            model: 'gpt-5.2',
            usage: new UsageStatistics(100, 20, 120),
            provider: 'openai',
        ));
        $this->subject->registerProvider($visionProvider);
        $this->subject->setDefaultProvider('openai-vision');

        $content = [
            ['type' => 'text', 'text' => 'Describe this image'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.jpg']],
        ];

        $result = $this->subject->vision($content);

        self::assertInstanceOf(VisionResponse::class, $result);
        self::assertEquals('Image shows a cat', $result->description);
    }

    #[Test]
    public function visionThrowsWhenProviderDoesNotSupportVision(): void
    {
        // Use the default provider which doesn't implement VisionCapableInterface
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support vision');

        $this->subject->vision([['type' => 'text', 'text' => 'test']]);
    }

    #[Test]
    public function streamChatDelegatesToProvider(): void
    {
        // Create streaming-capable provider
        $streamProvider = new TestableStreamingProvider();
        $this->subject->registerProvider($streamProvider);
        $this->subject->setDefaultProvider('openai-stream');

        $messages = [['role' => 'user', 'content' => 'Hello']];
        $chunks = [];
        foreach ($this->subject->streamChat($messages) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Hello', ' World'], $chunks);
    }

    #[Test]
    public function streamChatThrowsWhenProviderDoesNotSupportStreaming(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support streaming');

        foreach ($this->subject->streamChat([['role' => 'user', 'content' => 'test']]) as $chunk) {
            // Should not reach here
        }
    }

    #[Test]
    public function chatWithToolsDelegatesToProvider(): void
    {
        // Create tool-capable provider
        $toolProvider = new TestableToolProvider();
        $toolProvider->setNextResponse(new CompletionResponse(
            content: '',
            model: 'gpt-4o',
            usage: new UsageStatistics(20, 10, 30),
            finishReason: 'tool_calls',
            provider: 'openai',
            toolCalls: [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => []]],
            ],
        ));
        $this->subject->registerProvider($toolProvider);
        $this->subject->setDefaultProvider('openai-tools');

        $messages = [['role' => 'user', 'content' => 'What is the weather?']];
        $tools = [
            ['type' => 'function', 'function' => ['name' => 'get_weather', 'description' => 'Get weather', 'parameters' => []]],
        ];

        $result = $this->subject->chatWithTools($messages, $tools);

        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
    }

    #[Test]
    public function chatWithToolsThrowsWhenProviderDoesNotSupportTools(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support tool calling');

        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [['type' => 'function', 'function' => ['name' => 'test', 'description' => 'test', 'parameters' => []]]];

        $this->subject->chatWithTools($messages, $tools);
    }

    #[Test]
    public function getAdapterRegistryReturnsRegistry(): void
    {
        $registry = $this->subject->getAdapterRegistry();

        self::assertSame($this->adapterRegistryStub, $registry);
    }

    #[Test]
    public function loadConfigurationHandlesException(): void
    {
        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub
            ->method('get')
            ->willThrowException(new Exception('Config not found'));

        // Should not throw, but log warning
        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub);

        // Manager should work without configuration
        self::assertNull($manager->getDefaultProvider());
    }

    #[Test]
    public function embedThrowsWhenProviderDoesNotSupportEmbeddings(): void
    {
        // Create provider that doesn't support embeddings
        $noEmbeddingsProvider = new TestableProvider('no-embed', 'No Embed', true);
        $this->subject->registerProvider($noEmbeddingsProvider);
        $this->subject->setDefaultProvider('no-embed');

        // Override supportsFeature to return false for embeddings
        // Actually the TestableProvider already supports embeddings, so let's use a different approach
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support embeddings');

        $limitedProvider = new TestableNoEmbeddingsProvider();
        $this->subject->registerProvider($limitedProvider);
        $this->subject->setDefaultProvider('limited');

        $this->subject->embed('test');
    }

    #[Test]
    public function registerProviderConfiguresFromExtensionConfiguration(): void
    {
        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub
            ->method('get')
            ->willReturn([
                'defaultProvider' => 'test',
                'providers' => [
                    'configurable' => [
                        'apiKey' => 'test-key',
                        'model' => 'test-model',
                    ],
                ],
            ]);

        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub);

        $configurableProvider = new TestableProvider('configurable', 'Configurable', true);
        $manager->registerProvider($configurableProvider);

        // Provider should have been configured with the extension config
        $config = $configurableProvider->getLastConfiguration();
        self::assertEquals('test-key', $config['apiKey']);
        self::assertEquals('test-model', $config['model']);
    }

    #[Test]
    public function getAdapterFromModelDelegatesToRegistry(): void
    {
        $model = self::createStub(Model::class);
        $mockAdapter = self::createStub(ProviderInterface::class);

        $registryMock = $this->createMock(ProviderAdapterRegistry::class);
        $registryMock->expects(self::once())
            ->method('createAdapterFromModel')
            ->with($model)
            ->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock);

        $result = $manager->getAdapterFromModel($model);

        self::assertSame($mockAdapter, $result);
    }

    #[Test]
    public function getAdapterFromConfigurationDelegatesToRegistry(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('test-config');

        $mockAdapter = self::createStub(ProviderInterface::class);

        $registryMock = $this->createMock(ProviderAdapterRegistry::class);
        $registryMock->expects(self::once())
            ->method('createAdapterFromModel')
            ->with($model)
            ->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock);

        $result = $manager->getAdapterFromConfiguration($config);

        self::assertSame($mockAdapter, $result);
    }

    #[Test]
    public function getAdapterFromConfigurationThrowsWhenNoModel(): void
    {
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn(null);
        $config->method('getIdentifier')->willReturn('orphan-config');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('has no model assigned');

        $this->subject->getAdapterFromConfiguration($config);
    }

    #[Test]
    public function chatWithConfigurationUsesAdapter(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('test-config');
        $config->method('toOptionsArray')->willReturn(['temperature' => 0.7, 'provider' => 'test']);

        $expectedResponse = new CompletionResponse(
            content: 'Response from config',
            model: 'gpt-4o',
            usage: new UsageStatistics(10, 5, 15),
            finishReason: 'stop',
            provider: 'test',
        );

        $mockAdapter = $this->createMock(ProviderInterface::class);
        $mockAdapter->expects(self::once())
            ->method('chatCompletion')
            ->with(
                [['role' => 'user', 'content' => 'Hello']],
                ['temperature' => 0.7],  // provider key should be removed
            )
            ->willReturn($expectedResponse);

        $registryMock = self::createStub(ProviderAdapterRegistry::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock);

        $result = $manager->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Hello']],
            $config,
        );

        self::assertSame($expectedResponse, $result);
    }

    #[Test]
    public function completeWithConfigurationUsesAdapter(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('test-config');
        $config->method('toOptionsArray')->willReturn(['temperature' => 0.5]);

        $expectedResponse = new CompletionResponse(
            content: 'Completed text',
            model: 'gpt-4o',
            usage: new UsageStatistics(10, 5, 15),
            finishReason: 'stop',
            provider: 'test',
        );

        $mockAdapter = $this->createMock(ProviderInterface::class);
        $mockAdapter->expects(self::once())
            ->method('complete')
            ->with('Test prompt', ['temperature' => 0.5])
            ->willReturn($expectedResponse);

        $registryMock = self::createStub(ProviderAdapterRegistry::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock);

        $result = $manager->completeWithConfiguration('Test prompt', $config);

        self::assertSame($expectedResponse, $result);
    }

    #[Test]
    public function streamChatWithConfigurationUsesAdapter(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('test-config');
        $config->method('toOptionsArray')->willReturn(['temperature' => 0.5]);

        // Create a streaming-capable mock adapter
        $mockAdapter = new class implements ProviderInterface, StreamingCapableInterface {
            public function getIdentifier(): string
            {
                return 'mock';
            }
            public function getName(): string
            {
                return 'Mock';
            }
            public function isAvailable(): bool
            {
                return true;
            }
            public function supportsFeature(string|ModelCapability $feature): bool
            {
                return true;
            }
            public function configure(array $config): void {}
            public function chatCompletion(array $messages, array $options = []): CompletionResponse
            {
                throw new RuntimeException('Not implemented', 3106251534);
            }
            public function complete(string $prompt, array $options = []): CompletionResponse
            {
                throw new RuntimeException('Not implemented', 7104913232);
            }
            public function embeddings(string|array $input, array $options = []): EmbeddingResponse
            {
                throw new RuntimeException('Not implemented', 5854205295);
            }
            public function getAvailableModels(): array
            {
                return [];
            }
            public function getDefaultModel(): string
            {
                return 'test';
            }
            public function testConnection(): array
            {
                return ['success' => true, 'message' => 'OK'];
            }

            public function streamChatCompletion(array $messages, array $options = []): Generator
            {
                yield 'Streaming';
                yield ' response';
            }

            public function supportsStreaming(): bool
            {
                return true;
            }
        };

        $registryMock = self::createStub(ProviderAdapterRegistry::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock);

        $chunks = [];
        foreach ($manager->streamChatWithConfiguration([['role' => 'user', 'content' => 'Hello']], $config) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Streaming', ' response'], $chunks);
    }

    #[Test]
    public function streamChatWithConfigurationThrowsWhenNotSupported(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('test-config');
        $config->method('toOptionsArray')->willReturn([]);

        // Create a non-streaming mock adapter
        $mockAdapter = self::createStub(ProviderInterface::class);
        $mockAdapter->method('getIdentifier')->willReturn('non-streaming');

        $registryMock = self::createStub(ProviderAdapterRegistry::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock);

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support streaming');

        foreach ($manager->streamChatWithConfiguration([['role' => 'user', 'content' => 'Hello']], $config) as $chunk) {
            // Should throw before yielding
        }
    }
}

/**
 * Testable provider implementation for unit testing.
 */
class TestableProvider extends AbstractProvider
{
    private ?CompletionResponse $nextResponse = null;
    private ?EmbeddingResponse $nextEmbeddingResponse = null;
    /** @var array<string, mixed> */
    private array $lastOptions = [];
    /** @var array<string, mixed> */
    private array $lastConfiguration = [];

    public function __construct(
        private readonly string $id = 'openai',
        private readonly string $providerName = 'OpenAI',
        private readonly bool $available = true,
    ) {
        // Skip parent constructor as it requires dependencies
    }

    public function getName(): string
    {
        return $this->providerName;
    }

    public function getIdentifier(): string
    {
        return $this->id;
    }

    #[Override]
    public function isAvailable(): bool
    {
        return $this->available;
    }

    #[Override]
    public function supportsFeature(string|ModelCapability $feature): bool
    {
        $featureValue = $feature instanceof ModelCapability ? $feature->value : $feature;
        return in_array($featureValue, ['chat', 'embeddings', 'vision'], true);
    }

    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        $this->lastOptions = $options;
        return $this->nextResponse ?? new CompletionResponse(
            content: 'Default response',
            model: 'gpt-4o',
            usage: new UsageStatistics(0, 0, 0),
            finishReason: 'stop',
            provider: $this->id,
        );
    }

    #[Override]
    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        return $this->chatCompletion([['role' => 'user', 'content' => $prompt]], $options);
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        $this->lastOptions = $options;
        return $this->nextEmbeddingResponse ?? new EmbeddingResponse(
            embeddings: [array_fill(0, 1536, 0.0)],
            model: 'text-embedding-3-small',
            usage: new UsageStatistics(0, 0, 0),
            provider: $this->id,
        );
    }

    public function getAvailableModels(): array
    {
        return ['gpt-4o' => 'GPT-4o', 'gpt-4o-mini' => 'GPT-4o Mini'];
    }

    #[Override]
    public function getDefaultModel(): string
    {
        return 'gpt-4o';
    }

    #[Override]
    public function configure(array $config): void
    {
        $this->lastConfiguration = $config;
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function setNextResponse(CompletionResponse $response): void
    {
        $this->nextResponse = $response;
    }

    public function setNextEmbeddingResponse(EmbeddingResponse $response): void
    {
        $this->nextEmbeddingResponse = $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastOptions(): array
    {
        return $this->lastOptions;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastConfiguration(): array
    {
        return $this->lastConfiguration;
    }
}

/**
 * Testable provider that supports vision capabilities.
 */
class TestableVisionProvider extends TestableProvider implements VisionCapableInterface
{
    private ?VisionResponse $nextVisionResponse = null;

    public function __construct()
    {
        parent::__construct('openai-vision', 'OpenAI Vision', true);
    }

    public function setNextVisionResponse(VisionResponse $response): void
    {
        $this->nextVisionResponse = $response;
    }

    public function analyzeImage(array $content, array $options = []): VisionResponse
    {
        return $this->nextVisionResponse ?? new VisionResponse(
            description: 'Default description',
            model: 'gpt-4o',
            usage: new UsageStatistics(0, 0, 0),
            provider: $this->getIdentifier(),
        );
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function getSupportedImageFormats(): array
    {
        return ['jpeg', 'png', 'gif', 'webp'];
    }

    public function getMaxImageSize(): int
    {
        return 20 * 1024 * 1024;
    }
}

/**
 * Testable provider that supports streaming capabilities.
 */
class TestableStreamingProvider extends TestableProvider implements StreamingCapableInterface
{
    public function __construct()
    {
        parent::__construct('openai-stream', 'OpenAI Stream', true);
    }

    public function streamChatCompletion(array $messages, array $options = []): Generator
    {
        yield 'Hello';
        yield ' World';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }
}

/**
 * Testable provider that supports tool calling capabilities.
 */
class TestableToolProvider extends TestableProvider implements ToolCapableInterface
{
    public function __construct()
    {
        parent::__construct('openai-tools', 'OpenAI Tools', true);
    }

    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        // Return response with tool calls from parent
        $response = $this->chatCompletion($messages, $options);
        return $response;
    }

    public function supportsTools(): bool
    {
        return true;
    }
}

/**
 * Testable provider that does not support embeddings.
 */
class TestableNoEmbeddingsProvider extends TestableProvider
{
    public function __construct()
    {
        parent::__construct('limited', 'Limited Provider', true);
    }

    #[Override]
    public function supportsFeature(string|ModelCapability $feature): bool
    {
        $featureValue = $feature instanceof ModelCapability ? $feature->value : $feature;
        // Does NOT support embeddings
        return in_array($featureValue, ['chat'], true);
    }

    #[Override]
    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        throw new UnsupportedFeatureException('Provider "limited" does not support embeddings', 4932152837);
    }
}
