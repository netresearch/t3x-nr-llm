<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Integration\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Integration\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for OpenAI provider.
 *
 * Tests realistic API interactions with mocked HTTP responses.
 */
#[CoversClass(OpenAiProvider::class)]
class OpenAiProviderIntegrationTest extends AbstractIntegrationTestCase
{
    private OpenAiProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function createProvider(array $responses): OpenAiProvider
    {
        $httpClient = $this->createHttpClientWithResponses($responses);

        $provider = new OpenAiProvider(
            $httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger(),
        );

        $provider->configure([
            'apiKey' => 'sk-test-' . $this->faker->sha256(),
            'defaultModel' => 'gpt-4o',
            'organizationId' => 'org-test',
            'timeout' => 30,
        ]);

        return $provider;
    }

    #[Test]
    public function chatCompletionWithRealisticResponse(): void
    {
        $responseData = $this->getOpenAiChatResponse(
            content: 'The capital of France is Paris.',
            model: 'gpt-4o',
            finishReason: 'stop',
        );

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion([
            ['role' => 'user', 'content' => 'What is the capital of France?'],
        ]);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertEquals('The capital of France is Paris.', $result->content);
        self::assertEquals('gpt-4o', $result->model);
        self::assertEquals('stop', $result->finishReason);
        self::assertTrue($result->isComplete());
        self::assertFalse($result->wasTruncated());
    }

    #[Test]
    public function chatCompletionWithSystemPrompt(): void
    {
        $responseData = $this->getOpenAiChatResponse(
            content: 'Bonjour! Comment allez-vous?',
        );

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion([
            ['role' => 'system', 'content' => 'You are a French tutor. Always respond in French.'],
            ['role' => 'user', 'content' => 'Say hello'],
        ]);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertStringContainsString('Bonjour', $result->content);
    }

    #[Test]
    public function chatCompletionWithMaxTokensTruncation(): void
    {
        $responseData = $this->getOpenAiChatResponse(
            content: 'This response was truncated because it reached the maximum',
            finishReason: 'length',
        );

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Write a very long story']],
            ['max_tokens' => 50],
        );

        self::assertTrue($result->wasTruncated());
        self::assertFalse($result->isComplete());
    }

    #[Test]
    public function chatCompletionTracksUsage(): void
    {
        $responseData = [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'gpt-4o',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Test'],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
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
    public function embeddingsWithRealisticResponse(): void
    {
        $responseData = $this->getOpenAiEmbeddingResponse(dimensions: 1536);

        $provider = $this->createProvider([
            $this->createSuccessResponse($responseData),
        ]);

        $result = $provider->embeddings('Test text for embedding');

        self::assertInstanceOf(EmbeddingResponse::class, $result);
        self::assertCount(1, $result->embeddings);
        self::assertCount(1536, $result->embeddings[0]);
        self::assertEquals('text-embedding-3-small', $result->model);
    }

    #[Test]
    public function handles401UnauthorizedError(): void
    {
        $errorResponse = [
            'error' => [
                'message' => 'Incorrect API key provided: sk-test-***. You can find your API key at https://platform.openai.com/account/api-keys.',
                'type' => 'invalid_request_error',
                'param' => null,
                'code' => 'invalid_api_key',
            ],
        ];

        $provider = $this->createProvider([
            $this->createErrorResponse($errorResponse, 401),
        ]);

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('Incorrect API key');

        $provider->chatCompletion([
            ['role' => 'user', 'content' => 'Hello'],
        ]);
    }

    #[Test]
    public function handles429RateLimitError(): void
    {
        $errorResponse = [
            'error' => [
                'message' => 'Rate limit exceeded. Please retry after 20 seconds.',
                'type' => 'rate_limit_error',
                'param' => null,
                'code' => 'rate_limit_exceeded',
            ],
        ];

        $provider = $this->createProvider([
            $this->createErrorResponse($errorResponse, 429),
        ]);

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('Rate limit');

        $provider->chatCompletion([
            ['role' => 'user', 'content' => 'Hello'],
        ]);
    }

    #[Test]
    public function handles500ServerError(): void
    {
        $errorResponse = [
            'error' => [
                'message' => 'The server had an error while processing your request.',
                'type' => 'server_error',
                'param' => null,
                'code' => null,
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
        $responseData = $this->getOpenAiChatResponse(
            content: 'That is correct! Paris has been the capital of France for centuries.',
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
    public function temperatureOptionAffectsRequest(): void
    {
        $responseData = $this->getOpenAiChatResponse();

        $clientSetup = $this->createRequestCapturingClient(
            $this->createSuccessResponse($responseData),
        );

        $provider = new OpenAiProvider(
            $clientSetup['client'],
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger(),
        );

        $provider->configure([
            'apiKey' => 'sk-test',
            'defaultModel' => 'gpt-4o',
            'timeout' => 30,
        ]);

        $provider->chatCompletion(
            [['role' => 'user', 'content' => 'Hello']],
            ['temperature' => 0.2],
        );

        self::assertCount(1, $clientSetup['requests']);
        $request = $clientSetup['requests'][0];
        $body = json_decode((string)$request->getBody(), true);

        self::assertEquals(0.2, $body['temperature']);
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
