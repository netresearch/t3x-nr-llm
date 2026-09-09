<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Pricing;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\ValueObject\ImageTokenUsage;
use Netresearch\NrLlm\Domain\ValueObject\ProviderModelName;
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
        $repository->method('findOneByModelId')->willReturn(null);

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
        $repository->method('findOneByModelId')->willReturn($model);

        $calculator = new SpecializedCostCalculator($repository);

        // 1M input + 1M output at the row's pricing = $70, not the catalog's $35.
        $cost = $calculator->estimateImageCost('gpt-image-2', '', '1024x1024', 1, new ImageTokenUsage(1_000_000, 1_000_000));

        self::assertEqualsWithDelta(70.0, $cost, 1e-9);
    }

    /**
     * The row is found by its provider-side model name, not by its own
     * identifier.
     *
     * On a wizard-created row those differ: the identifier is `<slug>-<6 hex>`
     * while `model_id` carries the API string the caller passes. Looking up by
     * the identifier therefore matched nothing and curated pricing was
     * silently replaced by the catalog. The stub answers only the correct
     * lookup, so a call against the other one falls through to the catalog's
     * $35 instead of the row's $70.
     */
    #[Test]
    public function imageCostFindsTheRowByItsProviderSideModelName(): void
    {
        $model = new Model();
        $model->setIdentifier('gpt-image-2-a3f7c2');
        $model->setModelId('gpt-image-2');
        $model->setCostInputDollars(10.00);
        $model->setCostOutputDollars(60.00);

        $repository = self::createStub(ModelRepository::class);
        $repository->method('findOneByIdentifier')->willReturn(null);
        $repository->method('findOneByModelId')->willReturnCallback(
            static fn(ProviderModelName $modelName): ?Model => $modelName->value === 'gpt-image-2' ? $model : null,
        );

        $calculator = new SpecializedCostCalculator($repository);

        $cost = $calculator->estimateImageCost('gpt-image-2', '', '1024x1024', 1, new ImageTokenUsage(1_000_000, 1_000_000));

        self::assertEqualsWithDelta(70.0, $cost, 1e-9);
    }

    /**
     * A blank model name never reaches the repository.
     *
     * Since #893 the lookup takes a value object that refuses a blank name;
     * built inside the `try`, its error would be caught and reported as the
     * persistence failure the catch is for. The guard answers first, so the
     * repository is not asked at all.
     */
    /**
     * With a uid in hand the calculator prices the record the call was
     * attributed to, and never asks by name (#935).
     *
     * The name lookup cannot tell two providers' rows apart, so pricing from
     * it could charge one provider's negotiated rate to a call served by the
     * other. The usage intent already carries the record; using it makes
     * pricing and attribution the same decision rather than two.
     */
    #[Test]
    public function imageCostPricesTheRecordTheCallWasAttributedTo(): void
    {
        $attributed = new Model();
        $attributed->setModelId('gpt-image-2');
        $attributed->setCostInputDollars(10.00);
        $attributed->setCostOutputDollars(60.00);

        $repository = $this->createMock(ModelRepository::class);
        $repository->expects(self::never())->method('findOneByModelId');
        $repository->expects(self::once())->method('findOneByUid')->with(701)->willReturn($attributed);

        $calculator = new SpecializedCostCalculator($repository);

        $cost = $calculator->estimateImageCost('gpt-image-2', '', '1024x1024', 1, new ImageTokenUsage(1_000_000, 1_000_000), 701);

        self::assertEqualsWithDelta(70.0, $cost, 1e-9);
    }

    /**
     * Without a uid the name must identify exactly one row. Where it does
     * not, the repository answers null and the catalog prices the call --
     * an approximate number beats another provider's exact one.
     */
    #[Test]
    public function imageCostFallsBackToTheCatalogWhenTheNameIdentifiesNoSingleRow(): void
    {
        $repository = self::createStub(ModelRepository::class);
        $repository->method('findOneByModelId')->willReturn(null);

        $calculator = new SpecializedCostCalculator($repository);

        $cost = $calculator->estimateImageCost('gpt-image-2', '', '1024x1024', 1, new ImageTokenUsage(0, 1_000_000));

        self::assertEqualsWithDelta(30.0, $cost, 1e-9);
    }

    #[Test]
    public function imageCostDoesNotQueryForABlankModelName(): void
    {
        $repository = $this->createMock(ModelRepository::class);
        $repository->expects(self::never())->method('findOneByModelId');

        $calculator = new SpecializedCostCalculator($repository);

        self::assertSame(0.0, $calculator->estimateImageCost('   ', '', '1024x1024', 1, new ImageTokenUsage(1_000, 1_000)));
    }

    #[Test]
    public function imageCostIgnoresModelRowWithoutPricing(): void
    {
        $model = new Model(); // costInput = costOutput = 0 → hasPricing() false

        $repository = self::createStub(ModelRepository::class);
        $repository->method('findOneByModelId')->willReturn($model);

        $calculator = new SpecializedCostCalculator($repository);

        // Falls through to the catalog token prices: 1M out × $30/1M.
        $cost = $calculator->estimateImageCost('gpt-image-2', '', '1024x1024', 1, new ImageTokenUsage(0, 1_000_000));

        self::assertEqualsWithDelta(30.0, $cost, 1e-9);
    }

    #[Test]
    public function imageCostUsesCatalogTokenPricesWhenNoModelRowExists(): void
    {
        $cost = $this->calculatorWithoutModelRows()
            ->estimateImageCost('gpt-image-2', '', '1024x1024', 1, new ImageTokenUsage(50, 1000, 10));

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
        $repository->method('findOneByModelId')->willThrowException(new RuntimeException('no extbase'));

        $calculator = new SpecializedCostCalculator($repository);

        $cost = $calculator->estimateImageCost('gpt-image-2', '', '1024x1024', 1, new ImageTokenUsage(0, 1_000_000));

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
