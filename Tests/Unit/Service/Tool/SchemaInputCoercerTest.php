<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Service\Tool\SchemaInputCoercer;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaInputCoercer::class)]
final class SchemaInputCoercerTest extends TestCase
{
    private SchemaInputCoercer $coercer;

    protected function setUp(): void
    {
        $this->coercer = new SchemaInputCoercer(new SchemaPropertyClassifier());
    }

    #[Test]
    public function castsStringsToTheDeclaredScalarTypes(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'name'    => ['type' => 'string'],
                'age'     => ['type' => 'integer'],
                'ratio'   => ['type' => 'number'],
                'enabled' => ['type' => 'boolean'],
            ],
        ];

        $result = $this->coercer->coerce(
            ['name' => 'Ada', 'age' => '42', 'ratio' => '0.5', 'enabled' => '1'],
            $schema,
        );

        self::assertSame(['name' => 'Ada', 'age' => 42, 'ratio' => 0.5, 'enabled' => true], $result);
    }

    #[Test]
    public function coercedResultPassesTheStrictValidator(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'age'     => ['type' => 'integer'],
                'enabled' => ['type' => 'boolean'],
            ],
            'required' => ['age'],
        ];

        $result = $this->coercer->coerce(['age' => '7', 'enabled' => '1'], $schema);

        self::assertTrue((new JsonSchemaValidator())->validate($result, $schema));
    }

    #[Test]
    public function blankOptionalNumericIsOmittedNotPassedAsEmptyString(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => ['age' => ['type' => 'integer']],
        ];

        $result = $this->coercer->coerce(['age' => ''], $schema);

        self::assertArrayNotHasKey('age', $result);
        // And an absent optional integer validates.
        self::assertTrue((new JsonSchemaValidator())->validate($result, $schema));
    }

    #[Test]
    public function requiredBooleanAbsentIsFalseWhileOptionalBooleanAbsentIsOmitted(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'must'  => ['type' => 'boolean'],
                'maybe' => ['type' => 'boolean'],
            ],
            'required' => ['must'],
        ];

        // Neither checkbox posted (unchecked posts nothing).
        $result = $this->coercer->coerce([], $schema);

        self::assertSame(['must' => false], $result);
        self::assertArrayNotHasKey('maybe', $result);
    }

    #[Test]
    public function nonNumericStringForIntegerStaysUncoercedSoTheValidatorRejectsIt(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => ['age' => ['type' => 'integer']],
        ];

        $result = $this->coercer->coerce(['age' => 'abc'], $schema);

        // Left as the raw string, NOT silently cast to 0.
        self::assertSame(['age' => 'abc'], $result);
        self::assertFalse((new JsonSchemaValidator())->validate($result, $schema));
    }

    #[Test]
    public function unsupportedAndUnknownPropertiesAreOmitted(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'nested' => ['type' => 'object'],
            ],
        ];

        // Even if a crafted POST sends a value for the unsupported field, no
        // widget produced it and it is dropped.
        $result = $this->coercer->coerce(['nested' => 'x'], $schema);

        self::assertSame([], $result);
    }

    #[Test]
    public function aSchemaWithoutPropertiesYieldsEmptyData(): void
    {
        self::assertSame([], $this->coercer->coerce(['x' => 'y'], ['type' => 'string']));
    }
}
