<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiCallMetadata;
use Netresearch\NrLlm\Provider\OpenAi\ResponsesPayloadBuilder;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Throwable;

#[CoversClass(GeminiProvider::class)]
final class GeminiFunctionIdBoundaryTest extends AbstractUnitTestCase
{
    /**
     * @param list<array{name:string,id?:string}> $calls
     */
    #[Test]
    #[DataProvider('capturedCalls')]
    public function realCaptureKeepsNativeIdsBoundAfterGenericReordering(
        string $transport,
        array $calls,
        bool $legacy,
    ): void {
        $parts    = $this->parts($calls);
        $requests = [];
        $subject  = $this->provider(
            [$this->response($parts), $this->finished($transport)],
            $requests,
        );
        $first = $subject->chatCompletionWithTools(
            [ChatMessage::user('Literal question.')],
            $this->tools(),
            ['_capture_raw' => true],
        );
        self::assertNotNull($first->toolCalls);
        self::assertCount(count($calls), $first->toolCalls);
        $items = $this->items($first);
        self::assertSame($parts, $items[0]['parts']);
        $expectedBindings = [];
        $publicIds        = [];
        foreach ($first->toolCalls as $index => $call) {
            self::assertSame($calls[$index]['name'], $call->name);
            self::assertSame(['zone' => 'UTC'], $call->arguments);
            self::assertMatchesRegularExpression(
                '/^call_[0-9a-f]+\.[0-9]+$/',
                $call->id,
            );
            self::assertNotContains($call->id, $publicIds);
            $publicIds[] = $call->id;
            if (isset($calls[$index]['id'])) {
                $expectedBindings[] = [
                    'tool_call_id' => $call->id,
                    'native_id'    => $calls[$index]['id'],
                ];
            }
        }

        self::assertSame(
            $expectedBindings,
            $items[0]['tool_call_bindings'] ?? [],
        );
        self::assertSame(
            json_decode($this->response($parts), true, 512, JSON_THROW_ON_ERROR),
            $first->metadata['_raw'] ?? null,
        );
        if ($legacy) {
            unset($items[0]['tool_call_bindings']);
        }

        $messages = [
            ChatMessage::user('Literal question.'),
            ChatMessage::assistantToolCalls(
                array_reverse($first->toolCalls),
                $first->content,
                $items,
            ),
        ];
        foreach (array_reverse($first->toolCalls, true) as $index => $call) {
            $messages[] = ChatMessage::toolResult(
                $call->id,
                json_encode(
                    ['result' => 'result-' . $index],
                    JSON_THROW_ON_ERROR,
                ),
            );
        }

        $this->invoke($subject, $transport, $messages);
        self::assertCount(2, $requests);
        $wire = $this->wire($requests[1]);
        self::assertSame(
            ['role' => 'model', 'parts' => $parts],
            $wire['contents'][1] ?? null,
        );
        foreach (array_reverse($calls, true) as $index => $expected) {
            $position = count($calls) - $index + 1;
            $actual   = $wire['contents'][$position]['parts'][0]['functionResponse'] ?? null;
            self::assertIsArray($actual);
            self::assertSame($expected['name'], $actual['name'] ?? null);
            self::assertSame(
                ['result' => 'result-' . $index],
                $actual['response'] ?? null,
            );
            if (isset($expected['id'])) {
                self::assertSame($expected['id'], $actual['id'] ?? null);
            } else {
                self::assertArrayNotHasKey('id', $actual);
            }
        }

        self::assertStringNotContainsString(
            'tool_call_bindings',
            (string) $requests[1]->getBody(),
        );
        self::assertStringNotContainsString(
            'nrllm_gemini_generate_content',
            (string) $requests[1]->getBody(),
        );
    }

