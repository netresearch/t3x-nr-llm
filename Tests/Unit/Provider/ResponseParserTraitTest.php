<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use InvalidArgumentException;
use JsonException;
use Netresearch\NrLlm\Provider\ResponseParserTrait;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test class for ResponseParserTrait.
 *
 * Note: Traits and test helper classes cannot be covered directly in PHPUnit.
 * The trait's functionality is indirectly covered through AbstractProvider tests.
 */
#[CoversNothing]
class ResponseParserTraitTest extends AbstractUnitTestCase
{
    private ResponseParserTraitTestSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new ResponseParserTraitTestSubject();
    }

    // ==================== getString tests ====================

    #[Test]
    public function getStringReturnsStringValue(): void
    {
        $data = ['key' => 'value'];
        self::assertEquals('value', $this->subject->getString($data, 'key'));
    }

    #[Test]
    public function getStringReturnsDefaultWhenKeyMissing(): void
    {
        $data = [];
        self::assertEquals('default', $this->subject->getString($data, 'key', 'default'));
    }

    #[Test]
    public function getStringConvertsIntToString(): void
    {
        $data = ['key' => 123];
        self::assertEquals('123', $this->subject->getString($data, 'key'));
    }

    #[Test]
    public function getStringConvertsFloatToString(): void
    {
        $data = ['key' => 12.5];
        self::assertEquals('12.5', $this->subject->getString($data, 'key'));
    }

    #[Test]
    public function getStringReturnsDefaultForNonScalar(): void
    {
        $data = ['key' => ['nested' => 'array']];
        self::assertEquals('default', $this->subject->getString($data, 'key', 'default'));
    }

    // ==================== getInt tests ====================

    #[Test]
    public function getIntReturnsIntValue(): void
    {
        $data = ['key' => 42];
        self::assertEquals(42, $this->subject->getInt($data, 'key'));
    }

    #[Test]
    public function getIntConvertsNumericString(): void
    {
        $data = ['key' => '99'];
        self::assertEquals(99, $this->subject->getInt($data, 'key'));
    }

    #[Test]
    public function getIntReturnsDefaultForNonNumeric(): void
    {
        $data = ['key' => 'abc'];
        self::assertEquals(10, $this->subject->getInt($data, 'key', 10));
    }

    // ==================== getFloat tests ====================

    #[Test]
    public function getFloatReturnsFloatValue(): void
    {
        $data = ['key' => 3.14];
        self::assertEquals(3.14, $this->subject->getFloat($data, 'key'));
    }

    #[Test]
    public function getFloatConvertsNumericString(): void
    {
        $data = ['key' => '2.5'];
        self::assertEquals(2.5, $this->subject->getFloat($data, 'key'));
    }

    #[Test]
    public function getFloatReturnsDefaultForNonNumeric(): void
    {
        $data = ['key' => 'abc'];
        self::assertEquals(1.5, $this->subject->getFloat($data, 'key', 1.5));
    }

    // ==================== getBool tests ====================

    #[Test]
    public function getBoolReturnsBoolValue(): void
    {
        $data = ['key' => true];
        self::assertTrue($this->subject->getBool($data, 'key'));
    }

    #[Test]
    public function getBoolReturnsDefaultForNonBool(): void
    {
        $data = ['key' => 'yes'];
        self::assertFalse($this->subject->getBool($data, 'key', false));
    }

    // ==================== getArray tests ====================

    #[Test]
    public function getArrayReturnsArrayValue(): void
    {
        $data = ['key' => ['a' => 1, 'b' => 2]];
        self::assertEquals(['a' => 1, 'b' => 2], $this->subject->getArray($data, 'key'));
    }

    #[Test]
    public function getArrayReturnsDefaultForNonArray(): void
    {
        $data = ['key' => 'string'];
        self::assertEquals(['fallback' => 'value'], $this->subject->getArray($data, 'key', ['fallback' => 'value']));
    }

    // ==================== getList tests ====================

    #[Test]
    public function getListReturnsListValue(): void
    {
        $data = ['choices' => [['index' => 0], ['index' => 1]]];
        self::assertCount(2, $this->subject->getList($data, 'choices'));
    }

    #[Test]
    public function getListReturnsEmptyForNonArray(): void
    {
        $data = ['choices' => 'string'];
        self::assertEquals([], $this->subject->getList($data, 'choices'));
    }

    // ==================== getNullableString tests ====================

    #[Test]
    public function getNullableStringReturnsNullWhenMissing(): void
    {
        $data = [];
        self::assertNull($this->subject->getNullableString($data, 'key'));
    }

    #[Test]
    public function getNullableStringReturnsNullWhenNull(): void
    {
        $data = ['key' => null];
        self::assertNull($this->subject->getNullableString($data, 'key'));
    }

    #[Test]
    public function getNullableStringReturnsStringValue(): void
    {
        $data = ['key' => 'value'];
        self::assertEquals('value', $this->subject->getNullableString($data, 'key'));
    }

    #[Test]
    public function getNullableStringConvertsInt(): void
    {
        $data = ['key' => 42];
        self::assertEquals('42', $this->subject->getNullableString($data, 'key'));
    }

    #[Test]
    public function getNullableStringConvertsFloat(): void
    {
        $data = ['key' => 3.14];
        self::assertEquals('3.14', $this->subject->getNullableString($data, 'key'));
    }

    #[Test]
    public function getNullableStringReturnsNullForNonScalar(): void
    {
        $data = ['key' => ['array']];
        self::assertNull($this->subject->getNullableString($data, 'key'));
    }

    // ==================== getNullableInt tests ====================

    #[Test]
    public function getNullableIntReturnsNullWhenMissing(): void
    {
        $data = [];
        self::assertNull($this->subject->getNullableInt($data, 'key'));
    }

    #[Test]
    public function getNullableIntReturnsNullWhenNull(): void
    {
        $data = ['key' => null];
        self::assertNull($this->subject->getNullableInt($data, 'key'));
    }

    #[Test]
    public function getNullableIntReturnsIntValue(): void
    {
        $data = ['key' => 42];
        self::assertEquals(42, $this->subject->getNullableInt($data, 'key'));
    }

    #[Test]
    public function getNullableIntConvertsNumericString(): void
    {
        $data = ['key' => '99'];
        self::assertEquals(99, $this->subject->getNullableInt($data, 'key'));
    }

    #[Test]
    public function getNullableIntReturnsNullForNonNumeric(): void
    {
        $data = ['key' => 'abc'];
        self::assertNull($this->subject->getNullableInt($data, 'key'));
    }

    // ==================== getNestedArray tests ====================

    #[Test]
    public function getNestedArrayReturnsNestedValue(): void
    {
        $data = ['a' => ['b' => ['c' => 'value']]];
        self::assertEquals(['c' => 'value'], $this->subject->getNestedArray($data, 'a.b'));
    }

    #[Test]
    public function getNestedArrayReturnsDefaultWhenPathNotFound(): void
    {
        $data = ['a' => ['b' => 'value']];
        self::assertEquals(['fallback' => 'value'], $this->subject->getNestedArray($data, 'a.c', ['fallback' => 'value']));
    }

    #[Test]
    public function getNestedArrayReturnsDefaultWhenNotArray(): void
    {
        $data = ['a' => ['b' => 'string']];
        self::assertEquals(['fallback' => 'value'], $this->subject->getNestedArray($data, 'a.b', ['fallback' => 'value']));
    }

    #[Test]
    public function getNestedArrayReturnsDefaultWhenIntermediateNotArray(): void
    {
        $data = ['a' => 'string'];
        self::assertEquals(['fallback' => 'value'], $this->subject->getNestedArray($data, 'a.b', ['fallback' => 'value']));
    }

    // ==================== getNestedString tests ====================

    #[Test]
    public function getNestedStringReturnsNestedValue(): void
    {
        $data = ['a' => ['b' => 'value']];
        self::assertEquals('value', $this->subject->getNestedString($data, 'a.b'));
    }

    #[Test]
    public function getNestedStringConvertsInt(): void
    {
        $data = ['a' => ['b' => 42]];
        self::assertEquals('42', $this->subject->getNestedString($data, 'a.b'));
    }

    #[Test]
    public function getNestedStringConvertsFloat(): void
    {
        $data = ['a' => ['b' => 3.14]];
        self::assertEquals('3.14', $this->subject->getNestedString($data, 'a.b'));
    }

    #[Test]
    public function getNestedStringReturnsDefaultWhenNotFound(): void
    {
        $data = ['a' => ['b' => 'value']];
        self::assertEquals('default', $this->subject->getNestedString($data, 'a.c', 'default'));
    }

    #[Test]
    public function getNestedStringReturnsDefaultForNonScalar(): void
    {
        $data = ['a' => ['b' => ['nested' => 'array']]];
        self::assertEquals('default', $this->subject->getNestedString($data, 'a.b', 'default'));
    }

    // ==================== getNestedInt tests ====================

    #[Test]
    public function getNestedIntReturnsNestedValue(): void
    {
        $data = ['a' => ['b' => 42]];
        self::assertEquals(42, $this->subject->getNestedInt($data, 'a.b'));
    }

    #[Test]
    public function getNestedIntConvertsNumericString(): void
    {
        $data = ['a' => ['b' => '99']];
        self::assertEquals(99, $this->subject->getNestedInt($data, 'a.b'));
    }

    #[Test]
    public function getNestedIntReturnsDefaultWhenNotFound(): void
    {
        $data = ['a' => ['b' => 42]];
        self::assertEquals(10, $this->subject->getNestedInt($data, 'a.c', 10));
    }

    #[Test]
    public function getNestedIntReturnsDefaultForNonNumeric(): void
    {
        $data = ['a' => ['b' => 'abc']];
        self::assertEquals(10, $this->subject->getNestedInt($data, 'a.b', 10));
    }

    // ==================== asArray tests ====================

    #[Test]
    public function asArrayReturnsArrayValue(): void
    {
        self::assertEquals(['key' => 'value'], $this->subject->asArray(['key' => 'value']));
    }

    #[Test]
    public function asArrayReturnsDefaultForNonArray(): void
    {
        self::assertEquals(['fallback' => 'value'], $this->subject->asArray('string', ['fallback' => 'value']));
    }

    // ==================== asList tests ====================

    #[Test]
    public function asListReturnsListValue(): void
    {
        self::assertEquals([['a' => 1]], $this->subject->asList([['a' => 1]]));
    }

    #[Test]
    public function asListReturnsEmptyForNonArray(): void
    {
        self::assertEquals([], $this->subject->asList('string'));
    }

    // ==================== asString tests ====================

    #[Test]
    public function asStringReturnsStringValue(): void
    {
        self::assertEquals('value', $this->subject->asString('value'));
    }

    #[Test]
    public function asStringConvertsInt(): void
    {
        self::assertEquals('42', $this->subject->asString(42));
    }

    #[Test]
    public function asStringConvertsFloat(): void
    {
        self::assertEquals('3.14', $this->subject->asString(3.14));
    }

    #[Test]
    public function asStringReturnsDefaultForNonScalar(): void
    {
        self::assertEquals('default', $this->subject->asString(['array'], 'default'));
    }

    // ==================== asInt tests ====================

    #[Test]
    public function asIntReturnsIntValue(): void
    {
        self::assertEquals(42, $this->subject->asInt(42));
    }

    #[Test]
    public function asIntConvertsNumericString(): void
    {
        self::assertEquals(99, $this->subject->asInt('99'));
    }

    #[Test]
    public function asIntReturnsDefaultForNonNumeric(): void
    {
        self::assertEquals(10, $this->subject->asInt('abc', 10));
    }

    // ==================== asFloat tests ====================

    #[Test]
    public function asFloatReturnsFloatValue(): void
    {
        self::assertEquals(3.14, $this->subject->asFloat(3.14));
    }

    #[Test]
    public function asFloatConvertsInt(): void
    {
        self::assertEquals(42.0, $this->subject->asFloat(42));
    }

    #[Test]
    public function asFloatConvertsNumericString(): void
    {
        self::assertEquals(2.5, $this->subject->asFloat('2.5'));
    }

    #[Test]
    public function asFloatReturnsDefaultForNonNumeric(): void
    {
        self::assertEquals(1.5, $this->subject->asFloat('abc', 1.5));
    }

    // ==================== decodeJsonResponse tests ====================

    #[Test]
    public function decodeJsonResponseReturnsArray(): void
    {
        $json = '{"key": "value"}';
        self::assertEquals(['key' => 'value'], $this->subject->decodeJsonResponse($json));
    }

    #[Test]
    public function decodeJsonResponseThrowsOnInvalidJson(): void
    {
        $this->expectException(JsonException::class);

        $this->subject->decodeJsonResponse('invalid json');
    }

    #[Test]
    public function decodeJsonResponseThrowsOnNonObjectJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(8142137949);

        $this->subject->decodeJsonResponse('"just a string"');
    }
}

/**
 * Concrete test subject class that uses ResponseParserTrait.
 *
 * This class exposes the protected trait methods as public for testing.
 * Using a concrete class allows PHPUnit to properly track code coverage.
 *
 * @internal
 */
class ResponseParserTraitTestSubject
{
    use ResponseParserTrait {
        getString as public;
        getInt as public;
        getFloat as public;
        getBool as public;
        getArray as public;
        getList as public;
        getNullableString as public;
        getNullableInt as public;
        getNestedArray as public;
        getNestedString as public;
        getNestedInt as public;
        asArray as public;
        asList as public;
        asString as public;
        asInt as public;
        asFloat as public;
        decodeJsonResponse as public;
    }
}
