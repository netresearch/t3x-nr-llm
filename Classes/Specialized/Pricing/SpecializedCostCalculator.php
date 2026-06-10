<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Pricing;

use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Throwable;

/**
 * Default cost estimator for specialized service calls.
 *
 * Token-based pricing prefers an admin-curated tx_nrllm_model row
 * (looked up by model identifier, reusing Model::estimateCost()) so an
 * instance can override list prices with negotiated ones; otherwise the
 * static OpenAiPriceCatalog applies. Unknown models cost 0.0 — never
 * guessed (see the interface contract).
 *
 * The repository lookup is fail-soft: tracking a cost must never break
 * the actual generation call, so any persistence-layer failure falls
 * back to the static catalog.
 */
final readonly class SpecializedCostCalculator implements SpecializedCostCalculatorInterface
{
    public function __construct(
        private ModelRepository $modelRepository,
    ) {}

    public function estimateImageCost(
        string $model,
        string $quality,
        string $size,
        int $imageCount,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $imageInputTokens = 0,
    ): float {
        if ($inputTokens > 0 || $outputTokens > 0) {
            $dbCost = $this->estimateFromModelRow($model, $inputTokens, $outputTokens);
            if ($dbCost !== null) {
                return $dbCost;
            }

            $catalogCost = OpenAiPriceCatalog::imageTokenCost($model, $inputTokens, $outputTokens, $imageInputTokens);
            if ($catalogCost !== null) {
                return $catalogCost;
            }
        }

        $perImage = OpenAiPriceCatalog::imagePrice($model, $quality, $size);
        if ($perImage !== null && $imageCount > 0) {
            return $perImage * $imageCount;
        }

        return 0.0;
    }

    public function estimateSpeechSynthesisCost(string $model, int $characters): float
    {
        return OpenAiPriceCatalog::speechSynthesisCost($model, $characters) ?? 0.0;
    }

    public function estimateTranscriptionCost(string $model, float $audioSeconds): float
    {
        if ($audioSeconds <= 0.0) {
            return 0.0;
        }

        return OpenAiPriceCatalog::transcriptionCost($model, $audioSeconds) ?? 0.0;
    }

    /**
     * Token-based cost from an admin-curated model row, when one exists
     * for this identifier and carries pricing. Fail-soft on persistence
     * errors — cost estimation must never break the service call.
     */
    private function estimateFromModelRow(string $model, int $inputTokens, int $outputTokens): ?float
    {
        try {
            $modelRow = $this->modelRepository->findOneByIdentifier($model);
            if ($modelRow !== null && $modelRow->hasPricing()) {
                return $modelRow->estimateCost($inputTokens, $outputTokens);
            }
        } catch (Throwable) {
            // Extbase persistence may be unavailable in edge contexts
            // (early CLI bootstrap); fall back to the static catalog.
        }

        return null;
    }
}
