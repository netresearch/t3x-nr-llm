<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Response object for vision/image analysis requests.
 */
final readonly class VisionResponse
{
    /**
     * @param array<int, array<string, mixed>>|null $detectedObjects
     * @param array<string, mixed>|null             $metadata
     */
    public function __construct(
        public string $description,
        public string $model,
        public UsageStatistics $usage,
        public string $provider = '',
        public ?float $confidence = null,
        public ?array $detectedObjects = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Get the analysis text.
     */
    public function getText(): string
    {
        return $this->description;
    }

    /**
     * Alias for description property.
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Check if confidence score meets threshold.
     */
    public function meetsConfidence(float $threshold): bool
    {
        return $this->confidence !== null && $this->confidence >= $threshold;
    }
}
