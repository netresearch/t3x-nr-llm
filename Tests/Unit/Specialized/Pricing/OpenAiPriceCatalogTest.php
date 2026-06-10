<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Pricing;

use Netresearch\NrLlm\Specialized\Pricing\OpenAiPriceCatalog;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(OpenAiPriceCatalog::class)]
class OpenAiPriceCatalogTest extends AbstractUnitTestCase
{
    // ==================== imageTokenCost ====================

    #[Test]
    public function imageTokenCostPricesGptImage2TokensAtListPrice(): void
    {
        // 40 text-in × $5/1M + 10 image-in × $8/1M + 1000 out × $30/1M.
        $cost = OpenAiPriceCatalog::imageTokenCost('gpt-image-2', 50, 1000, 10);

        self::assertNotNull($cost);
        self::assertEqualsWithDelta(0.03028, $cost, 1e-9);
    }

    #[Test]
    public function imageTokenCostBillsAllInputAsTextWhenNoImageTokenSplitGiven(): void
    {
        // 50 in × $5/1M + 1000 out × $30/1M
        $cost = OpenAiPriceCatalog::imageTokenCost('gpt-image-2', 50, 1000);

        self::assertNotNull($cost);
        self::assertEqualsWithDelta(0.03025, $cost, 1e-9);
    }

    #[Test]
    public function imageTokenCostResolvesDatedPointReleasesByPrefix(): void
    {
        $exact = OpenAiPriceCatalog::imageTokenCost('gpt-image-2', 100, 100);
        $dated = OpenAiPriceCatalog::imageTokenCost('gpt-image-2-2026-04-21', 100, 100);

        self::assertNotNull($exact);
        self::assertSame($exact, $dated);
    }

    #[Test]
    public function imageTokenCostPrefersLongestPrefixForMiniVariant(): void
    {
        // gpt-image-1-mini must not resolve to the gpt-image-1 prices.
        // 1M output tokens: mini = $8, base model = $40.
        $cost = OpenAiPriceCatalog::imageTokenCost('gpt-image-1-mini', 0, 1_000_000);

        self::assertNotNull($cost);
        self::assertEqualsWithDelta(8.0, $cost, 1e-9);
    }

    #[Test]
    public function imageTokenCostReturnsNullForUnknownModel(): void
    {
        self::assertNull(OpenAiPriceCatalog::imageTokenCost('dall-e-3', 50, 1000));
        self::assertNull(OpenAiPriceCatalog::imageTokenCost('some-future-model', 50, 1000));
    }

    // ==================== imagePrice ====================

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: float}>
     */
    public static function imageListPriceProvider(): array
    {
        // List prices, see the catalog source documentation.
        return [
            'dall-e-3 standard square'   => ['dall-e-3', 'standard', '1024x1024', 0.040],
            'dall-e-3 standard wide'     => ['dall-e-3', 'standard', '1792x1024', 0.080],
            'dall-e-3 hd square'         => ['dall-e-3', 'hd', '1024x1024', 0.080],
            'dall-e-3 hd tall'           => ['dall-e-3', 'hd', '1024x1792', 0.120],
            'dall-e-2 1024'              => ['dall-e-2', 'standard', '1024x1024', 0.020],
            'dall-e-2 512'               => ['dall-e-2', 'standard', '512x512', 0.018],
            'dall-e-2 256'               => ['dall-e-2', 'standard', '256x256', 0.016],
            'gpt-image-1 medium square'  => ['gpt-image-1', 'medium', '1024x1024', 0.042],
            'gpt-image-2 high square'    => ['gpt-image-2', 'high', '1024x1024', 0.211],
        ];
    }

    #[Test]
    #[DataProvider('imageListPriceProvider')]
    public function imagePriceReturnsListPrice(string $model, string $quality, string $size, float $expected): void
    {
        self::assertSame($expected, OpenAiPriceCatalog::imagePrice($model, $quality, $size));
    }

    #[Test]
    public function imagePriceReturnsNullForUnknownCombinations(): void
    {
        // Never guess: unknown model, quality tier, or size yields null.
        self::assertNull(OpenAiPriceCatalog::imagePrice('unknown-model', 'standard', '1024x1024'));
        self::assertNull(OpenAiPriceCatalog::imagePrice('dall-e-3', 'low', '1024x1024'));
        self::assertNull(OpenAiPriceCatalog::imagePrice('dall-e-3', 'standard', '2048x2048'));
        // Arbitrary gpt-image-2 WxH sizes have no per-image list price.
        self::assertNull(OpenAiPriceCatalog::imagePrice('gpt-image-2', 'high', '3840x2160'));
    }

    // ==================== speechSynthesisCost ====================

    #[Test]
    public function speechSynthesisCostPricesPerMillionCharacters(): void
    {
        self::assertEqualsWithDelta(15.00, (float)OpenAiPriceCatalog::speechSynthesisCost('tts-1', 1_000_000), 1e-9);
        self::assertEqualsWithDelta(30.00, (float)OpenAiPriceCatalog::speechSynthesisCost('tts-1-hd', 1_000_000), 1e-9);
        self::assertEqualsWithDelta(0.0015, (float)OpenAiPriceCatalog::speechSynthesisCost('tts-1', 100), 1e-12);
    }

    #[Test]
    public function speechSynthesisCostReturnsNullForUnknownModel(): void
    {
        self::assertNull(OpenAiPriceCatalog::speechSynthesisCost('unknown-tts', 1000));
    }

    // ==================== transcriptionCost ====================

    #[Test]
    public function transcriptionCostPricesPerMinute(): void
    {
        self::assertEqualsWithDelta(0.006, (float)OpenAiPriceCatalog::transcriptionCost('whisper-1', 60.0), 1e-12);
        self::assertEqualsWithDelta(0.009, (float)OpenAiPriceCatalog::transcriptionCost('whisper-1', 90.0), 1e-12);
    }

    #[Test]
    public function transcriptionCostReturnsNullForUnknownModel(): void
    {
        self::assertNull(OpenAiPriceCatalog::transcriptionCost('unknown-stt', 60.0));
    }
}
