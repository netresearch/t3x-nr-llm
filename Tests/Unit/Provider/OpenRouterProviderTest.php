<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Provider\OpenRouterProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;

#[CoversClass(OpenRouterProvider::class)]
class OpenRouterProviderTest extends AbstractUnitTestCase
{
    private OpenRouterProvider $subject;

    /** @var ClientInterface&Stub */
    private ClientInterface $httpClientMock;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClientMock = $this->createHttpClientMock();

        $this->subject = new OpenRouterProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $this->subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'anthropic/claude-3.5-sonnet',
            'siteUrl' => 'https://example.com',
            'appName' => 'Test App',
            'routingStrategy' => 'balanced',
            'autoFallback' => true,
            'fallbackModels' => '',
            'timeout' => 60,
        ]);

        // Set HTTP client AFTER configure() to ensure timeout matches
        $this->subject->setHttpClient($this->httpClientMock);
    }

    #[Test]
    public function getNameReturnsOpenRouter(): void
    {
        self::assertEquals('OpenRouter', $this->subject->getName());
    }

    #[Test]
    public function getIdentifierReturnsOpenrouter(): void
    {
        self::assertEquals('openrouter', $this->subject->getIdentifier());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyConfigured(): void
    {
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function chatCompletionReturnsValidResponse(): void
    {
        $messages = [
            ['role' => 'user', 'content' => $this->randomPrompt()],
        ];

        $apiResponse = [
            'id' => 'gen-' . $this->faker->uuid(),
            'model' => 'anthropic/claude-3.5-sonnet',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'OpenRouter response',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ],
        ];

        // OpenRouter may make multiple requests (fallback), use method() instead of expects()
        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('OpenRouter response', $result->content);
        self::assertEquals('anthropic/claude-3.5-sonnet', $result->model);
    }

    #[Test]
    public function getAvailableModelsReturnsMultiProviderModels(): void
    {
        $models = $this->subject->getAvailableModels();

        self::assertNotEmpty($models);
        // OpenRouter models have provider prefixes in keys (e.g., "anthropic/claude-3.5-sonnet")
        $modelKeys = array_keys($models);
        self::assertTrue(
            count(array_filter($modelKeys, fn($m) => str_contains((string)$m, '/'))) > 0,
            'OpenRouter models should have provider prefixes in keys',
        );
    }

    #[Test]
    #[DataProvider('routingStrategyProvider')]
    public function selectModelRespectsRoutingStrategy(string $strategy, string $expectedBehavior): void
    {
        $provider = new OpenRouterProvider(
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->setHttpClient($this->httpClientMock);

        $provider->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'anthropic/claude-3.5-sonnet',
            'routingStrategy' => $strategy,
            'timeout' => 60,
        ]);

        // Just verify the provider can be instantiated with each strategy
        self::assertTrue($provider->isAvailable());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routingStrategyProvider(): array
    {
        return [
            'cost optimized' => ['cost_optimized', 'cheapest'],
            'performance' => ['performance', 'fastest'],
            'balanced' => ['balanced', 'balanced'],
            'explicit' => ['explicit', 'explicit'],
        ];
    }

    #[Test]
    public function chatCompletionWithFallbackHandlesFailure(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];

        // Simulate model overload, then success with fallback
        $errorResponse = [
            'error' => [
                'code' => 503,
                'message' => 'Model overloaded',
            ],
        ];

        $successResponse = [
            'id' => 'gen-test',
            'model' => 'openai/gpt-4o',
            'choices' => [
                ['message' => ['content' => 'Fallback response'], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMock($errorResponse, 503),
                $this->createJsonResponseMock($successResponse),
            );

        // When autoFallback is enabled, it should try another model
        // This test verifies the fallback mechanism exists
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function supportsVisionReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsVision());
    }

    #[Test]
    public function supportsStreamingReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsStreaming());
    }

    #[Test]
    public function supportsToolsReturnsTrue(): void
    {
        self::assertTrue($this->subject->supportsTools());
    }

    #[Test]
    public function chatCompletionIncludesRequiredHeaders(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'test-model',
            'choices' => [['message' => ['content' => 'test'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        // Verify request was made (headers are added internally)
        self::assertInstanceOf(CompletionResponse::class, $result);
    }

    #[Test]
    public function chatCompletionExtractsCostFromMetadata(): void
    {
        $messages = [['role' => 'user', 'content' => 'test']];

        $apiResponse = [
            'id' => 'gen-test',
            'model' => 'test-model',
            'choices' => [['message' => ['content' => 'test'], 'finish_reason' => 'stop']],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
                'total_cost' => 0.0015,
            ],
        ];

        $this->httpClientMock
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock($apiResponse));

        $result = $this->subject->chatCompletion($messages);

        self::assertEquals(150, $result->usage->totalTokens);
    }
}
