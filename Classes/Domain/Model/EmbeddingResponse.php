<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Response object for embedding requests
 */
final class EmbeddingResponse
{
    public function __construct(
        public readonly array $vector,
        public readonly int $dimensions,
        public readonly UsageStatistics $usage,
        public readonly ?string $model = null,
    ) {}

    /**
     * Get the embedding vector
     */
    public function getVector(): array
    {
        return $this->vector;
    }

    /**
     * Get vector dimension count
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Normalize the vector to unit length
     */
    public function normalize(): array
    {
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $this->vector)));

        if ($magnitude == 0) {
            return $this->vector;
        }

        return array_map(fn($x) => $x / $magnitude, $this->vector);
    }
}
