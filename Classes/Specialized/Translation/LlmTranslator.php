<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Attribute\AsTranslator;
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
#[AsTranslator]
final readonly class LlmTranslator implements TranslatorInterface
{
    private const SUPPORTED_LANGUAGES = [
        'en', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'pl', 'ru', 'ja', 'zh', 'ko',
        'ar', 'cs', 'da', 'fi', 'el', 'hu', 'id', 'no', 'ro', 'sk', 'sv', 'th',
        'tr', 'uk', 'vi', 'bg', 'hr', 'et', 'lv', 'lt', 'sl', 'he', 'hi', 'ms',
    ];

    private const LANGUAGE_NAMES = [
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

    public static function getPriority(): int
    {
        return 100;
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
        // ADR-052: extract the attribution uid once — it feeds the underlying
        // chat calls (budget enforcement + chat-row attribution in the
        // middleware pipeline) and the translation-level tracking row alike.
        $beUserUid = $this->extractBeUserUid($options);

        // Detect source language if not provided. As an internal step of the
        // translation it must not count as a separate request (#473) — the
        // 'translation' row below is the single request of record.
        if ($sourceLanguage === null) {
            $sourceLanguage = $this->detectLanguageAttributed($text, $beUserUid, false);
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

        // Execute translation. The uid rides along as pipeline metadata so
        // BudgetMiddleware enforces the caller's budget and UsageMiddleware
        // attributes the chat row to the same be_user as the translation row
        // (previously the chat row always landed in the ambient bucket).
        // The 'translation' row below is the single request-of-record; the
        // underlying chat row keeps its tokens/cost but must not also count as
        // a request (issue #473 double-count).
        $chatOptions = (new ChatOptions(
            temperature: $temperature,
            maxTokens: $maxTokens,
            provider: $provider,
            model: $model,
            beUserUid: $beUserUid,
        ))->withSuppressRequestCount(true);

        $response = $this->llmManager->chat($prompt['messages'], $chatOptions);

        // Track the translation-level view (request + characters). Tokens
        // and cost are deliberately NOT recorded here: the middleware
        // pipeline (UsageMiddleware) already records them on the underlying
        // chat row — repeating them here double-counted every translated
        // token in the analytics aggregates.
        $providerUsed = $provider ?? 'default';
        $this->usageTracker->trackUsage('translation', 'llm:' . $providerUsed, [
            'characters' => mb_strlen($text),
        ], modelId: $response->model, beUserUid: $beUserUid);

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
        // Public TranslatorInterface signature carries no options, so a
        // direct call stays ambient; translate() routes through
        // detectLanguageAttributed() with the caller's uid instead. A
        // standalone detection is a request in its own right, so it counts.
        return $this->detectLanguageAttributed($text, null, true);
    }

    /**
     * Detect the language of a text, attributing the underlying chat call
     * to the given backend user (ADR-052).
     *
     * @param bool $countsAsRequest false when detection runs as the internal
     *                              first step of translate() — the translation
     *                              records the single request-of-record, so the
     *                              detection sub-call must not increment the
     *                              request counter too (#473). true for a
     *                              standalone detectLanguage() call, which is a
     *                              request in its own right.
     */
    private function detectLanguageAttributed(string $text, ?int $beUserUid, bool $countsAsRequest): string
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

        // Suppress the request count only when detection is a sub-step of a
        // translation; a standalone detectLanguage() call counts (#473).
        $chatOptions = (new ChatOptions(
            temperature: 0.1,
            maxTokens: 10,
            beUserUid: $beUserUid,
        ))->withSuppressRequestCount(!$countsAsRequest);

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
     * Extract the usage-attribution uid attached by `TranslationService`
     * (`beUserUid` options key, ADR-052). Never part of the chat prompt or
     * payload; null falls back to the tracker's ambient `backend.user`
     * context. Negative values are treated as absent: the uid feeds the
     * `ChatOptions` constructor, whose budget-field validation would throw
     * outside any error-mapping boundary.
     *
     * @param array<string, mixed> $options
     */
    private function extractBeUserUid(array $options): ?int
    {
        $beUserUid = $options['beUserUid'] ?? null;

        return is_int($beUserUid) && $beUserUid >= 0 ? $beUserUid : null;
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
