<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

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

        $this->assertEquals('Hallo Welt', $result->translatedText);
        $this->assertEquals('en', $result->sourceLanguage);
        $this->assertEquals('de', $result->targetLanguage);
        $this->assertEquals('deepl', $result->translator);
        $this->assertEquals(0.95, $result->confidence);
        $this->assertEquals($metadata, $result->metadata);
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

        $this->assertNull($result->confidence);
        $this->assertNull($result->metadata);
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

        // Verify readonly properties are accessible
        $this->assertIsString($result->translatedText);
        $this->assertIsString($result->sourceLanguage);
        $this->assertIsString($result->targetLanguage);
        $this->assertIsString($result->translator);
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

        $this->assertEquals(0.0, $result->confidence);
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

        $this->assertEquals(1.0, $result->confidence);
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

        $this->assertEquals('', $result->translatedText);
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

        $this->assertEquals($metadata, $result->metadata);
    }
}
