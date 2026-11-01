<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Closure;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiCallMetadata;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiModelProfiles;
use Netresearch\NrLlm\Provider\OpenAi\ResponsesPayloadBuilder;
use Netresearch\NrLlm\Provider\OpenAi\ResponsesResultParser;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A tool round on the Responses transport, streamed (ADR-240).
 *
 * The event sequence and the shape of the items are what gpt-6-sol sent on
 * 2026-11-01: `response.created`, `response.in_progress`, the output item and
 * content part events, the text deltas, the argument deltas of a call, and
 * `response.completed` carrying the same `response` object the blocking call
 * returns.
 */
#[CoversClass(OpenAiProvider::class)]
#[UsesClass(ResponsesPayloadBuilder::class)]
#[UsesClass(ResponsesResultParser::class)]
#[UsesClass(OpenAiModelProfiles::class)]
final class OpenAiResponsesStreamingTest extends AbstractUnitTestCase
{
    /** @var list<string> */
    private array $text = [];

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    #[Test]
    public function theTextReachesTheSinkAndTheResponseEqualsTheBlockingOne(): void
    {
        $final    = $this->finalResponse('Ich lese die Seite.', [$this->functionCall()]);
        $streamed = $this->streamingSubject($this->eventsWithText(['Ich lese ', 'die Seite.'], $final))
            ->chatCompletionWithToolsStreamingText(...$this->call());

        self::assertSame(['Ich lese ', 'die Seite.'], $this->text);

        $blocking = $this->blockingSubject([json_encode($final, JSON_THROW_ON_ERROR)])
            ->chatCompletionWithTools($this->call()[0], $this->call()[1], $this->call()[2]);

        self::assertSame($blocking->content, $streamed->content);
        self::assertSame($blocking->finishReason, $streamed->finishReason);
        self::assertSame($blocking->usage->promptTokens, $streamed->usage->promptTokens);
        self::assertSame($blocking->usage->completionTokens, $streamed->usage->completionTokens);
        self::assertEquals($blocking->toolCalls, $streamed->toolCalls);
        self::assertSame($this->itemsOf($blocking), $this->itemsOf($streamed));
        self::assertSame(12153, $streamed->metadata[OpenAiCallMetadata::KEY_CACHED_PROMPT_TOKENS] ?? null);
    }

    #[Test]
    public function aRoundThatOnlyCallsAToolSendsNoTextAndReturnsTheCall(): void
    {
        $response = $this->streamingSubject($this->eventsWithText([], $this->finalResponse('', [$this->functionCall()])))
            ->chatCompletionWithToolsStreamingText(...$this->call());

        self::assertSame([], $this->text);
        self::assertSame('tool_calls', $response->finishReason);
        self::assertCount(1, $response->toolCalls ?? []);
    }

    #[Test]
    public function theRequestAsksForAStreamAndNamesNoOtherEndpoint(): void
    {
        $this->streamingSubject($this->eventsWithText(['ok'], $this->finalResponse('ok')))
            ->chatCompletionWithToolsStreamingText(...$this->call());

        self::assertTrue($this->sent[0]['stream'] ?? null);
        self::assertFalse($this->sent[0]['store'] ?? null);
        self::assertSame('gpt-6-sol', $this->sent[0]['model'] ?? null);
    }

    #[Test]
    public function anEventSplitAcrossTwoReadsIsReadWhole(): void
    {
        $events = $this->eventsWithText(['Hallo'], $this->finalResponse('Hallo'));
        $cut    = (int) strpos($events, '"Hallo"') + 3;

        $this->streamingSubject([substr($events, 0, $cut), substr($events, $cut)])
            ->chatCompletionWithToolsStreamingText(...$this->call());

        self::assertSame(['Hallo'], $this->text);
    }

    #[Test]
    public function aLineThatIsNoJsonIsSkipped(): void
    {
        $response = $this->streamingSubject("data: {broken\n\n" . $this->eventsWithText(['ok'], $this->finalResponse('ok')))
            ->chatCompletionWithToolsStreamingText(...$this->call());

        self::assertSame('ok', $response->content);
    }

