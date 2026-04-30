<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Budget\AutoPopulatesBeUserUidTrait;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;

/**
 * High-level service for text embeddings and similarity calculations.
 *
 * Caching is handled transparently by `CacheMiddleware` inside
 * `LlmServiceManager::embed()` — this service only owns the vector-math
 * utilities (cosine similarity, normalisation, top-k) and input validation.
 *
 * Budget pre-flight (REC #4 slice 15b): mirrors the wiring on
 * `CompletionService` (slice 15a) — when a caller does not set an
 * explicit `beUserUid` on the options, the service consults
 * `BackendUserContextResolverInterface` and populates the option so
 * `BudgetMiddleware` can enforce per-user limits without every caller
 * having to remember the wiring. The resolver is optional so unit
 * tests omit it; production DI always autowires it via
 * `Configuration/Services.yaml`.
 */
final readonly class EmbeddingService implements EmbeddingServiceInterface
{
    use AutoPopulatesBeUserUidTrait;

    public function __construct(
        private LlmServiceManagerInterface $llmManager,
        private ?BackendUserContextResolverInterface $beUserContextResolver = null,
    ) {}

    /**
     * Generate embedding vector for text.
     *
     * @param string $text Text to embed
     *
     * @return array<int, float> Embedding vector
     */
    public function embed(string $text, ?EmbeddingOptions $options = null): array
    {
        return $this->embedFull($text, $options)->getVector();
    }

    /**
     * Generate embedding with full response object.
     *
     * @param string $text Text to embed
     */
    public function embedFull(string $text, ?EmbeddingOptions $options = null): EmbeddingResponse
    {
        if ($text === '') {
            throw new InvalidArgumentException('Text cannot be empty', 6048498820);
        }

        return $this->llmManager->embed($text, $this->autoPopulateBeUserUid($options ?? new EmbeddingOptions()));
    }

    /**
     * Generate embeddings for multiple texts efficiently.
     *
     * @param array<int, string> $texts Array of texts to embed
     *
     * @return array<int, array<int, float>> Array of embedding vectors
     */
    public function embedBatch(array $texts, ?EmbeddingOptions $options = null): array
    {
        if (empty($texts)) {
            return [];
        }

        $options = $this->autoPopulateBeUserUid($options ?? new EmbeddingOptions());
        $response = $this->llmManager->embed($texts, $options);
        return $response->embeddings;
    }

    /**
     * Calculate cosine similarity between two vectors.
     *
     * Returns a value between -1 and 1, where:
     * - 1 means identical vectors
     * - 0 means orthogonal (no similarity)
     * - -1 means opposite vectors
     *
     * @param array<int, float> $vectorA First vector
     * @param array<int, float> $vectorB Second vector
     *
     * @throws InvalidArgumentException
     *
     * @return float Similarity score
     */
    public function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        return EmbeddingResponse::cosineSimilarity($vectorA, $vectorB);
    }

    /**
     * Find most similar vectors to query vector.
     *
     * @param array<int, float>             $queryVector      Query vector
     * @param array<int, array<int, float>> $candidateVectors Array of candidate vectors
     * @param int                           $topK             Number of results to return
     *
     * @return array<int, array{index: int, similarity: float}> Results sorted by similarity
     */
    public function findMostSimilar(
        array $queryVector,
        array $candidateVectors,
        int $topK = 5,
    ): array {
        if (empty($candidateVectors)) {
            return [];
        }

        $similarities = [];

        foreach ($candidateVectors as $index => $candidateVector) {
            $similarity = $this->cosineSimilarity($queryVector, $candidateVector);
            $similarities[] = [
                'index' => $index,
                'similarity' => $similarity,
            ];
        }

        // Sort by similarity descending
        usort($similarities, static fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        // Return top K results
        return array_slice($similarities, 0, $topK);
    }

    /**
     * Calculate pairwise similarities between all vectors.
     *
     * @param array<int, array<int, float>> $vectors Array of vectors
     *
     * @return array<int, array<int, float>> 2D array of similarity scores
     */
    public function pairwiseSimilarities(array $vectors): array
    {
        $count = count($vectors);
        $similarities = [];

        for ($i = 0; $i < $count; $i++) {
            $similarities[$i][$i] = 1.0;
            for ($j = $i + 1; $j < $count; $j++) {
                $score = $this->cosineSimilarity($vectors[$i], $vectors[$j]);
                $similarities[$i][$j] = $score;
                $similarities[$j][$i] = $score;
            }
        }

        return $similarities;
    }

    /**
     * Normalize vector to unit length.
     *
     * @param array<int, float> $vector
     *
     * @return array<int, float> Normalized vector
     */
    public function normalize(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(static fn($x) => $x * $x, $vector)));

        if ($magnitude == 0) {
            return $vector;
        }

        return array_map(static fn($x) => $x / $magnitude, $vector);
    }

    // `autoPopulateBeUserUid()` is provided by `AutoPopulatesBeUserUidTrait`.
}
