<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

/**
 * Contract for specialized translators.
 *
 * Implement this interface to create a new translator (e.g., DeepL, Google Translate).
 * All translators are registered via the `nr_llm.translator` service tag.
 */
interface TranslatorInterface
{
    /**
     * Get unique translator identifier.
     *
     * Used for routing and configuration (e.g., 'deepl', 'llm', 'google').
     */
    public function getIdentifier(): string;

    /**
     * Get human-readable translator name.
     */
    public function getName(): string;

    /**
     * Check if translator is available (configured and ready).
     */
    public function isAvailable(): bool;

    /**
     * Translate a single text.
     *
     * @param string $text Text to translate
     * @param string $targetLanguage Target language code (ISO 639-1)
     * @param string|null $sourceLanguage Source language code (auto-detect if null)
     * @param array<string, mixed> $options Translator-specific options
     * @return TranslatorResult Translation result
     */
    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslatorResult;

    /**
     * Translate multiple texts efficiently.
     *
     * @param array<int, string> $texts Texts to translate
     * @param string $targetLanguage Target language code
     * @param string|null $sourceLanguage Source language code
     * @param array<string, mixed> $options Translator-specific options
     * @return array<int, TranslatorResult> Translation results
     */
    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): array;

    /**
     * Get supported language codes.
     *
     * @return array<int, string> List of supported ISO 639-1 language codes
     */
    public function getSupportedLanguages(): array;

    /**
     * Detect language of text.
     *
     * @param string $text Text to analyze
     * @return string Detected language code (ISO 639-1)
     */
    public function detectLanguage(string $text): string;

    /**
     * Check if a specific language pair is supported.
     *
     * @param string $sourceLanguage Source language code
     * @param string $targetLanguage Target language code
     */
    public function supportsLanguagePair(string $sourceLanguage, string $targetLanguage): bool;
}
