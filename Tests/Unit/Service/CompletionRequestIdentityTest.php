<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use JsonSerializable;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\CompletionRequestIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CompletionRequestIdentity::class)]
final class CompletionRequestIdentityTest extends TestCase
{
    #[Test]
    public function mapOrderAndGeneratorDoNotChangeEffectiveTaskIdentity(): void
    {
        $messages = [ChatMessage::system('Be concise'), ChatMessage::user('Reply ACK')];
        $first    = CompletionRequestIdentity::fingerprint(
            $messages,
            [
                'model'           => 'first',
                'provider'        => 'a',
                'temperature'     => 0.2,
                'response_format' => ['type' => 'json', 'schema' => ['b' => 2, 'a' => 1]],
            ],
        );
        $second = CompletionRequestIdentity::fingerprint(
            $messages,
            [
                'response_format' => ['schema' => ['a' => 1, 'b' => 2], 'type' => 'json'],
                'temperature'     => 0.2,
                'provider'        => 'b',
                'model'           => 'second',
            ],
        );
        self::assertSame($first, $second);
        self::assertSame($first, CompletionRequestIdentity::read($first));
        self::assertNotSame(
            $first,
            CompletionRequestIdentity::fingerprint(
                array_reverse($messages),
                ['temperature' => 0.2],
            ),
        );
    }

    #[Test]
    public function exactContentAndOrderedSettingsRemainSignificant(): void
    {
        $messages = [ChatMessage::user("Reply \xff")];
        $first    = CompletionRequestIdentity::fingerprint(
            $messages,
            ['stop_sequences' => ['one', 'two']],
        );
        self::assertNotSame(
            $first,
            CompletionRequestIdentity::fingerprint(
                [ChatMessage::user("Reply \xfe")],
                ['stop_sequences' => ['one', 'two']],
            ),
        );
        self::assertNotSame(
            $first,
            CompletionRequestIdentity::fingerprint(
                $messages,
                ['stop_sequences' => ['two', 'one']],
            ),
        );
        self::assertSame('', CompletionRequestIdentity::read(null));
        self::assertSame('', CompletionRequestIdentity::read('legacy'));
    }

    #[Test]
    public function unsupportedOptionsRemainUnknownWithoutCallingObjectHooks(): void
    {
        $object = new class implements JsonSerializable {
            /**
             * @return array<string, mixed>
             */
            public function __serialize(): array
            {
                throw new RuntimeException('Serialization must not run.', 2320250185);
            }

            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('JSON serialization must not run.', 9970859554);
            }
        };
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        try {
            foreach ([static fn (): string => 'callback', $object, $resource] as $unsupported) {
                self::assertSame(
                    '',
                    CompletionRequestIdentity::fingerprint(
                        [ChatMessage::user('ACK')],
                        ['custom' => ['nested' => $unsupported]],
                    ),
                );
            }

            self::assertSame(
                '',
                CompletionRequestIdentity::fingerprint(
                    [
                        [
                            'role'    => 'user',
                            'content' => 'ACK',
                            'custom'  => $object,
                        ],
                    ],
                    [],
                ),
            );
            self::assertSame(
                '',
                CompletionRequestIdentity::fingerprint(
                    [
                        ChatMessage::assistantToolCalls(
                            [
                                ToolCall::function(
                                    'id',
                                    'tool',
                                    ['custom' => $object],
                                ),
                            ],
                        ),
                    ],
                    [],
                ),
            );
        } finally {
            fclose($resource);
        }
    }

    #[Test]
    public function recursiveAndExcessivelyDeepArraysRemainUnknown(): void
    {
        $recursive         = [];
        $recursive['self'] = & $recursive;
        self::assertSame(
            '',
            CompletionRequestIdentity::fingerprint(
                [ChatMessage::user('ACK')],
                ['custom' => $recursive],
            ),
        );
        $deep = 'value';
        for ($i = 0; $i < 70; ++$i) {
            $deep = ['nested' => $deep];
        }

        self::assertSame(
            '',
            CompletionRequestIdentity::fingerprint(
                [ChatMessage::user('ACK')],
                ['custom' => $deep],
            ),
        );
    }

    /**
     * ADR-323 widens the identity to tool calls and provider items. Every
     * request that had an identity before keeps exactly its bytes, because
     * ADR-271's benchmark identity compares them across releases.
     */
    #[Test]
    public function identitiesThatExistedKeepTheirBytes(): void
    {
        self::assertSame(
            'v1:0fde2089bb682218c3f0484a095e256d4a1a1766870593f31e4068702dc58de8',
            CompletionRequestIdentity::fingerprint([ChatMessage::system('Be concise'), ChatMessage::user('Reply ACK')], ['temperature' => 0.2, 'max_tokens' => 64, 'model' => 'gpt-5']),
        );
        self::assertSame(
            'v1:a0347d562a9a0a361e1dde22c4ba0c5440ee9506c69c333f4045f7a3b339a6b2',
            CompletionRequestIdentity::fingerprint([['role' => 'user', 'content' => 'ACK', 'custom' => ['literal' => null]]], []),
        );
        self::assertSame(
            'v1:cfe222d3d9503f69be47f6ac3fc2347a5e18058cafd0ccac23514edd1a59f9af',
            CompletionRequestIdentity::fingerprint([ChatMessage::user('Q'), new ChatMessage('tool', 'result text', toolCallId: 'call_1')], ['response_format' => 'json']),
        );
    }

    /**
     * ADR-323: a request that carries tool calls or provider items has an
     * identity now. What a provider generates afresh for every run, the call
     * ids and its own items, does not count, nor does the order of argument
     * keys; the arguments and which result answers which call do.
     */
    #[Test]
    public function toolCallsAndProviderItemsHaveAnIdentity(): void
    {
        $call = static fn (string $id, string $q): array => [
            ChatMessage::user('Q'),
            ChatMessage::assistantToolCalls([ToolCall::function($id, 'search', $q === 'reordered' ? ['limit' => 3, 'q' => 'x'] : ['q' => $q, 'limit' => 3])]),
            new ChatMessage('tool', 'found', toolCallId: $id),
        ];

        $identity = CompletionRequestIdentity::fingerprint($call('call_1', 'x'), []);
        self::assertMatchesRegularExpression('/^v1:[a-f0-9]{64}$/D', $identity);
        self::assertSame($identity, CompletionRequestIdentity::fingerprint($call('call_1', 'reordered'), []));
        self::assertSame($identity, CompletionRequestIdentity::fingerprint($call('toolu_9', 'x'), []), 'the call id a provider generated does not count');
        self::assertNotSame($identity, CompletionRequestIdentity::fingerprint($call('call_1', 'y'), []));

        $answers = static fn (string $answered): string => CompletionRequestIdentity::fingerprint([
            ChatMessage::assistantToolCalls([ToolCall::function('a', 'search', ['q' => 'x']), ToolCall::function('b', 'search', ['q' => 'y'])]),
            new ChatMessage('tool', 'found', toolCallId: $answered),
        ], []);
        self::assertNotSame($answers('a'), $answers('b'), 'which call a result answers counts');

        $native = static fn (string $opaque): string => CompletionRequestIdentity::fingerprint(
            [new ChatMessage('assistant', 'ACK', providerItems: [['opaque' => $opaque]])],
            [],
        );
        self::assertMatchesRegularExpression('/^v1:[a-f0-9]{64}$/D', $native('provider-owned'));
        self::assertSame($native('provider-owned'), $native('signature-of-another-run'), 'provider items do not count');
    }
}
