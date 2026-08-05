<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Provider\OpenAiResponseFormatTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The three rungs of ADR-128's OpenAI-family emission and the conservative
 * strict-mode profile. The profile's one job: a schema OpenAI's strict mode
 * would reject must degrade to json_object, never surface as an HTTP 400.
 */
#[CoversTrait(OpenAiResponseFormatTrait::class)]
final class OpenAiResponseFormatTraitTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private function build(array $options): ?array
    {
        $subject = new class {
            use OpenAiResponseFormatTrait;

            /**
             * @param array<string, mixed> $options
             *
             * @return array<string, mixed>|null
             */
            public function buildPublic(array $options): ?array
            {
                return $this->buildResponseFormat($options);
            }
        };

        return $subject->buildPublic($options);
    }

    #[Test]
    public function noFormatAndNoSchemaEmitsNothing(): void
    {
        self::assertNull($this->build([]));
        self::assertNull($this->build(['response_format' => 'text']));
        self::assertNull($this->build(['response_format' => 'markdown']));
    }

    #[Test]
    public function plainJsonFormatEmitsJsonObject(): void
    {
        self::assertSame(['type' => 'json_object'], $this->build(['response_format' => 'json']));
    }

    #[Test]
    public function qualifyingSchemaEmitsStrictJsonSchema(): void
    {
        $schema = [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'title' => ['type' => 'string', 'description' => 'The title'],
                'tags'  => [
                    'type'  => 'array',
                    'items' => ['type' => 'string', 'enum' => ['a', 'b']],
                ],
                'nested' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => ['n' => ['type' => 'integer']],
                    'required'             => ['n'],
                ],
            ],
            'required' => ['title', 'tags', 'nested'],
        ];

        $result = $this->build(['response_schema' => $schema]);

        self::assertNotNull($result);
        self::assertSame('json_schema', $result['type']);
        assert(isset($result['json_schema']) && is_array($result['json_schema']));
        self::assertTrue($result['json_schema']['strict']);
        self::assertSame($schema, $result['json_schema']['schema']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nonQualifyingSchemas(): iterable
    {
        yield 'missing additionalProperties:false' => [[
            'type'       => 'object',
            'properties' => ['a' => ['type' => 'string']],
            'required'   => ['a'],
        ]];
        yield 'a property not listed as required' => [[
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']],
            'required'             => ['a'],
        ]];
        yield 'required names outside properties' => [[
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => ['a' => ['type' => 'string']],
            'required'             => ['a', 'ghost'],
        ]];
        yield 'union type' => [[
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => ['a' => ['type' => ['string', 'null']]],
            'required'             => ['a'],
        ]];
        yield 'keyword outside the conservative allowlist' => [[
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => ['a' => ['type' => 'string', 'pattern' => '^x']],
            'required'             => ['a'],
        ]];
        yield 'non-object root' => [['enum' => ['a', 'b']]];
        yield 'a non-string entry in required' => [[
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => ['a' => ['type' => 'string']],
            // Filtering the 5 away would qualify a schema whose ORIGINAL
            // form is sent — fail closed instead.
            'required' => ['a', 5],
        ]];
        yield 'properties as a list' => [[
            'type'                 => 'object',
            'additionalProperties' => false,
            // A list would JSON-encode as [] instead of {} and be rejected.
            'properties' => [['type' => 'string']],
            'required'   => ['0'],
        ]];
        yield 'nested object breaks the rules' => [[
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'inner' => [
                    'type'       => 'object',
                    'properties' => ['x' => ['type' => 'string']],
                    'required'   => ['x'],
                ],
            ],
            'required' => ['inner'],
        ]];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[Test]
    #[DataProvider('nonQualifyingSchemas')]
    public function nonQualifyingSchemaDegradesToJsonObject(array $schema): void
    {
        self::assertSame(['type' => 'json_object'], $this->build(['response_schema' => $schema]));
    }

    #[Test]
    public function emptyPropertiesDegradesToJsonObject(): void
    {
        // An empty `properties` map would encode as JSON `[]` instead of
        // `{}` (PHP's empty-array ambiguity) — out of the profile.
        self::assertSame(['type' => 'json_object'], $this->build(['response_schema' => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [],
            'required'             => [],
        ]]));
    }
}
