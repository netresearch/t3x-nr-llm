<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use InvalidArgumentException;
use JsonException;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Mutation-killing tests for ResponseParserTrait.
 *
 * These tests specifically target escaped mutants in type coercion,
 * default value handling, and nested access methods.
 */
class ResponseParserTraitMutationTest extends AbstractUnitTestCase
{
    private object $traitObject;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a concrete provider since the trait is protected
        $this->traitObject = new GeminiProvider(
            $this->createHttpClientMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
    }

    // ===== Tests for getString() =====

    #[Test]
    public function getStringReturnsStringValue(): void
    {
        $result = $this->invokeTraitMethod('getString', [['key' => 'value'], 'key', '']);
        self::assertEquals('value', $result);
    }

    #[Test]
    public function getStringConvertsIntToString(): void
    {
        $result = $this->invokeTraitMethod('getString', [['key' => 123], 'key', '']);
        self::assertEquals('123', $result);
    }

    #[Test]
    public function getStringConvertsFloatToString(): void
    {
        $result = $this->invokeTraitMethod('getString', [['key' => 12.5], 'key', '']);
        self::assertEquals('12.5', $result);
    }

    #[Test]
    public function getStringReturnsDefaultForMissingKey(): void
    {
        $result = $this->invokeTraitMethod('getString', [['key' => 'value'], 'missing', 'default']);
        self::assertEquals('default', $result);
    }

    #[Test]
    public function getStringReturnsDefaultForArrayValue(): void
    {
        $result = $this->invokeTraitMethod('getString', [['key' => ['nested']], 'key', 'default']);
        self::assertEquals('default', $result);
    }

    #[Test]
    public function getStringReturnsDefaultForBoolValue(): void
    {
        $result = $this->invokeTraitMethod('getString', [['key' => true], 'key', 'default']);
        self::assertEquals('default', $result);
    }

    // ===== Tests for getInt() =====

    #[Test]
    public function getIntReturnsIntValue(): void
    {
        $result = $this->invokeTraitMethod('getInt', [['key' => 42], 'key', 0]);
        self::assertEquals(42, $result);
    }

    #[Test]
    public function getIntConvertsNumericStringToInt(): void
    {
        $result = $this->invokeTraitMethod('getInt', [['key' => '123'], 'key', 0]);
        self::assertEquals(123, $result);
    }

    #[Test]
    public function getIntConvertsFloatToInt(): void
    {
        $result = $this->invokeTraitMethod('getInt', [['key' => 12.9], 'key', 0]);
        self::assertEquals(12, $result);
    }

    #[Test]
    public function getIntReturnsDefaultForMissingKey(): void
    {
        $result = $this->invokeTraitMethod('getInt', [['key' => 42], 'missing', 99]);
        self::assertEquals(99, $result);
    }

    #[Test]
    public function getIntReturnsDefaultForNonNumericString(): void
    {
        $result = $this->invokeTraitMethod('getInt', [['key' => 'abc'], 'key', 99]);
        self::assertEquals(99, $result);
    }

    // ===== Tests for getFloat() =====

    #[Test]
    public function getFloatReturnsFloatValue(): void
    {
        $result = $this->invokeTraitMethod('getFloat', [['key' => 3.14], 'key', 0.0]);
        self::assertEquals(3.14, $result);
    }

    #[Test]
    public function getFloatConvertsIntToFloat(): void
    {
        $result = $this->invokeTraitMethod('getFloat', [['key' => 42], 'key', 0.0]);
        self::assertEquals(42.0, $result);
    }

    #[Test]
    public function getFloatConvertsNumericStringToFloat(): void
    {
        $result = $this->invokeTraitMethod('getFloat', [['key' => '3.14'], 'key', 0.0]);
        self::assertEquals(3.14, $result);
    }

    #[Test]
    public function getFloatReturnsDefaultForMissingKey(): void
    {
        $result = $this->invokeTraitMethod('getFloat', [['key' => 3.14], 'missing', 1.5]);
        self::assertEquals(1.5, $result);
    }

    #[Test]
    public function getFloatReturnsDefaultForNonNumericString(): void
    {
        $result = $this->invokeTraitMethod('getFloat', [['key' => 'abc'], 'key', 1.5]);
        self::assertEquals(1.5, $result);
    }

    // ===== Tests for getBool() =====

    #[Test]
    public function getBoolReturnsTrueValue(): void
    {
        $result = $this->invokeTraitMethod('getBool', [['key' => true], 'key', false]);
        self::assertTrue($result);
    }

