<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\E2E;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Override;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\NullLogger;

/**
 * Base class for End-to-End tests.
 *
 * E2E tests verify complete workflows from service entry point
 * through to response handling, using mocked HTTP clients to
 * simulate external API interactions.
 */
abstract class AbstractE2ETestCase extends AbstractUnitTestCase
{
    protected RequestFactoryInterface $requestFactory;
    protected StreamFactoryInterface $streamFactory;
    protected NullLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->requestFactory = new HttpFactory();
        $this->streamFactory = new HttpFactory();
        $this->logger = new NullLogger();
    }

    /**
     * Create a stub HTTP client that returns sequential responses.
     *
     * @param list<ResponseInterface> $responses
     */
    protected function createMockHttpClient(array $responses): ClientInterface&Stub
    {
        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')
            ->willReturnOnConsecutiveCalls(...$responses);

        return $client;
    }

    /**
     * Create a request-capturing HTTP client.
     *
     * @return array{client: ClientInterface&Stub, requests: array<RequestInterface>}
     */
    protected function createCapturingHttpClient(ResponseInterface $response): array
    {
        $requests = [];
        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use ($response, &$requests) {
                $requests[] = $request;
                return $response;
            });

        return ['client' => $client, 'requests' => &$requests];
    }

    /**
     * Create a JSON success response.
     *
     * @param array<string, mixed> $data
     */
    protected function createJsonResponse(array $data, int $status = 200): ResponseInterface
    {
        return new Response(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Create OpenAI-style chat completion response.
     *
     * @return array<string, mixed>
     */
    protected function createOpenAiChatResponse(
        string $content,
        string $model = 'gpt-5',
        string $finishReason = 'stop',
        int $promptTokens = 50,
        int $completionTokens = 100,
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
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
        ];
    }

    /**
     * Create Claude-style chat completion response.
     *
     * @return array<string, mixed>
     */
    protected function createClaudeChatResponse(
        string $content,
        string $model = 'claude-sonnet-4-20250514',
        string $stopReason = 'end_turn',
        int $inputTokens = 50,
        int $outputTokens = 100,
    ): array {
        return [
            'id' => 'msg_' . $this->faker->uuid(),
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => $content],
            ],
            'model' => $model,
            'stop_reason' => $stopReason,
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
            ],
        ];
    }

    /**
     * Create OpenAI-style embedding response.
     *
     * @return array<string, mixed>
     */
    protected function createOpenAiEmbeddingResponse(int $dimensions = 1536): array
    {
        return [
            'object' => 'list',
            'data' => [
                [
                    'object' => 'embedding',
                    'index' => 0,
                    'embedding' => array_map(
                        fn() => $this->faker->randomFloat(8, -1, 1),
                        range(1, $dimensions),
                    ),
                ],
            ],
            'model' => 'text-embedding-3-small',
            'usage' => [
                'prompt_tokens' => 10,
                'total_tokens' => 10,
            ],
        ];
    }

    /**
     * Create translation API response.
     *
     * @return array<string, mixed>
     */
    protected function createTranslationResponse(
        string $translatedText,
        string $detectedLanguage = 'EN',
        string $provider = 'deepl',
    ): array {
        if ($provider === 'deepl') {
            return [
                'translations' => [
                    [
                        'detected_source_language' => $detectedLanguage,
                        'text' => $translatedText,
                    ],
                ],
            ];
        }

        // OpenAI-style translation response
        return $this->createOpenAiChatResponse($translatedText);
    }
}
