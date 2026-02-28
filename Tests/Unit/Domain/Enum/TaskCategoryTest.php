<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\TaskCategory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Enums are not valid coverage targets in PHPUnit 12
#[CoversNothing]
final class TaskCategoryTest extends TestCase
{
    #[Test]
    public function allFiveCasesExist(): void
    {
        $cases = TaskCategory::cases();

        self::assertCount(5, $cases);
    }

    #[Test]
    public function enumCasesHaveCorrectValues(): void
    {
        self::assertSame('log_analysis', TaskCategory::LOG_ANALYSIS->value);
        self::assertSame('content', TaskCategory::CONTENT->value);
        self::assertSame('system', TaskCategory::SYSTEM->value);
        self::assertSame('developer', TaskCategory::DEVELOPER->value);
        self::assertSame('general', TaskCategory::GENERAL->value);
    }

    #[Test]
    public function valuesReturnsAllCategoryValues(): void
    {
        $values = TaskCategory::values();

        self::assertCount(5, $values);
        self::assertContains('log_analysis', $values);
        self::assertContains('content', $values);
        self::assertContains('system', $values);
        self::assertContains('developer', $values);
        self::assertContains('general', $values);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validCategoryValueProvider(): array
    {
        return [
            'log_analysis' => ['log_analysis'],
            'content' => ['content'],
            'system' => ['system'],
            'developer' => ['developer'],
            'general' => ['general'],
        ];
    }

    #[Test]
    #[DataProvider('validCategoryValueProvider')]
    public function isValidReturnsTrueForValidValues(string $value): void
    {
        self::assertTrue(TaskCategory::isValid($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCategoryValueProvider(): array
    {
        return [
            'empty string' => [''],
            'uppercase CONTENT' => ['CONTENT'],
            'unknown value' => ['unknown'],
            'partial match' => ['log'],
            'whitespace' => [' system'],
        ];
    }

    #[Test]
    #[DataProvider('invalidCategoryValueProvider')]
    public function isValidReturnsFalseForInvalidValues(string $value): void
    {
        self::assertFalse(TaskCategory::isValid($value));
    }

    /**
     * @return array<string, array{string, TaskCategory}>
     */
    public static function tryFromStringValidProvider(): array
    {
        return [
            'log_analysis' => ['log_analysis', TaskCategory::LOG_ANALYSIS],
            'content' => ['content', TaskCategory::CONTENT],
            'system' => ['system', TaskCategory::SYSTEM],
            'developer' => ['developer', TaskCategory::DEVELOPER],
            'general' => ['general', TaskCategory::GENERAL],
        ];
    }

    #[Test]
    #[DataProvider('tryFromStringValidProvider')]
    public function tryFromStringReturnsEnumForValidValues(string $value, TaskCategory $expected): void
    {
        $result = TaskCategory::tryFromString($value);

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
            'uppercase GENERAL' => ['GENERAL'],
        ];
    }

    #[Test]
    #[DataProvider('tryFromStringInvalidProvider')]
    public function tryFromStringReturnsNullForInvalidValues(string $value): void
    {
        self::assertNull(TaskCategory::tryFromString($value));
    }

    /**
     * @return array<string, array{TaskCategory, string}>
     */
    public static function getLabelProvider(): array
    {
        return [
            'LOG_ANALYSIS' => [TaskCategory::LOG_ANALYSIS, 'Log Analysis'],
            'CONTENT' => [TaskCategory::CONTENT, 'Content'],
            'SYSTEM' => [TaskCategory::SYSTEM, 'System'],
            'DEVELOPER' => [TaskCategory::DEVELOPER, 'Developer'],
            'GENERAL' => [TaskCategory::GENERAL, 'General'],
        ];
    }

    #[Test]
    #[DataProvider('getLabelProvider')]
    public function getLabelReturnsCorrectLabel(TaskCategory $category, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $category->getLabel());
    }

    #[Test]
    public function getLabelReturnsNonEmptyStringForAllCases(): void
    {
        foreach (TaskCategory::cases() as $category) {
            self::assertNotEmpty($category->getLabel(), sprintf(
                'getLabel() returned empty string for case %s',
                $category->name,
            ));
        }
    }

    /**
     * @return array<string, array{TaskCategory, string}>
     */
    public static function getIconIdentifierProvider(): array
    {
        return [
            'LOG_ANALYSIS' => [TaskCategory::LOG_ANALYSIS, 'actions-document-info'],
            'CONTENT' => [TaskCategory::CONTENT, 'actions-document-edit'],
            'SYSTEM' => [TaskCategory::SYSTEM, 'actions-cog'],
            'DEVELOPER' => [TaskCategory::DEVELOPER, 'actions-code'],
            'GENERAL' => [TaskCategory::GENERAL, 'actions-rocket'],
        ];
    }

    #[Test]
    #[DataProvider('getIconIdentifierProvider')]
    public function getIconIdentifierReturnsCorrectIdentifier(TaskCategory $category, string $expectedIdentifier): void
    {
        self::assertSame($expectedIdentifier, $category->getIconIdentifier());
    }

    #[Test]
    public function getIconIdentifierReturnsNonEmptyStringForAllCases(): void
    {
        foreach (TaskCategory::cases() as $category) {
            self::assertNotEmpty($category->getIconIdentifier(), sprintf(
                'getIconIdentifier() returned empty string for case %s',
                $category->name,
            ));
        }
    }
}
