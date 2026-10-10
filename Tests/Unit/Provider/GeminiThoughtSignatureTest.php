<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Gemini\GeminiReplayMetadata;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiCallMetadata;
use Netresearch\NrLlm\Provider\OpenAi\ResponsesPayloadBuilder;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Throwable;

#[CoversClass(GeminiProvider::class)]
#[CoversClass(GeminiReplayMetadata::class)]
#[CoversClass(ResponsesPayloadBuilder::class)]
#[CoversClass(OpenAiProvider::class)]
final class GeminiThoughtSignatureTest extends AbstractUnitTestCase
{
    /**
     * @param list<array<string, mixed>> $parts
     */
    #[Test]
    #[DataProvider('signedToolTurns')]
    public function nextSerializedRequestReplaysTheOriginalSignedParts(
        array $parts,
    ): void {
        $body = null;
        $provider = new GeminiProvider(
            $this->createRequestFactoryMock($body),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure(
            [
                'apiKeyIdentifier' => 'gemini-key',
                'defaultModel' => 'gemini-3-flash-preview',
            ],
        );
        $http = $this->createHttpClientWithExpectations();
        $http
            ->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponseMock(
                    [
                        'candidates' => [
                            [
                                'content' => ['role' => 'model', 'parts' => $parts],
                                'finishReason' => 'STOP',
                            ],
                        ],
                    ],
                ),
                $this->createJsonResponseMock(
                    [
                        'candidates' => [
                            [
                                'content' => [
                                    'role' => 'model',
                                    'parts' => [['text' => 'Done.']],
                                ],
                                'finishReason' => 'STOP',
                            ],
                        ],
                    ],
                ),
            );
        $provider->setHttpClient($http);
        $messages = [ChatMessage::user('Check both locations.')];
        $tools = [
            ToolSpec::function(
                'weather',
                'Fetch weather.',
                [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                    'required' => ['city'],
                ],
            ),
        ];
        $first = $provider->chatCompletionWithTools($messages, $tools);
        self::assertNotNull($first->toolCalls);
        $messages[] = ChatMessage::assistantToolCalls(
            $first->toolCalls,
            $first->content,
            $this->replayItems($first),
        );
        foreach ($first->toolCalls as $call) {
            $messages[] = ChatMessage::toolResult($call->id, '{"temperature":12}');
        }

        $second = $provider->chatCompletionWithTools($messages, $tools);
        self::assertSame('Done.', $second->content);
        self::assertNotNull($body);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['role' => 'model', 'parts' => $parts],
            $payload['contents'][1],
            'The serialized next request must retain signatures on their exact original parts.',
        );
        self::assertSame(
            'weather',
            $payload['contents'][2]['parts'][0]['functionResponse']['name'],
        );
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function signedToolTurns(): iterable
    {
        yield 'single signed function call' => [
            [
                [
                    'functionCall' => ['name' => 'weather', 'args' => ['city' => 'Leipzig']],
                    'thoughtSignature' => 'opaque+/signature==',
                ],
            ],
        ];
        yield 'parallel calls with signature on the first part' => [
            [
                ['text' => 'I will check both.'],
                [
                    'functionCall' => ['name' => 'weather', 'args' => ['city' => 'Leipzig']],
                    'thoughtSignature' => 'first+/parallel==',
                ],
                [
                    'functionCall' => ['name' => 'weather', 'args' => ['city' => 'Berlin']],
                ],
            ],
        ];
    }

