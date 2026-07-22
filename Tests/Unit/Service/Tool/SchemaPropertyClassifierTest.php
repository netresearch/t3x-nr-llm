<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaPropertyClassifier::class)]
final class SchemaPropertyClassifierTest extends TestCase
{
    /**
     * @param array<string, mixed> $propSchema
     */
    #[Test]
    #[DataProvider('cases')]
    public function classifyMapsTypeToControl(array $propSchema, string $expected): void
    {
        self::assertSame($expected, (new SchemaPropertyClassifier())->classify($propSchema));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function cases(): iterable
    {
        yield 'string' => [['type' => 'string'], 'text'];
        yield 'integer' => [['type' => 'integer'], 'integer'];
        yield 'number' => [['type' => 'number'], 'number'];
        yield 'boolean' => [['type' => 'boolean'], 'checkbox'];
        yield 'object is unsupported' => [['type' => 'object'], 'unsupported'];
        yield 'array is unsupported' => [['type' => 'array'], 'unsupported'];
        yield 'missing type defaults to text' => [[], 'text'];
        yield 'unknown type defaults to text' => [['type' => 'weird'], 'text'];
        yield 'type array picks first non-null scalar' => [['type' => ['null', 'integer']], 'integer'];
        yield 'non-string type defaults to text' => [['type' => 42], 'text'];
    }
}
