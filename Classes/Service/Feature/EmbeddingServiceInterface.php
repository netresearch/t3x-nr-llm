<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;

/**
 * Public surface of the high-level embedding service.
 *
 * Consumers (controllers, search/RAG indexers, tests, downstream
 * extensions) should depend on this interface rather than the concrete
 * `EmbeddingService` so the implementation can be substituted without
 * inheritance.
 */
interface EmbeddingServiceInterface
{
    /**
     * Generate embedding vector for a single text.
     *
     * @return array<int, float> Embedding vector
     */
    public function embed(string $text, ?EmbeddingOptions $options = null): array;

    /**
     * Generate embedding with the full response object (vector + metadata).
     *
     * @throws InvalidArgumentException when `$text` is empty
     */
    public function embedFull(string $text, ?EmbeddingOptions $options = null): EmbeddingResponse;

    /**
     * Generate embeddings for a batch of texts in a single provider call.
     *
     * @param array<int, string> $texts
     *
     * @return array<int, array<int, float>> Array of embedding vectors, indexed parallel to `$texts`
     */
    public function embedBatch(array $texts, ?EmbeddingOptions $options = null): array;

    /**
     * Cosine similarity between two vectors. Result is in `[-1, 1]`.
     *
     * @param array<int, float> $vectorA
     * @param array<int, float> $vectorB
     *
     * @throws InvalidArgumentException when shapes mismatch
     */
    public function cosineSimilarity(array $vectorA, array $vectorB): float;

    /**
     * Top-K candidate vectors by cosine similarity to the query vector.
     *
     * @param array<int, float>             $queryVector
     * @param array<int, array<int, float>> $candidateVectors
     *
     * @return array<int, array{index: int, similarity: float}> Sorted by similarity descending
     */
    public function findMostSimilar(
        array $queryVector,
        array $candidateVectors,
        int $topK = 5,
    ): array;

    /**
     * Pairwise cosine similarities between every vector. Self-similarity is `1.0`.
     *
     * @param array<int, array<int, float>> $vectors
     *
     * @return array<int, array<int, float>>
     */
    public function pairwiseSimilarities(array $vectors): array;

    /**
     * Normalise a vector to unit length. A zero-magnitude vector is returned unchanged.
     *
     * @param array<int, float> $vector
     *
     * @return array<int, float>
     */
    public function normalize(array $vector): array;
}
