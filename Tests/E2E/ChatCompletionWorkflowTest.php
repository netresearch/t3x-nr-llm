<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * E2E tests for complete chat completion workflows
 *
 * Tests the full path from CompletionService through LlmServiceManager
 * to the provider and back, verifying correct data flow.
 */
#[CoversClass(CompletionService::class)]
#[CoversClass(LlmServiceManager::class)]
class ChatCompletionWorkflowTest extends AbstractE2ETestCase
{
    #[Test]
    public function completeOpenAiChatWorkflow(): void
    {
        // Arrange: Create complete stack with mocked HTTP
        $responseData = $this->createOpenAiChatResponse(
            content: 'Hello! How can I help you today?',
            model: 'gpt-4o'
        );

        $httpClient = $this->createMockHttpClient([
            $this->createJsonResponse($responseData),
        ]);

        $provider = new OpenAiProvider(
            $httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger
        );
        $provider->configure([
            'apiKey' => 'sk-test-key',
            'defaultModel' => 'gpt-4o',
        ]);

        $extensionConfig = $this->createMock(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'openai',
            'providers' => ['openai' => ['apiKey' => 'sk-test']],
        ]);

        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger());
        $serviceManager->registerProvider($provider);
        $serviceManager->setDefaultProvider('openai');

        $completionService = new CompletionService($serviceManager);

        // Act: Execute complete workflow
        $result = $completionService->complete('Hello!');

        // Assert: Verify complete response
        $this->assertInstanceOf(CompletionResponse::class, $result);
        $this->assertEquals('Hello! How can I help you today?', $result->content);
        $this->assertEquals('gpt-4o', $result->model);
        $this->assertEquals('stop', $result->finishReason);
        $this->assertTrue($result->isComplete());
        $this->assertFalse($result->wasTruncated());
    }

    #[Test]
    public function completeClaudeChatWorkflow(): void
    {
        // Arrange
        $responseData = $this->createClaudeChatResponse(
            content: 'I am Claude, happy to help!',
            model: 'claude-sonnet-4-20250514'
        );

        $httpClient = $this->createMockHttpClient([
            $this->createJsonResponse($responseData),
        ]);

        $provider = new ClaudeProvider(
            $httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger
        );
        $provider->configure([
            'apiKey' => 'sk-ant-test-key',
            'defaultModel' => 'claude-sonnet-4-20250514',
        ]);

        $extensionConfig = $this->createMock(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'claude',
            'providers' => ['claude' => ['apiKey' => 'sk-ant-test']],
        ]);

        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger());
        $serviceManager->registerProvider($provider);
        $serviceManager->setDefaultProvider('claude');

        $completionService = new CompletionService($serviceManager);

        // Act
        $result = $completionService->complete('Hello Claude!');

        // Assert
        $this->assertInstanceOf(CompletionResponse::class, $result);
        $this->assertEquals('I am Claude, happy to help!', $result->content);
        $this->assertEquals('claude-sonnet-4-20250514', $result->model);
        $this->assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function chatWithMultipleMessagesWorkflow(): void
    {
        // Arrange
        $responseData = $this->createOpenAiChatResponse(
            content: 'Based on our conversation, Paris is the capital of France.',
            model: 'gpt-4o'
        );

        $clientSetup = $this->createCapturingHttpClient(
            $this->createJsonResponse($responseData)
        );

        $provider = new OpenAiProvider(
            $clientSetup['client'],
            $this->requestFactory,
            $this->streamFactory,
            $this->logger
        );
        $provider->configure([
            'apiKey' => 'sk-test-key',
            'defaultModel' => 'gpt-4o',
        ]);

        $extensionConfig = $this->createMock(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'openai',
            'providers' => ['openai' => ['apiKey' => 'sk-test']],
        ]);

        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger());
        $serviceManager->registerProvider($provider);
        $serviceManager->setDefaultProvider('openai');

        // Act: Multi-turn conversation via service manager directly
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful geography assistant.'],
            ['role' => 'user', 'content' => 'What is the capital of France?'],
            ['role' => 'assistant', 'content' => 'The capital of France is Paris.'],
            ['role' => 'user', 'content' => 'Can you confirm that?'],
        ];

        $result = $serviceManager->chat($messages);

        // Assert
        $this->assertInstanceOf(CompletionResponse::class, $result);
        $this->assertStringContainsString('Paris', $result->content);

        // Verify the request was properly formed
        $this->assertCount(1, $clientSetup['requests']);
        $requestBody = json_decode((string) $clientSetup['requests'][0]->getBody(), true);
        $this->assertCount(4, $requestBody['messages']);
    }

    #[Test]
    public function chatWithOptionsWorkflow(): void
    {
        // Arrange
        $responseData = $this->createOpenAiChatResponse(
            content: '{"summary": "Test response in JSON format"}',
            model: 'gpt-4o'
        );

        $clientSetup = $this->createCapturingHttpClient(
            $this->createJsonResponse($responseData)
        );

        $provider = new OpenAiProvider(
            $clientSetup['client'],
            $this->requestFactory,
            $this->streamFactory,
            $this->logger
        );
        $provider->configure([
            'apiKey' => 'sk-test-key',
            'defaultModel' => 'gpt-4o',
        ]);

        $extensionConfig = $this->createMock(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'openai',
            'providers' => ['openai' => ['apiKey' => 'sk-test']],
        ]);

        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger());
        $serviceManager->registerProvider($provider);
        $serviceManager->setDefaultProvider('openai');

        $completionService = new CompletionService($serviceManager);

        // Act: Request with specific options
        $result = $completionService->complete('Generate JSON', [
            'temperature' => 0.1,
            'max_tokens' => 500,
        ]);

        // Assert
        $this->assertInstanceOf(CompletionResponse::class, $result);

        // Verify options were passed
        $requestBody = json_decode((string) $clientSetup['requests'][0]->getBody(), true);
        $this->assertEquals(0.1, $requestBody['temperature']);
        $this->assertEquals(500, $requestBody['max_tokens']);
    }

    #[Test]
    public function truncatedResponseWorkflow(): void
    {
        // Arrange: Response with max_tokens reached
        $responseData = $this->createOpenAiChatResponse(
            content: 'This response was cut off because-',
            finishReason: 'length'
        );

        $httpClient = $this->createMockHttpClient([
            $this->createJsonResponse($responseData),
        ]);

        $provider = new OpenAiProvider(
            $httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger
        );
        $provider->configure([
            'apiKey' => 'sk-test-key',
            'defaultModel' => 'gpt-4o',
        ]);

        $extensionConfig = $this->createMock(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'openai',
            'providers' => ['openai' => ['apiKey' => 'sk-test']],
        ]);

        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger());
        $serviceManager->registerProvider($provider);
        $serviceManager->setDefaultProvider('openai');

        $completionService = new CompletionService($serviceManager);

        // Act
        $result = $completionService->complete('Write a very long essay');

        // Assert
        $this->assertTrue($result->wasTruncated());
        $this->assertFalse($result->isComplete());
        $this->assertEquals('length', $result->finishReason);
    }

    #[Test]
    public function usageTrackingWorkflow(): void
    {
        // Arrange
        $responseData = $this->createOpenAiChatResponse(
            content: 'Usage tracked response',
            promptTokens: 25,
            completionTokens: 50
        );

        $httpClient = $this->createMockHttpClient([
            $this->createJsonResponse($responseData),
        ]);

        $provider = new OpenAiProvider(
            $httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger
        );
        $provider->configure([
            'apiKey' => 'sk-test-key',
            'defaultModel' => 'gpt-4o',
        ]);

        $extensionConfig = $this->createMock(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'openai',
            'providers' => ['openai' => ['apiKey' => 'sk-test']],
        ]);

        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger());
        $serviceManager->registerProvider($provider);
        $serviceManager->setDefaultProvider('openai');

        $completionService = new CompletionService($serviceManager);

        // Act
        $result = $completionService->complete('Track my usage');

        // Assert usage is correctly tracked
        $this->assertEquals(25, $result->usage->promptTokens);
        $this->assertEquals(50, $result->usage->completionTokens);
        $this->assertEquals(75, $result->usage->totalTokens);
    }

    #[Test]
    public function providerSwitchingWorkflow(): void
    {
        // Arrange: Set up both providers
        $openAiResponse = $this->createOpenAiChatResponse(
            content: 'OpenAI response',
            model: 'gpt-4o'
        );
        $claudeResponse = $this->createClaudeChatResponse(
            content: 'Claude response',
            model: 'claude-sonnet-4-20250514'
        );

        $openAiClient = $this->createMockHttpClient([
            $this->createJsonResponse($openAiResponse),
        ]);
        $claudeClient = $this->createMockHttpClient([
            $this->createJsonResponse($claudeResponse),
        ]);

        $openAiProvider = new OpenAiProvider(
            $openAiClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger
        );
        $openAiProvider->configure([
            'apiKey' => 'sk-openai-test',
            'defaultModel' => 'gpt-4o',
        ]);

        $claudeProvider = new ClaudeProvider(
            $claudeClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger
        );
        $claudeProvider->configure([
            'apiKey' => 'sk-ant-test',
            'defaultModel' => 'claude-sonnet-4-20250514',
        ]);

        $extensionConfig = $this->createMock(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([
            'defaultProvider' => 'openai',
            'providers' => [
                'openai' => ['apiKey' => 'sk-openai-test'],
                'claude' => ['apiKey' => 'sk-ant-test'],
            ],
        ]);

        $serviceManager = new LlmServiceManager($extensionConfig, new NullLogger());
        $serviceManager->registerProvider($openAiProvider);
        $serviceManager->registerProvider($claudeProvider);
        $serviceManager->setDefaultProvider('openai');

        $completionService = new CompletionService($serviceManager);

        // Act: Request Claude specifically
        $result = $completionService->complete('Hello', ['provider' => 'claude']);

        // Assert: Response came from Claude
        $this->assertEquals('Claude response', $result->content);
        $this->assertEquals('claude-sonnet-4-20250514', $result->model);
    }
}
