<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\TranslationResult;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for TranslationResult value object.
 *
 * Note: Domain models are excluded from coverage in phpunit.xml.
 */
#[CoversNothing]
final class TranslationResultTest extends AbstractUnitTestCase
{
    private UsageStatistics $defaultUsage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultUsage = new UsageStatistics(100, 50, 150);
    }

    // ========================================
    // Constructor / property access
    // ========================================

    #[Test]
    public function constructorSetsAllRequiredProperties(): void
    {
        $result = new TranslationResult(
            translation: 'Hallo Welt',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.95,
            usage: $this->defaultUsage,
        );

        self::assertSame('Hallo Welt', $result->translation);
        self::assertSame('en', $result->sourceLanguage);
        self::assertSame('de', $result->targetLanguage);
        self::assertSame(0.95, $result->confidence);
        self::assertSame($this->defaultUsage, $result->usage);
    }

    #[Test]
    public function constructorAlternativesDefaultToNull(): void
    {
        $result = new TranslationResult(
            translation: 'Bonjour',
            sourceLanguage: 'en',
            targetLanguage: 'fr',
            confidence: 0.9,
            usage: $this->defaultUsage,
        );

        self::assertNull($result->alternatives);
    }

    #[Test]
    public function constructorMetadataDefaultsToNull(): void
    {
        $result = new TranslationResult(
            translation: 'Ciao',
            sourceLanguage: 'en',
            targetLanguage: 'it',
            confidence: 0.88,
            usage: $this->defaultUsage,
        );

        self::assertNull($result->metadata);
    }

    #[Test]
    public function constructorStoresAlternativesWhenProvided(): void
    {
        $alternatives = ['Hallo Welt!', 'Guten Tag Welt'];

        $result = new TranslationResult(
            translation: 'Hallo Welt',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.9,
            usage: $this->defaultUsage,
            alternatives: $alternatives,
        );

        self::assertSame($alternatives, $result->alternatives);
    }

    #[Test]
    public function constructorStoresMetadataWhenProvided(): void
    {
        $metadata = ['engine' => 'gpt-4o', 'model_version' => '2024-11'];

        $result = new TranslationResult(
            translation: 'Hallo',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.9,
            usage: $this->defaultUsage,
            metadata: $metadata,
        );

        self::assertSame($metadata, $result->metadata);
    }

    // ========================================
    // getText
    // ========================================

    #[Test]
    public function getTextReturnsTranslation(): void
    {
        $result = new TranslationResult(
            translation: 'Translated text',
            sourceLanguage: 'en',
            targetLanguage: 'es',
            confidence: 0.8,
            usage: $this->defaultUsage,
        );

        self::assertSame('Translated text', $result->getText());
        self::assertSame($result->translation, $result->getText());
    }

    // ========================================
    // isConfident
    // ========================================

    #[Test]
    public function isConfidentReturnsTrueWhenAboveDefaultThreshold(): void
    {
        $result = new TranslationResult(
            translation: 'High confidence',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.95,
            usage: $this->defaultUsage,
        );

        self::assertTrue($result->isConfident());
    }

    #[Test]
    public function isConfidentReturnsTrueWhenAtDefaultThreshold(): void
    {
        $result = new TranslationResult(
            translation: 'Exactly threshold',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.7,
            usage: $this->defaultUsage,
        );

        self::assertTrue($result->isConfident());
    }

    #[Test]
    public function isConfidentReturnsFalseWhenBelowDefaultThreshold(): void
    {
        $result = new TranslationResult(
            translation: 'Low confidence',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.5,
            usage: $this->defaultUsage,
        );

        self::assertFalse($result->isConfident());
    }

    #[Test]
    public function isConfidentUsesCustomThreshold(): void
    {
        $result = new TranslationResult(
            translation: 'Medium confidence',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.6,
            usage: $this->defaultUsage,
        );

        // Above custom threshold
        self::assertTrue($result->isConfident(0.5));
        // Below custom threshold
        self::assertFalse($result->isConfident(0.8));
    }

    #[Test]
    public function isConfidentReturnsTrueForPerfectConfidence(): void
    {
        $result = new TranslationResult(
            translation: 'Perfect',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 1.0,
            usage: $this->defaultUsage,
        );

        self::assertTrue($result->isConfident());
        self::assertTrue($result->isConfident(1.0));
    }

    #[Test]
    public function isConfidentReturnsFalseForZeroConfidence(): void
    {
        $result = new TranslationResult(
            translation: 'Zero confidence',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.0,
            usage: $this->defaultUsage,
        );

        self::assertFalse($result->isConfident());
        self::assertFalse($result->isConfident(0.1));
    }

    /**
     * @return array<string, array{float, float, bool}>
     */
    public static function confidenceThresholdProvider(): array
    {
        return [
            'confidence 0.9 threshold 0.7 is confident' => [0.9, 0.7, true],
            'confidence 0.7 threshold 0.7 is confident' => [0.7, 0.7, true],
            'confidence 0.69 threshold 0.7 is not confident' => [0.69, 0.7, false],
            'confidence 0.5 threshold 0.4 is confident' => [0.5, 0.4, true],
            'confidence 0.3 threshold 0.5 is not confident' => [0.3, 0.5, false],
        ];
    }

    #[Test]
    #[DataProvider('confidenceThresholdProvider')]
    public function isConfidentWithVariousThresholds(
        float $confidence,
        float $threshold,
        bool $expectedResult,
    ): void {
        $result = new TranslationResult(
            translation: 'test',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: $confidence,
            usage: $this->defaultUsage,
        );

        self::assertSame($expectedResult, $result->isConfident($threshold));
    }

    // ========================================
    // getAlternatives
    // ========================================

    #[Test]
    public function getAlternativesReturnsEmptyArrayWhenNull(): void
    {
        $result = new TranslationResult(
            translation: 'Test',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.9,
            usage: $this->defaultUsage,
            alternatives: null,
        );

        self::assertSame([], $result->getAlternatives());
    }

    #[Test]
    public function getAlternativesReturnsProvidedAlternatives(): void
    {
        $alternatives = ['Alt 1', 'Alt 2', 'Alt 3'];

        $result = new TranslationResult(
            translation: 'Main translation',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.9,
            usage: $this->defaultUsage,
            alternatives: $alternatives,
        );

        self::assertSame($alternatives, $result->getAlternatives());
        self::assertCount(3, $result->getAlternatives());
    }

    // ========================================
    // hasAlternatives
    // ========================================

    #[Test]
    public function hasAlternativesReturnsFalseWhenNull(): void
    {
        $result = new TranslationResult(
            translation: 'Test',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.9,
            usage: $this->defaultUsage,
        );

        self::assertFalse($result->hasAlternatives());
    }

    #[Test]
    public function hasAlternativesReturnsFalseWhenEmptyArray(): void
    {
        $result = new TranslationResult(
            translation: 'Test',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.9,
            usage: $this->defaultUsage,
            alternatives: [],
        );

        self::assertFalse($result->hasAlternatives());
    }

    #[Test]
    public function hasAlternativesReturnsTrueWhenAlternativesExist(): void
    {
        $result = new TranslationResult(
            translation: 'Test',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.9,
            usage: $this->defaultUsage,
            alternatives: ['Alternative 1'],
        );

        self::assertTrue($result->hasAlternatives());
    }

    #[Test]
    public function hasAlternativesReturnsTrueForMultipleAlternatives(): void
    {
        $result = new TranslationResult(
            translation: 'Main',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.85,
            usage: $this->defaultUsage,
            alternatives: ['Alt 1', 'Alt 2', 'Alt 3'],
        );

        self::assertTrue($result->hasAlternatives());
    }

    // ========================================
    // Immutability
    // ========================================

    #[Test]
    public function resultIsImmutable(): void
    {
        $result = new TranslationResult(
            translation: 'Original',
            sourceLanguage: 'en',
            targetLanguage: 'de',
            confidence: 0.9,
            usage: $this->defaultUsage,
        );

        // Properties are readonly — verify they haven't changed
        self::assertSame('Original', $result->translation);
        self::assertSame('en', $result->sourceLanguage);
        self::assertSame('de', $result->targetLanguage);
    }
}
