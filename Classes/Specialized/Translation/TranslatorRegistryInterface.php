<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;

/**
 * Interface for translator registry.
 *
 * Extracted from TranslatorRegistry to enable testing with mocks.
 */
interface TranslatorRegistryInterface
{
    /**
     * Get translator by identifier.
     *
     * @param string $identifier Translator identifier (e.g., 'deepl', 'llm')
     *
     * @throws ServiceUnavailableException If translator not found or not available
     */
    public function get(string $identifier): TranslatorInterface;

    /**
     * Check if translator exists and is available.
     *
     * @param string $identifier Translator identifier
     */
    public function has(string $identifier): bool;

    /**
     * Get all available translators.
     *
     * @return array<string, TranslatorInterface> Available translators keyed by identifier
     */
    public function getAvailable(): array;

    /**
     * Get all registered translator identifiers.
     *
     * @return array<int, string>
     */
    public function getRegisteredIdentifiers(): array;

    /**
     * Get translator info for UI display.
     *
     * @return array<string, array{identifier: string, name: string, available: bool}>
     */
    public function getTranslatorInfo(): array;

    /**
     * Find best available translator for a language pair.
     *
     * @param string $sourceLanguage Source language code
     * @param string $targetLanguage Target language code
     *
     * @return TranslatorInterface|null Best available translator or null
     */
    public function findBestTranslator(string $sourceLanguage, string $targetLanguage): ?TranslatorInterface;
}
