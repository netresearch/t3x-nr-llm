<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Registry for specialized translators.
 *
 * Collects all translators tagged with `nr_llm.translator` and provides
 * access by identifier. Enables dual-path translation: LLM-based or specialized.
 *
 * Usage:
 *   $translator = $registry->get('deepl');
 *   $result = $translator->translate('Hello', 'de');
 */
final class TranslatorRegistry implements TranslatorRegistryInterface, SingletonInterface
{
    /** @var array<string, TranslatorInterface> */
    private array $translators = [];

    /**
     * @param iterable<TranslatorInterface> $translators
     */
    public function __construct(
        #[TaggedIterator('nr_llm.translator')]
        iterable $translators,
    ) {
        foreach ($translators as $translator) {
            $this->translators[$translator->getIdentifier()] = $translator;
        }
    }

    /**
     * Get translator by identifier.
     *
     * @param string $identifier Translator identifier (e.g., 'deepl', 'llm')
     *
     * @throws ServiceUnavailableException If translator not found or not available
     */
    public function get(string $identifier): TranslatorInterface
    {
        if (!isset($this->translators[$identifier])) {
            throw ServiceUnavailableException::translatorNotFound($identifier);
        }

        $translator = $this->translators[$identifier];

        if (!$translator->isAvailable()) {
            throw ServiceUnavailableException::notConfigured('translation', $identifier);
        }

        return $translator;
    }

    /**
     * Check if translator exists and is available.
     *
     * @param string $identifier Translator identifier
     */
    public function has(string $identifier): bool
    {
        return isset($this->translators[$identifier])
            && $this->translators[$identifier]->isAvailable();
    }

    /**
     * Get all available translators.
     *
     * @return array<string, TranslatorInterface> Available translators keyed by identifier
     */
    public function getAvailable(): array
    {
        return array_filter(
            $this->translators,
            static fn(TranslatorInterface $t) => $t->isAvailable(),
        );
    }

    /**
     * Get all registered translator identifiers.
     *
     * @return array<int, string>
     */
    public function getRegisteredIdentifiers(): array
    {
        return array_keys($this->translators);
    }

    /**
     * Get translator info for UI display.
     *
     * @return array<string, array{identifier: string, name: string, available: bool}>
     */
    public function getTranslatorInfo(): array
    {
        $info = [];

        foreach ($this->translators as $identifier => $translator) {
            $info[$identifier] = [
                'identifier' => $identifier,
                'name' => $translator->getName(),
                'available' => $translator->isAvailable(),
            ];
        }

        return $info;
    }

    /**
     * Find best available translator for a language pair.
     *
     * @param string $sourceLanguage Source language code
     * @param string $targetLanguage Target language code
     *
     * @return TranslatorInterface|null Best available translator or null
     */
    public function findBestTranslator(string $sourceLanguage, string $targetLanguage): ?TranslatorInterface
    {
        foreach ($this->getAvailable() as $translator) {
            if ($translator->supportsLanguagePair($sourceLanguage, $targetLanguage)) {
                return $translator;
            }
        }

        return null;
    }
}
