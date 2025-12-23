<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Integration\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Tests\Integration\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Integration tests for LlmServiceManager
 *
 * Tests full service coordination with multiple providers.
 */
#[CoversClass(LlmServiceManager::class)]
class LlmServiceManagerIntegrationTest extends AbstractIntegrationTestCase
{
    private LlmServiceManager $subject;
    private ExtensionConfiguration&MockObject $extensionConfigMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extensionConfigMock = $this->createMock(ExtensionConfiguration::class);
        $this->extensionConfigMock->method('get')
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

        $this->subject = new LlmServiceManager(
            $this->extensionConfigMock,
            new NullLogger()
        );
    }

    private function createConfiguredOpenAiProvider(array $responses): OpenAiProvider
    {
        $httpClient = $this->createHttpClientWithResponses($responses);

        return new OpenAiProvider(
            $httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger()
        );
    }

    private function createConfiguredClaudeProvider(array $responses): ClaudeProvider
    {
        $httpClient = $this->createHttpClientWithResponses($responses);

        return new ClaudeProvider(
            $httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger()
        );
    }

    #[Test]
    public function registerAndRetrieveProvider(): void
    {
        $provider = $this->createConfiguredOpenAiProvider([]);

        $this->subject->registerProvider($provider);

        $retrievedProvider = $this->subject->getProvider('openai');
        $this->assertSame($provider, $retrievedProvider);
    }

    #[Test]
    public function multipleProvidersCanBeRegistered(): void
    {
        $openAiProvider = $this->createConfiguredOpenAiProvider([]);
        $claudeProvider = $this->createConfiguredClaudeProvider([]);

        $this->subject->registerProvider($openAiProvider);
        $this->subject->registerProvider($claudeProvider);

        $this->assertSame($openAiProvider, $this->subject->getProvider('openai'));
        $this->assertSame($claudeProvider, $this->subject->getProvider('claude'));
    }

    #[Test]
    public function defaultProviderIsUsedWhenNoProviderSpecified(): void
    {
        $responseData = $this->getOpenAiChatResponse(content: 'OpenAI response');

        $openAiProvider = $this->createConfiguredOpenAiProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $this->subject->registerProvider($openAiProvider);
        $this->subject->setDefaultProvider('openai');

        $result = $this->subject->chat([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $this->assertInstanceOf(CompletionResponse::class, $result);
        $this->assertEquals('OpenAI response', $result->content);
    }

    #[Test]
    public function specificProviderCanBeRequestedInOptions(): void
    {
        $openAiResponse = $this->getOpenAiChatResponse(content: 'OpenAI response');
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
            ['provider' => 'claude']
        );

        $this->assertEquals('Claude response', $result->content);
    }

    #[Test]
    public function getAvailableProvidersReturnsOnlyAvailable(): void
    {
        // Create available provider
        $availableProvider = $this->createConfiguredOpenAiProvider([]);

        // Create unavailable provider (no API key)
        $unavailableProvider = new OpenAiProvider(
            $this->createHttpClientWithResponses([]),
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger()
        );
        // Don't configure it, so it's not available

        $this->subject->registerProvider($availableProvider);

        $available = $this->subject->getAvailableProviders();

        $this->assertCount(1, $available);
        $this->assertArrayHasKey('openai', $available);
    }

    #[Test]
    public function getProviderListReturnsAllRegisteredProviders(): void
    {
        $openAiProvider = $this->createConfiguredOpenAiProvider([]);
        $claudeProvider = $this->createConfiguredClaudeProvider([]);

        $this->subject->registerProvider($openAiProvider);
        $this->subject->registerProvider($claudeProvider);

        $list = $this->subject->getProviderList();

        $this->assertArrayHasKey('openai', $list);
        $this->assertArrayHasKey('claude', $list);
        $this->assertEquals('OpenAI', $list['openai']);
        $this->assertEquals('Anthropic Claude', $list['claude']);
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
        $configMock = $this->createMock(ExtensionConfiguration::class);
        $configMock->method('get')->willReturn([
            'providers' => [],
        ]);

        $manager = new LlmServiceManager($configMock, new NullLogger());

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No provider specified and no default provider configured');

        $manager->getProvider();
    }

    #[Test]
    public function chatWithOptionsPassesThemToProvider(): void
    {
        $responseData = $this->getOpenAiChatResponse();

        $clientSetup = $this->createRequestCapturingClient(
            $this->createSuccessResponse($responseData)
        );

        $provider = new OpenAiProvider(
            $clientSetup['client'],
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger()
        );
        $provider->configure([
            'apiKey' => 'sk-test',
            'defaultModel' => 'gpt-4o',
        ]);

        $this->subject->registerProvider($provider);
        $this->subject->setDefaultProvider('openai');

        $this->subject->chat(
            [['role' => 'user', 'content' => 'Hello']],
            ['temperature' => 0.5, 'max_tokens' => 100]
        );

        $this->assertCount(1, $clientSetup['requests']);
        $body = json_decode((string) $clientSetup['requests'][0]->getBody(), true);

        $this->assertEquals(0.5, $body['temperature']);
        $this->assertEquals(100, $body['max_tokens']);
    }

    #[Test]
    public function supportsFeatureChecksCorrectProvider(): void
    {
        $provider = $this->createConfiguredOpenAiProvider([]);
        $this->subject->registerProvider($provider);

        $this->assertTrue($this->subject->supportsFeature('vision', 'openai'));
        $this->assertTrue($this->subject->supportsFeature('streaming', 'openai'));
        $this->assertTrue($this->subject->supportsFeature('tools', 'openai'));
    }

    #[Test]
    public function supportsFeatureReturnsFalseForUnknownProvider(): void
    {
        $this->assertFalse($this->subject->supportsFeature('vision', 'unknown'));
    }

    #[Test]
    public function completeMethodCreatesUserMessage(): void
    {
        $responseData = $this->getOpenAiChatResponse(content: 'Completed prompt');

        $provider = $this->createConfiguredOpenAiProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $this->subject->registerProvider($provider);
        $this->subject->setDefaultProvider('openai');

        $result = $this->subject->complete('Tell me a joke');

        $this->assertEquals('Completed prompt', $result->content);
    }
}