    #[Test]
    public function getBoolReturnsFalseValue(): void
    {
        $result = $this->invokeTraitMethod('getBool', [['key' => false], 'key', true]);
        self::assertFalse($result);
    }

    #[Test]
    public function getBoolReturnsDefaultForMissingKey(): void
    {
        $result = $this->invokeTraitMethod('getBool', [['key' => true], 'missing', true]);
        self::assertTrue($result);
    }

    #[Test]
    public function getBoolReturnsDefaultForNonBoolValue(): void
    {
        $result = $this->invokeTraitMethod('getBool', [['key' => 'true'], 'key', false]);
        self::assertFalse($result);
    }

    #[Test]
    public function getBoolReturnsDefaultForIntValue(): void
    {
        $result = $this->invokeTraitMethod('getBool', [['key' => 1], 'key', true]);
        self::assertTrue($result);
    }

    // ===== Tests for getArray() =====

    #[Test]
    public function getArrayReturnsArrayValue(): void
    {
        $result = $this->invokeTraitMethod('getArray', [['key' => ['a', 'b']], 'key', []]);
        self::assertEquals(['a', 'b'], $result);
    }

    #[Test]
    public function getArrayReturnsDefaultForMissingKey(): void
    {
        $result = $this->invokeTraitMethod('getArray', [['key' => ['a']], 'missing', ['default']]);
        self::assertEquals(['default'], $result);
    }

    #[Test]
    public function getArrayReturnsDefaultForNonArrayValue(): void
    {
        $result = $this->invokeTraitMethod('getArray', [['key' => 'string'], 'key', ['default']]);
        self::assertEquals(['default'], $result);
    }

    // ===== Tests for getList() =====

    #[Test]
    public function getListReturnsListValue(): void
    {
        $list = [['id' => 1], ['id' => 2]];
        $result = $this->invokeTraitMethod('getList', [['items' => $list], 'items']);
        self::assertEquals($list, $result);
    }

    #[Test]
    public function getListReturnsEmptyArrayForMissingKey(): void
    {
        $result = $this->invokeTraitMethod('getList', [['key' => []], 'missing']);
        self::assertEquals([], $result);
    }

    #[Test]
    public function getListReturnsEmptyArrayForNonArrayValue(): void
    {
        $result = $this->invokeTraitMethod('getList', [['key' => 'string'], 'key']);
        self::assertEquals([], $result);
    }

    // ===== Tests for getNullableString() =====

    #[Test]
    public function getNullableStringReturnsStringValue(): void
    {
        $result = $this->invokeTraitMethod('getNullableString', [['key' => 'value'], 'key']);
        self::assertEquals('value', $result);
    }

    #[Test]
    public function getNullableStringReturnsNullForMissingKey(): void
    {
        $result = $this->invokeTraitMethod('getNullableString', [['key' => 'value'], 'missing']);
        self::assertNull($result);
    }

    #[Test]
    public function getNullableStringReturnsNullForNullValue(): void
    {
        $result = $this->invokeTraitMethod('getNullableString', [['key' => null], 'key']);
        self::assertNull($result);
    }

    #[Test]
    public function getNullableStringConvertsIntToString(): void
    {
        $result = $this->invokeTraitMethod('getNullableString', [['key' => 123], 'key']);
        self::assertEquals('123', $result);
    }

    #[Test]
    public function getNullableStringConvertsFloatToString(): void
    {
        $result = $this->invokeTraitMethod('getNullableString', [['key' => 12.5], 'key']);
        self::assertEquals('12.5', $result);
    }

    #[Test]
    public function getNullableStringReturnsNullForArrayValue(): void
    {
        $result = $this->invokeTraitMethod('getNullableString', [['key' => ['nested']], 'key']);
        self::assertNull($result);
    }

    // ===== Tests for getNullableInt() =====

    #[Test]
    public function getNullableIntReturnsIntValue(): void
    {
        $result = $this->invokeTraitMethod('getNullableInt', [['key' => 42], 'key']);
        self::assertEquals(42, $result);
    }

    #[Test]
    public function getNullableIntReturnsNullForMissingKey(): void
    {
        $result = $this->invokeTraitMethod('getNullableInt', [['key' => 42], 'missing']);
        self::assertNull($result);
    }

    #[Test]
    public function getNullableIntReturnsNullForNullValue(): void
    {
        $result = $this->invokeTraitMethod('getNullableInt', [['key' => null], 'key']);
        self::assertNull($result);
    }

    #[Test]
    public function getNullableIntConvertsNumericStringToInt(): void
    {
        $result = $this->invokeTraitMethod('getNullableInt', [['key' => '123'], 'key']);
        self::assertEquals(123, $result);
    }

