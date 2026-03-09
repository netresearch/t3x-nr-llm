<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Throwable;

/**
 * Shared test prompt resolution for backend controllers.
 *
 * Requires the using class to inject ExtensionConfiguration as $extensionConfiguration.
 */
trait TestPromptTrait
{
    /**
     * Resolve the test prompt from extension configuration, replacing {lang} with
     * the backend user's language.
     */
    private function resolveTestPrompt(): string
    {
        $default = 'Say hello and introduce yourself in one sentence. Respond in {lang}.';

        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
            $testing = is_array($config['testing'] ?? null) ? $config['testing'] : [];
            $prompt = is_string($testing['testPrompt'] ?? null) ? $testing['testPrompt'] : $default;
        } catch (Throwable) {
            $prompt = $default;
        }

        if (trim($prompt) === '') {
            $prompt = $default;
        }

        $beUser = $GLOBALS['BE_USER'] ?? null;
        $uc = is_object($beUser) && isset($beUser->uc) && is_array($beUser->uc) ? $beUser->uc : [];
        $lang = isset($uc['lang']) && is_string($uc['lang']) && $uc['lang'] !== '' ? $uc['lang'] : 'default';
        $languageName = $this->mapLanguageCodeToName($lang);

        return str_replace('{lang}', $languageName, $prompt);
    }

    /**
     * Map TYPO3 backend language code to human-readable language name.
     */
    private function mapLanguageCodeToName(string $code): string
    {
        $map = [
            'default' => 'English', 'en' => 'English', 'de' => 'German', 'fr' => 'French',
            'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'pt' => 'Portuguese',
            'da' => 'Danish', 'sv' => 'Swedish', 'no' => 'Norwegian', 'fi' => 'Finnish',
            'pl' => 'Polish', 'cs' => 'Czech', 'sk' => 'Slovak', 'hu' => 'Hungarian',
            'ro' => 'Romanian', 'bg' => 'Bulgarian', 'hr' => 'Croatian', 'sl' => 'Slovenian',
            'el' => 'Greek', 'tr' => 'Turkish', 'ru' => 'Russian', 'uk' => 'Ukrainian',
            'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean', 'ar' => 'Arabic',
        ];

        return $map[$code] ?? 'English';
    }
}
