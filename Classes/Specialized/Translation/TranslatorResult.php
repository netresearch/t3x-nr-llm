<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

/**
 * Result from specialized translators (DeepL, Google, etc.)
 *
 * Note: This is separate from Domain\Model\TranslationResult which is
 * used by LLM-based translation and includes UsageStatistics.
 */
final readonly class TranslatorResult
{
    /**
     * @param array<int, string>|null $alternatives
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public string $translatedText,
        public string $sourceLanguage,
        public string $targetLanguage,
        public string $translator,
        public ?float $confidence = null,
        public ?array $alternatives = null,
        public ?int $charactersUsed = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Get the translated text.
     */
    public function getText(): string
    {
        return $this->translatedText;
    }

    /**
     * Check if result is from LLM-based translation.
     */
    public function isFromLlm(): bool
    {
        return str_starts_with($this->translator, 'llm:');
    }

    /**
     * Check if result is from DeepL.
     */
    public function isFromDeepL(): bool
    {
        return $this->translator === 'deepl';
    }

    /**
     * Get human-readable translator name.
     */
    public function getTranslatorName(): string
    {
        if ($this->isFromLlm()) {
            $provider = substr($this->translator, 4);
            return 'LLM (' . ($provider ?: 'default') . ')';
        }

        return ucfirst($this->translator);
    }

    /**
     * Check if this result has alternatives.
     */
    public function hasAlternatives(): bool
    {
        return $this->alternatives !== null && $this->alternatives !== [];
    }

    /**
     * Get confidence as percentage string.
     */
    public function getConfidencePercent(): ?string
    {
        if ($this->confidence === null) {
            return null;
        }

        return number_format($this->confidence * 100, 1) . '%';
    }
}
