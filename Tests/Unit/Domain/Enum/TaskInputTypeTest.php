<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\TaskInputType;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Enums are not valid coverage targets in PHPUnit 12
#[CoversNothing]
final class TaskInputTypeTest extends TestCase
{
    #[Test]
    public function allFiveCasesExist(): void
    {
        $cases = TaskInputType::cases();

        self::assertCount(5, $cases);
    }

    #[Test]
    public function enumCasesHaveCorrectValues(): void
    {
        self::assertSame('manual', TaskInputType::MANUAL->value);
        self::assertSame('syslog', TaskInputType::SYSLOG->value);
        self::assertSame('deprecation_log', TaskInputType::DEPRECATION_LOG->value);
        self::assertSame('table', TaskInputType::TABLE->value);
        self::assertSame('file', TaskInputType::FILE->value);
    }

    #[Test]
    public function valuesReturnsAllInputTypeValues(): void
    {
        $values = TaskInputType::values();

        self::assertCount(5, $values);
        self::assertContains('manual', $values);
        self::assertContains('syslog', $values);
        self::assertContains('deprecation_log', $values);
        self::assertContains('table', $values);
        self::assertContains('file', $values);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validInputTypeValueProvider(): array
    {
        return [
            'manual' => ['manual'],
            'syslog' => ['syslog'],
            'deprecation_log' => ['deprecation_log'],
            'table' => ['table'],
            'file' => ['file'],
        ];
    }

    #[Test]
    #[DataProvider('validInputTypeValueProvider')]
    public function isValidReturnsTrueForValidValues(string $value): void
    {
        self::assertTrue(TaskInputType::isValid($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidInputTypeValueProvider(): array
    {
        return [
            'empty string' => [''],
            'uppercase MANUAL' => ['MANUAL'],
            'unknown value' => ['unknown'],
            'partial match' => ['log'],
            'whitespace' => [' file'],
        ];
    }

    #[Test]
    #[DataProvider('invalidInputTypeValueProvider')]
    public function isValidReturnsFalseForInvalidValues(string $value): void
    {
        self::assertFalse(TaskInputType::isValid($value));
    }

    /**
     * @return array<string, array{string, TaskInputType}>
     */
    public static function tryFromStringValidProvider(): array
    {
        return [
            'manual' => ['manual', TaskInputType::MANUAL],
            'syslog' => ['syslog', TaskInputType::SYSLOG],
            'deprecation_log' => ['deprecation_log', TaskInputType::DEPRECATION_LOG],
            'table' => ['table', TaskInputType::TABLE],
            'file' => ['file', TaskInputType::FILE],
        ];
    }

    #[Test]
    #[DataProvider('tryFromStringValidProvider')]
    public function tryFromStringReturnsEnumForValidValues(string $value, TaskInputType $expected): void
    {
        $result = TaskInputType::tryFromString($value);

        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tryFromStringInvalidProvider(): array
    {
        return [
            'empty string' => [''],
            'invalid value' => ['invalid'],
            'uppercase FILE' => ['FILE'],
        ];
    }

    #[Test]
    #[DataProvider('tryFromStringInvalidProvider')]
    public function tryFromStringReturnsNullForInvalidValues(string $value): void
    {
        self::assertNull(TaskInputType::tryFromString($value));
    }

    /**
     * @return array<string, array{TaskInputType, string}>
     */
    public static function getLabelProvider(): array
    {
        return [
            'MANUAL' => [TaskInputType::MANUAL, 'Manual Input'],
            'SYSLOG' => [TaskInputType::SYSLOG, 'System Log'],
            'DEPRECATION_LOG' => [TaskInputType::DEPRECATION_LOG, 'Deprecation Log'],
            'TABLE' => [TaskInputType::TABLE, 'Database Table'],
            'FILE' => [TaskInputType::FILE, 'File'],
        ];
    }

    #[Test]
    #[DataProvider('getLabelProvider')]
    public function getLabelReturnsCorrectLabel(TaskInputType $inputType, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $inputType->getLabel());
    }

    #[Test]
    public function getLabelReturnsNonEmptyStringForAllCases(): void
    {
        foreach (TaskInputType::cases() as $inputType) {
            self::assertNotEmpty($inputType->getLabel(), sprintf(
                'getLabel() returned empty string for case %s',
                $inputType->name,
            ));
        }
    }
}
