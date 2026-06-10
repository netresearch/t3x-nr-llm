<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Pricing;

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
     *  1. token-based via an admin-curated tx_nrllm_model row matching
     *     `$model` (when usage tokens are present and the row has pricing);
     *  2. token-based via the static OpenAI catalog (gpt-image-*);
     *  3. per-image via the static catalog (model, quality, size);
     *  4. 0.0.
     *
     * @param string $model            Model identifier (e.g. "gpt-image-2", "dall-e-3")
     * @param string $quality          Requested quality tier ('' when not applicable)
     * @param string $size             Requested size, e.g. "1024x1024"
     * @param int    $imageCount       Number of images produced
     * @param int    $inputTokens      `usage.input_tokens` from the response, 0 when absent
     * @param int    $outputTokens     `usage.output_tokens` from the response, 0 when absent
     * @param int    $imageInputTokens `usage.input_tokens_details.image_tokens`, 0 when absent
     */
    public function estimateImageCost(
        string $model,
        string $quality,
        string $size,
        int $imageCount,
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $imageInputTokens = 0,
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
