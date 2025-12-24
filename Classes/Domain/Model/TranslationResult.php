<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Result object for translation requests
 */
final class TranslationResult
{
    /**
     * @param array<int, string>|null $alternatives
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public readonly string $translation,
        public readonly string $sourceLanguage,
        public readonly string $targetLanguage,
        public readonly float $confidence,
        public readonly UsageStatistics $usage,
        public readonly ?array $alternatives = null,
        public readonly ?array $metadata = null,
    ) {}

    /**
     * Get the translated text
     */
    public function getText(): string
    {
        return $this->translation;
    }

    /**
     * Check if confidence score meets threshold
     */
    public function isConfident(float $threshold = 0.7): bool
    {
        return $this->confidence >= $threshold;
    }

    /**
     * Get alternative translations if available
     *
     * @return array<int, string>
     */
    public function getAlternatives(): array
    {
        return $this->alternatives ?? [];
    }

    /**
     * Check if alternative translations were provided
     */
    public function hasAlternatives(): bool
    {
        return !empty($this->alternatives);
    }
}
