<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolCall::class)]
final class ToolCallTest extends TestCase
{
    #[Test]
    public function constructorAcceptsValidInputs(): void
    {
        $call = new ToolCall(
            id: 'call_abc',
            name: 'get_weather',
            arguments: ['city' => 'Berlin'],
        );

        self::assertSame('call_abc', $call->id);
        self::assertSame('get_weather', $call->name);
        self::assertSame(['city' => 'Berlin'], $call->arguments);
        self::assertSame(ToolCall::TYPE_FUNCTION, $call->type);
    }

    #[Test]
    public function functionFactoryDefaultsTypeToFunction(): void
    {
        $call = ToolCall::function('id1', 'fn', ['a' => 1]);

        self::assertSame(ToolCall::TYPE_FUNCTION, $call->type);
    }

    #[Test]
    public function constructorRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745411001);

        new ToolCall(id: '', name: 'fn', arguments: []);
    }

    #[Test]
    public function constructorRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745411002);

        new ToolCall(id: 'id', name: '', arguments: []);
    }

    #[Test]
    public function constructorRejectsEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745411003);

        new ToolCall(id: 'id', name: 'fn', arguments: [], type: '');
    }

    #[Test]
    public function fromArrayDecodesJsonStringArguments(): void
    {
        $wire = [
            'id'   => 'call_abc',
            'type' => 'function',
            'function' => [
                'name'      => 'get_weather',
                'arguments' => '{"city":"Berlin","unit":"celsius"}',
            ],
        ];

        $call = ToolCall::fromArray($wire);

        self::assertSame('call_abc', $call->id);
        self::assertSame('get_weather', $call->name);
        self::assertSame(['city' => 'Berlin', 'unit' => 'celsius'], $call->arguments);
    }

    #[Test]
    public function fromArrayAcceptsAlreadyDecodedArrayArguments(): void
    {
        // OpenAiProvider's legacy extractor produces this shape after
        // `json_decode`. The factory must accept it without re-decoding.
        $alreadyDecoded = [
            'id'   => 'call_abc',
            'type' => 'function',
            'function' => [
                'name'      => 'fn',
                'arguments' => ['x' => 1, 'y' => 2],
            ],
        ];

        $call = ToolCall::fromArray($alreadyDecoded);

        self::assertSame(['x' => 1, 'y' => 2], $call->arguments);
    }

    #[Test]
    public function fromArrayProducesEmptyArgumentsForMissingOrMalformed(): void
    {
        $cases = [
            'missing arguments' => [
                'id' => 'a', 'function' => ['name' => 'fn'],
            ],
            'empty string arguments' => [
                'id' => 'a', 'function' => ['name' => 'fn', 'arguments' => ''],
            ],
            'malformed json arguments' => [
                'id' => 'a', 'function' => ['name' => 'fn', 'arguments' => 'not-json'],
            ],
            'json scalar arguments' => [
                'id' => 'a', 'function' => ['name' => 'fn', 'arguments' => '"just-a-string"'],
            ],
        ];

        foreach ($cases as $label => $wire) {
            /** @phpstan-ignore-next-line argument.type fixture intentionally varies */
            $call = ToolCall::fromArray($wire);
            self::assertSame([], $call->arguments, $label);
        }
    }

    #[Test]
    public function fromArrayDefaultsTypeToFunctionWhenAbsent(): void
    {
        $call = ToolCall::fromArray([
            'id' => 'id1',
            'function' => ['name' => 'fn'],
        ]);

        self::assertSame(ToolCall::TYPE_FUNCTION, $call->type);
    }

    #[Test]
    public function fromArrayPropagatesEmptyIdAndNameToConstructor(): void
    {
        // Defensive: the wire shape can legitimately omit fields when a
        // provider misbehaves. The constructor's invariant guards still
        // catch this — the factory does not silently default to dummy
        // values.
        $this->expectException(InvalidArgumentException::class);

        ToolCall::fromArray([]);
    }

    #[Test]
    public function toArrayProducesLegacyOpenAiShape(): void
    {
        $call = ToolCall::function('call_abc', 'get_weather', ['city' => 'Berlin']);

        self::assertSame(
            [
                'id'   => 'call_abc',
                'type' => 'function',
                'function' => [
                    'name'      => 'get_weather',
                    'arguments' => ['city' => 'Berlin'],
                ],
            ],
            $call->toArray(),
        );
    }

    #[Test]
    public function fromArrayWithJsonStringArgumentsAndToArrayRoundTrip(): void
    {
        $wire = [
            'id'   => 'call_abc',
            'type' => 'function',
            'function' => [
                'name'      => 'get_weather',
                'arguments' => '{"city":"Berlin"}',
            ],
        ];

        $expected = [
            'id'   => 'call_abc',
            'type' => 'function',
            'function' => [
                'name'      => 'get_weather',
                'arguments' => ['city' => 'Berlin'],
            ],
        ];

        self::assertSame($expected, ToolCall::fromArray($wire)->toArray());
    }

    #[Test]
    public function jsonSerializeMatchesToArray(): void
    {
        $call = ToolCall::function('id1', 'fn', ['a' => 1]);

        self::assertSame($call->toArray(), $call->jsonSerialize());
    }
}
