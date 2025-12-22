<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Response object for vision/image analysis requests
 */
final class VisionResponse
{
    public function __construct(
        public readonly string $analysis,
        public readonly UsageStatistics $usage,
        public readonly ?float $confidence = null,
        public readonly ?array $detectedObjects = null,
        public readonly ?array $metadata = null,
    ) {}

    /**
     * Get the analysis text
     */
    public function getText(): string
    {
        return $this->analysis;
    }

    /**
     * Check if confidence score meets threshold
     */
    public function meetsConfidence(float $threshold): bool
    {
        return $this->confidence !== null && $this->confidence >= $threshold;
    }
}
