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

    #[Test]
    public function nativeProviderItemsAndTypedToolArgumentsNeedWiderIdentitySupport(): void
    {
        self::assertSame(
            '',
            CompletionRequestIdentity::fingerprint(
                [
                    new ChatMessage(
                        'assistant',
                        'ACK',
                        providerItems: [['opaque' => 'provider-owned']],
                    ),
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
                                ['text' => 'literal'],
                            ),
                        ],
                    ),
                ],
                [],
            ),
        );
        self::assertNotSame(
            '',
            CompletionRequestIdentity::fingerprint(
                [
                    [
                        'role'    => 'assistant',
                        'content' => 'ACK',
                        'custom'  => ['literal' => null],
                    ],
                ],
                [],
            ),
        );
    }
}
