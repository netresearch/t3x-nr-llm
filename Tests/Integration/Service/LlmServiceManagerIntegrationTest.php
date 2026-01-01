<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Integration\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Tests\Integration\AbstractIntegrationTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Integration tests for LlmServiceManager.
 *
 * Tests full service coordination with multiple providers.
 */
#[CoversClass(LlmServiceManager::class)]
class LlmServiceManagerIntegrationTest extends AbstractIntegrationTestCase
{
    private LlmServiceManager $subject;
    private ExtensionConfiguration&Stub $extensionConfigStub;
    private ProviderAdapterRegistry&Stub $adapterRegistryStub;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $this->extensionConfigStub->method('get')
            ->with('nr_llm')
            ->willReturn([
                'defaultProvider' => 'openai',
                'providers' => [
                    'openai' => [
                        'apiKey' => 'sk-test-openai',
                        'defaultModel' => 'gpt-4o',
                    ],
                    'claude' => [
                        'apiKey' => 'sk-test-claude',
                        'defaultModel' => 'claude-sonnet-4-20250514',
                    ],
                ],
            ]);

        $this->adapterRegistryStub = self::createStub(ProviderAdapterRegistry::class);

        $this->subject = new LlmServiceManager(
            $this->extensionConfigStub,
            new NullLogger(),
            $this->adapterRegistryStub,
        );
    }

    /**
     * @param array<ResponseInterface> $responses
     */
    private function createConfiguredOpenAiProvider(array $responses): OpenAiProvider
    {
        $httpClient = $this->createHttpClientWithResponses($responses);

        $provider = new OpenAiProvider(
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger(),
        );
        $provider->setHttpClient($httpClient);

        return $provider;
    }

    /**
     * @param array<ResponseInterface> $responses
     */
    private function createConfiguredClaudeProvider(array $responses): ClaudeProvider
    {
        $httpClient = $this->createHttpClientWithResponses($responses);

        $provider = new ClaudeProvider(
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger(),
        );
        $provider->setHttpClient($httpClient);

        return $provider;
    }

    #[Test]
    public function registerAndRetrieveProvider(): void
    {
        $provider = $this->createConfiguredOpenAiProvider([]);

        $this->subject->registerProvider($provider);

        $retrievedProvider = $this->subject->getProvider('openai');
        self::assertSame($provider, $retrievedProvider);
    }

    #[Test]
    public function multipleProvidersCanBeRegistered(): void
    {
        $openAiProvider = $this->createConfiguredOpenAiProvider([]);
        $claudeProvider = $this->createConfiguredClaudeProvider([]);

        $this->subject->registerProvider($openAiProvider);
        $this->subject->registerProvider($claudeProvider);

        self::assertSame($openAiProvider, $this->subject->getProvider('openai'));
        self::assertSame($claudeProvider, $this->subject->getProvider('claude'));
    }

    #[Test]
    public function defaultProviderIsUsedWhenNoProviderSpecified(): void
    {
        /** @var array<string, mixed> $responseData */
        $responseData = $this->getOpenAiChatResponse(content: 'OpenAI response');

        $openAiProvider = $this->createConfiguredOpenAiProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $this->subject->registerProvider($openAiProvider);
        $this->subject->setDefaultProvider('openai');

        $result = $this->subject->chat([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('OpenAI response', $result->content);
    }

    #[Test]
    public function specificProviderCanBeRequestedInOptions(): void
    {
        /** @var array<string, mixed> $openAiResponse */
        $openAiResponse = $this->getOpenAiChatResponse(content: 'OpenAI response');
        /** @var array<string, mixed> $claudeResponse */
        $claudeResponse = $this->getClaudeChatResponse(content: 'Claude response');

        $openAiProvider = $this->createConfiguredOpenAiProvider([
            $this->createSuccessResponse($openAiResponse),
        ]);
        $claudeProvider = $this->createConfiguredClaudeProvider([
            $this->createSuccessResponse($claudeResponse),
        ]);

        $this->subject->registerProvider($openAiProvider);
        $this->subject->registerProvider($claudeProvider);
        $this->subject->setDefaultProvider('openai');

        // Request Claude specifically
        $result = $this->subject->chat(
            [['role' => 'user', 'content' => 'Hello']],
            new ChatOptions(provider: 'claude'),
        );

        self::assertEquals('Claude response', $result->content);
    }

    #[Test]
    public function getAvailableProvidersReturnsOnlyAvailable(): void
    {
        // Create available provider
        $availableProvider = $this->createConfiguredOpenAiProvider([]);

        // Create unavailable provider (no API key)
        $unavailableProvider = new OpenAiProvider(
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger(),
        );
        $unavailableProvider->setHttpClient($this->createHttpClientWithResponses([]));
        // Don't configure it, so it's not available

        $this->subject->registerProvider($availableProvider);

        $available = $this->subject->getAvailableProviders();

        self::assertCount(1, $available);
        self::assertArrayHasKey('openai', $available);
    }

    #[Test]
    public function getProviderListReturnsAllRegisteredProviders(): void
    {
        $openAiProvider = $this->createConfiguredOpenAiProvider([]);
        $claudeProvider = $this->createConfiguredClaudeProvider([]);

        $this->subject->registerProvider($openAiProvider);
        $this->subject->registerProvider($claudeProvider);

        $list = $this->subject->getProviderList();

        self::assertArrayHasKey('openai', $list);
        self::assertArrayHasKey('claude', $list);
        self::assertEquals('OpenAI', $list['openai']);
        self::assertEquals('Anthropic Claude', $list['claude']);
    }

    #[Test]
    public function throwsExceptionForUnknownProvider(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Provider "unknown" not found');

        $this->subject->getProvider('unknown');
    }

    #[Test]
    public function throwsExceptionWhenNoDefaultProviderConfigured(): void
    {
        // Create manager without default provider
        $configMock = self::createStub(ExtensionConfiguration::class);
        $configMock->method('get')->willReturn([
            'providers' => [],
        ]);

        $manager = new LlmServiceManager($configMock, new NullLogger(), $this->adapterRegistryStub);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No provider specified and no default provider configured');

        $manager->getProvider();
    }

    #[Test]
    public function chatWithOptionsPassesThemToProvider(): void
    {
        /** @var array<string, mixed> $responseData */
        $responseData = $this->getOpenAiChatResponse();

        $clientSetup = $this->createRequestCapturingClient(
            $this->createSuccessResponse($responseData),
        );

        $provider = new OpenAiProvider(
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger(),
        );
        $provider->setHttpClient($clientSetup['client']);
        $provider->configure([
            'apiKey' => 'sk-test',
            'defaultModel' => 'gpt-4o',
        ]);

        $this->subject->registerProvider($provider);
        $this->subject->setDefaultProvider('openai');

        $this->subject->chat(
            [['role' => 'user', 'content' => 'Hello']],
            new ChatOptions(temperature: 0.5, maxTokens: 100),
        );

        self::assertCount(1, $clientSetup['requests']);
        $body = json_decode((string)$clientSetup['requests'][0]->getBody(), true);
        self::assertIsArray($body);

        self::assertEquals(0.5, $body['temperature']);
        self::assertEquals(100, $body['max_tokens']);
    }

    #[Test]
    public function supportsFeatureChecksCorrectProvider(): void
    {
        $provider = $this->createConfiguredOpenAiProvider([]);
        $this->subject->registerProvider($provider);

        self::assertTrue($this->subject->supportsFeature('vision', 'openai'));
        self::assertTrue($this->subject->supportsFeature('streaming', 'openai'));
        self::assertTrue($this->subject->supportsFeature('tools', 'openai'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForUnknownProvider(): void
    {
        self::assertFalse($this->subject->supportsFeature('vision', 'unknown'));
    }

    #[Test]
    public function completeMethodCreatesUserMessage(): void
    {
        /** @var array<string, mixed> $responseData */
        $responseData = $this->getOpenAiChatResponse(content: 'Completed prompt');

        $provider = $this->createConfiguredOpenAiProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $this->subject->registerProvider($provider);
        $this->subject->setDefaultProvider('openai');

        $result = $this->subject->complete('Tell me a joke');

        self::assertEquals('Completed prompt', $result->content);
    }
}