    #[Test]
    public function getNullableIntReturnsNullForNonNumericString(): void
    {
        $result = $this->invokeTraitMethod('getNullableInt', [['key' => 'abc'], 'key']);
        self::assertNull($result);
    }

    // ===== Tests for getNestedArray() =====

    #[Test]
    public function getNestedArrayReturnsNestedValue(): void
    {
        $data = ['level1' => ['level2' => ['a', 'b']]];
        $result = $this->invokeTraitMethod('getNestedArray', [$data, 'level1.level2', []]);
        self::assertEquals(['a', 'b'], $result);
    }

    #[Test]
    public function getNestedArrayReturnsDefaultForMissingPath(): void
    {
        $data = ['level1' => ['level2' => ['a', 'b']]];
        $result = $this->invokeTraitMethod('getNestedArray', [$data, 'level1.missing', ['default']]);
        self::assertEquals(['default'], $result);
    }

    #[Test]
    public function getNestedArrayReturnsDefaultForNonArrayValue(): void
    {
        $data = ['level1' => ['level2' => 'string']];
        $result = $this->invokeTraitMethod('getNestedArray', [$data, 'level1.level2', ['default']]);
        self::assertEquals(['default'], $result);
    }

    #[Test]
    public function getNestedArrayHandlesNonArrayIntermediate(): void
    {
        $data = ['level1' => 'string'];
        $result = $this->invokeTraitMethod('getNestedArray', [$data, 'level1.level2', ['default']]);
        self::assertEquals(['default'], $result);
    }

    // ===== Tests for getNestedString() =====

    #[Test]
    public function getNestedStringReturnsNestedValue(): void
    {
        $data = ['level1' => ['level2' => 'value']];
        $result = $this->invokeTraitMethod('getNestedString', [$data, 'level1.level2', '']);
        self::assertEquals('value', $result);
    }

    #[Test]
    public function getNestedStringReturnsDefaultForMissingPath(): void
    {
        $data = ['level1' => ['level2' => 'value']];
        $result = $this->invokeTraitMethod('getNestedString', [$data, 'level1.missing', 'default']);
        self::assertEquals('default', $result);
    }

    #[Test]
    public function getNestedStringConvertsIntToString(): void
    {
        $data = ['level1' => ['level2' => 123]];
        $result = $this->invokeTraitMethod('getNestedString', [$data, 'level1.level2', '']);
        self::assertEquals('123', $result);
    }

    #[Test]
    public function getNestedStringConvertsFloatToString(): void
    {
        $data = ['level1' => ['level2' => 12.5]];
        $result = $this->invokeTraitMethod('getNestedString', [$data, 'level1.level2', '']);
        self::assertEquals('12.5', $result);
    }

    #[Test]
    public function getNestedStringReturnsDefaultForArrayValue(): void
    {
        $data = ['level1' => ['level2' => ['nested']]];
        $result = $this->invokeTraitMethod('getNestedString', [$data, 'level1.level2', 'default']);
        self::assertEquals('default', $result);
    }

    // ===== Tests for getNestedInt() =====

    #[Test]
    public function getNestedIntReturnsNestedValue(): void
    {
        $data = ['level1' => ['level2' => 42]];
        $result = $this->invokeTraitMethod('getNestedInt', [$data, 'level1.level2', 0]);
        self::assertEquals(42, $result);
    }

    #[Test]
    public function getNestedIntReturnsDefaultForMissingPath(): void
    {
        $data = ['level1' => ['level2' => 42]];
        $result = $this->invokeTraitMethod('getNestedInt', [$data, 'level1.missing', 99]);
        self::assertEquals(99, $result);
    }

    #[Test]
    public function getNestedIntConvertsNumericStringToInt(): void
    {
        $data = ['level1' => ['level2' => '123']];
        $result = $this->invokeTraitMethod('getNestedInt', [$data, 'level1.level2', 0]);
        self::assertEquals(123, $result);
    }

    #[Test]
    public function getNestedIntReturnsDefaultForNonNumericValue(): void
    {
        $data = ['level1' => ['level2' => 'abc']];
        $result = $this->invokeTraitMethod('getNestedInt', [$data, 'level1.level2', 99]);
        self::assertEquals(99, $result);
    }

    // ===== Tests for asArray() =====

    #[Test]
    public function asArrayReturnsArrayValue(): void
    {
        $result = $this->invokeTraitMethod('asArray', [['key' => 'value'], []]);
        self::assertEquals(['key' => 'value'], $result);
    }

