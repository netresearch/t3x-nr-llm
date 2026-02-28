<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Enums are not valid coverage targets in PHPUnit 12
#[CoversNothing]
final class ModelCapabilityTest extends TestCase
{
    #[Test]
    public function allEightCasesExist(): void
    {
        $cases = ModelCapability::cases();

        self::assertCount(8, $cases);
    }

    #[Test]
    public function enumCasesHaveCorrectValues(): void
    {
        self::assertSame('chat', ModelCapability::CHAT->value);
        self::assertSame('completion', ModelCapability::COMPLETION->value);
        self::assertSame('embeddings', ModelCapability::EMBEDDINGS->value);
        self::assertSame('vision', ModelCapability::VISION->value);
        self::assertSame('streaming', ModelCapability::STREAMING->value);
        self::assertSame('tools', ModelCapability::TOOLS->value);
        self::assertSame('json_mode', ModelCapability::JSON_MODE->value);
        self::assertSame('audio', ModelCapability::AUDIO->value);
    }

    #[Test]
    public function valuesReturnsAllCapabilityValues(): void
    {
        $values = ModelCapability::values();

        self::assertCount(8, $values);
        self::assertContains('chat', $values);
        self::assertContains('completion', $values);
        self::assertContains('embeddings', $values);
        self::assertContains('vision', $values);
        self::assertContains('streaming', $values);
        self::assertContains('tools', $values);
        self::assertContains('json_mode', $values);
        self::assertContains('audio', $values);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validCapabilityValueProvider(): array
    {
        return [
            'chat' => ['chat'],
            'completion' => ['completion'],
            'embeddings' => ['embeddings'],
            'vision' => ['vision'],
            'streaming' => ['streaming'],
            'tools' => ['tools'],
            'json_mode' => ['json_mode'],
            'audio' => ['audio'],
        ];
    }

    #[Test]
    #[DataProvider('validCapabilityValueProvider')]
    public function isValidReturnsTrueForValidValues(string $value): void
    {
        self::assertTrue(ModelCapability::isValid($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCapabilityValueProvider(): array
    {
        return [
            'empty string' => [''],
            'uppercase CHAT' => ['CHAT'],
            'unknown value' => ['unknown'],
            'partial match' => ['json'],
            'whitespace' => [' chat'],
        ];
    }

    #[Test]
    #[DataProvider('invalidCapabilityValueProvider')]
    public function isValidReturnsFalseForInvalidValues(string $value): void
    {
        self::assertFalse(ModelCapability::isValid($value));
    }

    /**
     * @return array<string, array{string, ModelCapability}>
     */
    public static function tryFromStringValidProvider(): array
    {
        return [
            'chat' => ['chat', ModelCapability::CHAT],
            'completion' => ['completion', ModelCapability::COMPLETION],
            'embeddings' => ['embeddings', ModelCapability::EMBEDDINGS],
            'vision' => ['vision', ModelCapability::VISION],
            'streaming' => ['streaming', ModelCapability::STREAMING],
            'tools' => ['tools', ModelCapability::TOOLS],
            'json_mode' => ['json_mode', ModelCapability::JSON_MODE],
            'audio' => ['audio', ModelCapability::AUDIO],
        ];
    }

    #[Test]
    #[DataProvider('tryFromStringValidProvider')]
    public function tryFromStringReturnsEnumForValidValues(string $value, ModelCapability $expected): void
    {
        $result = ModelCapability::tryFromString($value);

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
            'uppercase AUDIO' => ['AUDIO'],
        ];
    }

    #[Test]
    #[DataProvider('tryFromStringInvalidProvider')]
    public function tryFromStringReturnsNullForInvalidValues(string $value): void
    {
        self::assertNull(ModelCapability::tryFromString($value));
    }
}
