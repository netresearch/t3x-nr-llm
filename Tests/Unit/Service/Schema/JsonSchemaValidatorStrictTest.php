<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Schema;

use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Service\Schema\StrictSchemaSubset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The strict mode (ADR-126): every subset keyword enforced, everything
 * outside the subset fail-closed. The lenient mode's own 9 tests next door
 * stay untouched — its semantics are a security boundary (ADR-105) and must
 * not move.
 */
#[CoversClass(JsonSchemaValidator::class)]
#[CoversClass(StrictSchemaSubset::class)]
final class JsonSchemaValidatorStrictTest extends TestCase
{
    private JsonSchemaValidator $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new JsonSchemaValidator();
    }

    // ---- subset membership (the pre-flight surface) ----

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function subsetMembership(): iterable
    {
        yield 'plain object schema' => [['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]], true];
        yield 'annotations are welcome' => [['type' => 'string', 'description' => 'x', 'title' => 't', 'default' => 'd', 'format' => 'email', '$schema' => 'https://json-schema.org/draft/2020-12/schema', '$id' => 'x', 'examples' => ['a']], true];
        yield 'enum, const, pattern' => [['type' => 'string', 'enum' => ['a', 'b'], 'const' => 'a', 'pattern' => '^[a-z]+$'], true];
        yield 'numeric bounds' => [['type' => 'number', 'minimum' => 0, 'maximum' => 10.5, 'exclusiveMinimum' => -1, 'exclusiveMaximum' => 11, 'multipleOf' => 2], true];
        yield 'array keywords' => [['type' => 'array', 'items' => ['type' => 'integer'], 'prefixItems' => [['type' => 'string']], 'minItems' => 0, 'maxItems' => 5, 'uniqueItems' => true], true];
        yield 'combinators' => [['oneOf' => [['type' => 'string'], ['type' => 'integer']], 'not' => ['type' => 'null']], true];
        yield 'additionalProperties as schema' => [['type' => 'object', 'additionalProperties' => ['type' => 'string']], true];

        yield 'empty schema is degenerate' => [[], false];
        yield 'annotations alone assert nothing' => [['description' => 'x', 'title' => 't'], false];
        yield 'unknown keyword' => [['type' => 'string', 'if' => ['type' => 'string']], false];
        yield '$ref is out of subset' => [['$ref' => '#/definitions/x'], false];
        yield 'definitions are out of subset' => [['type' => 'object', 'definitions' => []], false];
        yield 'unknown type name' => [['type' => 'text'], false];
        yield 'non-compiling pattern' => [['type' => 'string', 'pattern' => '([a-z'], false];
        yield 'float multipleOf is out of subset' => [['type' => 'number', 'multipleOf' => 0.1], false];
        yield 'zero multipleOf is out of subset' => [['type' => 'integer', 'multipleOf' => 0], false];
        yield 'negative minLength' => [['type' => 'string', 'minLength' => -1], false];
        yield 'empty enum' => [['enum' => []], false];
        yield 'nested unknown keyword fails the whole schema' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'patternProperties' => []]]], false];
        yield 'tuple items form of draft-04 is out of subset' => [['type' => 'array', 'items' => [['type' => 'string']]], false];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[Test]
    #[DataProvider('subsetMembership')]
    public function supportsSchemaDrawsTheSubsetBoundary(array $schema, bool $expected): void
    {
        self::assertSame($expected, $this->subject->supportsSchema($schema));
    }

    /**
     * An out-of-subset schema rejects every instance — fail-closed, and the
     * reason callers must pre-flight before paying for a provider call.
     */
    #[Test]
    public function strictRejectsAnyDataAgainstAnUnsupportedSchema(): void
    {
        self::assertFalse($this->subject->validateStrict('anything', ['unknownKeyword' => true]));
        self::assertFalse($this->subject->validateStrict([], []));
    }

    // ---- data validation per keyword ----

    /**
     * @return iterable<string, array{mixed, array<string, mixed>, bool}>
     */
    public static function strictCases(): iterable
    {
        // enum / const — JSON equality: maps key-order-insensitive
        yield 'enum hit' => ['b', ['enum' => ['a', 'b']], true];
        yield 'enum miss' => ['c', ['enum' => ['a', 'b']], false];
        yield 'enum with object member, key order irrelevant' => [['y' => 2, 'x' => 1], ['enum' => [['x' => 1, 'y' => 2]]], true];
        yield 'enum does not type-juggle' => ['1', ['enum' => [1]], false];
        yield 'const hit' => [42, ['const' => 42], true];
        yield 'const miss int vs float' => [1.0, ['const' => 1], false];

        // pattern — applies to strings only
        yield 'pattern match' => ['abc', ['type' => 'string', 'pattern' => '^[a-c]+$'], true];
        yield 'pattern miss' => ['abd', ['type' => 'string', 'pattern' => '^[a-c]+$'], false];
        yield 'pattern ignores non-strings' => [7, ['pattern' => '^[a-c]+$'], true];
        yield 'pattern with slash needs no delimiter escaping by the author' => ['a/b', ['pattern' => '^a/b$'], true];

        // string lengths — code points, not bytes
        yield 'minLength boundary hit' => ['ab', ['minLength' => 2], true];
        yield 'minLength boundary miss' => ['a', ['minLength' => 2], false];
        yield 'maxLength boundary hit' => ['ab', ['maxLength' => 2], true];
        yield 'maxLength boundary miss' => ['abc', ['maxLength' => 2], false];
        yield 'umlauts count as one' => ['äöüß', ['maxLength' => 4], true];

        // numeric bounds — boundary twins for the mutation gate
        yield 'minimum inclusive' => [5, ['minimum' => 5], true];
        yield 'minimum miss' => [4, ['minimum' => 5], false];
        yield 'maximum inclusive' => [5, ['maximum' => 5], true];
        yield 'maximum miss' => [6, ['maximum' => 5], false];
        yield 'exclusiveMinimum boundary rejected' => [5, ['exclusiveMinimum' => 5], false];
        yield 'exclusiveMinimum above passes' => [6, ['exclusiveMinimum' => 5], true];
        yield 'exclusiveMaximum boundary rejected' => [5, ['exclusiveMaximum' => 5], false];
        yield 'exclusiveMaximum below passes' => [4, ['exclusiveMaximum' => 5], true];
        yield 'multipleOf hit' => [9, ['multipleOf' => 3], true];
        yield 'multipleOf miss' => [10, ['multipleOf' => 3], false];
        yield 'multipleOf on integral float' => [9.0, ['multipleOf' => 3], true];
        yield 'bounds ignore strings' => ['x', ['minimum' => 5], true];

        // union types
        yield 'union type first branch' => ['x', ['type' => ['string', 'null']], true];
        yield 'union type second branch' => [null, ['type' => ['string', 'null']], true];
        yield 'union type miss' => [5, ['type' => ['string', 'null']], false];
        yield 'five point zero is not an integer' => [5.0, ['type' => 'integer'], false];

        // arrays
        yield 'items enforced on every element' => [[1, 2], ['type' => 'array', 'items' => ['type' => 'integer']], true];
        yield 'items rejects a stray string' => [[1, 'x'], ['type' => 'array', 'items' => ['type' => 'integer']], false];
        yield 'prefixItems positional' => [['a', 1], ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]], true];
        yield 'prefixItems positional miss' => [[1, 'a'], ['prefixItems' => [['type' => 'string'], ['type' => 'integer']]], false];
        yield 'items constrains the rest after prefixItems' => [['a', 1, 2], ['prefixItems' => [['type' => 'string']], 'items' => ['type' => 'integer']], true];
        yield 'rest violation after prefixItems' => [['a', 1, 'x'], ['prefixItems' => [['type' => 'string']], 'items' => ['type' => 'integer']], false];
        yield 'minItems boundary' => [[1], ['minItems' => 1], true];
        yield 'minItems miss' => [[], ['type' => 'array', 'minItems' => 1], false];
        yield 'maxItems boundary' => [[1, 2], ['maxItems' => 2], true];
        yield 'maxItems miss' => [[1, 2, 3], ['maxItems' => 2], false];
        yield 'uniqueItems on scalars' => [[1, 2, 1], ['uniqueItems' => true], false];
        yield 'uniqueItems key-order-insensitive on objects' => [[['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]], ['uniqueItems' => true], false];
        yield 'uniqueItems does not juggle types' => [[1, '1'], ['uniqueItems' => true], true];

        // objects
        yield 'required present' => [['a' => 1], ['type' => 'object', 'required' => ['a']], true];
        yield 'required missing' => [['b' => 1], ['type' => 'object', 'required' => ['a']], false];
        yield 'required with explicit null passes, as in lenient' => [['a' => null], ['type' => 'object', 'required' => ['a']], true];
        yield 'required on a non-object fails' => ['x', ['required' => ['a']], false];
        yield 'additionalProperties false rejects extras' => [['a' => 1, 'b' => 2], ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => false], false];
        yield 'additionalProperties false with only declared keys' => [['a' => 1], ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => false], true];
        yield 'additionalProperties schema validates extras' => [['a' => 1, 'b' => 'x'], ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => ['type' => 'string']], true];
        yield 'additionalProperties schema rejects wrong extras' => [['a' => 1, 'b' => 2], ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']], 'additionalProperties' => ['type' => 'string']], false];
        yield 'nested property enforcement' => [['a' => ['b' => 'x']], ['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['type' => 'string', 'minLength' => 1]]]]], true];

        // combinators
        yield 'oneOf exactly one' => ['x', ['oneOf' => [['type' => 'string'], ['type' => 'integer']]], true];
        yield 'oneOf zero matches' => [null, ['oneOf' => [['type' => 'string'], ['type' => 'integer']]], false];
        yield 'oneOf two matches rejected' => [5, ['oneOf' => [['type' => 'integer'], ['type' => 'number']]], false];
        yield 'anyOf at least one' => [5, ['anyOf' => [['type' => 'integer'], ['type' => 'number']]], true];
        yield 'anyOf none' => ['x', ['anyOf' => [['type' => 'integer'], ['type' => 'number']]], false];
        yield 'allOf all must hold' => [5, ['allOf' => [['type' => 'integer'], ['minimum' => 5]]], true];
        yield 'allOf one branch fails' => [4, ['allOf' => [['type' => 'integer'], ['minimum' => 5]]], false];
        yield 'not inverts' => ['x', ['type' => 'string', 'not' => ['const' => 'y']], true];
        yield 'not rejects the match' => ['y', ['type' => 'string', 'not' => ['const' => 'y']], false];

        // the shared ambiguity, mirrored from lenient
        yield 'empty array counts as object' => [[], ['type' => 'object'], true];
        yield 'empty array counts as array too' => [[], ['type' => 'array'], true];
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[Test]
    #[DataProvider('strictCases')]
    public function strictEnforcesEveryKeywordInTheSubset(mixed $data, array $schema, bool $expected): void
    {
        self::assertSame($expected, $this->subject->validateStrict($data, $schema));
    }

    #[Test]
    public function validateJsonStrictParsesBothSides(): void
    {
        self::assertTrue($this->subject->validateJsonStrict('{"a": "x"}', '{"type":"object","required":["a"],"properties":{"a":{"type":"string","enum":["x","y"]}}}'));
        self::assertFalse($this->subject->validateJsonStrict('{"a": "z"}', '{"type":"object","properties":{"a":{"enum":["x","y"]}}}'));
        self::assertFalse($this->subject->validateJsonStrict('not json', '{"type":"object"}'));
        self::assertFalse($this->subject->validateJsonStrict('{}', 'not json'));
    }

    /**
     * A pattern that stops matching because the engine gave up (backtrack
     * limit) is a fail-closed rejection, not a warning. Asserted on outcome,
     * not on timing.
     */
    #[Test]
    public function aCatastrophicPatternFailsClosed(): void
    {
        $schema = ['type' => 'string', 'pattern' => '^(a+)+$'];

        self::assertTrue($this->subject->supportsSchema($schema), 'the pattern itself compiles');
        self::assertFalse(
            $this->subject->validateStrict(str_repeat('a', 40) . 'b', $schema),
            'the non-matching input is rejected regardless of how the engine got there',
        );
    }
}
