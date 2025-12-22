<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Domain\Model\TranslationResult;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\PromptTemplateService;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * High-level service for text translation
 *
 * Provides language translation with quality control,
 * glossary support, and context awareness.
 */
class TranslationService
{
    private const SUPPORTED_FORMALITIES = ['default', 'formal', 'informal'];
    private const SUPPORTED_DOMAINS = ['general', 'technical', 'medical', 'legal', 'marketing'];

    public function __construct(
        private readonly LlmServiceManager $llmManager,
        private readonly PromptTemplateService $promptService,
    ) {}

    /**
     * Translate text to target language
     *
     * @param string $text Text to translate
     * @param string $targetLanguage Target language code (ISO 639-1)
     * @param string|null $sourceLanguage Source language code (auto-detected if null)
     * @param array $options Configuration options:
     *   - formality: string ('default'|'formal'|'informal')
     *   - glossary: array Term translations ['term' => 'translation']
     *   - context: string Surrounding content for context
     *   - preserve_formatting: bool Keep HTML, markdown, etc. (default true)
     *   - domain: string ('general'|'technical'|'medical'|'legal'|'marketing')
     *   - temperature: float Creativity level (default 0.3 for consistency)
     *   - max_tokens: int Maximum output tokens (default 2000)
     * @return TranslationResult
     */
    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslationResult {
        if (empty($text)) {
            throw new InvalidArgumentException('Text cannot be empty');
        }

        $this->validateLanguageCode($targetLanguage);

        // Auto-detect source language if not provided
        if ($sourceLanguage === null) {
            $sourceLanguage = $this->detectLanguage($text);
        } else {
            $this->validateLanguageCode($sourceLanguage);
        }

        // Validate options
        $this->validateOptions($options);

        // Build prompt
        $prompt = $this->buildTranslationPrompt(
            $text,
            $sourceLanguage,
            $targetLanguage,
            $options
        );

        // Execute translation
        $response = $this->llmManager->complete([
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $prompt['system'],
                ],
                [
                    'role' => 'user',
                    'content' => $prompt['user'],
                ],
            ],
            'temperature' => $options['temperature'] ?? 0.3,
            'max_tokens' => $options['max_tokens'] ?? 2000,
        ]);

        return new TranslationResult(
            translation: $response->getContent(),
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            confidence: $this->calculateConfidence($response),
            usage: UsageStatistics::fromTokens(
                promptTokens: $response->getUsage()['prompt_tokens'] ?? 0,
                completionTokens: $response->getUsage()['completion_tokens'] ?? 0,
                estimatedCost: $response->getUsage()['estimated_cost'] ?? null
            ),
            metadata: $response->getMetadata()
        );
    }

    /**
     * Translate multiple texts efficiently
     *
     * @param array $texts Array of texts to translate
     * @param string $targetLanguage Target language code
     * @param string|null $sourceLanguage Source language code (auto-detected if null)
     * @param array $options Configuration options (same as translate())
     * @return array Array of TranslationResult objects
     */
    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): array {
        if (empty($texts)) {
            return [];
        }

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
    public function detectLanguage(string $text): string
    {
        // Use LLM for language detection
        $response = $this->llmManager->complete([
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a language detection expert. Respond with ONLY the ISO 639-1 language code (e.g., "en", "de", "fr"). No explanation.',
                ],
                [
                    'role' => 'user',
                    'content' => "Detect the language of this text:\n\n" . $text,
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 10,
        ]);

        $detectedLang = trim(strtolower($response->getContent()));

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
        string $targetLanguage
    ): float {
        $response = $this->llmManager->complete([
            'messages' => [
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
            ],
            'temperature' => 0.1,
            'max_tokens' => 10,
        ]);

        $score = (float) trim($response->getContent());

        // Clamp to 0.0-1.0 range
        return max(0.0, min(1.0, $score));
    }

    /**
     * Build translation prompt with template
     *
     * @param string $text
     * @param string $sourceLanguage
     * @param string $targetLanguage
     * @param array $options
     * @return array ['system' => string, 'user' => string]
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
     * Calculate confidence score from response
     *
     * @param mixed $response
     * @return float
     */
    private function calculateConfidence($response): float
    {
        // Default confidence based on finish reason
        $finishReason = $response->getFinishReason();

        if ($finishReason === 'stop') {
            return 0.9;
        } elseif ($finishReason === 'length') {
            return 0.6;
        }

        return 0.5;
    }

    /**
     * Validate language code format
     *
     * @param string $languageCode
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
     * @param array $options
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
     *
     * @param string $code
     * @return string
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
