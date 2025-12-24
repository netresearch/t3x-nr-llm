<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Response object for vision/image analysis requests
 */
final class VisionResponse
{
    /**
     * @param array<int, array<string, mixed>>|null $detectedObjects
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly string $description,
        public readonly string $model,
        public readonly UsageStatistics $usage,
        public readonly string $provider = '',
        public readonly ?float $confidence = null,
        public readonly ?array $detectedObjects = null,
        public readonly ?array $metadata = null,
    ) {}

    /**
     * Get the analysis text
     */
    public function getText(): string
    {
        return $this->description;
    }

    /**
     * Alias for description property
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Check if confidence score meets threshold
     */
    public function meetsConfidence(float $threshold): bool
    {
        return $this->confidence !== null && $this->confidence >= $threshold;
    }
}
