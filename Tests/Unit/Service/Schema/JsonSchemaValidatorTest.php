<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Schema;

use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonSchemaValidator::class)]
final class JsonSchemaValidatorTest extends TestCase
{
    private JsonSchemaValidator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new JsonSchemaValidator();
    }

    #[Test]
    public function acceptsAnObjectSatisfyingTypeRequiredAndProperties(): void
    {
        $schema = [
            'type'       => 'object',
            'required'   => ['title', 'description'],
            'properties' => [
                'title'       => ['type' => 'string'],
                'description' => ['type' => 'string'],
            ],
        ];

        self::assertTrue($this->subject->validate(['title' => 'a', 'description' => 'b'], $schema));
    }

    #[Test]
    public function rejectsAMissingRequiredKey(): void
    {
        $schema = ['type' => 'object', 'required' => ['title', 'description']];

        self::assertFalse($this->subject->validate(['title' => 'a'], $schema));
    }

    #[Test]
    public function allowsExtraKeysNotDeclaredInTheSchema(): void
    {
        $schema = ['type' => 'object', 'required' => ['title']];

        self::assertTrue($this->subject->validate(['title' => 'a', 'extra' => 1], $schema));
    }

    #[Test]
    public function rejectsAWrongTopLevelType(): void
    {
        self::assertFalse($this->subject->validate('a string', ['type' => 'object']));
        self::assertFalse($this->subject->validate(['a', 'b'], ['type' => 'object']));
        self::assertTrue($this->subject->validate(['a', 'b'], ['type' => 'array']));
    }

    #[Test]
    public function treatsAnEmptyArrayAsAnEmptyObject(): void
    {
        self::assertTrue($this->subject->validate([], ['type' => 'object']));
        self::assertFalse($this->subject->validate([], ['type' => 'object', 'required' => ['x']]));
    }

    #[Test]
    public function validatesScalarPropertyTypes(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'count'  => ['type' => 'integer'],
                'ratio'  => ['type' => 'number'],
                'active' => ['type' => 'boolean'],
                'name'   => ['type' => 'string'],
            ],
        ];

        self::assertTrue($this->subject->validate(['count' => 3, 'ratio' => 1.5, 'active' => true, 'name' => 'x'], $schema));
        self::assertFalse($this->subject->validate(['count' => '3'], $schema));
        self::assertFalse($this->subject->validate(['active' => 'yes'], $schema));
    }

    #[Test]
    public function validatesNestedObjectProperties(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'meta' => ['type' => 'object', 'required' => ['id']],
            ],
        ];

        self::assertTrue($this->subject->validate(['meta' => ['id' => 1]], $schema));
        self::assertFalse($this->subject->validate(['meta' => ['other' => 1]], $schema));
    }

    #[Test]
    public function validateJsonParsesBothSides(): void
    {
        self::assertTrue($this->subject->validateJson('{"a": 1}', '{"type": "object", "required": ["a"]}'));
        self::assertFalse($this->subject->validateJson('{"b": 1}', '{"type": "object", "required": ["a"]}'));
    }

    #[Test]
    public function validateJsonRejectsMalformedInput(): void
    {
        self::assertFalse($this->subject->validateJson('not json', '{"type": "object"}'));
        self::assertFalse($this->subject->validateJson('{"a": 1}', 'not json'));
    }
}