    #[Test]
    public function asArrayReturnsDefaultForNonArray(): void
    {
        $result = $this->invokeTraitMethod('asArray', ['string', ['default']]);
        self::assertEquals(['default'], $result);
    }

    #[Test]
    public function asArrayReturnsDefaultForNull(): void
    {
        $result = $this->invokeTraitMethod('asArray', [null, ['default']]);
        self::assertEquals(['default'], $result);
    }

    // ===== Tests for asList() =====

    #[Test]
    public function asListReturnsArrayValue(): void
    {
        $list = [['id' => 1], ['id' => 2]];
        $result = $this->invokeTraitMethod('asList', [$list]);
        self::assertEquals($list, $result);
    }

    #[Test]
    public function asListReturnsEmptyArrayForNonArray(): void
    {
        $result = $this->invokeTraitMethod('asList', ['string']);
        self::assertEquals([], $result);
    }

    // ===== Tests for asString() =====

    #[Test]
    public function asStringReturnsStringValue(): void
    {
        $result = $this->invokeTraitMethod('asString', ['value', '']);
        self::assertEquals('value', $result);
    }

    #[Test]
    public function asStringConvertsIntToString(): void
    {
        $result = $this->invokeTraitMethod('asString', [123, '']);
        self::assertEquals('123', $result);
    }

    #[Test]
    public function asStringConvertsFloatToString(): void
    {
        $result = $this->invokeTraitMethod('asString', [12.5, '']);
        self::assertEquals('12.5', $result);
    }

    #[Test]
    public function asStringReturnsDefaultForArray(): void
    {
        $result = $this->invokeTraitMethod('asString', [['array'], 'default']);
        self::assertEquals('default', $result);
    }

    // ===== Tests for asInt() =====

    #[Test]
    public function asIntReturnsIntValue(): void
    {
        $result = $this->invokeTraitMethod('asInt', [42, 0]);
        self::assertEquals(42, $result);
    }

    #[Test]
    public function asIntConvertsNumericStringToInt(): void
    {
        $result = $this->invokeTraitMethod('asInt', ['123', 0]);
        self::assertEquals(123, $result);
    }

    #[Test]
    public function asIntReturnsDefaultForNonNumeric(): void
    {
        $result = $this->invokeTraitMethod('asInt', ['abc', 99]);
        self::assertEquals(99, $result);
    }

    // ===== Tests for asFloat() =====

    #[Test]
    public function asFloatReturnsFloatValue(): void
    {
        $result = $this->invokeTraitMethod('asFloat', [3.14, 0.0]);
        self::assertEquals(3.14, $result);
    }

    #[Test]
    public function asFloatConvertsIntToFloat(): void
    {
        $result = $this->invokeTraitMethod('asFloat', [42, 0.0]);
        self::assertEquals(42.0, $result);
    }

    #[Test]
    public function asFloatConvertsNumericStringToFloat(): void
    {
        $result = $this->invokeTraitMethod('asFloat', ['3.14', 0.0]);
        self::assertEquals(3.14, $result);
    }

    #[Test]
    public function asFloatReturnsDefaultForNonNumeric(): void
    {
        $result = $this->invokeTraitMethod('asFloat', ['abc', 1.5]);
        self::assertEquals(1.5, $result);
    }

    // ===== Tests for decodeJsonResponse() =====

    #[Test]
    public function decodeJsonResponseReturnsDecodedArray(): void
    {
        $result = $this->invokeTraitMethod('decodeJsonResponse', ['{"key":"value"}']);
        self::assertEquals(['key' => 'value'], $result);
    }

    #[Test]
    public function decodeJsonResponseThrowsOnInvalidJson(): void
    {
        $this->expectException(JsonException::class);
        $this->invokeTraitMethod('decodeJsonResponse', ['not json']);
    }

    #[Test]
    public function decodeJsonResponseThrowsOnNonObjectJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected JSON object');
        $this->invokeTraitMethod('decodeJsonResponse', ['"string"']);
    }

    #[Test]
    public function decodeJsonResponseAcceptsJsonArray(): void
    {
        // A JSON array like [1, 2, 3] is still a valid array in PHP
        // The implementation accepts any array (both objects and lists)
        $result = $this->invokeTraitMethod('decodeJsonResponse', ['[1, 2, 3]']);
        self::assertEquals([1, 2, 3], $result);
    }

    // ===== Helper method =====

    private function invokeTraitMethod(string $method, array $args): mixed
    {
        $reflection = new ReflectionClass($this->traitObject);
        $reflectionMethod = $reflection->getMethod($method);

        return $reflectionMethod->invokeArgs($this->traitObject, $args);
    }
}
