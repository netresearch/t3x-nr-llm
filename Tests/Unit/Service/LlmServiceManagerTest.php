<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Exception;
use Generator;
use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(LlmServiceManager::class)]
class LlmServiceManagerTest extends AbstractUnitTestCase
{
    private LlmServiceManager $subject;
    private ExtensionConfiguration $extensionConfigStub;
    private LoggerInterface $loggerStub;
    private ProviderAdapterRegistryInterface $adapterRegistryStub;
    private TestableProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $this->extensionConfigStub
            ->method('get')
            ->willReturn([
                'providers' => [],
            ]);

        $this->loggerStub = self::createStub(LoggerInterface::class);
        $this->adapterRegistryStub = self::createStub(ProviderAdapterRegistryInterface::class);

        $this->subject = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $this->adapterRegistryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
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

        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

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

        $result = $this->subject->chat($messages, new ChatOptions(provider: 'openai'));

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

        $result = $this->subject->complete($prompt, new ChatOptions(provider: 'openai'));

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

        $result = $this->subject->embed($text, new EmbeddingOptions(provider: 'openai'));

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
            provider: 'openai',
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
                'providers' => [
                    'openai' => [
                        'apiKeyIdentifier' => 'sk-test',
                        'defaultModel' => 'gpt-4o',
                    ],
                ],
            ]);

        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));
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

        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

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

        $content = [
            ['type' => 'text', 'text' => 'Describe this image'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.jpg']],
        ];

        $result = $this->subject->vision($content, new VisionOptions(provider: 'openai-vision'));

        self::assertInstanceOf(VisionResponse::class, $result);
        self::assertEquals('Image shows a cat', $result->description);
    }

    #[Test]
    public function visionThrowsWhenProviderDoesNotSupportVision(): void
    {
        // Pin the registered provider (openai), which doesn't implement VisionCapableInterface
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support vision');

        $this->subject->vision([['type' => 'text', 'text' => 'test']], new VisionOptions(provider: 'openai'));
    }

    #[Test]
    public function streamChatDelegatesToProvider(): void
    {
        // Create streaming-capable provider
        $streamProvider = new TestableStreamingProvider();
        $this->subject->registerProvider($streamProvider);

        $messages = [['role' => 'user', 'content' => 'Hello']];
        $chunks = [];
        foreach ($this->subject->streamChat($messages, new ChatOptions(provider: 'openai-stream')) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Hello', ' World'], $chunks);
    }

    #[Test]
    public function streamChatThrowsWhenProviderDoesNotSupportStreaming(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support streaming');

        iterator_to_array($this->subject->streamChat([['role' => 'user', 'content' => 'test']], new ChatOptions(provider: 'openai')));
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
                ToolCall::function('call_1', 'get_weather', []),
            ],
        ));
        $this->subject->registerProvider($toolProvider);

        $messages = [['role' => 'user', 'content' => 'What is the weather?']];
        $tools = [
            ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'get_weather', 'description' => 'Get weather', 'parameters' => []]]),
        ];

        $result = $this->subject->chatWithTools($messages, $tools, new ToolOptions(provider: 'openai-tools'));

        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
    }

    #[Test]
    public function chatWithToolsAcceptsLegacyArrayShapedToolFixtures(): void
    {
        // Back-compat path: callers passing the pre-#158 array fixture
        // shape are normalised via ToolSpec::fromArray() inside
        // chatWithTools(). The provider sees only ToolSpec instances.
        $toolProvider = new TestableToolProvider();
        $toolProvider->setNextResponse(new CompletionResponse(
            content: '',
            model: 'gpt-4o',
            usage: new UsageStatistics(5, 5, 10),
            finishReason: 'tool_calls',
            provider: 'openai',
            toolCalls: [ToolCall::function('call_1', 'echo', [])],
        ));
        $this->subject->registerProvider($toolProvider);

        $messages    = [['role' => 'user', 'content' => 'echo back']];
        $legacyTools = [
            ['type' => 'function', 'function' => ['name' => 'echo', 'description' => 'echoes input', 'parameters' => []]],
        ];

        $result = $this->subject->chatWithTools($messages, $legacyTools, new ToolOptions(provider: 'openai-tools'));

        self::assertNotNull($result->toolCalls);
        self::assertCount(1, $result->toolCalls);
        self::assertSame('echo', $result->toolCalls[0]->name);

        // The provider must have received typed ToolSpec instances — otherwise
        // LlmServiceManager forwarded the legacy array shape unchanged and
        // the normalisation contract is broken.
        self::assertCount(1, $toolProvider->capturedTools);
        self::assertInstanceOf(ToolSpec::class, $toolProvider->capturedTools[0]);
        self::assertSame('echo', $toolProvider->capturedTools[0]->name);
        self::assertSame('echoes input', $toolProvider->capturedTools[0]->description);
    }

    #[Test]
    public function chatWithToolsThrowsWhenProviderDoesNotSupportTools(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support tool calling');

        $messages = [['role' => 'user', 'content' => 'test']];
        $tools = [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'test', 'description' => 'test', 'parameters' => []]])];

        $this->subject->chatWithTools($messages, $tools, new ToolOptions(provider: 'openai'));
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
        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        // Manager should construct and operate without configuration: no
        // providers registered yet, so the provider list is empty.
        self::assertSame([], $manager->getProviderList());
    }

    #[Test]
    public function embedThrowsWhenProviderDoesNotSupportEmbeddings(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support embeddings');

        $limitedProvider = new TestableNoEmbeddingsProvider();
        $this->subject->registerProvider($limitedProvider);

        $this->subject->embed('test', new EmbeddingOptions(provider: 'limited'));
    }

    #[Test]
    public function registerProviderConfiguresFromExtensionConfiguration(): void
    {
        $extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $extensionConfigStub
            ->method('get')
            ->willReturn([
                'providers' => [
                    'configurable' => [
                        'apiKey' => 'test-key',
                        'model' => 'test-model',
                    ],
                ],
            ]);

        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

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

        $registryMock = $this->createMock(ProviderAdapterRegistryInterface::class);
        $registryMock->expects(self::once())
            ->method('createAdapterFromModel')
            ->with($model)
            ->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

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

        $registryMock = $this->createMock(ProviderAdapterRegistryInterface::class);
        $registryMock->expects(self::once())
            ->method('createAdapterFromModel')
            ->with($model)
            ->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

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
    public function getAdapterFromConfigurationResolvesCriteriaModeViaModelSelectionService(): void
    {
        // Criteria-mode configuration: no direct model relation. The concrete model
        // is picked from the criteria by ModelSelectionService. The configuration
        // entity must NOT be mutated (Extbase would persist the change).
        $resolvedModel = self::createStub(Model::class);
        $config = $this->createMock(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn(null);
        $config->method('getIdentifier')->willReturn('nr_ai_search.embeddings');
        $config->expects(self::never())->method('setLlmModel');

        $selection = $this->createMock(ModelSelectionServiceInterface::class);
        $selection->expects(self::once())
            ->method('resolveModel')
            ->with($config)
            ->willReturn($resolvedModel);

        $mockAdapter = self::createStub(ProviderInterface::class);
        $registryMock = $this->createMock(ProviderAdapterRegistryInterface::class);
        $registryMock->expects(self::once())
            ->method('createAdapterFromModel')
            ->with($resolvedModel)
            ->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class), null, null, $selection);

        self::assertSame($mockAdapter, $manager->getAdapterFromConfiguration($config));
    }

    #[Test]
    public function getAdapterFromConfigurationThrowsWhenCriteriaResolvesToNoModel(): void
    {
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn(null);
        $config->method('getIdentifier')->willReturn('nr_ai_search.embeddings');

        $selection = self::createStub(ModelSelectionServiceInterface::class);
        $selection->method('resolveModel')->willReturn(null);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class), null, null, $selection);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('has no model assigned');

        $manager->getAdapterFromConfiguration($config);
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
                [ChatMessage::fromArray(['role' => 'user', 'content' => 'Hello'])],
                ['temperature' => 0.7],  // provider key should be removed
            )
            ->willReturn($expectedResponse);

        $registryMock = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        $result = $manager->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Hello']],
            $config,
        );

        self::assertSame($expectedResponse, $result);
    }

    /**
     * A configuration's stored system prompt must reach the adapter as a
     * leading system message — the adapters read the system instruction from
     * the message list, not from options['system_prompt'].
     */
    #[Test]
    public function chatWithConfigurationInjectsConfiguredSystemPrompt(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('test-config');
        $config->method('toOptionsArray')->willReturn(['temperature' => 0.7, 'system_prompt' => 'You are helpful']);

        $expectedResponse = new CompletionResponse(
            content: 'ok',
            model: 'gpt-4o',
            usage: new UsageStatistics(10, 5, 15),
            finishReason: 'stop',
            provider: 'test',
        );

        $mockAdapter = $this->createMock(ProviderInterface::class);
        $mockAdapter->expects(self::once())
            ->method('chatCompletion')
            ->with(
                [
                    ChatMessage::system('You are helpful'),
                    ChatMessage::fromArray(['role' => 'user', 'content' => 'Hello']),
                ],
                ['temperature' => 0.7, 'system_prompt' => 'You are helpful'],
            )
            ->willReturn($expectedResponse);

        $registryMock = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        $manager->chatWithConfiguration([['role' => 'user', 'content' => 'Hello']], $config);
    }

    /**
     * An explicit system message supplied by the caller wins over the
     * configuration's stored system prompt (per-call precedence).
     */
    #[Test]
    public function chatWithConfigurationKeepsExplicitSystemMessageOverConfigPrompt(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('test-config');
        $config->method('toOptionsArray')->willReturn(['system_prompt' => 'Config prompt']);

        $expectedResponse = new CompletionResponse(
            content: 'ok',
            model: 'gpt-4o',
            usage: new UsageStatistics(10, 5, 15),
            finishReason: 'stop',
            provider: 'test',
        );

        $mockAdapter = $this->createMock(ProviderInterface::class);
        $mockAdapter->expects(self::once())
            ->method('chatCompletion')
            ->with(
                [
                    ChatMessage::fromArray(['role' => 'system', 'content' => 'Caller prompt']),
                    ChatMessage::fromArray(['role' => 'user', 'content' => 'Hello']),
                ],
                ['system_prompt' => 'Config prompt'],
            )
            ->willReturn($expectedResponse);

        $registryMock = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        $manager->chatWithConfiguration(
            [
                ['role' => 'system', 'content' => 'Caller prompt'],
                ['role' => 'user', 'content' => 'Hello'],
            ],
            $config,
        );
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

        $registryMock = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        $result = $manager->completeWithConfiguration('Test prompt', $config);

        self::assertSame($expectedResponse, $result);
    }

    #[Test]
    public function embedForConfigurationUsesAdapterWithConfigurationModel(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('embed-config');
        $config->method('getModelId')->willReturn('text-embedding-3-small');
        $config->method('toOptionsArray')->willReturn(['model' => 'text-embedding-3-small', 'provider' => 'openai']);

        $providerResponse = new EmbeddingResponse(
            embeddings: [[0.1, 0.2, 0.3]],
            model: 'text-embedding-3-small',
            usage: new UsageStatistics(5, 0, 5),
            provider: 'openai',
        );

        $capturedOptions = [];
        $mockAdapter = $this->createMock(ProviderInterface::class);
        $mockAdapter->method('supportsFeature')->willReturnCallback(
            static fn(string $feature): bool => $feature === 'embeddings',
        );
        $mockAdapter->expects(self::once())
            ->method('embeddings')
            ->willReturnCallback(
                function (string|array $input, array $options) use (&$capturedOptions, $providerResponse): EmbeddingResponse {
                    self::assertSame('Some text', $input);
                    $capturedOptions = $options;
                    return $providerResponse;
                },
            );

        $registryMock = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        $result = $manager->embedForConfiguration('Some text', $config);

        // Typed response is reconstructed from the array-shaped pipeline
        // payload (cache round-trip contract) — compare values, not identity.
        self::assertSame([[0.1, 0.2, 0.3]], $result->embeddings);
        self::assertSame('text-embedding-3-small', $result->model);
        // Configuration's model drives the call when the options carry none;
        // the provider key is stripped before dispatch.
        self::assertSame('text-embedding-3-small', $capturedOptions['model']);
        self::assertArrayNotHasKey('provider', $capturedOptions);
    }

    #[Test]
    public function embedForConfigurationHonoursOptionsModelOverride(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('embed-config');
        $config->method('getModelId')->willReturn('config-model');
        $config->method('toOptionsArray')->willReturn(['model' => 'config-model']);

        $capturedOptions = [];
        $mockAdapter = $this->createMock(ProviderInterface::class);
        $mockAdapter->method('supportsFeature')->willReturnCallback(
            static fn(string $feature): bool => $feature === 'embeddings',
        );
        $mockAdapter->method('embeddings')->willReturnCallback(
            function (string|array $input, array $options) use (&$capturedOptions): EmbeddingResponse {
                $capturedOptions = $options;
                return new EmbeddingResponse(
                    embeddings: [[0.4]],
                    model: 'override-model',
                    usage: new UsageStatistics(1, 0, 1),
                    provider: 'openai',
                );
            },
        );

        $registryMock = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        $manager->embedForConfiguration('text', $config, (new EmbeddingOptions())->withModel('override-model'));

        // Per-call options win over the configuration's stored model id.
        self::assertSame('override-model', $capturedOptions['model']);
    }

    #[Test]
    public function embedForConfigurationThrowsWhenEmbeddingsUnsupported(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('chat-only-config');
        $config->method('toOptionsArray')->willReturn([]);

        $mockAdapter = self::createStub(ProviderInterface::class);
        $mockAdapter->method('supportsFeature')->willReturn(false);
        $mockAdapter->method('getIdentifier')->willReturn('chat-only');

        $registryMock = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('Provider "chat-only" does not support embeddings');

        $manager->embedForConfiguration('text', $config);
    }

    #[Test]
    public function embedForConfigurationPlumbsBudgetAndCacheMetadata(): void
    {
        $spy = new RecordingMiddleware();
        $manager = $this->buildManagerForEmbedConfiguration([$spy]);
        $config = $this->buildEmbeddingConfigurationStub();

        $options = (new EmbeddingOptions())
            ->withBeUserUid(7)
            ->withPlannedCost(0.42);

        $manager->embedForConfiguration('hello', $config, $options);

        self::assertCount(1, $spy->calls);
        self::assertSame(ProviderOperation::Embedding, $spy->calls[0]['operation']);
        // The pipeline sees the real configuration, not an ad-hoc transient.
        self::assertSame('embed-config', $spy->calls[0]['identifier']);
        $metadata = $spy->calls[0]['metadata'];
        self::assertSame(7, $metadata[BudgetMiddleware::METADATA_BE_USER_UID]);
        self::assertSame(0.42, $metadata[BudgetMiddleware::METADATA_PLANNED_COST]);
        // EmbeddingOptions defaults to cacheTtl = 86400, so cache metadata
        // coexists with the budget keys.
        self::assertArrayHasKey(CacheMiddleware::METADATA_CACHE_KEY, $metadata);
        self::assertSame(86400, $metadata[CacheMiddleware::METADATA_CACHE_TTL]);
    }

    #[Test]
    public function embedForConfigurationOmitsCacheMetadataWhenTtlZero(): void
    {
        $spy = new RecordingMiddleware();
        $manager = $this->buildManagerForEmbedConfiguration([$spy]);
        $config = $this->buildEmbeddingConfigurationStub();

        $manager->embedForConfiguration('hello', $config, EmbeddingOptions::noCache());

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertArrayNotHasKey(CacheMiddleware::METADATA_CACHE_KEY, $metadata);
        self::assertArrayNotHasKey(BudgetMiddleware::METADATA_BE_USER_UID, $metadata);
    }

    #[Test]
    public function embedForConfigurationRoutesTheConfigurationCacheTagThroughTheSanitizer(): void
    {
        // A dotted configuration identifier (the preset naming scheme) must not reach
        // the cache frontend verbatim in a tag — it is rejected as an invalid tag.
        $spy = new RecordingMiddleware();
        $adapter = self::createStub(ProviderInterface::class);
        $adapter->method('supportsFeature')->willReturn(true);
        $adapter->method('embeddings')->willReturn(new EmbeddingResponse(
            embeddings: [[0.1, 0.2]],
            model: 'text-embedding-3-small',
            usage: new UsageStatistics(1, 0, 1),
            provider: 'openai',
        ));
        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        $cacheStub = self::createStub(CacheManagerInterface::class);
        $cacheStub->method('generateCacheKey')->willReturn('cache-key');
        $cacheStub->method('sanitizeCacheTag')->willReturnCallback(
            static fn(string $value): string => preg_replace('/\W/', '_', $value) ?? '',
        );

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registry, new MiddlewarePipeline([$spy]), $cacheStub);

        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn(self::createStub(Model::class));
        $config->method('getIdentifier')->willReturn('nr_ai_search.embeddings');
        $config->method('getModelId')->willReturn('text-embedding-3-small');
        $config->method('toOptionsArray')->willReturn(['model' => 'text-embedding-3-small']);
        $config->method('getFallbackChainDTO')->willReturn(FallbackChain::fromArray([]));

        // Default EmbeddingOptions keeps cacheTtl = 86400 > 0, so cache tags are built.
        $manager->embedForConfiguration('hello', $config, new EmbeddingOptions());

        $tags = $spy->calls[0]['metadata'][CacheMiddleware::METADATA_CACHE_TAGS];
        self::assertContains('nrllm_configuration_nr_ai_search_embeddings', $tags);
        foreach ($tags as $tag) {
            self::assertMatchesRegularExpression('/^[a-zA-Z0-9_%\-&]{1,250}$/', $tag);
        }
    }

    /**
     * Build a manager whose registry resolves an embeddings-capable adapter,
     * wrapped in the given middleware stack — for embedForConfiguration()
     * metadata-plumbing assertions.
     *
     * @param list<ProviderMiddlewareInterface> $middleware
     */
    private function buildManagerForEmbedConfiguration(array $middleware): LlmServiceManager
    {
        $adapter = self::createStub(ProviderInterface::class);
        $adapter->method('supportsFeature')->willReturn(true);
        $adapter->method('embeddings')->willReturn(new EmbeddingResponse(
            embeddings: [[0.1, 0.2]],
            model: 'text-embedding-3-small',
            usage: new UsageStatistics(1, 0, 1),
            provider: 'openai',
        ));

        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        return new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $registry,
            new MiddlewarePipeline($middleware),
            self::createStub(CacheManagerInterface::class),
        );
    }

    private function buildEmbeddingConfigurationStub(): LlmConfiguration
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('embed-config');
        $config->method('getModelId')->willReturn('text-embedding-3-small');
        $config->method('toOptionsArray')->willReturn(['model' => 'text-embedding-3-small']);
        // FallbackChain is final (not doubleable): RecordingMiddleware reads
        // getFallbackChainDTO(), so the stub must return a real instance.
        $config->method('getFallbackChainDTO')->willReturn(FallbackChain::fromArray([]));

        return $config;
    }

    #[Test]
    public function chatRoutesThroughDefaultConfigurationWhenNoProviderPinned(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('nr_repurpose');
        $config->method('toOptionsArray')->willReturn([
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'model' => 'gpt-4o',
            'provider' => 'openai',
        ]);

        $expectedResponse = new CompletionResponse(
            content: '{"ok":true}',
            model: 'gpt-4o',
            usage: new UsageStatistics(1, 1, 2),
            finishReason: 'stop',
            provider: 'openai',
        );

        $captured = [];
        $adapter = $this->createMock(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturnCallback(
            function (array $messages, array $options) use (&$captured, $expectedResponse): CompletionResponse {
                $captured = $options;
                return $expectedResponse;
            },
        );

        $registryStub = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryStub->method('createAdapterFromModel')->willReturn($adapter);

        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->method('findDefault')->willReturn($config);

        $manager = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $registryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configRepo,
        );

        $result = $manager->chat(
            [['role' => 'user', 'content' => 'Hello']],
            (new ChatOptions(temperature: 0.3))->withResponseFormat('json'),
        );

        self::assertSame($expectedResponse, $result);
        // Per-call options override the configuration's stored defaults.
        self::assertSame(0.3, $captured['temperature']);
        self::assertSame('json', $captured['response_format']);
        // Configuration-only defaults survive the merge.
        self::assertSame(4096, $captured['max_tokens']);
        self::assertSame('gpt-4o', $captured['model']);
        // The provider key is never forwarded to the adapter.
        self::assertArrayNotHasKey('provider', $captured);
    }

    #[Test]
    public function chatBypassesDefaultConfigurationWhenProviderPinned(): void
    {
        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        // A pinned provider must short-circuit before the default-config lookup.
        $configRepo->expects(self::never())->method('findDefault');

        $manager = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $this->adapterRegistryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configRepo,
        );
        $manager->registerProvider($this->provider);

        $result = $manager->chat(
            [['role' => 'user', 'content' => 'Hello']],
            new ChatOptions(provider: 'openai'),
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatThrowsWhenNoDefaultConfigurationAndNoProviderPinned(): void
    {
        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->method('findDefault')->willReturn(null);

        $manager = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $this->adapterRegistryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configRepo,
        );
        $manager->registerProvider($this->provider);

        // No active default configuration and no per-call provider: with the
        // extension-config default-provider fallback removed, the generic path
        // must throw rather than silently pick a provider.
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No provider specified and no default provider configured');

        $manager->chat([['role' => 'user', 'content' => 'Hello']]);
    }

    #[Test]
    public function chatThrowsWhenDefaultConfigurationHasNoModelAndNoProviderPinned(): void
    {
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn(null);

        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->method('findDefault')->willReturn($config);

        $manager = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $this->adapterRegistryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configRepo,
        );
        $manager->registerProvider($this->provider);

        // A model-less default config must not route into *WithConfiguration()
        // (which would throw a different "no model" error): resolveDefaultConfiguration()
        // returns null, so with no pinned provider the generic path throws the
        // no-provider error — asserting that distinguishes the gate from a misroute.
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No provider specified and no default provider configured');

        $manager->chat([['role' => 'user', 'content' => 'Hello']]);
    }

    #[Test]
    public function chatThrowsWhenDefaultConfigurationIsAccessRestrictedAndNoProviderPinned(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('hasAccessRestrictions')->willReturn(true);

        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->method('findDefault')->willReturn($config);

        $manager = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $this->adapterRegistryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configRepo,
        );
        $manager->registerProvider($this->provider);

        // A group-restricted default must not be auto-applied without a BE-user
        // context to enforce membership: resolveDefaultConfiguration() returns
        // null, so with no pinned provider the generic path throws.
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No provider specified and no default provider configured');

        $manager->chat([['role' => 'user', 'content' => 'Hello']]);
    }

    #[Test]
    public function completeRoutesThroughDefaultConfigurationWhenNoProviderPinned(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('nr_repurpose');
        $config->method('toOptionsArray')->willReturn([
            'temperature' => 0.7,
            'model' => 'gpt-4o',
            'provider' => 'openai',
        ]);

        $expectedResponse = new CompletionResponse(
            content: 'text',
            model: 'gpt-4o',
            usage: new UsageStatistics(1, 1, 2),
            finishReason: 'stop',
            provider: 'openai',
        );

        $captured = [];
        $adapter = $this->createMock(ProviderInterface::class);
        $adapter->method('complete')->willReturnCallback(
            function (string $prompt, array $options) use (&$captured, $expectedResponse): CompletionResponse {
                $captured = $options;
                return $expectedResponse;
            },
        );

        $registryStub = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryStub->method('createAdapterFromModel')->willReturn($adapter);

        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->method('findDefault')->willReturn($config);

        $manager = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $registryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configRepo,
        );

        $result = $manager->complete('Prompt', new ChatOptions(temperature: 0.2));

        self::assertSame($expectedResponse, $result);
        self::assertSame(0.2, $captured['temperature']);
        self::assertSame('gpt-4o', $captured['model']);
        self::assertArrayNotHasKey('provider', $captured);
    }

    #[Test]
    public function completeBypassesDefaultConfigurationWhenProviderPinned(): void
    {
        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->expects(self::never())->method('findDefault');

        $manager = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $this->adapterRegistryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configRepo,
        );
        $manager->registerProvider($this->provider);

        $result = $manager->complete('Prompt', new ChatOptions(provider: 'openai'));

        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function completeThrowsWhenNoDefaultConfigurationAndNoProviderPinned(): void
    {
        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->method('findDefault')->willReturn(null);

        $manager = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $this->adapterRegistryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configRepo,
        );
        $manager->registerProvider($this->provider);

        // Mirror of the chat() no-fallback contract for the complete() entry point.
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No provider specified and no default provider configured');

        $manager->complete('Prompt');
    }

    #[Test]
    public function streamChatWithConfigurationUsesAdapter(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('test-config');
        $config->method('toOptionsArray')->willReturn(['temperature' => 0.5]);

        $mockAdapter = new StubStreamingProvider();

        $registryMock = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        $chunks = [];
        foreach ($manager->streamChatWithConfiguration([['role' => 'user', 'content' => 'Hello']], $config) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Streaming', ' response'], $chunks);
    }

    /**
     * The streaming path must apply the configuration's system prompt too —
     * the adapters read the system instruction from the message list.
     */
    #[Test]
    public function streamChatWithConfigurationInjectsConfiguredSystemPrompt(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('getIdentifier')->willReturn('test-config');
        $config->method('toOptionsArray')->willReturn(['system_prompt' => 'You are helpful']);

        $adapter = new StubStreamingProvider();
        $registryMock = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryMock->method('createAdapterFromModel')->willReturn($adapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        iterator_to_array($manager->streamChatWithConfiguration([['role' => 'user', 'content' => 'Hello']], $config));

        self::assertEquals(
            [
                ChatMessage::system('You are helpful'),
                ChatMessage::fromArray(['role' => 'user', 'content' => 'Hello']),
            ],
            $adapter->capturedMessages,
        );
    }

    #[Test]
    public function streamChatRoutesThroughDefaultConfigurationWithOptionOverrides(): void
    {
        $model = self::createStub(Model::class);
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('hasAccessRestrictions')->willReturn(false);
        $config->method('toOptionsArray')->willReturn([
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'model' => 'gpt-4o',
            'provider' => 'openai',
        ]);

        $adapter = new StubStreamingProvider();
        $registryStub = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryStub->method('createAdapterFromModel')->willReturn($adapter);

        $configRepo = $this->createMock(LlmConfigurationRepository::class);
        $configRepo->method('findDefault')->willReturn($config);

        $manager = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $registryStub,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configRepo,
        );

        $chunks = [];
        foreach ($manager->streamChat([['role' => 'user', 'content' => 'Hello']], (new ChatOptions(temperature: 0.3))->withResponseFormat('json')) as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertEquals(['Streaming', ' response'], $chunks);
        // Per-call options override config defaults; config-only defaults survive; provider stripped.
        self::assertSame(0.3, $adapter->capturedOptions['temperature']);
        self::assertSame('json', $adapter->capturedOptions['response_format']);
        self::assertSame(4096, $adapter->capturedOptions['max_tokens']);
        self::assertSame('gpt-4o', $adapter->capturedOptions['model']);
        self::assertArrayNotHasKey('provider', $adapter->capturedOptions);
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

        $registryMock = self::createStub(ProviderAdapterRegistryInterface::class);
        $registryMock->method('createAdapterFromModel')->willReturn($mockAdapter);

        $manager = new LlmServiceManager($this->extensionConfigStub, $this->loggerStub, $registryMock, $this->emptyMiddlewarePipeline(), self::createStub(CacheManagerInterface::class));

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support streaming');

        iterator_to_array($manager->streamChatWithConfiguration([['role' => 'user', 'content' => 'Hello']], $config));
    }

    // ====================================================================
    // Middleware pipeline wiring for direct (ad-hoc) calls — ADR-026 FU-5
    // ====================================================================

    #[Test]
    public function directChatRoutesThroughMiddlewarePipeline(): void
    {
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        $manager->chat([['role' => 'user', 'content' => 'Hello']], new ChatOptions(provider: 'openai'));

        self::assertCount(1, $spy->calls);
        self::assertSame(ProviderOperation::Chat, $spy->calls[0]['operation']);
        self::assertTrue($spy->calls[0]['fallbackChainEmpty'], 'Ad-hoc call has empty fallback chain');
        self::assertNull($spy->calls[0]['uid'], 'Synthesized configuration is unpersisted (uid = null)');
        self::assertStringStartsWith('ad-hoc:chat:', $spy->calls[0]['identifier']);
    }

    #[Test]
    public function directCompleteRoutesThroughMiddlewarePipeline(): void
    {
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        $manager->complete('prompt', new ChatOptions(provider: 'openai'));

        self::assertCount(1, $spy->calls);
        self::assertSame(ProviderOperation::Completion, $spy->calls[0]['operation']);
    }

    #[Test]
    public function directEmbedRoutesThroughMiddlewarePipeline(): void
    {
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        $manager->embed('text', new EmbeddingOptions(provider: 'openai'));

        self::assertCount(1, $spy->calls);
        self::assertSame(ProviderOperation::Embedding, $spy->calls[0]['operation']);
    }

    #[Test]
    public function directVisionRoutesThroughMiddlewarePipeline(): void
    {
        $spy      = new RecordingMiddleware();
        $provider = new TestableVisionProvider();
        $manager  = $this->buildManagerWithMiddleware([$spy], $provider);

        $manager->vision([
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/i.png']],
        ], new VisionOptions(provider: 'openai-vision'));

        self::assertCount(1, $spy->calls);
        self::assertSame(ProviderOperation::Vision, $spy->calls[0]['operation']);
    }

    #[Test]
    public function directChatWithToolsRoutesThroughMiddlewarePipeline(): void
    {
        $spy      = new RecordingMiddleware();
        $provider = new TestableToolProvider();
        $manager  = $this->buildManagerWithMiddleware([$spy], $provider);

        $manager->chatWithTools(
            [['role' => 'user', 'content' => 'hi']],
            [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'noop', 'description' => '', 'parameters' => []]])],
            new ToolOptions(provider: 'openai-tools'),
        );

        self::assertCount(1, $spy->calls);
        self::assertSame(ProviderOperation::Tools, $spy->calls[0]['operation']);
    }

    #[Test]
    public function embedPlumbsCacheMetadataWhenTtlPositive(): void
    {
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        // EmbeddingOptions defaults to cacheTtl = 86400, so cache metadata
        // should be set on the ProviderCallContext the middleware sees.
        $manager->embed('text', new EmbeddingOptions(provider: 'openai'));

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertArrayHasKey(CacheMiddleware::METADATA_CACHE_KEY, $metadata);
        self::assertArrayHasKey(CacheMiddleware::METADATA_CACHE_TTL, $metadata);
        self::assertSame(86400, $metadata[CacheMiddleware::METADATA_CACHE_TTL]);
    }

    #[Test]
    public function embedOmitsCacheMetadataWhenTtlZero(): void
    {
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        $manager->embed('text', EmbeddingOptions::noCache()->withProvider('openai'));

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertArrayNotHasKey(CacheMiddleware::METADATA_CACHE_KEY, $metadata);
    }

    #[Test]
    public function chatPlumbsBudgetMetadataFromOptions(): void
    {
        // REC #4 slice 15a — the manager translates the typed
        // ChatOptions::beUserUid / plannedCost fields into the metadata
        // keys that BudgetMiddleware reads from the ProviderCallContext.
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        $options = (new ChatOptions())
            ->withProvider('openai')
            ->withBeUserUid(7)
            ->withPlannedCost(0.42);

        $manager->chat([['role' => 'user', 'content' => 'hi']], $options);

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertSame(7, $metadata[BudgetMiddleware::METADATA_BE_USER_UID]);
        self::assertSame(0.42, $metadata[BudgetMiddleware::METADATA_PLANNED_COST]);
    }

    #[Test]
    public function chatOmitsBudgetMetadataWhenOptionsAreUnset(): void
    {
        // Default ChatOptions leave both fields null — the manager must
        // not fabricate keys for them. BudgetMiddleware then takes its
        // documented "skip the check" branch.
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        $manager->chat([['role' => 'user', 'content' => 'hi']], new ChatOptions(provider: 'openai'));

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertArrayNotHasKey(BudgetMiddleware::METADATA_BE_USER_UID, $metadata);
        self::assertArrayNotHasKey(BudgetMiddleware::METADATA_PLANNED_COST, $metadata);
    }

    #[Test]
    public function completePlumbsBudgetMetadataFromOptions(): void
    {
        // Mirror of chatPlumbsBudgetMetadataFromOptions for the
        // complete() entry point — closes the metadata-bypass that
        // PR #177 review (Copilot) found in slice 15a where only
        // chat() carried the metadata.
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        $options = (new ChatOptions())
            ->withProvider('openai')
            ->withBeUserUid(13)
            ->withPlannedCost(0.17);

        $manager->complete('hello', $options);

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertSame(13, $metadata[BudgetMiddleware::METADATA_BE_USER_UID]);
        self::assertSame(0.17, $metadata[BudgetMiddleware::METADATA_PLANNED_COST]);
    }

    #[Test]
    public function chatWithToolsPlumbsBudgetMetadataFromOptions(): void
    {
        // ToolOptions extends ChatOptions, so the typed budget fields
        // are already present on the subclass — just need to be plumbed
        // through the chatWithTools() entrypoint identically to chat().
        $spy      = new RecordingMiddleware();
        $provider = new TestableToolProvider();
        $manager  = $this->buildManagerWithMiddleware([$spy], $provider);

        $options = new ToolOptions(
            provider: 'openai-tools',
            beUserUid: 21,
            plannedCost: 0.05,
        );

        $manager->chatWithTools(
            [['role' => 'user', 'content' => 'hi']],
            [ToolSpec::fromArray(['type' => 'function', 'function' => ['name' => 'noop', 'description' => '', 'parameters' => []]])],
            $options,
        );

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertSame(21, $metadata[BudgetMiddleware::METADATA_BE_USER_UID]);
        self::assertSame(0.05, $metadata[BudgetMiddleware::METADATA_PLANNED_COST]);
    }

    #[Test]
    public function embedPlumbsBudgetMetadataFromOptions(): void
    {
        // REC #4 slice 15b — same wiring as chat()/complete() but
        // through the embed() entry point, which already maintained
        // its own metadata array (cache keys). Budget keys must
        // coexist with cache keys without overwriting them.
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        $options = (new EmbeddingOptions(cacheTtl: 0))
            ->withProvider('openai')
            ->withBeUserUid(7)
            ->withPlannedCost(0.42);

        $manager->embed('hello', $options);

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertSame(7, $metadata[BudgetMiddleware::METADATA_BE_USER_UID]);
        self::assertSame(0.42, $metadata[BudgetMiddleware::METADATA_PLANNED_COST]);
    }

    #[Test]
    public function embedKeepsBudgetAndCacheMetadataTogether(): void
    {
        // Budget metadata must not collide with cache metadata in the
        // embed() pipeline — both must reach the middleware so
        // BudgetMiddleware AND CacheMiddleware can both do their job.
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        $options = (new EmbeddingOptions())
            ->withProvider('openai')
            ->withBeUserUid(11)
            ->withPlannedCost(0.05);

        $manager->embed('hello', $options);

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertSame(11, $metadata[BudgetMiddleware::METADATA_BE_USER_UID]);
        self::assertSame(0.05, $metadata[BudgetMiddleware::METADATA_PLANNED_COST]);
        self::assertArrayHasKey(CacheMiddleware::METADATA_CACHE_KEY, $metadata);
        self::assertArrayHasKey(CacheMiddleware::METADATA_CACHE_TTL, $metadata);
    }

    #[Test]
    public function visionPlumbsBudgetMetadataFromOptions(): void
    {
        $spy      = new RecordingMiddleware();
        $provider = new TestableVisionProvider();
        $manager  = $this->buildManagerWithMiddleware([$spy], $provider);

        $options = (new VisionOptions())
            ->withProvider('openai-vision')
            ->withBeUserUid(21)
            ->withPlannedCost(0.10);

        $manager->vision(
            [['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/i.png']]],
            $options,
        );

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertSame(21, $metadata[BudgetMiddleware::METADATA_BE_USER_UID]);
        self::assertSame(0.10, $metadata[BudgetMiddleware::METADATA_PLANNED_COST]);
    }

    #[Test]
    public function chatPlumbsOnlyBeUserUidWhenPlannedCostUnset(): void
    {
        // Each field is independently optional — a uid-only call (the
        // common "I know who but not the cost" shape from CompletionService
        // auto-populate) must not leak a planned_cost: 0.0 entry that
        // would change BudgetMiddleware behaviour vs. the absent-key path.
        $spy     = new RecordingMiddleware();
        $manager = $this->buildManagerWithMiddleware([$spy]);

        $options = (new ChatOptions())->withProvider('openai')->withBeUserUid(11);

        $manager->chat([['role' => 'user', 'content' => 'hi']], $options);

        self::assertCount(1, $spy->calls);
        $metadata = $spy->calls[0]['metadata'];
        self::assertSame(11, $metadata[BudgetMiddleware::METADATA_BE_USER_UID]);
        self::assertArrayNotHasKey(BudgetMiddleware::METADATA_PLANNED_COST, $metadata);
    }

    /**
     * Build a fresh LlmServiceManager pre-configured with the given middleware
     * and (optionally) a non-default TestableProvider. Separate setup from the
     * default subject so ad-hoc tests can inspect a specific middleware stack.
     *
     * @param list<ProviderMiddlewareInterface> $middleware
     */
    private function buildManagerWithMiddleware(
        array $middleware,
        ?TestableProvider $provider = null,
    ): LlmServiceManager {
        $manager = new LlmServiceManager(
            $this->extensionConfigStub,
            $this->loggerStub,
            $this->adapterRegistryStub,
            new MiddlewarePipeline($middleware),
            self::createStub(CacheManagerInterface::class),
        );
        $testProvider = $provider ?? new TestableProvider();
        $testProvider->setNextResponse(new CompletionResponse(
            content: 'stub',
            model: 'stub',
            usage: new UsageStatistics(1, 1, 2),
            finishReason: 'stop',
            provider: $testProvider->getIdentifier(),
        ));
        $testProvider->setNextEmbeddingResponse(new EmbeddingResponse(
            embeddings: [[0.1, 0.2]],
            model: 'stub',
            usage: new UsageStatistics(1, 0, 1),
            provider: $testProvider->getIdentifier(),
        ));
        if ($testProvider instanceof TestableVisionProvider) {
            $testProvider->setNextVisionResponse(new VisionResponse(
                description: 'stub',
                model: 'stub',
                usage: new UsageStatistics(1, 1, 2),
                provider: $testProvider->getIdentifier(),
            ));
        }
        $manager->registerProvider($testProvider);

        return $manager;
    }
}