    #[Test]
    public function aTruncatedReplyReportsLengthLikeTheBlockingCall(): void
    {
        $final = $this->finalResponse('abge', [], ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']]);

        $response = $this->streamingSubject($this->eventsWithText(['abge'], $final, 'response.incomplete'))
            ->chatCompletionWithToolsStreamingText(...$this->call());

        self::assertSame('length', $response->finishReason);
    }

    #[Test]
    public function aFailedStreamRaisesTheProvidersReason(): void
    {
        $final = $this->finalResponse('', [], ['status' => 'failed', 'error' => ['message' => 'overloaded']]);

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('overloaded');

        $this->streamingSubject($this->eventsWithText([], $final, 'response.failed'))
            ->chatCompletionWithToolsStreamingText(...$this->call());
    }

    #[Test]
    public function anErrorEventRaisesItsMessage(): void
    {
        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('rate limited');

        $this->streamingSubject('data: ' . json_encode(['type' => 'error', 'message' => 'rate limited'], JSON_THROW_ON_ERROR) . "\n\n")
            ->chatCompletionWithToolsStreamingText(...$this->call());
    }

    #[Test]
    public function aConnectionThatFailsBeforeAnyTextGoesBlocking(): void
    {
        $subject = $this->subjectWith(function (): ResponseInterface {
            if ($this->sent === [] || ($this->sent[count($this->sent) - 1]['stream'] ?? false) === true) {
                throw $this->networkFailure();
            }

            return $this->createHttpResponseMock(200, json_encode($this->finalResponse('Antwort'), JSON_THROW_ON_ERROR));
        });

        $response = $subject->chatCompletionWithToolsStreamingText(...$this->call());

        self::assertSame('Antwort', $response->content);
        self::assertSame([true, null], array_map(static fn (array $sent): mixed => $sent['stream'] ?? null, $this->sent));
    }

    #[Test]
    public function aStreamThatBreaksAfterTextRaisesInsteadOfSendingItAgain(): void
    {
        $body = 'data: ' . json_encode(['type' => 'response.output_text.delta', 'delta' => 'Hal'], JSON_THROW_ON_ERROR) . "\n\n";

        try {
            $this->streamingSubject($body)->chatCompletionWithToolsStreamingText(...$this->call());
            self::fail('A stream without its end was taken for a response.');
        } catch (ProviderConnectionException) {
            self::assertSame(['Hal'], $this->text);
            self::assertCount(1, $this->sent);
        }
    }

    #[Test]
    public function aStreamThatOpenedAndEndedWithoutAResultIsNotSentAgain(): void
    {
        $this->expectException(ProviderConnectionException::class);

        try {
            $this->streamingSubject("data: {}\n\n")->chatCompletionWithToolsStreamingText(...$this->call());
        } finally {
            self::assertCount(1, $this->sent);
        }
    }

    #[Test]
    public function aFailureAtTheTimeoutIsNotSentAgain(): void
    {
        $subject = $this->subjectWith(function (): ResponseInterface {
            usleep(600_000);

            throw $this->networkFailure();
        });
        // The call lasts longer than one second less the tolerance.
        $call               = $this->call();
        $call[2]['timeout'] = 1;

        try {
            $subject->chatCompletionWithToolsStreamingText(...$call);
            self::fail('The failure did not reach the caller.');
        } catch (NetworkExceptionInterface) {
            self::assertCount(1, $this->sent);
        }
    }

    #[Test]
    public function anOverloadReportedInTheStreamBeforeAnyTextGoesBlocking(): void
    {
        $subject = $this->subjectWith(function (): ResponseInterface {
            if (($this->sent[count($this->sent) - 1]['stream'] ?? false) === true) {
                return $this->streamResponse(['data: ' . json_encode(['type' => 'error', 'code' => 'server_error', 'message' => 'busy'], JSON_THROW_ON_ERROR) . "\n\n"]);
            }

            return $this->createHttpResponseMock(200, json_encode($this->finalResponse('Antwort'), JSON_THROW_ON_ERROR));
        });

        self::assertSame('Antwort', $subject->chatCompletionWithToolsStreamingText(...$this->call())->content);
    }

    #[Test]
    public function anOverloadAfterTextIsAConnectionFailureThePipelineCanAnswer(): void
    {
        $body = 'data: ' . json_encode(['type' => 'response.output_text.delta', 'delta' => 'Hal'], JSON_THROW_ON_ERROR) . "\n\n"
            . 'data: ' . json_encode(['type' => 'error', 'code' => 'rate_limit_exceeded', 'message' => 'slow down'], JSON_THROW_ON_ERROR) . "\n\n";

        $this->expectException(ProviderConnectionException::class);
        $this->expectExceptionMessage('slow down');

        $this->streamingSubject($body)->chatCompletionWithToolsStreamingText(...$this->call());
    }

    #[Test]
    public function aFailedResponseWithAnOverloadCodeGoesBlocking(): void
    {
        $failed  = $this->finalResponse('', [], ['status' => 'failed', 'error' => ['code' => 'server_error', 'message' => 'busy']]);
        $subject = $this->subjectWith(function () use ($failed): ResponseInterface {
            if (($this->sent[count($this->sent) - 1]['stream'] ?? false) === true) {
                return $this->streamResponse([$this->eventsWithText([], $failed, 'response.failed')]);
            }

            return $this->createHttpResponseMock(200, json_encode($this->finalResponse('Antwort'), JSON_THROW_ON_ERROR));
        });

        self::assertSame('Antwort', $subject->chatCompletionWithToolsStreamingText(...$this->call())->content);
    }

    #[Test]
    public function aSecretThatCannotBeReadGoesBlocking(): void
    {
        $subject = $this->subjectWith(function (): ResponseInterface {
            if (($this->sent[count($this->sent) - 1]['stream'] ?? false) === true) {
                throw new \Netresearch\NrVault\Exception\SecretNotFoundException('gone', 1);
            }

            return $this->createHttpResponseMock(200, json_encode($this->finalResponse('Antwort'), JSON_THROW_ON_ERROR));
        });

        self::assertSame('Antwort', $subject->chatCompletionWithToolsStreamingText(...$this->call())->content);
    }

    #[Test]
    public function aModelOnChatCompletionsDoesNotStream(): void
    {
        $subject = $this->streamingSubject('');

        self::assertFalse($subject->streamsToolCalls(['model' => 'gpt-4.1-mini']));
        self::assertTrue($subject->streamsToolCalls(['model' => 'gpt-6-sol']));
    }

    #[Test]
    public function theCallsTimeIsReadableOnTheResponse(): void
    {
        $response = $this->streamingSubject($this->eventsWithText(['ok'], $this->finalResponse('ok')), ['openai-processing-ms' => ['162']])
            ->chatCompletionWithToolsStreamingText(...$this->call());

        self::assertIsFloat($response->metadata[OpenAiCallMetadata::KEY_HTTP_MS] ?? null);
        // That header left before the model wrote anything.
        self::assertArrayNotHasKey(OpenAiCallMetadata::KEY_PROVIDER_PROCESSING_MS, $response->metadata);
    }

    #[Test]
    public function anAccountThatMayNotStreamGoesBlocking(): void
    {
        $subject = $this->subjectWith(function (): ResponseInterface {
            if (($this->sent[count($this->sent) - 1]['stream'] ?? false) === true) {
                return $this->createHttpResponseMock(400, json_encode(['error' => ['message' => 'stream not allowed']], JSON_THROW_ON_ERROR));
            }

            return $this->createHttpResponseMock(200, json_encode($this->finalResponse('Antwort'), JSON_THROW_ON_ERROR));
        });

        self::assertSame('Antwort', $subject->chatCompletionWithToolsStreamingText(...$this->call())->content);
    }

    /**
     * @return array{list<ChatMessage>, list<ToolSpec>, array<string, mixed>, Closure(string): void}
     */
    private function call(): array
    {
        return [
            [ChatMessage::user('Lies die Seite.')],
            [new ToolSpec(name: 'read_records', description: 'Reads records.', parameters: ['type' => 'object'])],
            ['model' => 'gpt-6-sol'],
            function (string $delta): void {
                $this->text[] = $delta;
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function functionCall(): array
    {
        return ['id' => 'fc_1', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"table":"pages"}', 'call_id' => 'call_1', 'name' => 'read_records'];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $overrides
     *
     * @return array<string, mixed>
     */
    private function finalResponse(string $text, array $items = [], array $overrides = []): array
    {
        $message = $text === '' ? [] : [[
            'id'      => 'msg_1',
            'type'    => 'message',
            'status'  => 'completed',
            'phase'   => 'commentary',
            'role'    => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
        ]];

        return [
            'id'     => 'resp_1',
            'object' => 'response',
            'status' => 'completed',
            'model'  => 'gpt-6-sol',
            'output' => [
                ['id' => 'rs_1', 'type' => 'reasoning', 'content' => [], 'encrypted_content' => 'gAAAAA', 'summary' => []],
                ...$message,
                ...$items,
            ],
            'usage' => [
                'input_tokens'         => 12156,
                'input_tokens_details' => ['cache_write_tokens' => 0, 'cached_tokens' => 12153],
                'output_tokens'        => 17,
                'total_tokens'         => 12173,
            ],
            ...$overrides,
        ];
    }

    /**
     * @param list<string>         $deltas
     * @param array<string, mixed> $final
     */
    private function eventsWithText(array $deltas, array $final, string $end = 'response.completed'): string
    {
        $events = [
            ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'status' => 'in_progress']],
            ['type' => 'response.in_progress', 'response' => ['id' => 'resp_1', 'status' => 'in_progress']],
            ['type' => 'response.output_item.added', 'item' => ['id' => 'msg_1', 'type' => 'message']],
            ['type' => 'response.content_part.added', 'part' => ['type' => 'output_text', 'text' => '']],
        ];
        foreach ($deltas as $delta) {
            $events[] = ['type' => 'response.output_text.delta', 'delta' => $delta];
        }

        $events[] = ['type' => 'response.output_text.done', 'text' => implode('', $deltas)];
        $events[] = ['type' => 'response.function_call_arguments.delta', 'delta' => '{"table'];
        $events[] = ['type' => $end, 'response' => $final];

        $wire = '';
        foreach ($events as $event) {
            $wire .= 'event: ' . $event['type'] . "\n" . 'data: ' . json_encode($event, JSON_THROW_ON_ERROR) . "\n\n";
        }

        return $wire;
    }

    /**
     * @param string|list<string>         $body    one string, or the pieces single reads return
     * @param array<string, list<string>> $headers
     */
    private function streamingSubject(string|array $body, array $headers = []): OpenAiProvider
    {
        return $this->subjectWith(fn (): ResponseInterface => $this->streamResponse(is_array($body) ? $body : [$body], $headers));
    }

    /**
     * @param list<string> $bodies
     */
    private function blockingSubject(array $bodies): OpenAiProvider
    {
        return $this->subjectWith(function () use (&$bodies): ResponseInterface {
            return $this->createHttpResponseMock(200, array_shift($bodies) ?? '{}');
        });
    }

    /**
     * @param callable(): ResponseInterface $reply
     */
    private function subjectWith(callable $reply): OpenAiProvider
    {
        $subject = new OpenAiProvider(
            $this->createRequestFactoryMock(),
            $this->bodyRecordingStreamFactory(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel'     => 'gpt-6-sol',
            'baseUrl'          => '',
            'timeout'          => 30,
        ]);

        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(static fn (RequestInterface $request): ResponseInterface => $reply());
        $subject->setHttpClient($client);

        return $subject;
    }

    private function bodyRecordingStreamFactory(): \Psr\Http\Message\StreamFactoryInterface
    {
        $factory = self::createStub(\Psr\Http\Message\StreamFactoryInterface::class);
        $factory->method('createStream')->willReturnCallback(function (string $content): StreamInterface {
            /** @var array<string, mixed> $decoded */
            $decoded      = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $this->sent[] = $decoded;

            return self::createStub(StreamInterface::class);
        });

        return $factory;
    }

    /**
     * @param list<string>                $pieces
     * @param array<string, list<string>> $headers
     */
    private function streamResponse(array $pieces, array $headers = []): ResponseInterface
    {
        $stream = self::createStub(StreamInterface::class);
        $stream->method('eof')->willReturnCallback(static function () use (&$pieces): bool {
            return $pieces === [];
        });
        $stream->method('read')->willReturnCallback(static function () use (&$pieces): string {
            return array_shift($pieces) ?? '';
        });

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeader')->willReturnCallback(static fn (string $name): array => $headers[$name] ?? []);

        return $response;
    }

    /**
     * @return list<mixed>
     */
    private function itemsOf(CompletionResponse $response): array
    {
        $items = $response->metadata[OpenAiCallMetadata::KEY_PROVIDER_ITEMS] ?? [];
        self::assertIsArray($items);

        return array_values($items);
    }

    private function networkFailure(): NetworkExceptionInterface
    {
        return new class ('connection refused') extends RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                throw new RuntimeException('not needed');
            }
        };
    }
}
