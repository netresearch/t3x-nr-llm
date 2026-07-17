<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

/**
 * Static catalog of vector dimensionality for well-known embedding models.
 *
 * ADR-055 made `tx_nrllm_model.dimensions` descriptive metadata
 * (0 = unknown), but nothing seeded it: every record started at 0, so
 * consumers validating a persisted vector index against the configured
 * model fell back to a paid live calibration probe. This catalog seeds
 * the column for the embedding models this extension already references
 * (provider default-model constants, setup wizard discovery) — on record
 * creation via the setup wizard and on existing rows via
 * EmbeddingModelDimensionsUpdateWizard.
 *
 * Maintenance contract mirrors OpenAiPriceCatalog:
 *  - every entry documents its source and verification date;
 *  - unknown model ids return 0 ("unknown") — callers keep the column
 *    default rather than guessing.
 */
final class EmbeddingModelDimensions
{
    /**
     * Default output dimensionality per provider model id.
     *
     * Sources (verified 2026-07-16):
     *  - text-embedding-3-small 1536, text-embedding-3-large 3072:
     *    https://platform.openai.com/docs/guides/embeddings
     *    (`openai/…` is the OpenRouter route to the same model,
     *    OpenRouterProvider::DEFAULT_EMBEDDING_MODEL)
     *  - mistral-embed 1024: https://docs.mistral.ai/capabilities/embeddings/
     *  - nomic-embed-text 768: https://ollama.com/library/nomic-embed-text
     *  - gemini-embedding-2 3072 (Matryoshka default):
     *    https://deepmind.google/models/gemini/embedding/
     *
     * text-embedding-ada-002 (1536) is deliberately absent:
     * LlmServiceManager forwards a non-zero record value as the
     * `dimensions` request parameter, which the OpenAI API rejects for
     * ada-002 (only the v3 models accept it).
     *
     * @var array<string, int>
     */
    private const DIMENSIONS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'openai/text-embedding-3-small' => 1536,
        'mistral-embed' => 1024,
        'nomic-embed-text' => 768,
        'gemini-embedding-2' => 3072,
    ];

    /**
     * Static catalog — not meant to be instantiated.
     */
    private function __construct() {}

    /**
     * Dimensionality for the given provider model id, 0 when unknown.
     *
     * Ollama model ids carry a tag suffix (`nomic-embed-text:latest`);
     * the lookup falls back to the bare name before the first colon.
     */
    public static function forModelId(string $modelId): int
    {
        $dimensions = self::DIMENSIONS[$modelId] ?? null;
        if ($dimensions !== null) {
            return $dimensions;
        }

        $colonPosition = strpos($modelId, ':');
        if ($colonPosition === false) {
            return 0;
        }

        return self::DIMENSIONS[substr($modelId, 0, $colonPosition)] ?? 0;
    }
}