/**
 * Middleware that captures every invocation's configuration + operation for
 * assertions without wrapping or transforming behaviour.
 */
final class RecordingMiddleware implements ProviderMiddlewareInterface
{
    /** @var list<array{operation: ProviderOperation, identifier: string, fallbackChainEmpty: bool, uid: ?int, metadata: array<string, mixed>}> */
    public array $calls = [];

    public function handle(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $next,
    ): mixed {
        $this->calls[] = [
            'operation'          => $context->operation,
            'identifier'         => $configuration->getIdentifier(),
            'fallbackChainEmpty' => $configuration->getFallbackChainDTO()->isEmpty(),
            'uid'                => $configuration->getUid(),
            'metadata'           => $context->metadata,
        ];

        return $next($configuration);
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

    public function isAvailable(): bool
    {
        return $this->available;
    }

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

    public function getDefaultModel(): string
    {
        return 'gpt-4o';
    }

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
 * Streaming-capable provider stub that records the options it received — shared by the
 * streaming tests so the throw-stubs live in one place (avoids duplicated fixtures).
 */
class StubStreamingProvider extends TestableProvider implements StreamingCapableInterface
{
    /** @var array<string, mixed> */
    public array $capturedOptions = [];

    /** @var list<ChatMessage|array<string, mixed>> */
    public array $capturedMessages = [];

    public function __construct()
    {
        parent::__construct('mock', 'Mock', true);
    }

    public function streamChatCompletion(array $messages, array $options = []): Generator
    {
        $this->capturedMessages = $messages;
        $this->capturedOptions = $options;
        yield 'Streaming';
        yield ' response';
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
    /** @var list<ToolSpec> */
    public array $capturedTools = [];

    public function __construct()
    {
        parent::__construct('openai-tools', 'OpenAI Tools', true);
    }

    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        // Capture so tests can assert that LlmServiceManager normalised
        // legacy array fixtures into typed ToolSpec instances before forwarding.
        $this->capturedTools = $tools;
        return $this->chatCompletion($messages, $options);
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

    public function supportsFeature(string|ModelCapability $feature): bool
    {
        $featureValue = $feature instanceof ModelCapability ? $feature->value : $feature;
        // Does NOT support embeddings
        return in_array($featureValue, ['chat'], true);
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        throw new UnsupportedFeatureException('Provider "limited" does not support embeddings', 4932152837);
    }
}
