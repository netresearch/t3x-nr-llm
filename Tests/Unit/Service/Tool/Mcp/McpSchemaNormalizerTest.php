<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\Mcp\McpSchemaNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(McpSchemaNormalizer::class)]
final class McpSchemaNormalizerTest extends TestCase
{
    private McpSchemaNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new McpSchemaNormalizer();
    }

    #[Test]
    #[DataProvider('nonObjectRoots')]
    public function aRootThatIsNotAnObjectSchemaIsRejected(mixed $inputSchema): void
    {
        self::assertNull($this->normalizer->normalise($inputSchema));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonObjectRoots(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['{"type":"object"}'];
        yield 'integer' => [42];
        yield 'boolean schema' => [true];
        yield 'list' => [[['type' => 'object']]];
        yield 'scalar root type' => [['type' => 'string']];
        yield 'array root type' => [['type' => 'array', 'items' => ['type' => 'string']]];
        yield 'type union with null' => [['type' => ['object', 'null'], 'properties' => []]];
        yield 'no declared type' => [['properties' => ['q' => ['type' => 'string']]]];
    }

    #[Test]
    public function aMinimalObjectSchemaPassesThroughUnchanged(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search term'],
                'limit' => ['type' => 'integer'],
            ],
            'required' => ['query'],
        ];

        self::assertSame($schema, $this->normalizer->normalise($schema));
    }

    #[Test]
    public function unknownTopLevelKeysAreDropped(): void
    {
        $result = $this->normalizer->normalise([
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            '$id'        => 'https://example.invalid/tool.json',
            'title'      => 'Search',
            'examples'   => [['query' => 'a']],
            'x-vendor'   => ['internal' => true],
            'type'       => 'object',
            'properties' => ['query' => ['type' => 'string']],
        ]);

        self::assertSame(
            [
                'type'       => 'object',
                'properties' => ['query' => ['type' => 'string']],
            ],
            $result,
        );
    }

    /**
     * @param array<string, mixed> $inputSchema
     */
    #[Test]
    #[DataProvider('unsupportedKeywordSchemas')]
    public function aKeywordWeCannotCarryOverRejectsTheWholeSchema(array $inputSchema): void
    {
        self::assertNull($this->normalizer->normalise($inputSchema));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unsupportedKeywordSchemas(): iterable
    {
        yield 'root allOf' => [[
            'type'       => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'allOf'      => [['required' => ['q']]],
        ]];

        yield 'reference into a dropped definition block' => [[
            'type'       => 'object',
            '$defs'      => ['Id' => ['type' => 'string']],
            'properties' => ['id' => ['$ref' => '#/$defs/Id']],
        ]];

        yield 'nested $ref' => [[
            'type'       => 'object',
            'properties' => ['id' => ['$ref' => 'https://example.invalid/Id']],
        ]];

        yield 'nested oneOf' => [[
            'type'       => 'object',
            'properties' => ['id' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]],
        ]];
    }

    #[Test]
    public function aSchemaAtTheDepthCapIsAccepted(): void
    {
        $schema = $this->nestedObjectSchema($this->acceptableNestingLevels());

        self::assertSame($schema, $this->normalizer->normalise($schema));
    }

    #[Test]
    public function aSchemaBeyondTheDepthCapIsRejected(): void
    {
        self::assertNull(
            $this->normalizer->normalise($this->nestedObjectSchema($this->acceptableNestingLevels() + 1)),
        );
    }

    #[Test]
    public function aSchemaBeyondTheByteCapIsRejected(): void
    {
        self::assertNull($this->normalizer->normalise([
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => str_repeat('a', McpSchemaNormalizer::MAX_ENCODED_BYTES),
                ],
            ],
        ]));
    }

    #[Test]
    public function anOversizedAnnotationIsDroppedBeforeTheByteCapIsMeasured(): void
    {
        $result = $this->normalizer->normalise([
            'type'       => 'object',
            'properties' => ['query' => ['type' => 'string']],
            'examples'   => [str_repeat('a', McpSchemaNormalizer::MAX_ENCODED_BYTES)],
        ]);

        self::assertSame(
            [
                'type'       => 'object',
                'properties' => ['query' => ['type' => 'string']],
            ],
            $result,
        );
    }

    /**
     * @param array<string, mixed> $inputSchema
     */
    #[Test]
    #[DataProvider('malformedRetainedValues')]
    public function aRetainedKeyWithAnUnexpectedValueTypeIsRejected(array $inputSchema): void
    {
        self::assertNull($this->normalizer->normalise($inputSchema));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedRetainedValues(): iterable
    {
        yield 'properties is a string' => [['type' => 'object', 'properties' => 'none']];
        yield 'property schema is a boolean' => [['type' => 'object', 'properties' => ['q' => true]]];
        yield 'required is a string' => [['type' => 'object', 'required' => 'query']];
        yield 'required is a map' => [['type' => 'object', 'required' => ['0' => 'q', '2' => 'r']]];
        yield 'required holds a non-string' => [['type' => 'object', 'required' => ['q', 7]]];
        yield 'description is an array' => [['type' => 'object', 'description' => ['a']]];
        yield 'additionalProperties is a schema' => [['type' => 'object', 'additionalProperties' => ['type' => 'string']]];
    }

    #[Test]
    public function emptyPropertiesSurvivesAsAnEmptyArrayBecauseToolSpecOwnsTheObjectCast(): void
    {
        $result = $this->normalizer->normalise(['type' => 'object', 'properties' => []]);

        self::assertNotNull($result);
        self::assertSame(['type' => 'object', 'properties' => []], $result);

        // ToolSpec turns the empty array into `{}` so strict providers accept it;
        // the normaliser must not do that a second time.
        self::assertInstanceOf(stdClass::class, ToolSpec::function('t', '', $result)->parameters['properties']);
    }

    /**
     * Nesting levels the cap still admits. The walk counts PHP array levels, so
     * one declared object level costs two: the `properties` map and the property
     * sub-schema. Derived from the constant so a changed cap does not silently
     * turn these two tests into assertions about the same side of the boundary.
     */
    private function acceptableNestingLevels(): int
    {
        return intdiv(McpSchemaNormalizer::MAX_DEPTH - 1, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function nestedObjectSchema(int $levels): array
    {
        $schema = ['type' => 'object', 'properties' => ['leaf' => ['type' => 'string']]];

        for ($level = 1; $level < $levels; ++$level) {
            $schema = ['type' => 'object', 'properties' => ['child' => $schema]];
        }

        return $schema;
    }

    #[Test]
    public function rejectionReasonNamesTheKeywordForARealWorldSchema(): void
    {
        // Verbatim from https://mcp.deepwiki.com/mcp (tools/list, 2026-08-20):
        // the `ask_question` tool, whose `repoName` accepts either one repo or
        // a list. Two sibling tools on the same server import fine — this is
        // the one an operator sees skipped, and "no usable parameter schema"
        // gave them nothing to act on.
        $schema = [
            'type'       => 'object',
            'properties' => [
                'repoName' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['items' => ['type' => 'string'], 'type' => 'array'],
                    ],
                    'description' => 'GitHub repository or list of repositories (max 10) in owner/repo format.',
                ],
                'question' => [
                    'description' => 'The question to ask about the repository.',
                    'type'        => 'string',
                ],
            ],
            'required' => ['repoName', 'question'],
        ];

        $subject = new McpSchemaNormalizer();

        self::assertNull($subject->normalise($schema), 'the schema is still refused');

        $reason = $subject->rejectionReason($schema);
        self::assertIsString($reason);
        self::assertStringContainsString('anyOf', $reason);
    }

    #[Test]
    public function rejectionReasonIsNullForASchemaThatPasses(): void
    {
        $subject = new McpSchemaNormalizer();

        // The sibling tool from the same server, which does import.
        $schema = [
            'type'       => 'object',
            'properties' => [
                'repoName' => [
                    'description' => 'GitHub repository in owner/repo format.',
                    'type'        => 'string',
                ],
            ],
            'required' => ['repoName'],
        ];

        self::assertNotNull($subject->normalise($schema));
        self::assertNull($subject->rejectionReason($schema));
    }

    #[Test]
    public function rejectionReasonDoesNotBlameAKeywordInADroppedKey(): void
    {
        // `outputSchema`-style siblings and other non-retained keys are filtered
        // out before the walk, so a keyword sitting in one of them is not what
        // refused the schema — reporting it would send the operator after the
        // wrong thing.
        $subject = new McpSchemaNormalizer();

        $schema = [
            'type'       => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required'   => ['q'],
            'examples'   => [['anyOf' => [['type' => 'string']]]],
        ];

        self::assertNotNull($subject->normalise($schema));
        self::assertNull($subject->rejectionReason($schema));
    }
}
