<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

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

        $lang = $this->resolveBackendUserLanguage();
        $languageName = $this->mapLanguageCodeToName($lang);

        return str_replace('{lang}', $languageName, $prompt);
    }

    /**
     * Read the backend user's `uc.lang` preference. Traits can't depend on
     * DI cleanly, so this is the rare legitimate use of `$GLOBALS['BE_USER']`
     * — the alternative (BackendUtility::getBackendUserAuthentication) is
     * `protected static` and Context-API requires every consuming controller
     * to wire DI for a single config-fallback string.
     */
    private function resolveBackendUserLanguage(): string
    {
        /** @var BackendUserAuthentication|null $beUser */
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if (!$beUser instanceof BackendUserAuthentication) {
            return 'default';
        }
        $uc = $beUser->uc;
        $lang = $uc['lang'] ?? null;
        return is_string($lang) && $lang !== '' ? $lang : 'default';
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
