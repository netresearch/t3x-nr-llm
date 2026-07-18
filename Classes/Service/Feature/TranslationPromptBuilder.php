<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

/**
 * Builds the system/user prompt pair for LLM-based translation.
 *
 * Extracted from TranslationService so both translate() and
 * translateForConfiguration() share one prompt template.
 */
final readonly class TranslationPromptBuilder
{
    /**
     * Build translation prompt with template.
     *
     * @param array<string, mixed> $options
     *
     * @return array{system: string, user: string}
     */
    public function build(
        string $text,
        string $sourceLanguage,
        string $targetLanguage,
        array $options,
    ): array {
        $formality = is_string($options['formality'] ?? null) ? $options['formality'] : 'default';
        $domain = is_string($options['domain'] ?? null) ? $options['domain'] : 'general';
        $glossary = is_array($options['glossary'] ?? null) ? $options['glossary'] : [];
        $context = is_string($options['context'] ?? null) ? $options['context'] : '';
        $preserveFormatting = $options['preserve_formatting'] ?? true;

        // Build system prompt
        $systemPrompt = sprintf(
            "You are a professional %s translator. Translate the following text from %s to %s.\n",
            $domain,
            $this->getLanguageName($sourceLanguage),
            $this->getLanguageName($targetLanguage),
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
        if ($glossary !== []) {
            $systemPrompt .= "\nUse these exact term translations:\n";
            foreach ($glossary as $term => $translation) {
                if (is_string($translation) || is_int($translation) || is_float($translation)) {
                    $systemPrompt .= sprintf("- %s → %s\n", $term, $translation);
                }
            }
        }

        // Add context if provided
        if ($context !== '') {
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
     * Get human-readable language name.
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
