<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for VisionResponse value object.
 *
 * Note: Domain models are excluded from coverage in phpunit.xml.
 */
#[CoversNothing]
final class VisionResponseTest extends AbstractUnitTestCase
{
    private UsageStatistics $defaultUsage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultUsage = new UsageStatistics(200, 100, 300);
    }

    // ========================================
    // Constructor / property access
    // ========================================

    #[Test]
    public function constructorSetsAllRequiredProperties(): void
    {
        $response = new VisionResponse(
            description: 'A cat sitting on a windowsill',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
        );

        self::assertSame('A cat sitting on a windowsill', $response->description);
        self::assertSame('gpt-4o', $response->model);
        self::assertSame($this->defaultUsage, $response->usage);
    }

    #[Test]
    public function constructorProviderDefaultsToEmptyString(): void
    {
        $response = new VisionResponse(
            description: 'Description',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
        );

        self::assertSame('', $response->provider);
    }

    #[Test]
    public function constructorConfidenceDefaultsToNull(): void
    {
        $response = new VisionResponse(
            description: 'Description',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
        );

        self::assertNull($response->confidence);
    }

    #[Test]
    public function constructorDetectedObjectsDefaultToNull(): void
    {
        $response = new VisionResponse(
            description: 'Description',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
        );

        self::assertNull($response->detectedObjects);
    }

    #[Test]
    public function constructorMetadataDefaultsToNull(): void
    {
        $response = new VisionResponse(
            description: 'Description',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
        );

        self::assertNull($response->metadata);
    }

    #[Test]
    public function constructorStoresAllOptionalProperties(): void
    {
        $detectedObjects = [
            ['label' => 'cat', 'confidence' => 0.97],
            ['label' => 'window', 'confidence' => 0.85],
        ];
        $metadata = ['request_id' => 'req-abc123'];

        $response = new VisionResponse(
            description: 'A cat by a window',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
            provider: 'openai',
            confidence: 0.95,
            detectedObjects: $detectedObjects,
            metadata: $metadata,
        );

        self::assertSame('openai', $response->provider);
        self::assertSame(0.95, $response->confidence);
        self::assertSame($detectedObjects, $response->detectedObjects);
        self::assertSame($metadata, $response->metadata);
    }

    // ========================================
    // getText / getDescription
    // ========================================

    #[Test]
    public function getTextReturnsDescription(): void
    {
        $response = new VisionResponse(
            description: 'The image shows a sunset over the ocean.',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
        );

        self::assertSame('The image shows a sunset over the ocean.', $response->getText());
    }

    #[Test]
    public function getDescriptionAliasReturnsDescription(): void
    {
        $response = new VisionResponse(
            description: 'A landscape photo',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
        );

        self::assertSame($response->description, $response->getDescription());
    }

    #[Test]
    public function getTextAndGetDescriptionReturnSameValue(): void
    {
        $description = 'A detailed analysis of the image content.';
        $response = new VisionResponse(
            description: $description,
            model: 'gpt-4o',
            usage: $this->defaultUsage,
        );

        self::assertSame($response->getText(), $response->getDescription());
    }

    // ========================================
    // meetsConfidence
    // ========================================

    #[Test]
    public function meetsConfidenceReturnsFalseWhenConfidenceIsNull(): void
    {
        $response = new VisionResponse(
            description: 'No confidence',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
            confidence: null,
        );

        self::assertFalse($response->meetsConfidence(0.5));
        self::assertFalse($response->meetsConfidence(0.0));
    }

    #[Test]
    public function meetsConfidenceReturnsTrueWhenAboveThreshold(): void
    {
        $response = new VisionResponse(
            description: 'High confidence',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
            confidence: 0.95,
        );

        self::assertTrue($response->meetsConfidence(0.8));
        self::assertTrue($response->meetsConfidence(0.95));
    }

    #[Test]
    public function meetsConfidenceReturnsTrueWhenAtThreshold(): void
    {
        $response = new VisionResponse(
            description: 'At threshold',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
            confidence: 0.75,
        );

        self::assertTrue($response->meetsConfidence(0.75));
    }

    #[Test]
    public function meetsConfidenceReturnsFalseWhenBelowThreshold(): void
    {
        $response = new VisionResponse(
            description: 'Low confidence',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
            confidence: 0.4,
        );

        self::assertFalse($response->meetsConfidence(0.5));
        self::assertFalse($response->meetsConfidence(0.9));
    }

    #[Test]
    public function meetsConfidenceReturnsTrueForPerfectConfidence(): void
    {
        $response = new VisionResponse(
            description: 'Perfect confidence',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
            confidence: 1.0,
        );

        self::assertTrue($response->meetsConfidence(1.0));
        self::assertTrue($response->meetsConfidence(0.99));
    }

    #[Test]
    public function meetsConfidenceReturnsFalseForZeroConfidenceWithPositiveThreshold(): void
    {
        $response = new VisionResponse(
            description: 'Zero confidence',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
            confidence: 0.0,
        );

        self::assertFalse($response->meetsConfidence(0.1));
    }

    #[Test]
    public function meetsConfidenceReturnsTrueForZeroConfidenceWithZeroThreshold(): void
    {
        $response = new VisionResponse(
            description: 'Zero confidence',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
            confidence: 0.0,
        );

        self::assertTrue($response->meetsConfidence(0.0));
    }

    /**
     * @return array<string, array{float|null, float, bool}>
     */
    public static function meetsConfidenceProvider(): array
    {
        return [
            'null confidence always fails' => [null, 0.5, false],
            'null confidence with zero threshold fails' => [null, 0.0, false],
            '0.9 meets 0.8' => [0.9, 0.8, true],
            '0.9 meets 0.9' => [0.9, 0.9, true],
            '0.8 fails 0.9' => [0.8, 0.9, false],
            'perfect confidence meets any threshold' => [1.0, 0.99, true],
        ];
    }

    #[Test]
    #[DataProvider('meetsConfidenceProvider')]
    public function meetsConfidenceWithVariousInputs(
        ?float $confidence,
        float $threshold,
        bool $expected,
    ): void {
        $response = new VisionResponse(
            description: 'test',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
            confidence: $confidence,
        );

        self::assertSame($expected, $response->meetsConfidence($threshold));
    }

    // ========================================
    // Immutability
    // ========================================

    #[Test]
    public function responseIsImmutable(): void
    {
        $response = new VisionResponse(
            description: 'Original description',
            model: 'gpt-4o',
            usage: $this->defaultUsage,
            confidence: 0.9,
        );

        // Readonly properties cannot be changed — verify initial values remain
        self::assertSame('Original description', $response->description);
        self::assertSame('gpt-4o', $response->model);
        self::assertSame(0.9, $response->confidence);
    }

    // ========================================
    // Usage statistics delegation
    // ========================================

    #[Test]
    public function usageStatisticsAreAccessible(): void
    {
        $usage = new UsageStatistics(50, 150, 200, 0.01);

        $response = new VisionResponse(
            description: 'test',
            model: 'gpt-4o',
            usage: $usage,
        );

        self::assertSame(50, $response->usage->promptTokens);
        self::assertSame(150, $response->usage->completionTokens);
        self::assertSame(200, $response->usage->totalTokens);
        self::assertSame(0.01, $response->usage->estimatedCost);
    }
}
