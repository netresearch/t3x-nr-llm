<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolSpec::class)]
final class ToolSpecTest extends TestCase
{
    private const PARAMS = [
        'type'       => 'object',
        'properties' => [
            'city' => ['type' => 'string'],
        ],
        'required' => ['city'],
    ];

    #[Test]
    public function constructorAcceptsValidInputs(): void
    {
        $spec = new ToolSpec(
            name: 'get_weather',
            description: 'Get the weather for a city',
            parameters: self::PARAMS,
        );

        self::assertSame('get_weather', $spec->name);
        self::assertSame('Get the weather for a city', $spec->description);
        self::assertSame(self::PARAMS, $spec->parameters);
        self::assertSame(ToolSpec::TYPE_FUNCTION, $spec->type);
    }

    #[Test]
    public function functionFactoryDefaultsTypeToFunction(): void
    {
        $spec = ToolSpec::function('get_weather', '...', self::PARAMS);

        self::assertSame(ToolSpec::TYPE_FUNCTION, $spec->type);
    }

    #[Test]
    public function constructorRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745410001);

        new ToolSpec(name: '', description: 'd', parameters: []);
    }

    #[Test]
    public function constructorRejectsEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745410002);

        new ToolSpec(name: 'n', description: 'd', parameters: [], type: '');
    }

    #[Test]
    public function fromArrayReadsWireShape(): void
    {
        $wire = [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_weather',
                'description' => 'Weather lookup',
                'parameters'  => self::PARAMS,
            ],
        ];

        $spec = ToolSpec::fromArray($wire);

        self::assertSame('get_weather', $spec->name);
        self::assertSame('Weather lookup', $spec->description);
        self::assertSame(self::PARAMS, $spec->parameters);
        self::assertSame('function', $spec->type);
    }

    #[Test]
    public function fromArrayDefaultsMissingDescriptionAndParameters(): void
    {
        $spec = ToolSpec::fromArray([
            'function' => ['name' => 'noop'],
        ]);

        self::assertSame('noop', $spec->name);
        self::assertSame('', $spec->description);
        self::assertSame([], $spec->parameters);
        self::assertSame(ToolSpec::TYPE_FUNCTION, $spec->type);
    }

    #[Test]
    public function fromArrayThrowsWhenFunctionKeyMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745410003);

        /** @phpstan-ignore-next-line argument.type intentional malformed input */
        ToolSpec::fromArray(['type' => 'function']);
    }

    #[Test]
    public function fromArrayThrowsWhenFunctionNameMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745410004);

        /** @phpstan-ignore-next-line argument.type intentional malformed input */
        ToolSpec::fromArray(['function' => ['description' => 'no name']]);
    }

    #[Test]
    public function toArrayProducesIdempotentWireShape(): void
    {
        $spec = ToolSpec::function('get_weather', 'Get weather', self::PARAMS);

        self::assertSame(
            [
                'type'     => 'function',
                'function' => [
                    'name'        => 'get_weather',
                    'description' => 'Get weather',
                    'parameters'  => self::PARAMS,
                ],
            ],
            $spec->toArray(),
        );
    }

    #[Test]
    public function fromArrayAndToArrayRoundTrip(): void
    {
        $wire = [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_weather',
                'description' => 'Get weather',
                'parameters'  => self::PARAMS,
            ],
        ];

        self::assertSame($wire, ToolSpec::fromArray($wire)->toArray());
    }

    #[Test]
    public function jsonSerializeMatchesToArray(): void
    {
        $spec = ToolSpec::function('a', 'b', ['type' => 'object']);

        self::assertSame($spec->toArray(), $spec->jsonSerialize());
    }
}
