<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;

/**
 * High-level service for text embeddings and similarity calculations.
 *
 * Provides text-to-vector conversion with caching and
 * similarity calculation utilities.
 */
class EmbeddingService
{
    private const int DEFAULT_CACHE_TTL = 86400; // 24 hours (embeddings are deterministic)

    public function __construct(
        private readonly LlmServiceManagerInterface $llmManager,
        private readonly CacheManagerInterface $cacheManager,
    ) {}

    /**
     * Generate embedding vector for text.
     *
     * Embeddings are deterministic and aggressively cached.
     *
     * @param string $text Text to embed
     *
     * @return array<int, float> Embedding vector
     */
    public function embed(string $text, ?EmbeddingOptions $options = null): array
    {
        $response = $this->embedFull($text, $options);
        return $response->getVector();
    }

    /**
     * Generate embedding with full response object.
     *
     * @param string $text Text to embed
     */
    public function embedFull(string $text, ?EmbeddingOptions $options = null): EmbeddingResponse
    {
        $options ??= new EmbeddingOptions();
        $optionsArray = $options->toArray();

        if (empty($text)) {
            throw new InvalidArgumentException('Text cannot be empty', 6048498820);
        }

        $model = is_string($optionsArray['model'] ?? null) ? $optionsArray['model'] : 'default';
        $provider = is_string($optionsArray['provider'] ?? null) ? $optionsArray['provider'] : 'openai';
        $cacheTtl = is_int($optionsArray['cache_ttl'] ?? null) ? $optionsArray['cache_ttl'] : self::DEFAULT_CACHE_TTL;

        // Check cache
        $cached = $this->cacheManager->getCachedEmbeddings($provider, $text, $optionsArray);
        if ($cached !== null) {
            /** @var array{embeddings: array<int, array<int, float>>, model: string, usage?: array{promptTokens?: int, totalTokens?: int}} $cached */
            $usageData = $cached['usage'] ?? [];
            return new EmbeddingResponse(
                embeddings: $cached['embeddings'],
                model: $cached['model'],
                usage: new UsageStatistics(
                    promptTokens: $usageData['promptTokens'] ?? 0,
                    completionTokens: 0,
                    totalTokens: $usageData['totalTokens'] ?? 0,
                ),
                provider: $provider,
            );
        }

        // Execute embedding request
        $response = $this->llmManager->embed($text, $options);

        // Cache the result
        $this->cacheManager->cacheEmbeddings(
            $provider,
            $text,
            $optionsArray,
            [
                'embeddings' => $response->embeddings,
                'model' => $response->model,
                'usage' => [
                    'promptTokens' => $response->usage->promptTokens,
                    'totalTokens' => $response->usage->totalTokens,
                ],
            ],
            $cacheTtl,
        );

        return $response;
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

        $options ??= new EmbeddingOptions();
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
            $similarities[$i] = [];
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    $similarities[$i][$j] = 1.0;
                } else {
                    $similarities[$i][$j] = $this->cosineSimilarity(
                        $vectors[$i],
                        $vectors[$j],
                    );
                }
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

}
