<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * Response object for embedding requests.
 */
final readonly class EmbeddingResponse
{
    /**
     * @param array<int, array<int, float>> $embeddings Array of embedding vectors
     * @param string                        $model      Model used for embeddings
     * @param UsageStatistics               $usage      Token usage statistics
     * @param string                        $provider   Provider identifier
     */
    public function __construct(
        public array $embeddings,
        public string $model,
        public UsageStatistics $usage,
        public string $provider = '',
    ) {}

    /**
     * Serialize to an array shape suitable for cache storage.
     *
     * Paired with `fromArray()` so `CacheMiddleware` (which stores
     * `array<string, mixed>`) can round-trip an `EmbeddingResponse`
     * through a TYPO3 cache frontend without a per-type codec.
     *
     * @return array{embeddings: array<int, array<int, float>>, model: string, usage: array{promptTokens: int, completionTokens: int, totalTokens: int, estimatedCost: ?float}, provider: string}
     */
    public function toArray(): array
    {
        return [
            'embeddings' => $this->embeddings,
            'model'      => $this->model,
            'usage'      => $this->usage->toArray(),
            'provider'   => $this->provider,
        ];
    }

    /**
     * Restore from a previously serialized array shape. Missing fields fall
     * back to safe empty defaults so cached payloads from an older shape still
     * load.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $embeddings = $data['embeddings'] ?? [];
        if (!\is_array($embeddings)) {
            $embeddings = [];
        }

        // Validate the inner shape, not just the outer array, so the
        // `array<int, array<int, float>>` annotation below is not a lie:
        // every element must itself be an array of floats. A payload whose
        // inner shape does not hold (e.g. an older/foreign cache entry)
        // falls back to no embeddings rather than constructing an object
        // that violates its own type contract.
        $embeddings = self::normalizeEmbeddings($embeddings);

        $usageData = $data['usage'] ?? [];
        if (!\is_array($usageData)) {
            $usageData = [];
        }
        /** @var array<string, mixed> $usageData The serialized usage shape is a JSON object (string keys). */

        return new self(
            embeddings: $embeddings,
            model: \is_string($data['model'] ?? null) ? $data['model'] : '',
            usage: UsageStatistics::fromArray($usageData),
            provider: \is_string($data['provider'] ?? null) ? $data['provider'] : '',
        );
    }

    /**
     * Validate and reindex a decoded embeddings payload into the strict
     * `list<list<float>>` shape this object promises.
     *
     * Every element must itself be an array whose values are numeric;
     * numeric values are coerced to float (a vector component stored as the
     * int `0` is still a valid `0.0`). If any element is not an array, or
     * contains a non-numeric value, the whole payload is rejected (returns
     * `[]`) rather than silently constructing a half-typed object.
     *
     * @param array<array-key, mixed> $embeddings
     *
     * @return array<int, array<int, float>>
     */
    private static function normalizeEmbeddings(array $embeddings): array
    {
        $normalized = [];
        foreach ($embeddings as $vector) {
            if (!\is_array($vector)) {
                return [];
            }
            $floats = [];
            foreach ($vector as $component) {
                if (!\is_int($component) && !\is_float($component)) {
                    return [];
                }
                $floats[] = (float)$component;
            }
            $normalized[] = $floats;
        }

        return $normalized;
    }

    /**
     * Get the first embedding vector (for single input).
     *
     * @return array<int, float>
     */
    public function getVector(): array
    {
        return $this->embeddings[0] ?? [];
    }

    /**
     * Get all embedding vectors.
     *
     * @return array<int, array<int, float>>
     */
    public function getEmbeddings(): array
    {
        return $this->embeddings;
    }

    /**
     * Get vector dimension count.
     */
    public function getDimensions(): int
    {
        $vector = $this->getVector();
        return count($vector);
    }

    /**
     * Get number of embeddings.
     */
    public function getCount(): int
    {
        return count($this->embeddings);
    }

    /**
     * Normalize a vector to unit length.
     *
     * @param array<int, float> $vector
     *
     * @return array<int, float>
     */
    public function normalizeVector(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(static fn($x) => $x * $x, $vector)));

        if ($magnitude === 0.0) {
            return $vector;
        }

        return array_map(static fn($x) => $x / $magnitude, $vector);
    }

    /**
     * Calculate cosine similarity between two embedding vectors.
     *
     * @param array<int, float> $vectorA
     * @param array<int, float> $vectorB
     */
    public static function cosineSimilarity(array $vectorA, array $vectorB): float
    {
        if (count($vectorA) !== count($vectorB)) {
            throw new InvalidArgumentException('Vectors must have the same dimensions', 5756353142);
        }

        // Reindex both vectors to sequential 0..n-1 keys before pairing them.
        // The count() guard only proves equal length, not matching/sequential
        // keys; a sparse or non-aligned key set would otherwise pair mismatched
        // components (or hit an undefined offset) in the loop below.
        $vectorA = array_values($vectorA);
        $vectorB = array_values($vectorB);

        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        foreach ($vectorA as $i => $a) {
            $b = $vectorB[$i];
            $dotProduct += $a * $b;
            $magnitudeA += $a * $a;
            $magnitudeB += $b * $b;
        }

        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }
}
