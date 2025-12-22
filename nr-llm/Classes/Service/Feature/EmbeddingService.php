<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

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
     * @param array $options Configuration options:
     *   - model: string Provider-specific embedding model
     *   - dimensions: int Output dimensions (if supported by provider)
     *   - encoding_format: string ('float'|'base64')
     *   - cache_ttl: int Cache duration in seconds, default 86400
     * @return array Embedding vector
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
     * @param array $options Configuration options (same as embed())
     * @return EmbeddingResponse
     */
    public function embedFull(string $text, array $options = []): EmbeddingResponse
    {
        if (empty($text)) {
            throw new InvalidArgumentException('Text cannot be empty');
        }

        $model = $options['model'] ?? 'default';
        $cacheKey = $this->getCacheKey($text, $model);
        $cacheTtl = $options['cache_ttl'] ?? self::DEFAULT_CACHE_TTL;

        // Check cache
        $cached = $this->cacheManager->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Execute embedding request
        $requestOptions = [
            'input' => $text,
            'encoding_format' => $options['encoding_format'] ?? 'float',
        ];

        if (isset($options['model'])) {
            $requestOptions['model'] = $options['model'];
        }

        if (isset($options['dimensions'])) {
            $requestOptions['dimensions'] = $options['dimensions'];
        }

        $response = $this->llmManager->embed($requestOptions);

        $embeddingResponse = new EmbeddingResponse(
            vector: $response->getVector(),
            dimensions: count($response->getVector()),
            usage: UsageStatistics::fromTokens(
                promptTokens: $response->getUsage()['prompt_tokens'] ?? 0,
                completionTokens: 0,
                estimatedCost: $response->getUsage()['estimated_cost'] ?? null
            ),
            model: $response->getModel()
        );

        // Cache the result
        $this->cacheManager->set($cacheKey, $embeddingResponse, $cacheTtl);

        return $embeddingResponse;
    }

    /**
     * Generate embeddings for multiple texts efficiently
     *
     * @param array $texts Array of texts to embed
     * @param array $options Configuration options (same as embed())
     * @return array Array of embedding vectors
     */
    public function embedBatch(array $texts, array $options = []): array
    {
        if (empty($texts)) {
            return [];
        }

        $model = $options['model'] ?? 'default';
        $batchSize = $options['batch_size'] ?? 100;

        // Split into chunks if needed
        $chunks = array_chunk($texts, $batchSize);
        $allVectors = [];

        foreach ($chunks as $chunk) {
            // Check cache for each text
            $uncachedTexts = [];
            $uncachedIndices = [];
            $cachedVectors = [];

            foreach ($chunk as $index => $text) {
                $cacheKey = $this->getCacheKey($text, $model);
                $cached = $this->cacheManager->get($cacheKey);

                if ($cached !== null) {
                    $cachedVectors[$index] = $cached->getVector();
                } else {
                    $uncachedTexts[] = $text;
                    $uncachedIndices[] = $index;
                }
            }

            // Process uncached texts
            if (!empty($uncachedTexts)) {
                $requestOptions = [
                    'input' => $uncachedTexts,
                    'encoding_format' => $options['encoding_format'] ?? 'float',
                ];

                if (isset($options['model'])) {
                    $requestOptions['model'] = $options['model'];
                }

                if (isset($options['dimensions'])) {
                    $requestOptions['dimensions'] = $options['dimensions'];
                }

                $response = $this->llmManager->embedBatch($requestOptions);
                $vectors = $response->getVectors();

                // Cache each result
                $cacheTtl = $options['cache_ttl'] ?? self::DEFAULT_CACHE_TTL;
                foreach ($uncachedTexts as $idx => $text) {
                    $cacheKey = $this->getCacheKey($text, $model);
                    $vector = $vectors[$idx];

                    $embeddingResponse = new EmbeddingResponse(
                        vector: $vector,
                        dimensions: count($vector),
                        usage: new UsageStatistics(0, 0, 0),
                        model: $response->getModel()
                    );

                    $this->cacheManager->set($cacheKey, $embeddingResponse, $cacheTtl);

                    $cachedVectors[$uncachedIndices[$idx]] = $vector;
                }
            }

            // Reconstruct in original order
            ksort($cachedVectors);
            $allVectors = array_merge($allVectors, $cachedVectors);
        }

        return $allVectors;
    }

    /**
     * Calculate cosine similarity between two vectors
     *
     * Returns a value between -1 and 1, where:
     * - 1 means identical vectors
     * - 0 means orthogonal (no similarity)
     * - -1 means opposite vectors
     *
     * @param array $vectorA First vector
     * @param array $vectorB Second vector
     * @return float Similarity score (0.0-1.0 for normalized vectors)
     * @throws InvalidArgumentException
     */
    public function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        if (count($vectorA) !== count($vectorB)) {
            throw new InvalidArgumentException(
                'Vectors must have the same dimensions'
            );
        }

        if (empty($vectorA)) {
            throw new InvalidArgumentException('Vectors cannot be empty');
        }

        $dotProduct = 0;
        $magnitudeA = 0;
        $magnitudeB = 0;

        for ($i = 0; $i < count($vectorA); $i++) {
            $dotProduct += $vectorA[$i] * $vectorB[$i];
            $magnitudeA += $vectorA[$i] * $vectorA[$i];
            $magnitudeB += $vectorB[$i] * $vectorB[$i];
        }

        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);

        if ($magnitudeA == 0 || $magnitudeB == 0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }

    /**
     * Find most similar vectors to query vector
     *
     * @param array $queryVector Query vector
     * @param array $candidateVectors Array of candidate vectors
     * @param int $topK Number of results to return
     * @return array Array of ['index' => int, 'similarity' => float] sorted by similarity
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
        usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        // Return top K results
        return array_slice($similarities, 0, $topK);
    }

    /**
     * Calculate pairwise similarities between all vectors
     *
     * @param array $vectors Array of vectors
     * @return array 2D array of similarity scores
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
     * @param array $vector
     * @return array Normalized vector
     */
    public function normalize(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $vector)));

        if ($magnitude == 0) {
            return $vector;
        }

        return array_map(fn($x) => $x / $magnitude, $vector);
    }

    /**
     * Generate cache key for embedding
     *
     * @param string $text
     * @param string $model
     * @return string
     */
    private function getCacheKey(string $text, string $model): string
    {
        return 'embedding_' . hash('sha256', $model . '|' . $text);
    }
}
