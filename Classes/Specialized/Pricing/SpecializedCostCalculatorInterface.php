<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Pricing;

use Netresearch\NrLlm\Domain\ValueObject\ImageTokenUsage;

/**
 * Estimates the cost of specialized (non-chat) AI service calls so the
 * usage tracker can record spend for image generation, text-to-speech
 * and transcription alongside the token-based chat costs.
 *
 * Contract: implementations must never guess — an unknown model, size
 * or quality yields 0.0 so the Analytics module reports "no price data"
 * rather than a fabricated number.
 */
interface SpecializedCostCalculatorInterface
{
    /**
     * Estimate the cost of an image generation call.
     *
     * Resolution order:
     *  1. token-based via the admin-curated tx_nrllm_model row `$modelUid`
     *     names, or -- with no uid -- the one row `$model` identifies (when
     *     usage tokens are present and the row has pricing);
     *  2. token-based via the static OpenAI catalog (gpt-image-*);
     *  3. per-image via the static catalog (model, quality, size);
     *  4. 0.0.
     *
     * @param string           $model      Provider-side model name (e.g. "gpt-image-2", "dall-e-3")
     * @param string           $quality    Requested quality tier ('' when not applicable)
     * @param string           $size       Requested size, e.g. "1024x1024"
     * @param int              $imageCount Number of images produced
     * @param ?ImageTokenUsage $tokens     What the response accounted for, null when it
     *                                     carried no `usage` object at all (dall-e-2/3)
     * @param int              $modelUid   The record the call was attributed to, 0 when none;
     *                                     the model name alone cannot identify a row when two
     *                                     providers offer the same model (#935)
     */
    public function estimateImageCost(
        string $model,
        string $quality,
        string $size,
        int $imageCount,
        ?ImageTokenUsage $tokens = null,
        int $modelUid = 0,
    ): float;

    /**
     * Estimate the cost of synthesizing `$characters` characters of speech.
     */
    public function estimateSpeechSynthesisCost(string $model, int $characters): float;

    /**
     * Estimate the cost of transcribing/translating `$audioSeconds` seconds
     * of audio. Pass 0.0 when the response did not expose a duration.
     */
    public function estimateTranscriptionCost(string $model, float $audioSeconds): float;
}
