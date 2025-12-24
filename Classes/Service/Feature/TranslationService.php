<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\TranslationResult;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistry;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;

/**
 * High-level service for text translation
 *
 * Provides language translation with quality control,
 * glossary support, and context awareness.
 *
 * Supports dual-path translation:
 * - LLM-based translation (default)
 * - Specialized translators (DeepL, etc.) via TranslatorRegistry
 */
class TranslationService
{
    private const SUPPORTED_FORMALITIES = ['default', 'formal', 'informal'];
    private const SUPPORTED_DOMAINS = ['general', 'technical', 'medical', 'legal', 'marketing'];

    public function __construct(
        private readonly LlmServiceManager $llmManager,
        private readonly TranslatorRegistry $translatorRegistry,
        private readonly LlmConfigurationService $configurationService,
    ) {}

    /**
     * Translate text to target language
     *
     * @param string $text Text to translate
     * @param string $targetLanguage Target language code (ISO 639-1)
     * @param string|null $sourceLanguage Source language code (auto-detected if null)
     */
    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null
    ): TranslationResult {
        $options ??= new TranslationOptions();
        $optionsArray = $options->toArray();

        if (empty($text)) {
            throw new InvalidArgumentException('Text cannot be empty');
        }

        $this->validateLanguageCode($targetLanguage);

        // Auto-detect source language if not provided
        if ($sourceLanguage === null) {
            $sourceLanguage = $this->detectLanguage($text, $options);
        } else {
            $this->validateLanguageCode($sourceLanguage);
        }

        // Validate options
        $this->validateOptions($optionsArray);

        // Build prompt
        $prompt = $this->buildTranslationPrompt(
            $text,
            $sourceLanguage,
            $targetLanguage,
            $optionsArray
        );

        // Execute translation
        $messages = [
            [
                'role' => 'system',
                'content' => $prompt['system'],
            ],
            [
                'role' => 'user',
                'content' => $prompt['user'],
            ],
        ];

        $chatOptions = new ChatOptions(
            temperature: $options->getTemperature() ?? 0.3,
            maxTokens: $options->getMaxTokens() ?? 2000,
            provider: $options->getProvider()
        );

        $response = $this->llmManager->chat($messages, $chatOptions);

        return new TranslationResult(
            translation: $response->content,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            confidence: $this->calculateConfidence($response->finishReason),
            usage: $response->usage
        );
    }

    /**
     * Translate multiple texts efficiently
     *
     * @param array<int, string> $texts Array of texts to translate
     * @param string $targetLanguage Target language code
     * @param string|null $sourceLanguage Source language code (auto-detected if null)
     * @return array<int, TranslationResult> Array of TranslationResult objects
     */
    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null
    ): array {
        if (empty($texts)) {
            return [];
        }

        $options ??= new TranslationOptions();
        $results = [];

        foreach ($texts as $text) {
            $results[] = $this->translate($text, $targetLanguage, $sourceLanguage, $options);
        }

        return $results;
    }

    /**
     * Detect language of text
     *
     * @param string $text Text to analyze
     * @return string Language code (ISO 639-1)
     */
    public function detectLanguage(string $text, ?TranslationOptions $options = null): string
    {
        $options ??= new TranslationOptions();
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a language detection expert. Respond with ONLY the ISO 639-1 language code (e.g., "en", "de", "fr"). No explanation.',
            ],
            [
                'role' => 'user',
                'content' => "Detect the language of this text:\n\n" . $text,
            ],
        ];

        $chatOptions = new ChatOptions(
            temperature: 0.1,
            maxTokens: 10,
            provider: $options->getProvider()
        );

        $response = $this->llmManager->chat($messages, $chatOptions);

        $detectedLang = trim(strtolower($response->content));

        // Validate the response is a 2-letter code
        if (!preg_match('/^[a-z]{2}$/', $detectedLang)) {
            // Fallback to 'en' if detection fails
            return 'en';
        }

        return $detectedLang;
    }

    /**
     * Score translation quality
     *
     * Analyzes translation quality based on accuracy, fluency, and consistency.
     *
     * @param string $sourceText Original text
     * @param string $translatedText Translated text
     * @param string $targetLanguage Target language code
     * @return float Quality score (0.0-1.0)
     */
    public function scoreTranslationQuality(
        string $sourceText,
        string $translatedText,
        string $targetLanguage,
        ?TranslationOptions $options = null
    ): float {
        $options ??= new TranslationOptions();
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a translation quality expert. Evaluate the translation quality based on accuracy, fluency, and consistency. Respond with ONLY a number between 0.0 and 1.0 (e.g., "0.85"). No explanation.',
            ],
            [
                'role' => 'user',
                'content' => sprintf(
                    "Source text:\n%s\n\nTranslation to %s:\n%s\n\nQuality score:",
                    $sourceText,
                    $targetLanguage,
                    $translatedText
                ),
            ],
        ];

        $chatOptions = new ChatOptions(
            temperature: 0.1,
            maxTokens: 10,
            provider: $options->getProvider()
        );

        $response = $this->llmManager->chat($messages, $chatOptions);

        $score = (float) trim($response->content);

        // Clamp to 0.0-1.0 range
        return max(0.0, min(1.0, $score));
    }

    /**
     * Translate using specialized translator or LLM
     *
     * Supports dual-path translation with priority routing:
     * 1. Explicit translator specified in options
     * 2. Translator from LlmConfiguration preset
     * 3. Default LLM-based translation
     *
     * @param string $text Text to translate
     * @param string $targetLanguage Target language code (ISO 639-1)
     * @param string|null $sourceLanguage Source language code (auto-detected if null)
     * @return TranslatorResult Translation result with metadata
     */
    public function translateWithTranslator(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null
    ): TranslatorResult {
        $options ??= new TranslationOptions();
        $optionsArray = $options->toArray();

        if (empty($text)) {
            throw new InvalidArgumentException('Text cannot be empty');
        }

        $this->validateLanguageCode($targetLanguage);
        if ($sourceLanguage !== null) {
            $this->validateLanguageCode($sourceLanguage);
        }

        // Determine translator to use
        $translator = $this->resolveTranslator($optionsArray);

        // Execute translation via resolved translator
        return $translator->translate($text, $targetLanguage, $sourceLanguage, $optionsArray);
    }

    /**
     * Translate batch using specialized translator or LLM
     *
     * @param array<int, string> $texts Texts to translate
     * @param string $targetLanguage Target language code
     * @param string|null $sourceLanguage Source language code
     * @return array<int, TranslatorResult> Translation results
     */
    public function translateBatchWithTranslator(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        ?TranslationOptions $options = null
    ): array {
        if (empty($texts)) {
            return [];
        }

        $options ??= new TranslationOptions();
        $optionsArray = $options->toArray();
        $translator = $this->resolveTranslator($optionsArray);

        return $translator->translateBatch($texts, $targetLanguage, $sourceLanguage, $optionsArray);
    }

    /**
     * Get available translators
     *
     * @return array<string, array{identifier: string, name: string, available: bool}>
     */
    public function getAvailableTranslators(): array
    {
        return $this->translatorRegistry->getTranslatorInfo();
    }

    /**
     * Check if a specific translator is available
     */
    public function hasTranslator(string $identifier): bool
    {
        return $this->translatorRegistry->has($identifier);
    }

    /**
     * Get translator by identifier
     *
     * @throws \Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException
     */
    public function getTranslator(string $identifier): TranslatorInterface
    {
        return $this->translatorRegistry->get($identifier);
    }

    /**
     * Find best translator for a language pair
     */
    public function findBestTranslator(string $sourceLanguage, string $targetLanguage): ?TranslatorInterface
    {
        return $this->translatorRegistry->findBestTranslator($sourceLanguage, $targetLanguage);
    }

    /**
     * Resolve which translator to use based on options
     */
    private function resolveTranslator(array $options): TranslatorInterface
    {
        // Priority 1: Explicit translator specified
        if (isset($options['translator']) && $options['translator'] !== '') {
            return $this->translatorRegistry->get($options['translator']);
        }

        // Priority 2: Preset specified - check for translator in configuration
        if (isset($options['preset']) && $options['preset'] !== '') {
            $configuration = $this->configurationService->getConfiguration($options['preset']);
            if ($configuration !== null && $configuration->getTranslator() !== '') {
                return $this->translatorRegistry->get($configuration->getTranslator());
            }
        }

        // Default: Use LLM-based translator
        return $this->translatorRegistry->get('llm');
    }

    /**
     * Convert TranslatorResult to legacy TranslationResult
     *
     * For backwards compatibility with existing code.
     */
    public function convertToLegacyResult(TranslatorResult $result): TranslationResult
    {
        return new TranslationResult(
            translation: $result->translatedText,
            sourceLanguage: $result->sourceLanguage,
            targetLanguage: $result->targetLanguage,
            confidence: $result->confidence,
            usage: $this->extractUsageFromMetadata($result->metadata)
        );
    }

    /**
     * Extract UsageStatistics from translator metadata
     */
    private function extractUsageFromMetadata(array $metadata): ?UsageStatistics
    {
        if (!isset($metadata['usage'])) {
            return null;
        }

        $usage = $metadata['usage'];

        return new UsageStatistics(
            promptTokens: $usage['prompt_tokens'] ?? 0,
            completionTokens: $usage['completion_tokens'] ?? 0,
            totalTokens: $usage['total_tokens'] ?? 0
        );
    }

    /**
     * Build translation prompt with template
     *
     * @return array{system: string, user: string}
     */
    private function buildTranslationPrompt(
        string $text,
        string $sourceLanguage,
        string $targetLanguage,
        array $options
    ): array {
        $formality = $options['formality'] ?? 'default';
        $domain = $options['domain'] ?? 'general';
        $glossary = $options['glossary'] ?? [];
        $context = $options['context'] ?? '';
        $preserveFormatting = $options['preserve_formatting'] ?? true;

        // Build system prompt
        $systemPrompt = sprintf(
            "You are a professional %s translator. Translate the following text from %s to %s.\n",
            $domain,
            $this->getLanguageName($sourceLanguage),
            $this->getLanguageName($targetLanguage)
        );

        // Add formality instruction
        if ($formality !== 'default') {
            $systemPrompt .= sprintf("Maintain %s tone.\n", $formality);
        }

        // Add formatting instruction
        if ($preserveFormatting) {
            $systemPrompt .= "Preserve all formatting, HTML tags, markdown, and special characters.\n";
        }

        // Add glossary if provided
        if (!empty($glossary)) {
            $systemPrompt .= "\nUse these exact term translations:\n";
            foreach ($glossary as $term => $translation) {
                $systemPrompt .= sprintf("- %s → %s\n", $term, $translation);
            }
        }

        // Add context if provided
        if (!empty($context)) {
            $systemPrompt .= sprintf("\nContext (for reference only):\n%s\n", $context);
        }

        $systemPrompt .= "\nProvide ONLY the translation, no explanations or notes.";

        // Build user prompt
        $userPrompt = sprintf("Translate this text:\n\n%s", $text);

        return [
            'system' => $systemPrompt,
            'user' => $userPrompt,
        ];
    }

    /**
     * Calculate confidence score from finish reason
     */
    private function calculateConfidence(string $finishReason): float
    {
        return match ($finishReason) {
            'stop' => 0.9,
            'length' => 0.6,
            default => 0.5,
        };
    }

    /**
     * Validate language code format
     *
     * @throws InvalidArgumentException
     */
    private function validateLanguageCode(string $languageCode): void
    {
        if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $languageCode)) {
            throw new InvalidArgumentException(
                'Invalid language code format. Expected ISO 639-1 (e.g., "en", "de-DE")'
            );
        }
    }

    /**
     * Validate translation options
     *
     * @throws InvalidArgumentException
     */
    private function validateOptions(array $options): void
    {
        if (isset($options['formality'])) {
            if (!in_array($options['formality'], self::SUPPORTED_FORMALITIES, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Invalid formality. Supported: %s',
                        implode(', ', self::SUPPORTED_FORMALITIES)
                    )
                );
            }
        }

        if (isset($options['domain'])) {
            if (!in_array($options['domain'], self::SUPPORTED_DOMAINS, true)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Invalid domain. Supported: %s',
                        implode(', ', self::SUPPORTED_DOMAINS)
                    )
                );
            }
        }

        if (isset($options['glossary']) && !is_array($options['glossary'])) {
            throw new InvalidArgumentException('Glossary must be an associative array');
        }
    }

    /**
     * Get human-readable language name
     */
    private function getLanguageName(string $code): string
    {
        $languages = [
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'zh' => 'Chinese',
            'ko' => 'Korean',
            'ar' => 'Arabic',
        ];

        return $languages[$code] ?? $code;
    }
}
