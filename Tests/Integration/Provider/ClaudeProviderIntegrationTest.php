<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Integration\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Tests\Integration\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for Claude provider.
 *
 * Tests realistic API interactions with mocked HTTP responses.
 */
#[CoversClass(ClaudeProvider::class)]
class ClaudeProviderIntegrationTest extends AbstractIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    private function createProvider(array $responses): ClaudeProvider
    {
        $httpClient = $this->createHttpClientWithResponses($responses);

        $provider = new ClaudeProvider(
            $httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger(),
        );

        $provider->configure([
            'apiKey' => 'sk-ant-test-' . $this->faker->sha256(),
            'defaultModel' => 'claude-sonnet-4-20250514',
            'timeout' => 30,
        ]);

        return $provider;
    }

    #[Test]
    public function chatCompletionWithRealisticResponse(): void
    {
        $responseData = $this->getClaudeChatResponse(
            content: 'The capital of France is Paris.',
            model: 'claude-sonnet-4-20250514',
            stopReason: 'end_turn',
        );

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion([
            ['role' => 'user', 'content' => 'What is the capital of France?'],
        ]);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('The capital of France is Paris.', $result->content);
        self::assertEquals('claude-sonnet-4-20250514', $result->model);
        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function chatCompletionWithSystemPrompt(): void
    {
        $responseData = $this->getClaudeChatResponse(
            content: 'Bonjour! Je suis ravi de vous aider avec le français.',
        );

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion([
            ['role' => 'system', 'content' => 'You are a French tutor. Always respond in French.'],
            ['role' => 'user', 'content' => 'Hello, can you help me learn French?'],
        ]);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertStringContainsString('Bonjour', $result->content);
    }

    #[Test]
    public function chatCompletionWithMultipleContentBlocks(): void
    {
        $responseData = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'First part of the response. '],
                ['type' => 'text', 'text' => 'Second part of the response.'],
            ],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 50,
                'output_tokens' => 100,
            ],
        ];

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion([
            ['role' => 'user', 'content' => 'Tell me something'],
        ]);

        self::assertEquals(
            'First part of the response. Second part of the response.',
            $result->content,
        );
    }

    #[Test]
    public function chatCompletionWithMaxTokensTruncation(): void
    {
        $responseData = $this->getClaudeChatResponse(
            content: 'This response was truncated',
            stopReason: 'max_tokens',
        );

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Write a long essay']],
            ['max_tokens' => 50],
        );

        self::assertTrue($result->wasTruncated());
        self::assertFalse($result->isComplete());
    }

    #[Test]
    public function chatCompletionTracksUsage(): void
    {
        $responseData = [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Test']],
            'model' => 'claude-sonnet-4-20250514',
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
            ],
        ];

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        self::assertEquals(100, $result->usage->promptTokens);
        self::assertEquals(50, $result->usage->completionTokens);
        self::assertEquals(150, $result->usage->totalTokens);
    }

    #[Test]
    public function handles401UnauthorizedError(): void
    {
        $errorResponse = [
            'type' => 'error',
            'error' => [
                'type' => 'authentication_error',
                'message' => 'Invalid API key',
            ],
        ];

        $provider = $this->createProvider([
            $this->createErrorResponse($errorResponse, 401),
        ]);

        $this->expectException(ProviderResponseException::class);

        $provider->chatCompletion([
            ['role' => 'user', 'content' => 'Hello'],
        ]);
    }

    #[Test]
    public function handles429RateLimitError(): void
    {
        $errorResponse = [
            'type' => 'error',
            'error' => [
                'type' => 'rate_limit_error',
                'message' => 'Rate limit exceeded',
            ],
        ];

        $provider = $this->createProvider([
            $this->createErrorResponse($errorResponse, 429),
        ]);

        $this->expectException(ProviderResponseException::class);

        $provider->chatCompletion([
            ['role' => 'user', 'content' => 'Hello'],
        ]);
    }

    #[Test]
    public function handles500ServerError(): void
    {
        $errorResponse = [
            'type' => 'error',
            'error' => [
                'type' => 'api_error',
                'message' => 'Internal server error',
            ],
        ];

        $response = $this->createErrorResponse($errorResponse, 500);

        // Provider has retry logic, provide 3 responses
        $provider = $this->createProvider([
            $response,
            $response,
            $response,
        ]);

        $this->expectException(ProviderConnectionException::class);

        $provider->chatCompletion([
            ['role' => 'user', 'content' => 'Hello'],
        ]);
    }

    #[Test]
    public function multipleMessagesInConversation(): void
    {
        $responseData = $this->getClaudeChatResponse(
            content: 'Yes, Paris has been the capital of France since...',
        );

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion([
            ['role' => 'user', 'content' => 'What is the capital of France?'],
            ['role' => 'assistant', 'content' => 'The capital of France is Paris.'],
            ['role' => 'user', 'content' => 'Is that correct?'],
        ]);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertStringContainsString('Paris', $result->content);
    }

    #[Test]
    public function mapsEndTurnToStopFinishReason(): void
    {
        $responseData = $this->getClaudeChatResponse(stopReason: 'end_turn');

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        self::assertEquals('stop', $result->finishReason);
    }

    #[Test]
    public function mapsMaxTokensToLengthFinishReason(): void
    {
        $responseData = $this->getClaudeChatResponse(stopReason: 'max_tokens');

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        self::assertEquals('length', $result->finishReason);
    }

    #[Test]
    public function supportsVisionFeature(): void
    {
        $provider = $this->createProvider([]);
        self::assertTrue($provider->supportsVision());
    }

    #[Test]
    public function supportsStreamingFeature(): void
    {
        $provider = $this->createProvider([]);
        self::assertTrue($provider->supportsStreaming());
    }

    #[Test]
    public function supportsToolsFeature(): void
    {
        $provider = $this->createProvider([]);
        self::assertTrue($provider->supportsTools());
    }
}
