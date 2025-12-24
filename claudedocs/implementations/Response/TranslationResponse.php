<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Translation-specific response
 *
 * @api This class is part of the public API
 */
class TranslationResponse extends LlmResponse
{
    public function __construct(
        private string $translation,
        private ?float $confidence = null,
        private ?array $alternatives = null,
        string $content = '',
        ?TokenUsage $usage = null,
        ?array $metadata = null,
        ?string $finishReason = null
    ) {
        parent::__construct(
            $content ?: $translation,
            $usage,
            $metadata,
            $finishReason
        );
    }

    /**
     * Get translation text
     */
    public function getTranslation(): string
    {
        return $this->translation;
    }

    /**
     * Get confidence score (0.0-1.0)
     */
    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    /**
     * Get alternative translations
     *
     * @return array Array of alternative translations with scores
     */
    public function getAlternatives(): array
    {
        return $this->alternatives ?? [];
    }

    /**
     * Check if alternatives are available
     */
    public function hasAlternatives(): bool
    {
        return !empty($this->alternatives);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'translation' => $this->translation,
            'confidence' => $this->confidence,
            'alternatives' => $this->alternatives,
        ]);
    }
}
