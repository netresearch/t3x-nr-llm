<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
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
                        'apiKey' => 'sk-test',
                        'defaultModel' => 'gpt-4o',
                    ],
                ],
            ]);

        $manager = new LlmServiceManager($extensionConfigStub, $this->loggerStub, $this->adapterRegistryStub);
        $config = $manager->getProviderConfiguration('openai');

        self::assertArrayHasKey('apiKey', $config);
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
        $newConfig = ['apiKey' => 'new-key', 'defaultModel' => 'new-model'];

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
    public function supportsFeature(string $feature): bool
    {
        return in_array($feature, ['chat', 'embeddings', 'vision'], true);
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
