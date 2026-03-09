<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\TaskController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Unit tests for TaskController wizard helper methods.
 *
 * Tests the private static helper methods (stringVal, intVal, floatVal)
 * via reflection. These are pure functions with no dependencies.
 */
final class TaskControllerWizardTest extends TestCase
{
    private ReflectionMethod $stringVal;
    private ReflectionMethod $intVal;
    private ReflectionMethod $floatVal;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new ReflectionClass(TaskController::class);

        $this->stringVal = $reflection->getMethod('stringVal');
        $this->intVal = $reflection->getMethod('intVal');
        $this->floatVal = $reflection->getMethod('floatVal');
    }

    // stringVal tests

    #[Test]
    public function testStringValReturnsStringFromString(): void
    {
        $result = $this->stringVal->invoke(null, 'hello');
        self::assertSame('hello', $result);
    }

    #[Test]
    public function testStringValReturnsDefaultForNonString(): void
    {
        self::assertSame('', $this->stringVal->invoke(null, null));
        self::assertSame('', $this->stringVal->invoke(null, []));
        self::assertSame('', $this->stringVal->invoke(null, new stdClass()));
        self::assertSame('', $this->stringVal->invoke(null, true));
        self::assertSame('', $this->stringVal->invoke(null, false));
    }

    #[Test]
    public function testStringValReturnsStringFromNumeric(): void
    {
        self::assertSame('42', $this->stringVal->invoke(null, 42));
        self::assertSame('3.14', $this->stringVal->invoke(null, 3.14));
        self::assertSame('0', $this->stringVal->invoke(null, 0));
        self::assertSame('123', $this->stringVal->invoke(null, '123'));
    }

    // intVal tests

    #[Test]
    public function testIntValReturnsIntFromInt(): void
    {
        self::assertSame(42, $this->intVal->invoke(null, 42));
        self::assertSame(0, $this->intVal->invoke(null, 0));
        self::assertSame(-5, $this->intVal->invoke(null, -5));
    }

    #[Test]
    public function testIntValReturnsDefaultForNonNumeric(): void
    {
        self::assertSame(0, $this->intVal->invoke(null, null));
        self::assertSame(0, $this->intVal->invoke(null, 'not-a-number'));
        self::assertSame(0, $this->intVal->invoke(null, []));
        self::assertSame(0, $this->intVal->invoke(null, new stdClass()));
        self::assertSame(0, $this->intVal->invoke(null, true));
        self::assertSame(0, $this->intVal->invoke(null, false));
        self::assertSame(0, $this->intVal->invoke(null, ''));
    }

    // floatVal tests

    #[Test]
    public function testFloatValReturnsFloatFromFloat(): void
    {
        self::assertSame(3.14, $this->floatVal->invoke(null, 3.14));
        self::assertSame(0.0, $this->floatVal->invoke(null, 0.0));
        self::assertSame(42.0, $this->floatVal->invoke(null, 42));
        self::assertSame(0.7, $this->floatVal->invoke(null, '0.7'));
        self::assertSame(-1.5, $this->floatVal->invoke(null, -1.5));
    }

    #[Test]
    public function testFloatValReturnsDefaultForNonNumeric(): void
    {
        self::assertSame(0.0, $this->floatVal->invoke(null, null));
        self::assertSame(0.0, $this->floatVal->invoke(null, 'not-a-number'));
        self::assertSame(0.0, $this->floatVal->invoke(null, []));
        self::assertSame(0.0, $this->floatVal->invoke(null, new stdClass()));
        self::assertSame(0.0, $this->floatVal->invoke(null, true));
        self::assertSame(0.0, $this->floatVal->invoke(null, false));
        self::assertSame(0.0, $this->floatVal->invoke(null, ''));
    }
}