    #[Test]
    #[DataProvider('closingTransports')]
    public function closingRequestsKeepTheNativeTurn(
        string $transport,
        bool $stored,
    ): void {
        $body = null;
        $response = $transport === 'stream' ? new Response(
            200,
            [],
            'data: {"candidates":[{"content":{"parts":[{"text":"Done."}]}}]}' . "\n\n",
        ) : $this->createJsonResponseMock(
            [
                'candidates' => [['content' => ['parts' => [['text' => 'Done.']]]]],
            ],
        );
        $provider = $this->subject('gemini', [$response], $body);
        $parts = [
            ['text' => 'Before.', 'thoughtSignature' => 'text+/=='],
            [
                'functionCall' => ['name' => 'weather', 'args' => ['city' => 'Leipzig']],
                'thoughtSignature' => 'call+/==',
            ],
            ['text' => 'After.'],
        ];
        $messages = [
            ChatMessage::user('Check.'),
            ChatMessage::assistantToolCalls(
                [
                    ToolCall::function(
                        'call_stored',
                        'weather',
                        ['city' => 'Leipzig'],
                    ),
                ],
                'Visible.',
                [['type' => 'nrllm_gemini_generate_content', 'parts' => $parts]],
            ),
            ChatMessage::toolResult('call_stored', '{"temperature":12}'),
        ];
        if ($stored) {
            $messages = $this->storedTranscript($messages);
        }

        if ($transport === 'stream') {
            self::assertSame(
                ['Done.'],
                iterator_to_array($provider->streamChatCompletion($messages)),
            );
        } elseif ($transport === 'chat') {
            self::assertSame(
                'Done.',
                $provider->chatCompletion($messages)->content,
            );
        } else {
            self::assertSame(
                'Done.',
                $provider->chatCompletionWithTools(
                    $messages,
                    [
                        ToolSpec::function(
                            'weather',
                            'Weather.',
                            ['type' => 'object'],
                        ),
                    ],
                )->content,
            );
        }

        $payload = $this->payload($body);
        self::assertSame(
            ['role' => 'model', 'parts' => $parts],
            $payload['contents'][1],
        );
        self::assertSame(
            [
                'role' => 'user',
                'parts' => [
                    [
                        'functionResponse' => [
                            'name' => 'weather',
                            'response' => ['temperature' => 12],
                        ],
                    ],
                ],
            ],
            $payload['contents'][2],
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function closingTransports(): iterable
    {
        foreach (['chat', 'stream', 'tools'] as $transport) {
            yield $transport . ' typed' => [$transport, false];
            yield $transport . ' JSON transcript' => [$transport, true];
        }
    }

    #[Test]
    public function sequentialToolTurnsRetainBothSignedPartsAndResultNames(): void
    {
        $body = null;
        $firstParts = [
            [
                'functionCall' => ['name' => 'weather', 'args' => ['city' => 'Leipzig']],
                'thoughtSignature' => 'first+/==',
            ],
        ];
        $secondParts = [
            ['text' => 'Another step.'],
            [
                'functionCall' => ['name' => 'clock', 'args' => ['zone' => 'Europe/Berlin']],
                'thoughtSignature' => 'second+/==',
            ],
        ];
        $provider = $this->subject(
            'gemini',
            [
                $this->createJsonResponseMock(
                    ['candidates' => [['content' => ['parts' => $firstParts]]]],
                ),
                $this->createJsonResponseMock(
                    ['candidates' => [['content' => ['parts' => $secondParts]]]],
                ),
                $this->createJsonResponseMock(
                    [
                        'candidates' => [['content' => ['parts' => [['text' => 'Done.']]]]],
                    ],
                ),
            ],
            $body,
        );
        $messages = [ChatMessage::user('Check weather then clock.')];
        $tools = [
            ToolSpec::function('weather', 'Weather.', ['type' => 'object']),
            ToolSpec::function(
                'clock',
                'Clock.',
                [
                    'type' => 'object',
                    'properties' => ['zone' => ['type' => 'string']],
                    'required' => ['zone'],
                ],
            ),
        ];
        $ids = [];
        foreach ([['temperature' => 12], ['hour' => 10]] as $result) {
            $response = $provider->chatCompletionWithTools($messages, $tools);
            self::assertNotNull($response->toolCalls);
            self::assertCount(1, $response->toolCalls);
            $ids[] = $response->toolCalls[0]->id;
            $messages[] = ChatMessage::assistantToolCalls(
                $response->toolCalls,
                $response->content,
                $this->replayItems($response),
            );
            $messages[] = ChatMessage::toolResult(
                $response->toolCalls[0]->id,
                json_encode($result, JSON_THROW_ON_ERROR),
            );
            $messages = array_map(
                fn(
                    ChatMessage $m,
                ): ChatMessage => ChatMessage::fromArray($this->storedTranscript([$m])[0]),
                $messages,
            );
        }

        self::assertNotSame($ids[0], $ids[1]);
        self::assertSame('Done.', $provider->chatCompletion($messages)->content);
        $payload = $this->payload($body);
        self::assertSame(
            ['role' => 'model', 'parts' => $firstParts],
            $payload['contents'][1],
        );
        self::assertSame(
            ['role' => 'model', 'parts' => $secondParts],
            $payload['contents'][3],
        );
        self::assertSame(
            'weather',
            $payload['contents'][2]['parts'][0]['functionResponse']['name'],
        );
        self::assertSame(
            'clock',
            $payload['contents'][4]['parts'][0]['functionResponse']['name'],
        );
    }

    #[Test]
    #[DataProvider('plainResponses')]
    public function signedPlainResponsesPreserveNativePartsIndependentlyOfRawCapture(
        bool $signed,
        bool $captureRaw,
    ): void {
        $body = null;
        $parts = [['text' => '<think>Private.</think>Visible.'], ['text' => 'Later.']];
        if ($signed) {
            $parts[0]['thoughtSignature'] = 'plain+/==';
            $parts[0]['nativeExtension'] = ['future' => 17];
        }

        $reply = [
            'candidates' => [['content' => ['parts' => $parts], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 3],
        ];
        $provider = $this->subject(
            'gemini',
            [
                $this->createJsonResponseMock($reply),
                $this->createJsonResponseMock(
                    [
                        'candidates' => [['content' => ['parts' => [['text' => 'Done.']]]]],
                    ],
                ),
            ],
            $body,
        );
        $response = $provider->chatCompletion(
            [ChatMessage::user('Check.')],
            ['_capture_raw' => $captureRaw],
        );
        self::assertSame('Visible.Later.', $response->content);
        self::assertSame('Private.', $response->thinking);
        self::assertSame('gemini', $response->provider);
        self::assertSame(7, $response->usage->promptTokens);
        self::assertSame(3, $response->usage->completionTokens);
        self::assertSame('stop', $response->finishReason);
        $expectedMetadata = $signed ? [
            OpenAiCallMetadata::KEY_PROVIDER_ITEMS => [['type' => 'nrllm_gemini_generate_content', 'parts' => $parts]],
        ] : null;
        if ($signed && $captureRaw) {
            $expectedMetadata['_raw'] = $reply;
        }

        self::assertSame($expectedMetadata, $response->metadata);
        $provider->chatCompletion(
            [
                ChatMessage::user('Check.'),
                new ChatMessage(
                    'assistant',
                    $response->content,
                    providerItems: $this->replayItems($response),
                ),
                ChatMessage::user('Continue.'),
            ],
        );
        self::assertSame(
            [
                'role' => 'model',
                'parts' => $signed ? $parts : [['text' => 'Visible.Later.']],
            ],
            $this->payload($body)['contents'][1],
        );
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function plainResponses(): iterable
    {
        yield 'signed without raw capture' => [true, false];
        yield 'signed with raw capture' => [true, true];
        yield 'unsigned without raw capture' => [false, false];
        yield 'unsigned with raw capture stays unchanged' => [false, true];
    }

    #[Test]
    #[DataProvider('transcriptForms')]
    public function geminiToResponsesRebuildsBothVisibleTextAndOrdinaryCalls(
        bool $stored,
    ): void {
        $body = null;
        $provider = $this->subject('openai', [$this->responsesReply()], $body);
        $message = ChatMessage::assistantToolCalls(
            [
                ToolCall::function(
                    'call_authorized',
                    'weather',
                    ['city' => 'Leipzig'],
                ),
            ],
            'Visible text.',
            [
                [
                    'type' => 'nrllm_gemini_generate_content',
                    'parts' => [
                        [
                            'text' => 'Opaque native text.',
                            'thoughtSignature' => 'do-not-forward+/==',
                        ],
                    ],
                ],
            ],
        );
        $messages = [
            ChatMessage::user('Check.'),
            $message,
            ChatMessage::toolResult('call_authorized', '{"temperature":12}'),
        ];
        if ($stored) {
            $messages = $this->storedTranscript($messages);
        }

        $provider->chatCompletionWithTools(
            $messages,
            [ToolSpec::function('weather', 'Weather.', ['type' => 'object'])],
            ['model' => 'gpt-6-luna'],
        );
        self::assertSame(
            [
                ['role' => 'user', 'content' => 'Check.'],
                ['role' => 'assistant', 'content' => 'Visible text.'],
                [
                    'type' => 'function_call',
                    'call_id' => 'call_authorized',
                    'name' => 'weather',
                    'arguments' => '{"city":"Leipzig"}',
                ],
                [
                    'type' => 'function_call_output',
                    'call_id' => 'call_authorized',
                    'output' => '{"temperature":12}',
                ],
            ],
            $this->payload($body)['input'],
        );
        self::assertStringNotContainsString('do-not-forward', $body ?? '');
        self::assertStringNotContainsString(
            'nrllm_gemini_generate_content',
            $body ?? '',
        );
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function transcriptForms(): iterable
    {
        yield 'typed' => [false];
        yield 'JSON stored transcript' => [true];
    }

    #[Test]
    #[DataProvider('transcriptForms')]
    public function historicalOpenAiItemsKeepTheirOwnReplayAndAreRebuiltForGemini(
        bool $stored,
    ): void {
        $body = null;
        $items = [
            ['type' => 'reasoning', 'encrypted_content' => 'openai-only+/=='],
            [
                'type' => 'function_call',
                'call_id' => 'call_old',
                'name' => 'weather',
                'arguments' => '{"city":"Leipzig"}',
            ],
        ];
        $message = ChatMessage::assistantToolCalls(
            [
                ToolCall::function(
                    'call_old',
                    'weather',
                    ['city' => 'Leipzig'],
                ),
            ],
            'Visible.',
            $items,
        );
        $messages = [
            ChatMessage::user('Check.'),
            $message,
            ChatMessage::toolResult('call_old', '12'),
        ];
        if ($stored) {
            $messages = $this->storedTranscript($messages);
        }

        $gemini = $this->subject(
            'gemini',
            [
                $this->createJsonResponseMock(
                    [
                        'candidates' => [['content' => ['parts' => [['text' => 'Done.']]]]],
                    ],
                ),
            ],
            $body,
        );
        $gemini->chatCompletion($messages);
        self::assertSame(
            [
                'role' => 'model',
                'parts' => [
                    ['text' => 'Visible.'],
                    [
                        'functionCall' => ['name' => 'weather', 'args' => ['city' => 'Leipzig']],
                    ],
                ],
            ],
            $this->payload($body)['contents'][1],
        );
        self::assertSame(
            'weather',
            $this->payload($body)['contents'][2]['parts'][0]['functionResponse']['name'],
        );
        self::assertStringNotContainsString('openai-only', $body ?? '');
        $openai = $this->subject('openai', [$this->responsesReply()], $body);
        $openai->chatCompletionWithTools(
            $messages,
            [ToolSpec::function('weather', 'Weather.', ['type' => 'object'])],
            ['model' => 'gpt-6-luna'],
        );
        self::assertSame(
            [
                ['role' => 'user', 'content' => 'Check.'],
                ...$items,
                [
                    'type' => 'function_call_output',
                    'call_id' => 'call_old',
                    'output' => '12',
                ],
            ],
            $this->payload($body)['input'],
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    #[Test]
    #[DataProvider('ambiguousCarriers')]
    public function malformedOrAmbiguousOwnedReplayIsRefusedBeforeHttp(
        string $adapter,
        array $items,
    ): void {
        $body = null;
        $provider = $this->subject($adapter, [], $body);
        $message = new ChatMessage('assistant', 'Visible.', providerItems: $items);
        $caught = null;
        try {
            $provider->chatCompletionWithTools(
                [$message],
                [
                    ToolSpec::function(
                        'weather',
                        'Weather.',
                        ['type' => 'object'],
                    ),
                ],
                [
                    'model' => $adapter === 'gemini' ? 'gemini-3-flash-preview' : 'gpt-6-luna',
                ],
            );
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(
            ProviderConfigurationException::class,
            $caught,
        );
        self::assertSame(
            'Gemini replay state is malformed or ambiguous.',
            $caught->getMessage(),
        );
        self::assertSame(0, $this->unexpectedHttpCalls);
        self::assertNull($body);
    }

    /**
     * @return iterable<string, array{string, list<array<string, mixed>>}>
     */
    public static function ambiguousCarriers(): iterable
    {
        $valid = [
            'type' => 'nrllm_gemini_generate_content',
            'parts' => [['text' => 'private+/==']],
        ];
        $cases = [
            'missing parts' => [['type' => 'nrllm_gemini_generate_content']],
            'scalar parts' => [
                [
                    'type' => 'nrllm_gemini_generate_content',
                    'parts' => 'private+/==',
                ],
            ],
            'non-list parts' => [
                [
                    'type' => 'nrllm_gemini_generate_content',
                    'parts' => ['first' => ['text' => 'private+/==']],
                ],
            ],
            'non-array part' => [['type' => 'nrllm_gemini_generate_content', 'parts' => [17]]],
            'duplicate owned capsule' => [$valid, $valid],
            'mixed native owners' => [
                $valid,
                ['type' => 'reasoning', 'encrypted_content' => 'private+/=='],
            ],
        ];
        foreach (['gemini', 'openai'] as $adapter) {
            foreach ($cases as $name => $items) {
                yield $adapter . ' ' . $name => [$adapter, $items];
            }
        }
    }

    /** @param list<array<string, mixed>>|null $items
     * @param list<array<string, mixed>> $expectedParts */
    #[Test]
    #[DataProvider('emptyCarrierControls')]
    public function anEmptyOwnedPartsListIsDistinctFromNoCarrier(
        ?array $items,
        array $expectedParts,
    ): void {
        $body = null;
        $provider = $this->subject(
            'gemini',
            [
                $this->createJsonResponseMock(
                    [
                        'candidates' => [['content' => ['parts' => [['text' => 'Done.']]]]],
                    ],
                ),
            ],
            $body,
        );
        $provider->chatCompletion(
            [new ChatMessage('assistant', 'Visible.', providerItems: $items)],
        );
        self::assertSame(
            ['role' => 'model', 'parts' => $expectedParts],
            $this->payload($body)['contents'][0],
        );
    }

    /**
     * @return iterable<string, array{?list<array<string, mixed>>, list<array<string, mixed>>}>
     */
    public static function emptyCarrierControls(): iterable
    {
        yield 'no carrier' => [null, [['text' => 'Visible.']]];
        yield 'empty historical carrier' => [[], [['text' => 'Visible.']]];
        yield 'empty owned native parts' => [[['type' => 'nrllm_gemini_generate_content', 'parts' => []]], []];
    }

    #[Test]
    public function malformedResponsePartsDoNotRewriteTheValidNativeParts(): void
    {
        $body = null;
        $provider = $this->subject(
            'gemini',
            [
                $this->createJsonResponseMock(
                    [
                        'candidates' => [
                            [
                                'content' => [
                                    'parts' => [
                                        null,
                                        'malformed',
                                        ['text' => 'Visible.'],
                                        [],
                                        [
                                            'functionCall' => [
                                                'name' => 'weather',
                                                'args' => ['city' => 'Leipzig'],
                                            ],
                                            'thoughtSignature' => 'valid+/==',
                                            'nativeExtension' => ['future' => 17],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ),
            ],
            $body,
        );
        $response = $provider->chatCompletionWithTools(
            [ChatMessage::user('Check.')],
            [ToolSpec::function('weather', 'Weather.', ['type' => 'object'])],
        );
        self::assertSame('Visible.', $response->content);
        self::assertNotNull($response->toolCalls);
        self::assertCount(1, $response->toolCalls);
        self::assertSame(
            [
                [
                    'type' => 'nrllm_gemini_generate_content',
                    'parts' => [
                        ['text' => 'Visible.'],
                        [],
                        [
                            'functionCall' => [
                                'name' => 'weather',
                                'args' => ['city' => 'Leipzig'],
                            ],
                            'thoughtSignature' => 'valid+/==',
                            'nativeExtension' => ['future' => 17],
                        ],
                    ],
                ],
            ],
            $this->replayItems($response),
        );
    }

    /**
     * @param list<ResponseInterface> $replies
     */
    private function subject(
        string $adapter,
        array $replies,
        ?string &$body,
    ): GeminiProvider|OpenAiProvider {
        $class = $adapter === 'gemini' ? GeminiProvider::class : OpenAiProvider::class;
        $provider = new $class(
            $this->createRequestFactoryMock($body),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure(
            [
                'apiKeyIdentifier' => 'test-key',
                'defaultModel' => $adapter === 'gemini' ? 'gemini-3-flash-preview' : 'gpt-6-luna',
            ],
        );
        $http = $this->createHttpClientWithExpectations();
        if ($replies === []) {
            $http
                ->method('sendRequest')
                ->willReturnCallback(
                    function (): ResponseInterface {
                        ++$this->unexpectedHttpCalls;
                        return $this->responsesReply();
                    },
                );
        } else {
            $http
                ->expects(self::exactly(count($replies)))
                ->method('sendRequest')
                ->willReturnOnConsecutiveCalls(...$replies);
        }

        $provider->setHttpClient($http);
        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?string $body): array
    {
        self::assertNotNull($body);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function responsesReply(): ResponseInterface
    {
        return $this->createJsonResponseMock(
            [
                'id' => 'resp_test',
                'model' => 'gpt-6-luna',
                'output' => [
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'output_text', 'text' => 'Done.']],
                    ],
                ],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ],
        );
    }

    private int $unexpectedHttpCalls = 0;

    /** @param list<ChatMessage> $messages
     * @return non-empty-list<array<string, mixed>>
     */
    private function storedTranscript(array $messages): array
    {
        $decoded = json_decode(
            json_encode(
                array_map(
                    static fn(
                        ChatMessage $message,
                    ): array => $message->toTranscriptArray(),
                    $messages,
                ),
                JSON_THROW_ON_ERROR,
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);
        self::assertNotEmpty($decoded);
        self::assertTrue(array_is_list($decoded));
        foreach ($decoded as $item) {
            self::assertIsArray($item);
        }

        /** @var non-empty-list<array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function replayItems(
        CompletionResponse $completion,
    ): ?array {
        $items = $completion->metadata[OpenAiCallMetadata::KEY_PROVIDER_ITEMS] ?? null;
        if ($items === null) {
            return null;
        }

        self::assertIsArray($items);
        self::assertTrue(array_is_list($items));
        foreach ($items as $item) {
            self::assertIsArray($item);
        }

        /** @var list<array<string, mixed>> $items */
        return $items;
    }
}
