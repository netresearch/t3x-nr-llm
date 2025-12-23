<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Service\LlmServiceManager;

/**
 * High-level service for text embeddings and similarity calculations
 *
 * Provides text-to-vector conversion with caching and
 * similarity calculation utilities.
 */
class EmbeddingService
{
    private const DEFAULT_CACHE_TTL = 86400; // 24 hours (embeddings are deterministic)

    public function __construct(
        private readonly LlmServiceManager $llmManager,
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * Generate embedding vector for text
     *
     * Embeddings are deterministic and aggressively cached.
     *
     * @param string $text Text to embed
     * @param array<string, mixed> $options Configuration options:
     *   - model: string Provider-specific embedding model
     *   - dimensions: int Output dimensions (if supported by provider)
     *   - cache_ttl: int Cache duration in seconds, default 86400
     *   - provider: string Specific provider to use
     * @return array<int, float> Embedding vector
     */
    public function embed(string $text, array $options = []): array
    {
        $response = $this->embedFull($text, $options);
        return $response->getVector();
    }

    /**
     * Generate embedding with full response object
     *
     * @param string $text Text to embed
     * @param array<string, mixed> $options Configuration options (same as embed())
     */
    public function embedFull(string $text, array $options = []): EmbeddingResponse
    {
        if (empty($text)) {
            throw new InvalidArgumentException('Text cannot be empty');
        }

        $model = $options['model'] ?? 'default';
        $provider = $options['provider'] ?? 'openai';
        $cacheKey = $this->getCacheKey($text, $model, $provider);
        $cacheTtl = $options['cache_ttl'] ?? self::DEFAULT_CACHE_TTL;

        // Check cache
        $cached = $this->cacheManager->getCachedEmbeddings($provider, $text, $options);
        if ($cached !== null) {
            return new EmbeddingResponse(
                embeddings: $cached['embeddings'],
                model: $cached['model'],
                usage: new \Netresearch\NrLlm\Domain\Model\UsageStatistics(
                    promptTokens: $cached['usage']['promptTokens'] ?? 0,
                    completionTokens: 0,
                    totalTokens: $cached['usage']['totalTokens'] ?? 0
                ),
                provider: $provider
            );
        }

        // Execute embedding request
        $response = $this->llmManager->embed($text, $options);

        // Cache the result
        $this->cacheManager->cacheEmbeddings(
            $provider,
            $text,
            $options,
            [
                'embeddings' => $response->embeddings,
                'model' => $response->model,
                'usage' => [
                    'promptTokens' => $response->usage->promptTokens,
                    'totalTokens' => $response->usage->totalTokens,
                ],
            ],
            $cacheTtl
        );

        return $response;
    }

    /**
     * Generate embeddings for multiple texts efficiently
     *
     * @param array<int, string> $texts Array of texts to embed
     * @param array<string, mixed> $options Configuration options (same as embed())
     * @return array<int, array<int, float>> Array of embedding vectors
     */
    public function embedBatch(array $texts, array $options = []): array
    {
        if (empty($texts)) {
            return [];
        }

        $response = $this->llmManager->embed($texts, $options);
        return $response->embeddings;
    }

    /**
     * Calculate cosine similarity between two vectors
     *
     * Returns a value between -1 and 1, where:
     * - 1 means identical vectors
     * - 0 means orthogonal (no similarity)
     * - -1 means opposite vectors
     *
     * @param array<int, float> $vectorA First vector
     * @param array<int, float> $vectorB Second vector
     * @return float Similarity score
     * @throws InvalidArgumentException
     */
    public function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        return EmbeddingResponse::cosineSimilarity($vectorA, $vectorB);
    }

    /**
     * Find most similar vectors to query vector
     *
     * @param array<int, float> $queryVector Query vector
     * @param array<int, array<int, float>> $candidateVectors Array of candidate vectors
     * @param int $topK Number of results to return
     * @return array<int, array{index: int, similarity: float}> Results sorted by similarity
     */
    public function findMostSimilar(
        array $queryVector,
        array $candidateVectors,
        int $topK = 5
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
     * Calculate pairwise similarities between all vectors
     *
     * @param array<int, array<int, float>> $vectors Array of vectors
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
                        $vectors[$j]
                    );
                }
            }
        }

        return $similarities;
    }

    /**
     * Normalize vector to unit length
     *
     * @param array<int, float> $vector
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

    /**
     * Generate cache key for embedding
     */
    private function getCacheKey(string $text, string $model, string $provider): string
    {
        return 'embedding_' . hash('sha256', $provider . '|' . $model . '|' . $text);
    }
}
