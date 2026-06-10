<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Pricing;

/**
 * Static USD price catalog for the OpenAI specialized SKUs the
 * specialized services dispatch (images, text-to-speech, speech-to-text).
 *
 * Why a static catalog: the tx_nrllm_model table only carries token
 * pricing for admin-curated chat/embedding models; the specialized
 * services (DALL-E / gpt-image-*, tts-*, whisper-1) usually have no
 * model row, so without this catalog their spend would be recorded as
 * zero and the Analytics module / MonthlyCost widget / BudgetService
 * would systematically under-report total cost.
 *
 * Maintenance contract:
 *  - every constant documents its source URL and verification date;
 *  - unknown models/sizes/qualities return null — callers record a cost
 *    of 0 rather than guessing;
 *  - when OpenAI changes list prices, update the tables here and the
 *    verification dates in one commit.
 *
 * All prices verified 2026-06-10 against
 * https://platform.openai.com/docs/pricing (mirror:
 * https://developers.openai.com/api/docs/pricing) and
 * https://openai.com/api/pricing/.
 */
final class OpenAiPriceCatalog
{
    /**
     * Token prices for the gpt-image-* family in USD per 1M tokens.
     * gpt-image-* bills by tokens (the response carries a `usage`
     * object); the per-image table below is only a fallback estimate.
     *
     * Keys: model (longest prefix wins, so dated point releases like
     * "gpt-image-2-2026-04-21" resolve to their base model).
     * Values: [text input, image input, image output] per 1M tokens.
     *
     * Source: https://developers.openai.com/api/docs/pricing (2026-06-10):
     *  - gpt-image-2:      text in $5.00, image in $8.00, image out $30.00
     *  - gpt-image-1.5:    text in $5.00, image in $8.00, image out $32.00
     *  - gpt-image-1-mini: text in $2.00, image in $2.50, image out  $8.00
     *  - gpt-image-1:      text in $5.00, image in $10.00, image out $40.00
     *    (launch list price, https://openai.com/api/pricing/, 2025)
     *
     * @var array<string, array{textInput: float, imageInput: float, output: float}>
     */
    private const IMAGE_TOKEN_PRICES_PER_MILLION = [
        'gpt-image-2'      => ['textInput' => 5.00, 'imageInput' => 8.00, 'output' => 30.00],
        'gpt-image-1.5'    => ['textInput' => 5.00, 'imageInput' => 8.00, 'output' => 32.00],
        'gpt-image-1-mini' => ['textInput' => 2.00, 'imageInput' => 2.50, 'output' => 8.00],
        'gpt-image-1'      => ['textInput' => 5.00, 'imageInput' => 10.00, 'output' => 40.00],
    ];

    /**
     * Per-image USD prices, keyed model => quality => size.
     *
     * DALL-E list prices (stable since 2023, verified 2026-06-10 against
     * https://openai.com/api/pricing/):
     *  - dall-e-3 standard: 1024x1024 $0.040; 1792x1024 / 1024x1792 $0.080
     *  - dall-e-3 hd:       1024x1024 $0.080; 1792x1024 / 1024x1792 $0.120
     *  - dall-e-2:          1024x1024 $0.020; 512x512 $0.018; 256x256 $0.016
     *    (dall-e-2 has a single quality tier, keyed 'standard')
     *
     * gpt-image per-image values are OpenAI's own calculator estimates
     * (token billing is authoritative — these apply only when a response
     * unexpectedly carries no usage object):
     *  - gpt-image-1 (https://platform.openai.com/docs/guides/image-generation,
     *    2025): low $0.011/$0.016/$0.016, medium $0.042/$0.063/$0.063,
     *    high $0.167/$0.25/$0.25 for 1024x1024 / 1024x1536 / 1536x1024
     *  - gpt-image-2 (OpenAI image-generation guide calculator, 2026-06-10):
     *    1024x1024 low $0.006, medium $0.053, high $0.211
     *
     * @var array<string, array<string, array<string, float>>>
     */
    private const IMAGE_PRICES = [
        'dall-e-3' => [
            'standard' => ['1024x1024' => 0.040, '1792x1024' => 0.080, '1024x1792' => 0.080],
            'hd'       => ['1024x1024' => 0.080, '1792x1024' => 0.120, '1024x1792' => 0.120],
        ],
        'dall-e-2' => [
            'standard' => ['1024x1024' => 0.020, '512x512' => 0.018, '256x256' => 0.016],
        ],
        'gpt-image-2' => [
            'low'    => ['1024x1024' => 0.006],
            'medium' => ['1024x1024' => 0.053],
            'high'   => ['1024x1024' => 0.211],
        ],
        'gpt-image-1' => [
            'low'    => ['1024x1024' => 0.011, '1024x1536' => 0.016, '1536x1024' => 0.016],
            'medium' => ['1024x1024' => 0.042, '1024x1536' => 0.063, '1536x1024' => 0.063],
            'high'   => ['1024x1024' => 0.167, '1024x1536' => 0.250, '1536x1024' => 0.250],
        ],
    ];

