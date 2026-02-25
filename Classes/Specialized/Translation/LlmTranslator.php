<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;

/**
 * LLM-based translator wrapper.
 *
 * Wraps the existing LLM-based translation functionality to implement
 * the TranslatorInterface, enabling it to work within the translator registry.
 *
 * This is the default translator and is always available if any LLM provider
 * is configured.
 */
final readonly class LlmTranslator implements TranslatorInterface
{
    private const array SUPPORTED_LANGUAGES = [
        'en', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'pl', 'ru', 'ja', 'zh', 'ko',
        'ar', 'cs', 'da', 'fi', 'el', 'hu', 'id', 'no', 'ro', 'sk', 'sv', 'th',
        'tr', 'uk', 'vi', 'bg', 'hr', 'et', 'lv', 'lt', 'sl', 'he', 'hi', 'ms',
    ];

    private const array LANGUAGE_NAMES = [
        'en' => 'English', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish',
        'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'pl' => 'Polish',
        'ru' => 'Russian', 'ja' => 'Japanese', 'zh' => 'Chinese', 'ko' => 'Korean',
        'ar' => 'Arabic', 'cs' => 'Czech', 'da' => 'Danish', 'fi' => 'Finnish',
        'el' => 'Greek', 'hu' => 'Hungarian', 'id' => 'Indonesian', 'no' => 'Norwegian',
        'ro' => 'Romanian', 'sk' => 'Slovak', 'sv' => 'Swedish', 'th' => 'Thai',
        'tr' => 'Turkish', 'uk' => 'Ukrainian', 'vi' => 'Vietnamese', 'bg' => 'Bulgarian',
        'hr' => 'Croatian', 'et' => 'Estonian', 'lv' => 'Latvian', 'lt' => 'Lithuanian',
        'sl' => 'Slovenian', 'he' => 'Hebrew', 'hi' => 'Hindi', 'ms' => 'Malay',
    ];

    public function __construct(
        private LlmServiceManagerInterface $llmManager,
        private UsageTrackerServiceInterface $usageTracker,
    ) {}

    public function getIdentifier(): string
    {
        return 'llm';
    }

    public function getName(): string
    {
        return 'LLM-based Translation';
    }

    public function isAvailable(): bool
    {
        // Available if any LLM provider is configured
        return $this->llmManager->hasAvailableProvider();
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = [],
    ): TranslatorResult {
        // Detect source language if not provided
        if ($sourceLanguage === null) {
            $sourceLanguage = $this->detectLanguage($text);
        }

        // Build translation prompt
        $prompt = $this->buildPrompt($text, $sourceLanguage, $targetLanguage, $options);

        // Extract and validate options
        $temperature = isset($options['temperature']) && is_float($options['temperature'])
            ? $options['temperature']
            : 0.3;
        $maxTokens = isset($options['max_tokens']) && is_int($options['max_tokens'])
            ? $options['max_tokens']
            : 2000;
        $provider = isset($options['provider']) && is_string($options['provider'])
            ? $options['provider']
            : null;
        $model = isset($options['model']) && is_string($options['model'])
            ? $options['model']
            : null;

        // Execute translation
        $chatOptions = new ChatOptions(
            temperature: $temperature,
            maxTokens: $maxTokens,
            provider: $provider,
            model: $model,
        );

        $response = $this->llmManager->chat($prompt['messages'], $chatOptions);

        // Track usage
        $providerUsed = $provider ?? 'default';
        $this->usageTracker->trackUsage('translation', 'llm:' . $providerUsed, [
            'tokens' => $response->usage->totalTokens,
            'characters' => mb_strlen($text),
        ]);

        // Calculate confidence from finish reason
        $confidence = match ($response->finishReason) {
            'stop' => 0.9,
            'length' => 0.6,
            default => 0.5,
        };

        return new TranslatorResult(
            translatedText: $response->content,
            sourceLanguage: $sourceLanguage,
            targetLanguage: $targetLanguage,
            translator: 'llm:' . $providerUsed,
            confidence: $confidence,
            metadata: [
                'model' => $response->model,
                'usage' => [
                    'prompt_tokens' => $response->usage->promptTokens,
                    'completion_tokens' => $response->usage->completionTokens,
                    'total_tokens' => $response->usage->totalTokens,
                ],
            ],
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = [],
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

    public function getSupportedLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }

    public function detectLanguage(string $text): string
    {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a language detection expert. Respond with ONLY the ISO 639-1 language code (e.g., "en", "de", "fr"). No explanation.',
            ],
            [
                'role' => 'user',
                'content' => "Detect the language of this text:\n\n" . substr($text, 0, 500),
            ],
        ];

        $chatOptions = new ChatOptions(
            temperature: 0.1,
            maxTokens: 10,
        );

        $response = $this->llmManager->chat($messages, $chatOptions);

        $detectedLang = trim(strtolower($response->content));

        // Validate the response is a 2-letter code
        if (!preg_match('/^[a-z]{2}$/', $detectedLang)) {
            return 'en'; // Fallback
        }

        return $detectedLang;
    }

    public function supportsLanguagePair(string $sourceLanguage, string $targetLanguage): bool
    {
        // LLM can translate any language pair (quality may vary)
        return true;
    }

    /**
     * Build translation prompt.
     *
     * @param array<string, mixed> $options
     *
     * @return array{messages: array<int, array{role: string, content: string}>}
     */
    private function buildPrompt(
        string $text,
        string $sourceLanguage,
        string $targetLanguage,
        array $options,
    ): array {
        $formality = isset($options['formality']) && is_string($options['formality'])
            ? $options['formality']
            : 'default';
        $domain = isset($options['domain']) && is_string($options['domain'])
            ? $options['domain']
            : 'general';
        /** @var array<string, string> $glossary */
        $glossary = isset($options['glossary']) && is_array($options['glossary'])
            ? $options['glossary']
            : [];
        $context = isset($options['context']) && is_string($options['context'])
            ? $options['context']
            : '';
        $preserveFormatting = !isset($options['preserve_formatting']) || $options['preserve_formatting'] === true;

        // Build system prompt
        $systemPrompt = sprintf(
            "You are a professional %s translator. Translate the following text from %s to %s.\n",
            $domain,
            self::LANGUAGE_NAMES[$sourceLanguage] ?? $sourceLanguage,
            self::LANGUAGE_NAMES[$targetLanguage] ?? $targetLanguage,
        );

        if ($formality !== 'default') {
            $systemPrompt .= sprintf("Maintain %s tone.\n", $formality);
        }

        if ($preserveFormatting) {
            $systemPrompt .= "Preserve all formatting, HTML tags, markdown, and special characters.\n";
        }

        if ($glossary !== []) {
            $systemPrompt .= "\nUse these exact term translations:\n";
            foreach ($glossary as $term => $translation) {
                $systemPrompt .= sprintf("- %s → %s\n", (string)$term, (string)$translation);
            }
        }

        if ($context !== '') {
            $systemPrompt .= sprintf("\nContext (for reference only):\n%s\n", $context);
        }

        $systemPrompt .= "\nProvide ONLY the translation, no explanations or notes.";

        return [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Translate this text:\n\n" . $text],
            ],
        ];
    }
}
