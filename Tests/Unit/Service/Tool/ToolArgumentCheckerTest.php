<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ToolArgumentChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the check refuses, and what it lets through on purpose (ADR-246).
 */
#[CoversClass(ToolArgumentChecker::class)]
final class ToolArgumentCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{array<array-key, mixed>, array<string, mixed>, list<string>}>
     */
    public static function refused(): iterable
    {
        $uid = ['type' => 'object', 'properties' => ['uid' => ['type' => 'integer']], 'required' => ['uid']];

        yield 'missing required' => [[], $uid, ['"uid" is required.']];
        yield 'null for required' => [['uid' => null], $uid, ['"uid" is required.']];
        yield 'text for integer' => [['uid' => 'seven'], $uid, ['"uid" must be an integer, not the text "seven".']];
        yield 'fraction for integer' => [['uid' => 1.5], $uid, ['"uid" must be an integer, not the number 1.5.']];
        yield 'object for integer' => [['uid' => ['a' => 1]], $uid, ['"uid" must be an integer, not an object.']];
        yield 'list for text' => [
            ['title' => ['a', 'b']],
            ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
            ['"title" must be text, not a list.'],
        ];
        yield 'object for list' => [
            ['uids' => ['x' => 1]],
            ['type' => 'object', 'properties' => ['uids' => ['type' => 'array']]],
            ['"uids" must be a list, not an object.'],
        ];
        yield 'list for object' => [
            ['fields' => [1, 2]],
            ['type' => 'object', 'properties' => ['fields' => ['type' => 'object']]],
            ['"fields" must be an object, not a list.'],
        ];
        yield 'outside enum' => [
            ['table' => 'be_users'],
            ['type' => 'object', 'properties' => ['table' => ['type' => 'string', 'enum' => ['pages', 'tt_content']]]],
            ['"table" must be one of: "pages", "tt_content".'],
        ];
        yield 'unknown key where none is allowed' => [
            ['uid' => 1, 'pid' => 2],
            ['type' => 'object', 'properties' => ['uid' => ['type' => 'integer']], 'additionalProperties' => false],
            ['"pid" is not a parameter here. Parameters: uid.'],
        ];
        yield 'nested required' => [
            ['fields' => ['title' => 'x']],
            ['type' => 'object', 'properties' => ['fields' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['slug']]]],
            ['"fields.slug" is required.'],
        ];
        yield 'list item' => [
            ['uids' => [1, 'two', 3]],
            ['type' => 'object', 'properties' => ['uids' => ['type' => 'array', 'items' => ['type' => 'integer']]]],
            ['"uids[1]" must be an integer, not the text "two".'],
        ];
        yield 'long text is not echoed' => [
            ['uid' => str_repeat('x', 41)],
            $uid,
            ['"uid" must be an integer, not text.'],
        ];
        yield 'union type' => [
            ['v' => ['a']],
            ['type' => 'object', 'properties' => ['v' => ['type' => ['string', 'null']]]],
            ['"v" must be text or null, not a list.'],
        ];
        yield 'list as arguments' => [
            ['a', 'b'],
            $uid,
            ['The arguments must be a JSON object of named parameters, not a list.'],
        ];
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array<string, mixed>    $schema
     * @param list<string>            $expected
     */
    #[Test]
    #[DataProvider('refused')]
    public function namesWhatDoesNotFit(array $arguments, array $schema, array $expected): void
    {
        self::assertSame($expected, (new ToolArgumentChecker())->check($arguments, $schema));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function accepted(): iterable
    {
        $typed = ['type' => 'object', 'properties' => [
            'uid'    => ['type' => 'integer'],
            'ratio'  => ['type' => 'number'],
            'flag'   => ['type' => 'boolean'],
            'title'  => ['type' => 'string'],
            'tables' => ['type' => 'string', 'enum' => ['1', '2']],
        ]];

        yield 'numeric text for integer' => [['uid' => '5'], $typed];
        yield 'integral float for integer' => [['uid' => 5.0], $typed];
        yield 'numeric text for number' => [['ratio' => '0.5'], $typed];
        yield 'text true for boolean' => [['flag' => 'true'], $typed];
        yield 'one for boolean' => [['flag' => 1], $typed];
        yield 'number for text' => [['title' => 2026], $typed];
        yield 'number for a text enum' => [['tables' => 2], $typed];
        yield 'null for optional' => [['uid' => null], $typed];
        yield 'unknown key by default' => [['other' => 'x'], $typed];
        yield 'empty object for list' => [['l' => []], ['type' => 'object', 'properties' => ['l' => ['type' => 'array']]]];
        yield 'no schema' => [['anything' => [1]], []];
        yield 'oneOf is not judged' => [
            ['v' => ['x' => 1]],
            ['type' => 'object', 'properties' => ['v' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]]],
        ];
        yield '$ref is not judged' => [[], ['$ref' => '#/definitions/x', 'required' => ['uid']]];
        yield 'null for a required argument that admits null' => [
            ['x' => null],
            ['type' => 'object', 'properties' => ['x' => ['type' => ['string', 'null']]], 'required' => ['x']],
        ];
        yield 'null for a required argument whose schema is not judged' => [
            ['x' => null],
            ['type' => 'object', 'properties' => ['x' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]]], 'required' => ['x']],
        ];
        yield 'null for a required argument without a type' => [
            ['x' => null],
            ['type' => 'object', 'properties' => ['x' => ['description' => 'anything']], 'required' => ['x']],
        ];
        yield 'null for a required argument with no property schema' => [
            ['x' => null],
            ['type' => 'object', 'required' => ['x']],
        ];
        yield 'enum value in another case' => [
            ['type' => 'Partial'],
            ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['template', 'partial', 'layout']]]],
        ];
        yield 'unknown type name' => [['v' => 1], ['type' => 'object', 'properties' => ['v' => ['type' => 'uuid']]]];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $schema
     */
    #[Test]
    #[DataProvider('accepted')]
    public function letsThroughWhatAToolCommonlyReads(array $arguments, array $schema): void
    {
        self::assertSame([], (new ToolArgumentChecker())->check($arguments, $schema));
    }

    #[Test]
    public function namesAtMostTenMismatches(): void
    {
        $required = array_map(static fn (int $i): string => 'p' . $i, range(1, 15));

        self::assertCount(
            ToolArgumentChecker::MAX_VIOLATIONS,
            (new ToolArgumentChecker())->check([], ['type' => 'object', 'required' => $required]),
        );
    }
}
