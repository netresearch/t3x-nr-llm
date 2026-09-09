<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Pricing;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\ValueObject\ImageTokenUsage;
use Netresearch\NrLlm\Domain\ValueObject\ProviderModelName;
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
        ?ImageTokenUsage $tokens = null,
        int $modelUid = 0,
    ): float {
        $tokenBased = null;
        if ($tokens instanceof ImageTokenUsage && !$tokens->isEmpty()) {
            $tokenBased = $this->estimateFromModelRow($model, $tokens, $modelUid)
                ?? OpenAiPriceCatalog::imageTokenCost(
                    $model,
                    $tokens->inputTokens,
                    $tokens->outputTokens,
                    $tokens->imageInputTokens,
                );
        }

        $perImage = OpenAiPriceCatalog::imagePrice($model, $quality, $size);
        $perImageBased = ($perImage !== null && $imageCount > 0) ? $perImage * $imageCount : null;

        return $tokenBased ?? $perImageBased ?? 0.0;
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
     * for this model and carries pricing. Fail-soft on persistence
     * errors — cost estimation must never break the service call.
     *
     * Looked up by the provider-side model name (`model_id`), not by the row
     * identifier: the value arriving here is the API model string, the same
     * one the static catalog is keyed by. The wizard mints a row identifier
     * as `<slug>-<6 hex>` ({@see \Netresearch\NrLlm\Controller\Backend\SetupWizardController}),
     * so on a wizard-created row the two never match and the lookup found
     * nothing -- curated pricing was silently ignored in favour of the
     * catalog, and a model the catalog does not know priced at zero.
     */
    private function estimateFromModelRow(string $model, ImageTokenUsage $tokens, int $modelUid): ?float
    {
        // Built before the try, and blank answered here rather than by the
        // value object. Inside, `catch (Throwable)` would absorb the blank
        // error and report it as the persistence failure the comment below
        // describes -- a name nobody can price is not an Extbase problem.
        if ($modelUid <= 0 && trim($model) === '') {
            return null;
        }

        try {
            // The uid the usage intent carries names the record this call is
            // attributed to, so pricing and attribution cannot disagree (#935).
            // Falling back to the name is for callers that have no uid; it
            // yields a row only when the name identifies exactly one.
            $modelRow = $modelUid > 0
                ? $this->modelRepository->findByUid($modelUid)
                : $this->modelRepository->findOneByModelId(new ProviderModelName($model));
            if ($modelRow instanceof Model && $modelRow->hasPricing()) {
                return $modelRow->estimateCost($tokens->inputTokens, $tokens->outputTokens);
            }
        } catch (Throwable) {
            // Extbase persistence may be unavailable in edge contexts
            // (early CLI bootstrap); fall back to the static catalog.
        }

        return null;
    }
}
