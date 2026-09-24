<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
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
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Tool calling over the OpenAI Responses transport (ADR-203, issue #965).
 *
 * Every case here drives a faked PSR-18 client. **No request was ever made to
 * OpenAI** while this was written — no credential for it exists on the machine
 * — so the fixtures are the shapes the published API reference documents, and
 * the first real call is the measurement.
 */
#[CoversClass(OpenAiProvider::class)]
#[UsesClass(ResponsesPayloadBuilder::class)]
#[UsesClass(ResponsesResultParser::class)]
#[UsesClass(OpenAiModelProfiles::class)]
final class OpenAiResponsesToolCallTest extends AbstractUnitTestCase
{
    /**
     * The URI of every request the subject sent, in order.
     *
     * @var list<string>
     */
    private array $uris = [];

    /**
     * The decoded JSON body of every request the subject sent, in order.
     *
     * @var list<array<string, mixed>>
     */
    private array $payloads = [];

    /**
     * Bodies are captured per request rather than into one variable, because
     * the multi-step cases assert on what the SECOND request carried.
     *
     * @var list<string>
     */
    private array $rawBodies = [];

    #[Test]
    public function aToolRequestToAReasoningModelIsPostedToTheResponsesEndpoint(): void
    {
        $subject = $this->subjectAnswering([$this->responsesReplyWithToolCall()]);

        $subject->chatCompletionWithTools(
            [ChatMessage::user('Fetch source "invalid".')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        self::assertStringEndsWith('/responses', $this->uris[0]);
        self::assertSame('gpt-6-luna', $this->payloads[0]['model']);

        // The Responses tool shape is FLAT. A payload that still nested the
        // definition under `function` would be the Chat Completions shape on
        // the new endpoint, and OpenAI would reject it.
        $tools = $this->payloads[0]['tools'];
        self::assertIsArray($tools);
        self::assertSame('function', $tools[0]['type']);
        self::assertSame('site_fetch_source', $tools[0]['name']);
        self::assertArrayNotHasKey('function', $tools[0]);

        // Chat Completions tools are non-strict; with `strict` omitted,
        // Responses would try to rewrite the schema into strict mode.
        self::assertFalse($tools[0]['strict']);
    }

    #[Test]
    public function aToolRequestToGpt41MiniStaysOnChatCompletions(): void
    {
        // The reporter's own control in issue #965: this model works today and
        // must not be moved by the fix.
        $subject = $this->subjectAnswering([$this->chatCompletionsReply()]);

        $subject->chatCompletionWithTools(
            [ChatMessage::user('Fetch source "invalid".')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-4.1-mini'],
        );

        self::assertStringEndsWith('/chat/completions', $this->uris[0]);
        self::assertArrayHasKey('messages', $this->payloads[0]);
        self::assertArrayNotHasKey('reasoning_effort', $this->payloads[0]);
    }

    #[Test]
    public function aFunctionCallItemBecomesAToolCall(): void
    {
        $subject = $this->subjectAnswering([$this->responsesReplyWithToolCall()]);

        $response = $subject->chatCompletionWithTools(
            [ChatMessage::user('Fetch source "invalid".')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        self::assertTrue($response->hasToolCalls());
        $calls = $response->toolCalls ?? [];
        self::assertCount(1, $calls);

        // `call_id`, not `id`: only that one may be echoed in a
        // function_call_output, and taking the wrong one produces a request
        // OpenAI cannot correlate.
        self::assertSame('call_abc123', $calls[0]->id);
        self::assertSame('site_fetch_source', $calls[0]->name);
        self::assertSame(['source_id' => 'invalid'], $calls[0]->arguments);
    }

    #[Test]
    public function aToolResultIsSentBackWithTheCallIdItCameWith(): void
    {
        $subject = $this->subjectAnswering([
            $this->responsesReplyWithToolCall(),
            $this->responsesReplyWithText('Invalid source_id.'),
        ]);

        $first = $subject->chatCompletionWithTools(
            [ChatMessage::user('Fetch source "invalid".')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        $items = $this->itemsOf($first);

        $subject->chatCompletionWithTools(
            [
                ChatMessage::user('Fetch source "invalid".'),
                ChatMessage::assistantToolCalls($first->toolCalls ?? [], $first->content, $items),
                // The id the parser took from the reply — `call_id`, not the
                // item's `id` — is what must come back on the output.
                ChatMessage::toolResult(($first->toolCalls ?? [])[0]->id ?? '', 'Invalid source_id.'),
            ],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        $input = $this->payloads[1]['input'];
        self::assertIsArray($input);

        $outputs = array_values(array_filter(
            $input,
            static fn(mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'function_call_output',
        ));

        self::assertCount(1, $outputs);
        self::assertSame('call_abc123', $outputs[0]['call_id']);
        self::assertSame('Invalid source_id.', $outputs[0]['output']);
    }

    #[Test]
    public function theSecondRequestReplaysTheReasoningItemUntouched(): void
    {
        $subject = $this->subjectAnswering([
            $this->responsesReplyWithToolCall(),
            $this->responsesReplyWithText('Invalid source_id.'),
        ]);

        $first = $subject->chatCompletionWithTools(
            [ChatMessage::user('Fetch source "invalid".')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        $items = $this->itemsOf($first);

        $subject->chatCompletionWithTools(
            [
                ChatMessage::user('Fetch source "invalid".'),
                ChatMessage::assistantToolCalls($first->toolCalls ?? [], $first->content, $items),
                ChatMessage::toolResult('call_abc123', 'Invalid source_id.'),
            ],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        $input = $this->payloads[1]['input'];
        self::assertIsArray($input);

        // This is the assertion the whole transport exists for. The reasoning
        // item must arrive at OpenAI byte for byte, `encrypted_content` and
        // all — this extension can neither read it nor reproduce it, so a
        // replay that "normalises" it has lost the model's thinking.
        $reasoning = array_values(array_filter(
            $input,
            static fn(mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'reasoning',
        ));

        self::assertCount(1, $reasoning);
        self::assertSame(
            [
                'type' => 'reasoning',
                'id' => 'rs_abc123',
                'encrypted_content' => 'gAAAAAB-opaque-blob',
                'summary' => [],
            ],
            $reasoning[0],
        );

        // Order is part of the contract: user message, then the turn's own
        // items as they came back — reasoning before the call it led to —
        // then the output answering that call.
        self::assertSame('reasoning', $input[1]['type']);
        self::assertSame('function_call', $input[2]['type']);
        self::assertSame('call_abc123', $input[2]['call_id']);
        self::assertSame('function_call_output', $input[3]['type']);
    }

    #[Test]
    public function theRequestedReasoningEffortReachesThePayload(): void
    {
        $subject = $this->subjectAnswering([$this->responsesReplyWithText('done')]);

        $subject->chatCompletionWithTools(
            [ChatMessage::user('Think hard.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna', 'reasoning_effort' => 'high'],
        );

        self::assertSame(['effort' => 'high'], $this->payloads[0]['reasoning']);
    }

    #[Test]
    public function thinkingOffSendsTheNoneEffortToLuna(): void
    {
        // The Playground's "Thinking: Off" did nothing on OpenAI before this
        // change; `think` was read by the Ollama adapter alone (#965).
        $subject = $this->subjectAnswering([$this->responsesReplyWithText('done')]);

        $subject->chatCompletionWithTools(
            [ChatMessage::user('Hi.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna', 'think' => false],
        );

        self::assertSame(['effort' => 'none'], $this->payloads[0]['reasoning']);
    }

    #[Test]
    public function thinkingOffIsClampedToLowOnAstraOnTheWireAndInTheMetadata(): void
    {
        // The reply echoes no effort here, so the recorded value is the one
        // that was sent — and the source key says exactly that.
        $subject = $this->subjectAnswering([$this->responsesReplyWithText('done')]);

        $response = $subject->chatCompletionWithTools(
            [ChatMessage::user('Hi.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-astra', 'think' => false],
        );

        self::assertSame(['effort' => 'low'], $this->payloads[0]['reasoning']);
        self::assertSame('low', $response->metadata[OpenAiCallMetadata::KEY_REASONING_EFFORT] ?? null);
        self::assertSame(
            OpenAiCallMetadata::EFFORT_SOURCE_REQUEST,
            $response->metadata[OpenAiCallMetadata::KEY_EFFORT_SOURCE] ?? null,
        );
    }

    #[Test]
    public function anExplicitEffortWinsOverTheThinkSwitch(): void
    {
        $subject = $this->subjectAnswering([$this->responsesReplyWithText('done')]);

        $subject->chatCompletionWithTools(
            [ChatMessage::user('Hi.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna', 'think' => false, 'reasoning_effort' => 'high'],
        );

        self::assertSame(['effort' => 'high'], $this->payloads[0]['reasoning']);
    }

    #[Test]
    public function noEffortIsSentWhenNothingAsksForOne(): void
    {
        // The model's own default applies, and this extension does not guess
        // at it.
        $subject = $this->subjectAnswering([$this->responsesReplyWithText('done')]);

        $subject->chatCompletionWithTools(
            [ChatMessage::user('Hi.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        self::assertArrayNotHasKey('reasoning', $this->payloads[0]);
        self::assertFalse($this->payloads[0]['store']);
    }

    #[Test]
    public function theAppliedReasoningEffortIsReadableOnTheResponse(): void
    {
        $subject = $this->subjectAnswering([
            $this->responsesReplyWithText('done', ['reasoning' => ['effort' => 'medium']]),
        ]);

        $response = $subject->chatCompletionWithTools(
            [ChatMessage::user('Hello.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        self::assertSame('medium', $response->metadata[OpenAiCallMetadata::KEY_REASONING_EFFORT] ?? null);
        self::assertSame(
            OpenAiCallMetadata::EFFORT_SOURCE_PROVIDER,
            $response->metadata[OpenAiCallMetadata::KEY_EFFORT_SOURCE] ?? null,
        );
        self::assertSame(
            OpenAiCallMetadata::TRANSPORT_RESPONSES,
            $response->metadata[OpenAiCallMetadata::KEY_TRANSPORT] ?? null,
        );
    }

    #[Test]
    public function aJsonSchemaBecomesTextFormat(): void
    {
        $subject = $this->subjectAnswering([$this->responsesReplyWithText('{}')]);

        $subject->chatCompletionWithTools(
            [ChatMessage::user('Give me JSON.')],
            [$this->siteFetchSource()],
            [
                'model' => 'gpt-6-luna',
                'response_format' => 'json',
                'response_schema' => [
                    'type' => 'object',
                    'properties' => ['ok' => ['type' => 'boolean']],
                    'required' => ['ok'],
                    'additionalProperties' => false,
                ],
            ],
        );

        // Responses carries the schema under `text.format` and flattens the
        // json_schema configuration; `response_format` would be ignored.
        self::assertArrayNotHasKey('response_format', $this->payloads[0]);
        $format = $this->payloads[0]['text']['format'];
        self::assertSame('json_schema', $format['type']);
        self::assertArrayHasKey('schema', $format);
        self::assertArrayNotHasKey('json_schema', $format);
    }

    #[Test]
    public function aCompatibleEndpointKeepsChatCompletionsUnlessItOptsIn(): void
    {
        // Both directions in one case: an OpenAI-compatible gateway need not
        // serve /v1/responses, so the host decides — and an operator who knows
        // better overrides it.
        $gateway = $this->subjectAnswering(
            [$this->chatCompletionsReply()],
            baseUrl: 'https://llm.example.org/v1',
        );

        $gateway->chatCompletionWithTools(
            [ChatMessage::user('Hi.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        self::assertStringEndsWith('/chat/completions', $this->uris[0]);

        $optedIn = $this->subjectAnswering(
            [$this->responsesReplyWithText('done')],
            baseUrl: 'https://llm.example.org/v1',
        );

        $optedIn->chatCompletionWithTools(
            [ChatMessage::user('Hi.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna', 'openai_tools_transport' => 'responses'],
        );

        self::assertStringEndsWith('/responses', $this->uris[0]);
    }

    #[Test]
    public function aCallAResultAndAnAnswerCompleteInThreeRequests(): void
    {
        $subject = $this->subjectAnswering([
            $this->responsesReplyWithToolCall(),
            $this->responsesReplyWithToolCall('call_def456'),
            $this->responsesReplyWithText('Invalid source_id.'),
        ]);

        $messages = [ChatMessage::user('Call it twice, then report.')];

        $first = $subject->chatCompletionWithTools($messages, [$this->siteFetchSource()], ['model' => 'gpt-6-luna']);
        $messages[] = ChatMessage::assistantToolCalls(
            $first->toolCalls ?? [],
            $first->content,
            $this->itemsOf($first),
        );
        $messages[] = ChatMessage::toolResult('call_abc123', 'Invalid source_id.');

        $second = $subject->chatCompletionWithTools($messages, [$this->siteFetchSource()], ['model' => 'gpt-6-luna']);
        $messages[] = ChatMessage::assistantToolCalls(
            $second->toolCalls ?? [],
            $second->content,
            $this->itemsOf($second),
        );
        $messages[] = ChatMessage::toolResult('call_def456', 'Invalid source_id.');

        $third = $subject->chatCompletionWithTools($messages, [$this->siteFetchSource()], ['model' => 'gpt-6-luna']);

        self::assertCount(3, $this->payloads);
        self::assertFalse($third->hasToolCalls());
        self::assertSame('Invalid source_id.', $third->content);

        // The last request carries every turn, each turn's own items in the
        // order they came back and each output after the call it answers.
        // Distinct ids per turn make a duplicated first turn visible.
        $input = $this->payloads[2]['input'];
        self::assertIsArray($input);

        $shape = array_map(
            static function (mixed $item): string {
                self::assertIsArray($item);
                $type = $item['type'] ?? ('message:' . (is_string($item['role'] ?? null) ? $item['role'] : '?'));

                return match ($type) {
                    'reasoning' => 'reasoning ' . (is_string($item['id'] ?? null) ? $item['id'] : '?'),
                    'function_call', 'function_call_output' => $type . ' ' . (is_string($item['call_id'] ?? null) ? $item['call_id'] : '?'),
                    default => is_string($type) ? $type : '?',
                };
            },
            $input,
        );

        self::assertSame(
            [
                'message:user',
                'reasoning rs_abc123',
                'function_call call_abc123',
                'function_call_output call_abc123',
                'reasoning rs_def456',
                'function_call call_def456',
                'function_call_output call_def456',
            ],
            $shape,
        );
    }

    #[Test]
    public function aSystemMessageBecomesInstructions(): void
    {
        $subject = $this->subjectAnswering([$this->responsesReplyWithText('done')]);

        $subject->chatCompletionWithTools(
            [
                ChatMessage::system('You are terse.'),
                ChatMessage::system('Answer in German.'),
                ChatMessage::user('Hi.'),
            ],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        // Both system messages survive. Keeping only the first would drop a
        // configuration's skill block or a prompt snippet.
        self::assertSame("You are terse.\n\nAnswer in German.", $this->payloads[0]['instructions']);
        $input = $this->payloads[0]['input'];
        self::assertIsArray($input);
        self::assertCount(1, $input);
    }

    #[Test]
    public function aTruncatedReplyReportsALengthFinishReason(): void
    {
        $subject = $this->subjectAnswering([
            $this->responsesReplyWithText('half an ans', [
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
            ]),
        ]);

        $response = $subject->chatCompletionWithTools(
            [ChatMessage::user('Hi.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        // The Responses reply has no finish_reason; `wasTruncated()` must keep
        // answering correctly all the same.
        self::assertTrue($response->wasTruncated());
    }

    #[Test]
    public function theChatCompletionsOverrideKeepsAGpt6ModelOnTheOldPath(): void
    {
        // The other direction of the override: even on api.openai.com, a
        // configuration can pin the old endpoint.
        $subject = $this->subjectAnswering([$this->chatCompletionsReply()]);

        $subject->chatCompletionWithTools(
            [ChatMessage::user('Hi.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna', 'openai_tools_transport' => 'chat/completions'],
        );

        self::assertStringEndsWith('/chat/completions', $this->uris[0]);
    }

    #[Test]
    public function aForcedNonReasoningModelKeepsItsTemperatureButNoPenalties(): void
    {
        // Responses defines temperature and top_p, and no frequency or
        // presence penalty.
        $subject = $this->subjectAnswering([$this->responsesReplyWithText('done')]);

        $subject->chatCompletionWithTools(
            [ChatMessage::user('Hi.')],
            [$this->siteFetchSource()],
            [
                'model' => 'gpt-4o',
                'openai_tools_transport' => 'responses',
                'temperature' => 0.2,
                'top_p' => 0.9,
                'frequency_penalty' => 0.5,
            ],
        );

        self::assertStringEndsWith('/responses', $this->uris[0]);
        self::assertSame(0.2, $this->payloads[0]['temperature']);
        self::assertSame(0.9, $this->payloads[0]['top_p']);
        self::assertArrayNotHasKey('frequency_penalty', $this->payloads[0]);
    }

    #[Test]
    public function anImagePartBecomesAnInputImage(): void
    {
        // A multimodal message reached chat/completions before this transport
        // existed; it must not become an exception on the new one.
        $subject = $this->subjectAnswering([$this->responsesReplyWithText('a cat')]);

        $subject->chatCompletionWithTools(
            [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'What is this?'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'https://example.org/cat.png', 'detail' => 'low']],
                ],
            ]],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        self::assertSame(
            [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => 'What is this?'],
                    ['type' => 'input_image', 'image_url' => 'https://example.org/cat.png', 'detail' => 'low'],
                ],
            ]],
            $this->payloads[0]['input'],
        );
    }

    #[Test]
    public function aPartOfAnUnknownTypeIsRefusedByName(): void
    {
        // Dropping it would answer a different question than the one asked.
        $subject = $this->subjectAnswering([$this->responsesReplyWithText('never sent')]);

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionCode(1758600102);

        $subject->chatCompletionWithTools(
            [['role' => 'user', 'content' => [['type' => 'input_audio', 'input_audio' => ['data' => 'x']]]]],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );
    }

    #[Test]
    public function aFailedReplyRaisesAProviderResponseException(): void
    {
        // Returned as a result it would read as an empty final answer.
        $subject = $this->subjectAnswering([(string)json_encode([
            'id' => 'resp_x',
            'status' => 'failed',
            'error' => ['code' => 'server_error', 'message' => 'The model failed.'],
            'output' => [],
        ])]);

        $this->expectException(ProviderResponseException::class);
        $this->expectExceptionMessage('The model failed.');

        $subject->chatCompletionWithTools(
            [ChatMessage::user('Hi.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );
    }

    #[Test]
    public function aToolCallTurnReportsToolCallsAsItsFinishReason(): void
    {
        // As Chat Completions and Claude do; `stop` would make isComplete()
        // true for a turn still waiting for its tool results.
        $subject = $this->subjectAnswering([$this->responsesReplyWithToolCall()]);

        $response = $subject->chatCompletionWithTools(
            [ChatMessage::user('Fetch.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        self::assertSame('tool_calls', $response->finishReason);
        self::assertFalse($response->isComplete());
    }

    #[Test]
    public function aMalformedFunctionCallIsNeitherCalledNorReplayed(): void
    {
        // No tool result will answer a call without a call_id, and a replayed
        // function_call without its output is a request OpenAI refuses.
        $subject = $this->subjectAnswering([(string)json_encode([
            'id' => 'resp_m',
            'status' => 'completed',
            'model' => 'gpt-6-luna',
            'output' => [
                ['type' => 'reasoning', 'id' => 'rs_m', 'encrypted_content' => 'blob', 'summary' => []],
                ['type' => 'function_call', 'id' => 'fc_bad', 'call_id' => '', 'name' => 'site_fetch_source', 'arguments' => '{}'],
                ['type' => 'function_call', 'id' => 'fc_ok', 'call_id' => 'call_ok', 'name' => 'site_fetch_source', 'arguments' => '{}'],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $response = $subject->chatCompletionWithTools(
            [ChatMessage::user('Fetch.')],
            [$this->siteFetchSource()],
            ['model' => 'gpt-6-luna'],
        );

        self::assertSame(['call_ok'], array_map(static fn(ToolCall $c): string => $c->id, $response->toolCalls ?? []));
        self::assertSame(['rs_m', 'fc_ok'], array_column($this->itemsOf($response), 'id'));
    }

    // ========================================
    // Fixtures
    // ========================================

    /**
     * The provider items a response carries, typed — and asserted present,
     * because every caller of this helper depends on them being there.
     *
     * @return list<array<string, mixed>>
     */
    private function itemsOf(CompletionResponse $response): array
    {
        $items = $response->metadata[OpenAiCallMetadata::KEY_PROVIDER_ITEMS] ?? null;
        self::assertIsArray($items);
        self::assertTrue(array_is_list($items));

        $typed = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            /** @var array<string, mixed> $item */
            $typed[] = $item;
        }

        return $typed;
    }

    private function siteFetchSource(): ToolSpec
    {
        return ToolSpec::function(
            'site_fetch_source',
            'Fetch a configured source by id.',
            [
                'type' => 'object',
                'properties' => ['source_id' => ['type' => 'string']],
                'required' => ['source_id'],
                'additionalProperties' => false,
            ],
        );
    }

    /**
     * A `/v1/responses` reply that calls the tool, in the documented shape:
     * a reasoning item carrying opaque `encrypted_content`, then the
     * function_call item.
     */
    private function responsesReplyWithToolCall(string $callId = 'call_abc123'): string
    {
        // Every turn gets its own reasoning id and blob, so a test can tell
        // turn two's items from turn one's replayed twice.
        $turn = substr($callId, 5);
        $blob = $turn === 'abc123' ? 'gAAAAAB-opaque-blob' : 'gAAAAAB-opaque-blob-' . $turn;

        return json_encode([
            'id' => 'resp_1',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'gpt-6-luna',
            'output' => [
                [
                    'type' => 'reasoning',
                    'id' => 'rs_' . $turn,
                    'encrypted_content' => $blob,
                    'summary' => [],
                ],
                [
                    'type' => 'function_call',
                    'id' => 'fc_' . $turn,
                    'call_id' => $callId,
                    'name' => 'site_fetch_source',
                    'arguments' => '{"source_id":"invalid"}',
                    'status' => 'completed',
                ],
            ],
            'reasoning' => ['effort' => 'medium'],
            'usage' => ['input_tokens' => 291, 'output_tokens' => 23, 'total_tokens' => 314],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function responsesReplyWithText(string $text, array $overrides = []): string
    {
        return json_encode([
            'id' => 'resp_2',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'gpt-6-luna',
            'output' => [
                [
                    'type' => 'message',
                    'id' => 'msg_1',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
                ],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
            ...$overrides,
        ], JSON_THROW_ON_ERROR);
    }

    private function chatCompletionsReply(): string
    {
        return json_encode([
            'id' => 'chatcmpl-1',
            'model' => 'gpt-4.1-mini',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * A provider whose HTTP client returns the given bodies in order, and that
     * records the URI and decoded payload of every request it sent.
     *
     * @param list<string> $bodies
     */
    private function subjectAnswering(array $bodies, string $baseUrl = ''): OpenAiProvider
    {
        $this->uris      = [];
        $this->payloads  = [];
        $this->rawBodies = [];

        $subject = new OpenAiProvider(
            $this->recordingRequestFactory(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $subject->configure([
            'apiKeyIdentifier' => $this->randomApiKey(),
            'defaultModel' => 'gpt-4o',
            'baseUrl' => $baseUrl,
            'timeout' => 30,
        ]);

        $remaining = $bodies;
        $client    = self::createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            function () use (&$remaining): ResponseInterface {
                $body = array_shift($remaining) ?? '{}';

                // The body is only readable once the request has been built,
                // so the decode happens here rather than in the factory.
                $raw = end($this->rawBodies);
                if (is_string($raw) && $raw !== '') {
                    /** @var array<string, mixed> $decoded */
                    $decoded          = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    $this->payloads[] = $decoded;
                }

                return $this->createHttpResponseMock(200, $body);
            },
        );

        // setHttpClient must follow configure(), which resets the client.
        $subject->setHttpClient($client);

        return $subject;
    }

    private function recordingRequestFactory(): RequestFactoryInterface
    {
        $stub = self::createStub(RequestFactoryInterface::class);
        $stub->method('createRequest')->willReturnCallback(
            function (string $method, string $uri): RequestInterface {
                $this->uris[] = $uri;

                $uriStub = self::createStub(UriInterface::class);
                $uriStub->method('__toString')->willReturn($uri);
                $uriStub->method('getHost')->willReturn((string)(parse_url($uri, PHP_URL_HOST) ?? ''));
                $uriStub->method('getPath')->willReturn((string)(parse_url($uri, PHP_URL_PATH) ?? ''));

                $request = self::createStub(RequestInterface::class);
                $request->method('withHeader')->willReturnCallback(static fn(): RequestInterface => $request);
                $request->method('withoutHeader')->willReturnCallback(static fn(): RequestInterface => $request);
                $request->method('getMethod')->willReturn($method);
                $request->method('getUri')->willReturn($uriStub);
                $request->method('withBody')->willReturnCallback(
                    function (StreamInterface $body) use ($request): RequestInterface {
                        $this->rawBodies[] = $body->getContents();

                        return $request;
                    },
                );

                return $request;
            },
        );

        return $stub;
    }
}
