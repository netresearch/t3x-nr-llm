<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\TaskWizardController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * Unit tests for the wizard helper methods (`SafeCastTrait`'s
 * `toStr` / `toInt` / `toFloat`) as exercised by
 * `TaskWizardController`.
 *
 * The trait lives in `Classes/Utility/SafeCastTrait.php`; this
 * suite anchors the behaviour at the wizard-controller seam where
 * it is consumed for parsing wizard form-post bodies. Each method
 * is a pure function with no dependencies, so reflection-based
 * private invocation is safe.
 */
final class TaskWizardControllerTest extends TestCase
{
    private ReflectionMethod $toStr;
    private ReflectionMethod $toInt;
    private ReflectionMethod $toFloat;

    protected function setUp(): void
    {
        parent::setUp();

        $reflection = new ReflectionClass(TaskWizardController::class);

        $this->toStr = $reflection->getMethod('toStr');
        $this->toInt = $reflection->getMethod('toInt');
        $this->toFloat = $reflection->getMethod('toFloat');
    }

    // toStr tests

    #[Test]
    public function testToStrReturnsStringFromString(): void
    {
        $result = $this->toStr->invoke(null, 'hello');
        self::assertSame('hello', $result);
    }

    #[Test]
    public function testToStrReturnsDefaultForNonString(): void
    {
        self::assertSame('', $this->toStr->invoke(null, null));
        self::assertSame('', $this->toStr->invoke(null, []));
        self::assertSame('', $this->toStr->invoke(null, new stdClass()));
        self::assertSame('', $this->toStr->invoke(null, true));
        self::assertSame('', $this->toStr->invoke(null, false));
    }

    #[Test]
    public function testToStrReturnsStringFromNumeric(): void
    {
        self::assertSame('42', $this->toStr->invoke(null, 42));
        self::assertSame('3.14', $this->toStr->invoke(null, 3.14));
        self::assertSame('0', $this->toStr->invoke(null, 0));
        self::assertSame('123', $this->toStr->invoke(null, '123'));
    }

    // toInt tests

    #[Test]
    public function testToIntReturnsIntFromInt(): void
    {
        self::assertSame(42, $this->toInt->invoke(null, 42));
        self::assertSame(0, $this->toInt->invoke(null, 0));
        self::assertSame(-5, $this->toInt->invoke(null, -5));
    }

    #[Test]
    public function testToIntReturnsDefaultForNonNumeric(): void
    {
        self::assertSame(0, $this->toInt->invoke(null, null));
        self::assertSame(0, $this->toInt->invoke(null, 'not-a-number'));
        self::assertSame(0, $this->toInt->invoke(null, []));
        self::assertSame(0, $this->toInt->invoke(null, new stdClass()));
        self::assertSame(0, $this->toInt->invoke(null, true));
        self::assertSame(0, $this->toInt->invoke(null, false));
        self::assertSame(0, $this->toInt->invoke(null, ''));
    }

    // toFloat tests

    #[Test]
    public function testToFloatReturnsFloatFromFloat(): void
    {
        self::assertSame(3.14, $this->toFloat->invoke(null, 3.14));
        self::assertSame(0.0, $this->toFloat->invoke(null, 0.0));
        self::assertSame(42.0, $this->toFloat->invoke(null, 42));
        self::assertSame(0.7, $this->toFloat->invoke(null, '0.7'));
        self::assertSame(-1.5, $this->toFloat->invoke(null, -1.5));
    }

    #[Test]
    public function testToFloatReturnsDefaultForNonNumeric(): void
    {
        self::assertSame(0.0, $this->toFloat->invoke(null, null));
        self::assertSame(0.0, $this->toFloat->invoke(null, 'not-a-number'));
        self::assertSame(0.0, $this->toFloat->invoke(null, []));
        self::assertSame(0.0, $this->toFloat->invoke(null, new stdClass()));
        self::assertSame(0.0, $this->toFloat->invoke(null, true));
        self::assertSame(0.0, $this->toFloat->invoke(null, false));
        self::assertSame(0.0, $this->toFloat->invoke(null, ''));
    }
}
