<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Pricing;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculator;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[CoversClass(SpecializedCostCalculator::class)]
class SpecializedCostCalculatorTest extends AbstractUnitTestCase
{
    private function calculatorWithoutModelRows(): SpecializedCostCalculator
    {
        $repository = self::createStub(ModelRepository::class);
        $repository->method('findOneByIdentifier')->willReturn(null);

        return new SpecializedCostCalculator($repository);
    }

    #[Test]
    public function imageCostPrefersAdminCuratedModelRowPricingOverCatalog(): void
    {
        // An admin-curated tx_nrllm_model row with token pricing wins over
        // the static catalog (e.g. negotiated prices).
        $model = new Model();
        $model->setCostInputDollars(10.00);   // $10 / 1M input tokens
        $model->setCostOutputDollars(60.00);  // $60 / 1M output tokens

        $repository = self::createStub(ModelRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($model);

        $calculator = new SpecializedCostCalculator($repository);

        // 1M input + 1M output at the row's pricing = $70, not the catalog's $35.
        $cost = $calculator->estimateImageCost('gpt-image-2', '', '1024x1024', 1, 1_000_000, 1_000_000);

        self::assertEqualsWithDelta(70.0, $cost, 1e-9);
    }

    #[Test]
    public function imageCostIgnoresModelRowWithoutPricing(): void
    {
        $model = new Model(); // costInput = costOutput = 0 → hasPricing() false

        $repository = self::createStub(ModelRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($model);

        $calculator = new SpecializedCostCalculator($repository);

        // Falls through to the catalog token prices: 1M out × $30/1M.
        $cost = $calculator->estimateImageCost('gpt-image-2', '', '1024x1024', 1, 0, 1_000_000);

        self::assertEqualsWithDelta(30.0, $cost, 1e-9);
    }

    #[Test]
    public function imageCostUsesCatalogTokenPricesWhenNoModelRowExists(): void
    {
        $cost = $this->calculatorWithoutModelRows()
            ->estimateImageCost('gpt-image-2', '', '1024x1024', 1, 50, 1000, 10);

        self::assertEqualsWithDelta(0.03028, $cost, 1e-9);
    }

    #[Test]
    public function imageCostFallsBackToPerImagePriceWithoutTokens(): void
    {
        // dall-e-3 responses carry no usage object — per-image list price.
        $cost = $this->calculatorWithoutModelRows()
            ->estimateImageCost('dall-e-3', 'hd', '1024x1024', 2);

        self::assertEqualsWithDelta(0.160, $cost, 1e-9);
    }

    #[Test]
    public function imageCostIsZeroForUnknownModels(): void
    {
        // Never guess.
        $cost = $this->calculatorWithoutModelRows()
            ->estimateImageCost('some-unknown-model', 'standard', '1024x1024', 1);

        self::assertSame(0.0, $cost);
    }

    #[Test]
    public function imageCostSurvivesRepositoryFailures(): void
    {
        // Cost estimation must never break the generation call: persistence
        // failures fall back to the static catalog.
        $repository = self::createStub(ModelRepository::class);
        $repository->method('findOneByIdentifier')->willThrowException(new RuntimeException('no extbase'));

        $calculator = new SpecializedCostCalculator($repository);

        $cost = $calculator->estimateImageCost('gpt-image-2', '', '1024x1024', 1, 0, 1_000_000);

        self::assertEqualsWithDelta(30.0, $cost, 1e-9);
    }

    #[Test]
    public function speechSynthesisCostUsesCatalogAndNeverGuesses(): void
    {
        $calculator = $this->calculatorWithoutModelRows();

        self::assertEqualsWithDelta(0.000135, $calculator->estimateSpeechSynthesisCost('tts-1', 9), 1e-12);
        self::assertSame(0.0, $calculator->estimateSpeechSynthesisCost('unknown-tts', 9));
    }

    #[Test]
    public function transcriptionCostUsesCatalogAndNeverGuesses(): void
    {
        $calculator = $this->calculatorWithoutModelRows();

        self::assertEqualsWithDelta(0.009, $calculator->estimateTranscriptionCost('whisper-1', 90.0), 1e-12);
        self::assertSame(0.0, $calculator->estimateTranscriptionCost('whisper-1', 0.0));
        self::assertSame(0.0, $calculator->estimateTranscriptionCost('unknown-stt', 90.0));
    }
}
