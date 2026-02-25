<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

#[CoversClass(TranslatorResult::class)]
class TranslatorResultTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $metadata = ['detected_source_language' => 'en', 'billed_characters' => 100];

        $result = new TranslatorResult(
            translatedText: 'Hallo Welt',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'deepl',
            confidence: 0.95,
            metadata: $metadata,
        );

        self::assertEquals('Hallo Welt', $result->translatedText);
        self::assertEquals('en', $result->sourceLanguage);
        self::assertEquals('de', $result->targetLanguage);
        self::assertEquals('deepl', $result->translator);
        self::assertEquals(0.95, $result->confidence);
        self::assertEquals($metadata, $result->metadata);
    }

    #[Test]
    public function constructorDefaultsOptionalParameters(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Translated text',
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            translator: 'llm',
        );

        self::assertNull($result->confidence);
        self::assertNull($result->metadata);
    }

    #[Test]
    public function resultIsReadonly(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Test',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
        );

        // Verify readonly class reflection - class should be readonly
        $reflection = new ReflectionClass($result);
        self::assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function confidenceCanBeZero(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Test',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
            confidence: 0.0,
        );

        self::assertEquals(0.0, $result->confidence);
    }

    #[Test]
    public function confidenceCanBeOne(): void
    {
        $result = new TranslatorResult(
            translatedText: 'Test',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
            confidence: 1.0,
        );

        self::assertEquals(1.0, $result->confidence);
    }

    #[Test]
    public function emptyTranslatedTextIsAllowed(): void
    {
        $result = new TranslatorResult(
            translatedText: '',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
        );

        self::assertEquals('', $result->translatedText);
    }

    #[Test]
    public function metadataCanContainAnyData(): void
    {
        $metadata = [
            'string_key' => 'value',
            'int_key' => 123,
            'float_key' => 1.23,
            'bool_key' => true,
            'array_key' => ['nested' => 'data'],
        ];

        $result = new TranslatorResult(
            translatedText: 'Test',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            translator: 'test',
            metadata: $metadata,
        );

        self::assertEquals($metadata, $result->metadata);
    }
}