    /**
     * @return iterable<string,array{string,list<array{name:string,id?:string}>,bool}>
     */
    public static function capturedCalls(): iterable
    {
        $fixtures = [
            'single'            => [['name' => 'id_alpha', 'id' => 'native-a']],
            'distinct parallel' => [
                ['name' => 'id_beta', 'id' => 'native-b'],
                ['name' => 'id_alpha', 'id' => 'native-a'],
            ],
            'identical parallel' => [
                ['name' => 'id_alpha', 'id' => 'native-a'],
                ['name' => 'id_alpha', 'id' => 'native-b'],
            ],
            'idless'         => [['name' => 'id_alpha']],
            'numeric string' => [['name' => 'id_alpha', 'id' => '123']],
            'mixed'          => [['name' => 'id_alpha'], ['name' => 'id_beta', 'id' => 'native-b']],
        ];
        foreach (['tools', 'chat', 'stream'] as $transport) {
            foreach ($fixtures as $name => $calls) {
                yield $transport . ' captured ' . $name => [$transport, $calls, false];
                if ($name !== 'identical parallel') {
                    yield $transport . ' legacy ' . $name => [$transport, $calls, true];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('invalidNativeIds')]
    public function acceptedNamedResponseRefusesInvalidNativeIds(
        mixed $id,
        bool $duplicate,
    ): void {
        $parts = [
            [
                'functionCall' => ['name' => 'id_alpha', 'args' => ['zone' => 'UTC'], 'id' => $id],
            ],
        ];
        if ($duplicate) {
            $parts[] = [
                'functionCall' => ['name' => 'id_beta', 'args' => ['zone' => 'UTC'], 'id' => $id],
            ];
        }

        $requests = [];
        $subject  = $this->provider([$this->response($parts)], $requests);
        $caught   = null;
        try {
            $subject->chatCompletionWithTools(
                [ChatMessage::user('Literal question.')],
                $this->tools(),
            );
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertInstanceOf(ProviderConfigurationException::class, $caught);
        self::assertCount(1, $requests);
    }

    /**
     * @return iterable<string,array{mixed,bool}>
     */
    public static function invalidNativeIds(): iterable
    {
        yield 'empty' => ['', false];
        yield 'null' => [null, false];
        yield 'integer' => [17, false];
        yield 'false' => [false, false];
        yield 'list' => [[], false];
        yield 'duplicate valid id' => ['native-a', true];
    }

    #[Test]
    public function namelessFunctionStillSkipsItsOtherwiseInvalidId(): void
    {
        $requests = [];
        $subject  = $this->provider(
            [
                $this->response(
                    [
                        ['functionCall' => ['args' => [], 'id' => null]],
                        ['text' => 'Done.'],
                    ],
                ),
            ],
            $requests,
        );
        $response = $subject->chatCompletionWithTools(
            [ChatMessage::user('Literal question.')],
            $this->tools(),
        );
        self::assertNull($response->toolCalls);
        self::assertSame('Done.', $response->content);
        self::assertCount(1, $requests);
    }

    #[Test]
    #[DataProvider('invalidReplayChanges')]
    public function invalidOwnedAssociationRefusesBeforeAnotherContact(
        string $change,
    ): void {
        $calls = $change === 'legacy identical' ? [
            ['name' => 'id_alpha', 'id' => 'native-a'],
            ['name' => 'id_alpha', 'id' => 'native-b'],
        ] : [['name' => 'id_alpha', 'id' => 'native-a']];
        $requests = [];
        $subject  = $this->provider([$this->response($this->parts($calls))], $requests);
        $first    = $subject->chatCompletionWithTools(
            [ChatMessage::user('Literal question.')],
            $this->tools(),
        );
        self::assertNotNull($first->toolCalls);
        $items    = $this->items($first);
        $bindings = [
            [
                'tool_call_id' => $first->toolCalls[0]->id,
                'native_id'    => 'native-a',
            ],
        ];
        $items[0]['tool_call_bindings'] = $bindings;
        $generic                        = $first->toolCalls;
        switch ($change) {
            case 'null field':
                $items[0]['tool_call_bindings'] = null;
                break;
            case 'associative field':
                $items[0]['tool_call_bindings'] = ['entry' => $bindings[0]];
                break;
            case 'scalar entry':
                $items[0]['tool_call_bindings'] = ['bad'];
                break;
            case 'missing key':
                $items[0]['tool_call_bindings'] = [['tool_call_id' => $generic[0]->id]];
                break;
            case 'empty public':
                $items[0]['tool_call_bindings'][0]['tool_call_id'] = '';
                break;
            case 'invalid native':
                $items[0]['tool_call_bindings'][0]['native_id'] = 17;
                break;
            case 'duplicate entry':
                $items[0]['tool_call_bindings'][] = $bindings[0];
                break;
            case 'missing coverage':
                $items[0]['tool_call_bindings'] = [];
                break;
            case 'extra binding':
                $items[0]['tool_call_bindings'][] = ['tool_call_id' => 'extra', 'native_id' => 'extra'];
                break;
            case 'wrong public':
                $items[0]['tool_call_bindings'][0]['tool_call_id'] = 'other';
                break;
            case 'wrong native':
                $items[0]['tool_call_bindings'][0]['native_id'] = 'other';
                break;
            case 'name mismatch':
                $generic = [
                    ToolCall::function(
                        $generic[0]->id,
                        'id_beta',
                        ['zone' => 'UTC'],
                    ),
                ];
                break;
            case 'args mismatch':
                $generic = [
                    ToolCall::function(
                        $generic[0]->id,
                        'id_alpha',
                        ['zone' => 'Europe/Berlin'],
                    ),
                ];
                break;
            case 'invalid replay native':
                $items[0]['parts'][1]['functionCall']['id'] = null;
                break;
            case 'legacy identical':
                unset($items[0]['tool_call_bindings']);
                break;
            case 'native only nonempty':
                $generic = [];
                break;
        }

        $messages = [
            ChatMessage::user('Literal question.'),
            $generic === [] ? new ChatMessage('assistant', 'Fixture.', providerItems: $items) : ChatMessage::assistantToolCalls($generic, 'Fixture.', $items),
        ];
        $caught = null;
        try {
            $subject->chatCompletion($messages);
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertInstanceOf(ProviderConfigurationException::class, $caught);
        self::assertCount(
            1,
            $requests,
            'Malformed replay must fail before outbound contact.',
        );
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalidReplayChanges(): iterable
    {
        foreach ([
            'null field',
            'associative field',
            'scalar entry',
            'missing key',
            'empty public',
            'invalid native',
            'duplicate entry',
            'missing coverage',
            'extra binding',
            'wrong public',
            'wrong native',
            'name mismatch',
            'args mismatch',
            'invalid replay native',
            'legacy identical',
            'native only nonempty',
        ] as $change) {
            yield $change => [$change];
        }
    }

    #[Test]
    #[DataProvider('idlessBindingCases')]
    public function idlessNativePartsOnlyChecksPresentBindingShape(
        mixed $binding,
        bool $present,
        bool $valid,
    ): void {
        $parts = $this->parts([['name' => 'id_alpha']]);
        $items = [['type' => 'nrllm_gemini_generate_content', 'parts' => $parts]];
        if ($present) {
            $items[0]['tool_call_bindings'] = $binding;
        }

        $requests = [];
        $subject  = $this->provider($valid ? [$this->finished('chat')] : [], $requests);
        $caught   = null;
        $response = null;
        try {
            $response = $subject->chatCompletion(
                [new ChatMessage('assistant', 'Fixture.', providerItems: $items)],
            );
        } catch (Throwable $error) {
            $caught = $error;
        }

        if ($valid) {
            self::assertNull($caught);
            self::assertSame('Done.', $response?->content);
            self::assertCount(1, $requests);
            self::assertSame(
                $parts,
                $this->wire($requests[0])['contents'][0]['parts'] ?? null,
            );
        } else {
            self::assertInstanceOf(
                ProviderConfigurationException::class,
                $caught,
            );
            self::assertSame([], $requests);
        }
    }

    /**
     * @return iterable<string,array{mixed,bool,bool}>
     */
    public static function idlessBindingCases(): iterable
    {
        yield 'absent' => [null, false, true];
        yield 'empty list' => [[], true, true];
        yield 'present null' => [null, true, false];
        yield 'present scalar' => ['bad', true, false];
        yield 'nonempty' => [
            [['tool_call_id' => 'invented', 'native_id' => 'invented']],
            true,
            false,
        ];
    }

    #[Test]
    #[DataProvider('foreignDuplicatePositions')]
    public function ownedNativeAssociationRefusesAnotherCallWithTheSamePublicId(
        bool $before,
    ): void {
        $requests = [];
        $subject  = $this->provider(
            [
                $this->response(
                    $this->parts([['name' => 'id_alpha', 'id' => 'native-a']]),
                ),
            ],
            $requests,
        );
        $first = $subject->chatCompletionWithTools(
            [ChatMessage::user('Question.')],
            $this->tools(),
        );
        self::assertNotNull($first->toolCalls);
        $owned = ChatMessage::assistantToolCalls(
            $first->toolCalls,
            $first->content,
            $this->items($first),
        );
        $foreign = ChatMessage::assistantToolCalls(
            [
                ToolCall::function(
                    $first->toolCalls[0]->id,
                    'id_beta',
                    ['zone' => 'UTC'],
                ),
            ],
        );
        $caught = null;
        try {
            $subject->chatCompletion(
                $before ? [$foreign, $owned] : [$owned, $foreign],
            );
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertInstanceOf(ProviderConfigurationException::class, $caught);
        self::assertCount(1, $requests);
    }

    /**
     * @return iterable<string,array{bool}>
     */
    public static function foreignDuplicatePositions(): iterable
    {
        yield 'before' => [true];
        yield 'after' => [false];
    }

    /**
     * @param list<ChatMessage> $messages
     */
    private function invoke(
        GeminiProvider $subject,
        string $transport,
        array $messages,
    ): void {
        if ($transport === 'stream') {
            self::assertSame(
                ['Done.'],
                iterator_to_array(
                    $subject->streamChatCompletion($messages),
                    false,
                ),
            );
        } elseif ($transport === 'chat') {
            self::assertSame(
                'Done.',
                $subject->chatCompletion($messages)->content,
            );
        } else {
            self::assertSame(
                'Done.',
                $subject->chatCompletionWithTools($messages, $this->tools())->content,
            );
        }
    }

    /**
     * @return list<ToolSpec>
     */
    private function tools(): array
    {
        return [
            ToolSpec::function(
                'id_alpha',
                'Alpha.',
                [
                    'type'       => 'object',
                    'properties' => ['zone' => ['type' => 'string']],
                    'required'   => ['zone'],
                ],
            ),
            ToolSpec::function(
                'id_beta',
                'Beta.',
                [
                    'type'       => 'object',
                    'properties' => ['zone' => ['type' => 'string']],
                    'required'   => ['zone'],
                ],
            ),
        ];
    }

    /** @param list<array{name:string,id?:string}> $calls
     * @return list<array<string,mixed>> */
    private function parts(array $calls): array
    {
        $parts = [['text' => 'Before.']];
        foreach ($calls as $call) {
            $function = ['name' => $call['name'], 'args' => ['zone' => 'UTC']];
            if (isset($call['id'])) {
                $function['id'] = $call['id'];
            }

            $parts[] = [
                'functionCall'     => $function,
                'thoughtSignature' => 'opaque+/signature==',
            ];
        }

        return $parts;
    }

    /**
     * @param list<array<string,mixed>> $parts
     */
    private function response(
        array $parts,
        bool $preserveNumbers = false,
    ): string {
        return json_encode(
            [
                'candidates' => [
                    [
                        'content'      => ['role' => 'model', 'parts' => $parts],
                        'finishReason' => 'STOP',
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR | ($preserveNumbers ? JSON_PRESERVE_ZERO_FRACTION : 0),
        );
    }

    private function finished(string $transport): string
    {
        $body = $this->response([['text' => 'Done.']]);

        return $transport === 'stream' ? 'data: ' . $body . "\n\n" : $body;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function items(CompletionResponse $response): array
    {
        $items = $response->metadata[OpenAiCallMetadata::KEY_PROVIDER_ITEMS] ?? null;
        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertIsArray($items[0]);
        $capsule = [];
        foreach ($items[0] as $key => $value) {
            self::assertIsString($key);
            $capsule[$key] = $value;
        }

        return [$capsule];
    }

    /**
     * @return array<array-key,mixed>
     */
    private function wire(RequestInterface $request): array
    {
        $wire = json_decode(
            (string) $request->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($wire);

        return $wire;
    }

    /** @param list<string> $responses
     * @param list<RequestInterface> $requests */
    private function provider(
        array $responses,
        array &$requests,
    ): GeminiProvider {
        $client = self::createMock(ClientInterface::class);
        $index  = 0;
        $client
            ->expects(self::exactly(count($responses)))
            ->method('sendRequest')
            ->willReturnCallback(
                static function (
                    RequestInterface $request,
                ) use (&$requests, &$index, $responses): ResponseInterface {
                    $requests[] = $request;

                    return new Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        $responses[$index++] ?? '{"candidates":[]}',
                    );
                },
            );
        $factory = new HttpFactory();
        $subject = new GeminiProvider(
            $factory,
            $factory,
            new NullLogger(),
            $this->createVaultServiceMock(),
            new SecureHttpClientFactory(),
        );
        $subject->configure(
            [
                'apiKeyIdentifier' => 'fixture-key',
                'defaultModel'     => 'gemini-native-id-fixture',
                'maxRetries'       => 0,
            ],
        );
        $subject->setHttpClient($client);

        return $subject;
    }

    #[Test]
    public function nativeIdCanRepeatAcrossSeparateCapturedTurns(): void
    {
        $parts    = $this->parts([['name' => 'id_alpha', 'id' => 'native-repeat']]);
        $requests = [];
        $subject  = $this->provider(
            [
                $this->response($parts),
                $this->response($parts),
                $this->finished('chat'),
            ],
            $requests,
        );
        $messages = [ChatMessage::user('Literal question.')];
        $ids      = [];
        foreach ([1, 2] as $turn) {
            $response = $subject->chatCompletionWithTools($messages, $this->tools());
            self::assertNotNull($response->toolCalls);
            self::assertCount(1, $response->toolCalls);
            $call = $response->toolCalls[0];
            self::assertNotContains($call->id, $ids);
            $ids[] = $call->id;
            self::assertSame(
                [['tool_call_id' => $call->id, 'native_id' => 'native-repeat']],
                $this->items($response)[0]['tool_call_bindings'] ?? null,
            );
            $messages[] = ChatMessage::assistantToolCalls(
                $response->toolCalls,
                $response->content,
                $this->items($response),
            );
            $messages[] = ChatMessage::toolResult(
                $call->id,
                json_encode(['turn' => $turn], JSON_THROW_ON_ERROR),
            );
        }

        self::assertSame('Done.', $subject->chatCompletion($messages)->content);
        self::assertCount(3, $requests);
        $wire = $this->wire($requests[2]);
        self::assertSame(
            [
                'name'     => 'id_alpha',
                'response' => ['turn' => 1],
                'id'       => 'native-repeat',
            ],
            $wire['contents'][2]['parts'][0]['functionResponse'] ?? null,
        );
        self::assertSame(
            [
                'name'     => 'id_alpha',
                'response' => ['turn' => 2],
                'id'       => 'native-repeat',
            ],
            $wire['contents'][4]['parts'][0]['functionResponse'] ?? null,
        );
    }

    #[Test]
    public function signedPlainCaptureKeepsNativePartsWithoutFabricatingBindings(): void
    {
        $parts    = $this->parts([['name' => 'id_alpha', 'id' => 'native-plain']]);
        $requests = [];
        $subject  = $this->provider(
            [$this->response($parts), $this->finished('chat')],
            $requests,
        );
        $first = $subject->chatCompletion([ChatMessage::user('Literal question.')]);
        self::assertNull($first->toolCalls);
        $items = $this->items($first);
        self::assertSame(
            [['type' => 'nrllm_gemini_generate_content', 'parts' => $parts]],
            $items,
        );
        self::assertSame(
            'Done.',
            $subject->chatCompletion(
                [
                    new ChatMessage(
                        'assistant',
                        $first->content,
                        providerItems: $items,
                    ),
                ],
            )->content,
        );
        self::assertCount(2, $requests);
        self::assertSame(
            $parts,
            $this->wire($requests[1])['contents'][0]['parts'] ?? null,
        );
    }

    #[Test]
    public function foreignCarrierCannotBindGeminiResultsAndGenericDuplicatesKeepExistingBehavior(): void
    {
        $requests = [];
        $subject  = $this->provider([$this->finished('chat')], $requests);
        $foreign  = [
            [
                'type'               => 'function_call',
                'call_id'            => 'shared-public',
                'name'               => 'id_alpha',
                'arguments'          => '{"zone":"UTC"}',
                'tool_call_bindings' => [
                    [
                        'tool_call_id' => 'shared-public',
                        'native_id'    => 'foreign-native',
                    ],
                ],
            ],
        ];
        $messages = [
            ChatMessage::assistantToolCalls(
                [
                    ToolCall::function(
                        'shared-public',
                        'id_alpha',
                        ['zone' => 'UTC'],
                    ),
                ],
                providerItems: $foreign,
            ),
            ChatMessage::assistantToolCalls(
                [
                    ToolCall::function(
                        'shared-public',
                        'id_beta',
                        ['zone' => 'UTC'],
                    ),
                ],
            ),
            ChatMessage::toolResult('shared-public', '{"result":"literal"}'),
        ];
        self::assertSame('Done.', $subject->chatCompletion($messages)->content);
        self::assertCount(1, $requests);
        self::assertSame(
            ['name' => 'id_beta', 'response' => ['result' => 'literal']],
            $this->wire($requests[0])['contents'][2]['parts'][0]['functionResponse'] ?? null,
        );
        self::assertStringNotContainsString(
            'foreign-native',
            (string) $requests[0]->getBody(),
        );
        self::assertStringNotContainsString(
            'tool_call_bindings',
            (string) $requests[0]->getBody(),
        );
    }

    #[Test]
    public function capturedBindingsStayPrivateOnResponsesFallback(): void
    {
        $requests = [];
        $subject  = $this->provider(
            [
                $this->response(
                    $this->parts([['name' => 'id_alpha', 'id' => 'native-a']]),
                ),
            ],
            $requests,
        );
        $first = $subject->chatCompletionWithTools(
            [ChatMessage::user('Question.')],
            $this->tools(),
        );
        self::assertNotNull($first->toolCalls);
        $id      = $first->toolCalls[0]->id;
        $payload = (new ResponsesPayloadBuilder())->build(
            'fallback-model',
            [
                ChatMessage::assistantToolCalls(
                    $first->toolCalls,
                    $first->content,
                    $this->items($first),
                ),
                ChatMessage::toolResult($id, '{"result":"literal"}'),
            ],
            [],
            100,
            null,
        );
        self::assertSame(
            [
                ['role' => 'assistant', 'content' => 'Before.'],
                [
                    'type'      => 'function_call',
                    'call_id'   => $id,
                    'name'      => 'id_alpha',
                    'arguments' => '{"zone":"UTC"}',
                ],
                [
                    'type'    => 'function_call_output',
                    'call_id' => $id,
                    'output'  => '{"result":"literal"}',
                ],
            ],
            $payload['input'],
        );
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('tool_call_bindings', $json);
        self::assertStringNotContainsString('native-a', $json);
        self::assertStringNotContainsString('thoughtSignature', $json);
        self::assertStringNotContainsString(
            'nrllm_gemini_generate_content',
            $json,
        );
    }

    /**
     * Real response capture followed by the ordinary transcript encoder changes
     * whole-valued floats to integers without changing their numeric value.
     */
    #[Test]
    #[DataProvider('reversibleNumericValues')]
    public function capturedAndLegacyNumbersSurviveTranscriptEncoding(
        int|float $native,
        int|float $wireValue,
        bool $legacy,
        bool $reverse,
    ): void {
        $arguments = [
            'zone'   => 'UTC',
            'weight' => $native,
            'nested' => ['weight' => $native, 'values' => [$native, 'literal']],
        ];
        $parts = [
            [
                'functionCall' => [
                    'name' => 'id_alpha',
                    'id'   => 'native-number',
                    'args' => $arguments,
                ],
                'thoughtSignature' => 'numeric-signature==',
            ],
        ];
        $body    = $this->response($parts, true);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(
            $native,
            $decoded['candidates'][0]['content']['parts'][0]['functionCall']['args']['weight'] ?? null,
        );
        $requests = [];
        $subject  = $this->provider([$body, $this->finished('tools')], $requests);
        $first    = $subject->chatCompletionWithTools(
            [ChatMessage::user('Numeric capture.')],
            $this->numericTools('number'),
            ['_capture_raw' => true],
        );
        self::assertNotNull($first->toolCalls);
        self::assertCount(1, $first->toolCalls);
        $call = $first->toolCalls[0];
        self::assertSame($arguments, $call->arguments);
        self::assertSame($decoded, $first->metadata['_raw'] ?? null);
        $items = $this->items($first);
        self::assertSame($parts, $items[0]['parts']);
        self::assertSame(
            [['tool_call_id' => $call->id, 'native_id' => 'native-number']],
            $items[0]['tool_call_bindings'] ?? null,
        );
        if ($legacy) {
            unset($items[0]['tool_call_bindings']);
        }

        $transcript = ChatMessage::assistantToolCalls([$call], $first->content, $items)->toTranscriptArray();
        self::assertArrayHasKey('tool_calls', $transcript);
        if ($reverse) {
            // A valid stored JSON argument may retain the decimal representation.
            $genericArguments = [
                'zone'   => 'UTC',
                'weight' => 1.0,
                'nested' => ['weight' => 1.0, 'values' => [1.0, 'literal']],
            ];
            $transcript['tool_calls'][0]['function']['arguments'] = json_encode(
                $genericArguments,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            );
        }

        $generic = json_decode(
            $transcript['tool_calls'][0]['function']['arguments'],
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($generic);
        self::assertSame(
            $reverse ? 1.0 : $wireValue,
            $generic['weight'] ?? null,
        );
        self::assertSame(
            $reverse ? 1.0 : $wireValue,
            $generic['nested']['weight'] ?? null,
        );
        $caught = null;
        $result = null;
        try {
            $result = $subject->chatCompletionWithTools(
                [
                    ChatMessage::user('Numeric capture.'),
                    $transcript,
                    ChatMessage::toolResult(
                        $call->id,
                        '{"result":"numeric-result"}',
                    ),
                ],
                $this->numericTools('number'),
            );
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertNull(
            $caught,
            'A reversible JSON-number representation must preserve the association.',
        );
        self::assertNotNull($result);
        self::assertSame('Done.', $result->content);
        self::assertCount(2, $requests);
        $wire = $this->wire($requests[1]);
        self::assertSame(
            [
                'name' => 'id_alpha',
                'id'   => 'native-number',
                'args' => [
                    'zone'   => 'UTC',
                    'weight' => $wireValue,
                    'nested' => [
                        'weight' => $wireValue,
                        'values' => [$wireValue, 'literal'],
                    ],
                ],
            ],
            $wire['contents'][1]['parts'][0]['functionCall'] ?? null,
        );
        self::assertSame(
            'numeric-signature==',
            $wire['contents'][1]['parts'][0]['thoughtSignature'] ?? null,
        );
        self::assertSame(
            [
                'name'     => 'id_alpha',
                'response' => ['result' => 'numeric-result'],
                'id'       => 'native-number',
            ],
            $wire['contents'][2]['parts'][0]['functionResponse'] ?? null,
        );
        self::assertSame($parts, $items[0]['parts']);
    }

    /**
     * @return iterable<string,array{int|float,int|float,bool,bool}>
     */
    public static function reversibleNumericValues(): iterable
    {
        foreach ([false, true] as $legacy) {
            $mode = $legacy ? 'legacy' : 'captured';
            yield $mode . ' positive whole float' => [1.0, 1, $legacy, false];
            yield $mode . ' negative whole float' => [-3.0, -3, $legacy, false];
            yield $mode . ' negative zero' => [-0.0, 0, $legacy, false];
            yield $mode . ' fractional control' => [1.25, 1.25, $legacy, false];
            yield $mode . ' integer with decimal generic JSON' => [1, 1, $legacy, true];
        }
    }

    #[Test]
    #[DataProvider('nonEquivalentNumericChanges')]
    public function numericMatchingStillRefusesOtherKindsAndRoundedIntegers(
        int|float|bool|string $native,
        int|float|bool|string $generic,
        bool $legacy,
        bool $nestedOnly,
    ): void {
        $arguments = [
            'zone'   => 'UTC',
            'weight' => $native,
            'nested' => ['weight' => $native, 'values' => [$native, 'literal']],
        ];
        $parts = [
            [
                'functionCall' => [
                    'name' => 'id_alpha',
                    'id'   => 'strict-number',
                    'args' => $arguments,
                ],
            ],
        ];
        $requests = [];
        $subject  = $this->provider([$this->response($parts, true)], $requests);
        $type     = is_bool($native) ? 'boolean' : (is_string($native) ? 'string' : 'number');
        $first    = $subject->chatCompletionWithTools(
            [ChatMessage::user('Strict capture.')],
            $this->numericTools($type),
        );
        self::assertNotNull($first->toolCalls);
        self::assertCount(1, $first->toolCalls);
        $call = $first->toolCalls[0];
        self::assertSame($arguments, $call->arguments);
        $items = $this->items($first);
        self::assertSame($parts, $items[0]['parts']);
        if ($legacy) {
            unset($items[0]['tool_call_bindings']);
        }

        $transcript = ChatMessage::assistantToolCalls([$call], $first->content, $items)->toTranscriptArray();
        $changed    = $arguments;
        if ($nestedOnly) {
            $changed['nested']['values'][0] = $generic;
        } else {
            $changed['weight'] = $generic;
        }

        $transcript['tool_calls'][0]['function']['arguments'] = json_encode($changed, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $caught                                               = null;
        try {
            $subject->chatCompletion(
                [
                    ChatMessage::user('Strict capture.'),
                    $transcript,
                    ChatMessage::toolResult(
                        $call->id,
                        '{"result":"must-not-contact"}',
                    ),
                ],
            );
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertInstanceOf(ProviderConfigurationException::class, $caught);
        self::assertSame(
            'Gemini replay state is malformed or ambiguous.',
            $caught->getMessage(),
        );
        self::assertCount(
            1,
            $requests,
            'A non-equivalent association must fail before another request.',
        );
    }

    /**
     * @return iterable<string,array{int|float|bool|string,int|float|bool|string,bool,bool}>
     */
    public static function nonEquivalentNumericChanges(): iterable
    {
        foreach ([false, true] as $legacy) {
            $mode = $legacy ? 'legacy' : 'captured';
            yield $mode . ' number versus string' => [1, '1', $legacy, false];
            yield $mode . ' string versus number' => ['1', 1, $legacy, false];
            yield $mode . ' boolean versus number' => [true, 1, $legacy, false];
            yield $mode . ' number versus boolean' => [1, true, $legacy, false];
            yield $mode . ' recursive numeric string' => [1, '1', $legacy, true];
            if (PHP_INT_SIZE === 8) {
                yield $mode . ' integer rounding cannot establish equality' => [9007199254740993, 9007199254740992.0, $legacy, false];
                yield $mode . ' reverse integer rounding cannot establish equality' => [9007199254740992.0, 9007199254740993, $legacy, false];
            }
        }
    }

    /**
     * @return list<ToolSpec>
     */
    private function numericTools(string $valueType): array
    {
        return [
            ToolSpec::function(
                'id_alpha',
                'Numeric association.',
                [
                    'type'       => 'object',
                    'properties' => [
                        'zone'   => ['type' => 'string'],
                        'weight' => ['type' => $valueType],
                        'nested' => [
                            'type'       => 'object',
                            'properties' => [
                                'weight' => ['type' => $valueType],
                                'values' => [
                                    'type'  => 'array',
                                    'items' => [
                                        'anyOf' => [
                                            ['type' => $valueType],
                                            ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['zone', 'weight', 'nested'],
                ],
            ),
        ];
    }

    #[Test]
    #[DataProvider('numericLegacyAmbiguity')]
    public function legacyRefusesTwoNativeSignaturesWithTheSameNumericValue(
        bool $reverse,
    ): void {
        $values = $reverse ? [1, 1.0] : [1.0, 1];
        $parts  = [];
        foreach ($values as $index => $value) {
            $parts[] = [
                'functionCall' => [
                    'name' => 'id_alpha',
                    'id'   => 'native-' . $index,
                    'args' => [
                        'zone'   => 'UTC',
                        'weight' => $value,
                        'nested' => ['weight' => $value, 'values' => [$value, 'literal']],
                    ],
                ],
            ];
        }

        $requests = [];
        $subject  = $this->provider([$this->response($parts, true)], $requests);
        $first    = $subject->chatCompletionWithTools(
            [ChatMessage::user('Legacy ambiguity.')],
            $this->numericTools('number'),
        );
        self::assertNotNull($first->toolCalls);
        self::assertCount(2, $first->toolCalls);
        self::assertSame($values[0], $first->toolCalls[0]->arguments['weight']);
        self::assertSame($values[1], $first->toolCalls[1]->arguments['weight']);
        $items = $this->items($first);
        self::assertSame($parts, $items[0]['parts']);
        unset($items[0]['tool_call_bindings']);
        $caught = null;
        try {
            $subject->chatCompletion(
                [
                    ChatMessage::user('Legacy ambiguity.'),
                    ChatMessage::assistantToolCalls(
                        [$first->toolCalls[0]],
                        $first->content,
                        $items,
                    ),
                    ChatMessage::toolResult(
                        $first->toolCalls[0]->id,
                        '{"result":"ambiguous"}',
                    ),
                ],
            );
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertInstanceOf(ProviderConfigurationException::class, $caught);
        self::assertSame(
            'Gemini replay state is malformed or ambiguous.',
            $caught->getMessage(),
        );
        self::assertCount(1, $requests);
    }

    /**
     * @return iterable<string,array{bool}>
     */
    public static function numericLegacyAmbiguity(): iterable
    {
        yield 'float then integer' => [false];
        yield 'integer then float' => [true];
    }
}
