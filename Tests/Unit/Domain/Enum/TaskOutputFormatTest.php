<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\TaskOutputFormat;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Enums are not valid coverage targets in PHPUnit 12
#[CoversNothing]
final class TaskOutputFormatTest extends TestCase
{
    #[Test]
    public function allFourCasesExist(): void
    {
        $cases = TaskOutputFormat::cases();

        self::assertCount(4, $cases);
    }

    #[Test]
    public function enumCasesHaveCorrectValues(): void
    {
        self::assertSame('markdown', TaskOutputFormat::MARKDOWN->value);
        self::assertSame('json', TaskOutputFormat::JSON->value);
        self::assertSame('plain', TaskOutputFormat::PLAIN->value);
        self::assertSame('html', TaskOutputFormat::HTML->value);
    }

    #[Test]
    public function valuesReturnsAllOutputFormatValues(): void
    {
        $values = TaskOutputFormat::values();

        self::assertCount(4, $values);
        self::assertContains('markdown', $values);
        self::assertContains('json', $values);
        self::assertContains('plain', $values);
        self::assertContains('html', $values);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validOutputFormatValueProvider(): array
    {
        return [
            'markdown' => ['markdown'],
            'json' => ['json'],
            'plain' => ['plain'],
            'html' => ['html'],
        ];
    }

    #[Test]
    #[DataProvider('validOutputFormatValueProvider')]
    public function isValidReturnsTrueForValidValues(string $value): void
    {
        self::assertTrue(TaskOutputFormat::isValid($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidOutputFormatValueProvider(): array
    {
        return [
            'empty string' => [''],
            'uppercase MARKDOWN' => ['MARKDOWN'],
            'unknown value' => ['unknown'],
            'partial match' => ['mark'],
            'whitespace' => [' json'],
        ];
    }

    #[Test]
    #[DataProvider('invalidOutputFormatValueProvider')]
    public function isValidReturnsFalseForInvalidValues(string $value): void
    {
        self::assertFalse(TaskOutputFormat::isValid($value));
    }

    /**
     * @return array<string, array{string, TaskOutputFormat}>
     */
    public static function tryFromStringValidProvider(): array
    {
        return [
            'markdown' => ['markdown', TaskOutputFormat::MARKDOWN],
            'json' => ['json', TaskOutputFormat::JSON],
            'plain' => ['plain', TaskOutputFormat::PLAIN],
            'html' => ['html', TaskOutputFormat::HTML],
        ];
    }

    #[Test]
    #[DataProvider('tryFromStringValidProvider')]
    public function tryFromStringReturnsEnumForValidValues(string $value, TaskOutputFormat $expected): void
    {
        $result = TaskOutputFormat::tryFromString($value);

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
            'uppercase HTML' => ['HTML'],
        ];
    }

    #[Test]
    #[DataProvider('tryFromStringInvalidProvider')]
    public function tryFromStringReturnsNullForInvalidValues(string $value): void
    {
        self::assertNull(TaskOutputFormat::tryFromString($value));
    }

    /**
     * @return array<string, array{TaskOutputFormat, string}>
     */
    public static function getContentTypeProvider(): array
    {
        return [
            'MARKDOWN' => [TaskOutputFormat::MARKDOWN, 'text/markdown'],
            'JSON' => [TaskOutputFormat::JSON, 'application/json'],
            'PLAIN' => [TaskOutputFormat::PLAIN, 'text/plain'],
            'HTML' => [TaskOutputFormat::HTML, 'text/html'],
        ];
    }

    #[Test]
    #[DataProvider('getContentTypeProvider')]
    public function getContentTypeReturnsValidMimeType(TaskOutputFormat $format, string $expectedMimeType): void
    {
        self::assertSame($expectedMimeType, $format->getContentType());
    }

    #[Test]
    public function getContentTypeReturnsNonEmptyStringForAllCases(): void
    {
        foreach (TaskOutputFormat::cases() as $format) {
            self::assertNotEmpty($format->getContentType(), sprintf(
                'getContentType() returned empty string for case %s',
                $format->name,
            ));
        }
    }

    #[Test]
    public function getContentTypeContainsSlashForAllCases(): void
    {
        foreach (TaskOutputFormat::cases() as $format) {
            self::assertStringContainsString('/', $format->getContentType(), sprintf(
                'getContentType() does not contain "/" for case %s (not a valid MIME type)',
                $format->name,
            ));
        }
    }

    /**
     * @return array<string, array{TaskOutputFormat, string}>
     */
    public static function getFileExtensionProvider(): array
    {
        return [
            'MARKDOWN' => [TaskOutputFormat::MARKDOWN, 'md'],
            'JSON' => [TaskOutputFormat::JSON, 'json'],
            'PLAIN' => [TaskOutputFormat::PLAIN, 'txt'],
            'HTML' => [TaskOutputFormat::HTML, 'html'],
        ];
    }

    #[Test]
    #[DataProvider('getFileExtensionProvider')]
    public function getFileExtensionReturnsCorrectExtension(TaskOutputFormat $format, string $expectedExtension): void
    {
        self::assertSame($expectedExtension, $format->getFileExtension());
    }

    #[Test]
    public function getFileExtensionReturnsNonEmptyStringForAllCases(): void
    {
        foreach (TaskOutputFormat::cases() as $format) {
            self::assertNotEmpty($format->getFileExtension(), sprintf(
                'getFileExtension() returned empty string for case %s',
                $format->name,
            ));
        }
    }
}
