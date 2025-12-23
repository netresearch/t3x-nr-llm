<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Integration;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\NullLogger;

/**
 * Base class for integration tests
 *
 * Provides utilities for testing provider API interactions
 * with realistic HTTP responses.
 */
abstract class AbstractIntegrationTestCase extends AbstractUnitTestCase
{
    protected RequestFactoryInterface $requestFactory;
    protected StreamFactoryInterface $streamFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestFactory = new HttpFactory();
        $this->streamFactory = new HttpFactory();
    }

    /**
     * Create an HTTP client mock that returns sequential responses
     *
     * @param array<ResponseInterface> $responses
     */
    protected function createHttpClientWithResponses(array $responses): ClientInterface&MockObject
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')
            ->willReturnOnConsecutiveCalls(...$responses);

        return $client;
    }

    /**
     * Create a successful JSON response
     *
     * @param array<string, mixed> $body
     */
    protected function createSuccessResponse(array $body, int $statusCode = 200): ResponseInterface
    {
        return new Response(
            status: $statusCode,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Create an error response
     *
     * @param array<string, mixed> $body
     */
    protected function createErrorResponse(array $body, int $statusCode = 400): ResponseInterface
    {
        return new Response(
            status: $statusCode,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Create a mock HTTP client that captures request bodies
     *
     * @param ResponseInterface $response
     * @return array{client: ClientInterface&MockObject, requests: array<RequestInterface>}
     */
    protected function createRequestCapturingClient(ResponseInterface $response): array
    {
        $requests = [];
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use ($response, &$requests) {
                $requests[] = $request;
                return $response;
            });

        return ['client' => $client, 'requests' => &$requests];
    }

    /**
     * Get standard OpenAI-style chat completion response
     */
    protected function getOpenAiChatResponse(
        string $content = 'Test response',
        string $model = 'gpt-4o',
        string $finishReason = 'stop'
    ): array {
        return [
            'id' => 'chatcmpl-' . $this->faker->uuid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => [
                'prompt_tokens' => $this->faker->numberBetween(10, 100),
                'completion_tokens' => $this->faker->numberBetween(20, 200),
                'total_tokens' => $this->faker->numberBetween(30, 300),
            ],
        ];
    }

    /**
     * Get standard Claude-style chat completion response
     */
    protected function getClaudeChatResponse(
        string $content = 'Test response',
        string $model = 'claude-sonnet-4-20250514',
        string $stopReason = 'end_turn'
    ): array {
        return [
            'id' => 'msg_' . $this->faker->uuid(),
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $content,
                ],
            ],
            'model' => $model,
            'stop_reason' => $stopReason,
            'usage' => [
                'input_tokens' => $this->faker->numberBetween(10, 100),
                'output_tokens' => $this->faker->numberBetween(20, 200),
            ],
        ];
    }

    /**
     * Get standard Gemini-style chat completion response
     */
    protected function getGeminiChatResponse(
        string $content = 'Test response',
        string $model = 'gemini-1.5-pro'
    ): array {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => $content],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'safetyRatings' => [],
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => $this->faker->numberBetween(10, 100),
                'candidatesTokenCount' => $this->faker->numberBetween(20, 200),
                'totalTokenCount' => $this->faker->numberBetween(30, 300),
            ],
            'modelVersion' => $model,
        ];
    }

    /**
     * Get standard OpenAI-style embedding response
     */
    protected function getOpenAiEmbeddingResponse(int $dimensions = 1536): array
    {
        return [
            'object' => 'list',
            'data' => [
                [
                    'object' => 'embedding',
                    'index' => 0,
                    'embedding' => array_map(
                        fn() => $this->faker->randomFloat(8, -1, 1),
                        range(1, $dimensions)
                    ),
                ],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => [
                'prompt_tokens' => $this->faker->numberBetween(5, 50),
                'total_tokens' => $this->faker->numberBetween(5, 50),
            ],
        ];
    }

    /**
     * Get standard error response
     */
    protected function getErrorResponse(string $message, string $type = 'invalid_request_error'): array
    {
        return [
            'error' => [
                'message' => $message,
                'type' => $type,
                'param' => null,
                'code' => null,
            ],
        ];
    }

    protected function createNullLogger(): NullLogger
    {
        return new NullLogger();
    }
}
