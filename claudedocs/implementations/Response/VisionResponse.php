<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Vision/Image analysis response
 *
 * @api This class is part of the public API
 */
class VisionResponse extends LlmResponse
{
    public function __construct(
        private string $description,
        private ?array $objects = null,
        private ?array $scene = null,
        private ?float $confidence = null,
        string $content = '',
        ?TokenUsage $usage = null,
        ?array $metadata = null,
        ?string $finishReason = null
    ) {
        parent::__construct(
            $content ?: $description,
            $usage,
            $metadata,
            $finishReason
        );
    }

    /**
     * Get image description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get detected objects
     *
     * @return array List of detected objects
     */
    public function getObjects(): array
    {
        return $this->objects ?? [];
    }

    /**
     * Get scene information
     *
     * @return array Scene metadata (type, setting, timeOfDay, etc.)
     */
    public function getScene(): array
    {
        return $this->scene ?? [];
    }

    /**
     * Get confidence score (0.0-1.0)
     */
    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'description' => $this->description,
            'objects' => $this->objects,
            'scene' => $this->scene,
            'confidence' => $this->confidence
        ]);
    }
}
