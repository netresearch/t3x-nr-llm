<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\OllamaProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Covers the `ToolCapableInterface` slice of {@see OllamaProvider}: tool
 * serialisation into the `/api/chat` payload, id synthesis on the parsed
 * `message.tool_calls`, and the inbound translation of replayed OpenAI-shape
 * turns into Ollama's native shape.
 */
#[CoversClass(OllamaProvider::class)]
class OllamaProviderToolsTest extends AbstractUnitTestCase
{
    /**
     * Captures the JSON request body the provider hands to the stream factory
     * so the outbound payload shaping can be asserted directly.
     */
    private ?string $capturedBody = null;

    #[Test]
    public function supportsToolsReturnsTrue(): void
    {
        self::assertTrue($this->buildSubject([])->supportsTools());
    }

    #[Test]
    public function serialisesToolsIntoPayload(): void
    {
        $subject = $this->buildSubject($this->plainAssistantResponse('done'));

        $tool = ToolSpec::function('fetch_logs', 'Fetch recent log entries', [
            'type'       => 'object',
            'properties' => ['limit' => ['type' => 'integer']],
        ]);

        $subject->chatCompletionWithTools([['role' => 'user', 'content' => 'show logs']], [$tool]);

        $payload = $this->capturedRequest();
        self::assertArrayHasKey('tools', $payload);

        $tools = $payload['tools'];
        self::assertIsArray($tools);
        $first = $tools[0] ?? null;
        self::assertIsArray($first);
        $function = $first['function'] ?? null;
        self::assertIsArray($function);
        self::assertSame('fetch_logs', $function['name'] ?? null);
    }

    #[Test]
    public function parsesToolCallsAndSynthesisesIds(): void
    {
        $subject = $this->buildSubject([
            'model'   => 'llama3.2',
            'message' => [
                'role'       => 'assistant',
                'content'    => '',
                'tool_calls' => [
                    ['function' => ['name' => 'fetch_logs', 'arguments' => ['limit' => 5]]],
                ],
            ],
            'done'              => true,
            'done_reason'       => 'stop',
            'prompt_eval_count' => 3,
            'eval_count'        => 4,
        ]);

        $result = $subject->chatCompletionWithTools([['role' => 'user', 'content' => 'logs']], []);

        self::assertTrue($result->hasToolCalls());
        self::assertNotNull($result->toolCalls);

        $call = $result->toolCalls[0];
        self::assertSame('call_0', $call->id);
        self::assertSame('fetch_logs', $call->name);
        self::assertSame(['limit' => 5], $call->arguments);
    }

    #[Test]
    public function translatesReplayedAssistantAndToolTurnsToNativeShape(): void
    {
        $subject = $this->buildSubject($this->plainAssistantResponse('ok'));

        $messages = [
            ['role' => 'user', 'content' => 'show logs'],
            [
                'role'       => 'assistant',
                'content'    => '',
                'tool_calls' => [
                    [
                        'id'       => 'call_0',
                        'type'     => 'function',
                        'function' => ['name' => 'fetch_logs', 'arguments' => '{"limit":5}'],
                    ],
                ],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_0', 'content' => 'LOGS'],
        ];

        $subject->chatCompletionWithTools($messages, []);

        $payload = $this->capturedRequest();
        $sent    = $payload['messages'];
        self::assertIsArray($sent);

        // Assistant turn: the JSON-string arguments are decoded to an object.
        $assistant = $sent[1] ?? null;
        self::assertIsArray($assistant);
        $calls = $assistant['tool_calls'] ?? null;
        self::assertIsArray($calls);
        $firstCall = $calls[0] ?? null;
        self::assertIsArray($firstCall);
        $function = $firstCall['function'] ?? null;
        self::assertIsArray($function);
        self::assertSame(['limit' => 5], $function['arguments'] ?? null);

        // Tool turn: the OpenAI-only tool_call_id key is dropped.
        $toolTurn = $sent[2] ?? null;
        self::assertIsArray($toolTurn);
        self::assertArrayNotHasKey('tool_call_id', $toolTurn);
        self::assertSame('LOGS', $toolTurn['content'] ?? null);
    }

    #[Test]
    public function nonToolResponseReturnsPlainContent(): void
    {
        $subject = $this->buildSubject($this->plainAssistantResponse('plain answer'));

        $result = $subject->chatCompletionWithTools([['role' => 'user', 'content' => 'hi']], []);

        self::assertFalse($result->hasToolCalls());
        self::assertSame('plain answer', $result->content);
    }

    /**
     * Build an Ollama provider whose stream factory records the outgoing JSON
     * request body and whose HTTP client returns the given canned response.
     *
     * @param array<string, mixed> $apiResponse
     */
    private function buildSubject(array $apiResponse): OllamaProvider
    {
        $this->capturedBody = null;

        $streamFactory = self::createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content): StreamInterface {
                $this->capturedBody = $content;
                $stream             = self::createStub(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);
                $stream->method('getContents')->willReturn($content);
                return $stream;
            },
        );

        $subject = new OllamaProvider(
            $this->createRequestFactoryMock(),
            $streamFactory,
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => '',
            'defaultModel'     => 'llama3.2',
            'baseUrl'          => 'http://localhost:11434',
            'timeout'          => 30,
        ]);

        $httpClient = $this->createHttpClientMock();
        $httpClient->method('sendRequest')->willReturn($this->createJsonResponseMock($apiResponse));

        // setHttpClient must be called AFTER configure(), which resets the client.
        $subject->setHttpClient($httpClient);

        return $subject;
    }

    /**
     * Decode the captured outbound request body.
     *
     * @return array<string, mixed>
     */
    private function capturedRequest(): array
    {
        self::assertIsString($this->capturedBody);
        $decoded = json_decode($this->capturedBody, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A successful `/api/chat` response that carries only assistant content.
     *
     * @return array<string, mixed>
     */
    private function plainAssistantResponse(string $content): array
    {
        return [
            'model'   => 'llama3.2',
            'message' => ['role' => 'assistant', 'content' => $content],
            'done'              => true,
            'done_reason'       => 'stop',
            'prompt_eval_count' => 2,
            'eval_count'        => 3,
        ];
    }
}