    /**
     * Text-to-speech prices in USD per 1M input characters.
     *
     * Source: https://openai.com/api/pricing/ (verified 2026-06-10):
     *  - tts-1:    $15.00 per 1M characters
     *  - tts-1-hd: $30.00 per 1M characters
     *
     * @var array<string, float>
     */
    private const TTS_PRICES_PER_MILLION_CHARS = [
        'tts-1'    => 15.00,
        'tts-1-hd' => 30.00,
    ];

    /**
     * Speech-to-text prices in USD per minute of audio.
     *
     * Source: https://openai.com/api/pricing/ (verified 2026-06-10):
     *  - whisper-1: $0.006 per minute
     *
     * @var array<string, float>
     */
    private const TRANSCRIPTION_PRICES_PER_MINUTE = [
        'whisper-1' => 0.006,
    ];

    private function __construct()
    {
        // Static catalog — not instantiable.
    }

    /**
     * Token-based cost for a gpt-image-* call. Input tokens are billed at
     * the text-input rate unless the caller splits them via
     * `$imageInputTokens` (the API's `usage.input_tokens_details`).
     * Returns null for models without a catalog entry — never guesses.
     */
    public static function imageTokenCost(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $imageInputTokens = 0,
    ): ?float {
        $prices = self::matchByPrefix(self::IMAGE_TOKEN_PRICES_PER_MILLION, $model);
        if ($prices === null) {
            return null;
        }

        $textInputTokens = max(0, $inputTokens - $imageInputTokens);

        return ($textInputTokens / 1_000_000) * $prices['textInput']
            + ($imageInputTokens / 1_000_000) * $prices['imageInput']
            + ($outputTokens / 1_000_000) * $prices['output'];
    }

    /**
     * Per-image price for an exact (model, quality, size) combination.
     * Returns null when any part is unknown (e.g. arbitrary gpt-image-2
     * WxH sizes, or unknown quality tiers) — never guesses.
     */
    public static function imagePrice(string $model, string $quality, string $size): ?float
    {
        $qualities = self::matchByPrefix(self::IMAGE_PRICES, $model);

        return $qualities[$quality][$size] ?? null;
    }

    /**
     * Cost for synthesizing `$characters` characters with a TTS model.
     * Returns null for unknown models — never guesses.
     */
    public static function speechSynthesisCost(string $model, int $characters): ?float
    {
        $pricePerMillion = self::TTS_PRICES_PER_MILLION_CHARS[$model] ?? null;
        if ($pricePerMillion === null) {
            return null;
        }

        return ($characters / 1_000_000) * $pricePerMillion;
    }

    /**
     * Cost for transcribing/translating `$audioSeconds` seconds of audio.
     * Returns null for unknown models — never guesses.
     */
    public static function transcriptionCost(string $model, float $audioSeconds): ?float
    {
        $pricePerMinute = self::TRANSCRIPTION_PRICES_PER_MINUTE[$model] ?? null;
        if ($pricePerMinute === null) {
            return null;
        }

        return ($audioSeconds / 60) * $pricePerMinute;
    }

    /**
     * Resolve a model id against a price table by longest matching prefix,
     * so dated point releases ("gpt-image-2-2026-04-21") inherit their base
     * model's pricing while "gpt-image-1-mini" still wins over "gpt-image-1".
     *
     * @template T
     *
     * @param array<string, T> $table
     *
     * @return T|null
     */
    private static function matchByPrefix(array $table, string $model): mixed
    {
        if (isset($table[$model])) {
            return $table[$model];
        }

        $bestKey = null;
        foreach (array_keys($table) as $key) {
            if (str_starts_with($model, $key . '-') && ($bestKey === null || strlen($key) > strlen($bestKey))) {
                $bestKey = $key;
            }
        }

        return $bestKey === null ? null : $table[$bestKey];
    }
}
