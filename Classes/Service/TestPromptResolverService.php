<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;

/**
 * Resolves the backend test prompt, replacing `{lang}` with the current
 * backend user's language preference.
 *
 * Replaces the former `TestPromptTrait` — extracting the logic into a
 * service with proper DI removes the trait's dependency on
 * `$GLOBALS['BE_USER']` (TC-37) and makes the BE-language lookup testable.
 *
 * Uses the TYPO3 Context API (`backend.user` aspect) which is the
 * canonical replacement for direct `$GLOBALS` access since TYPO3 v9.
 */
final readonly class TestPromptResolverService implements TestPromptResolverInterface
{
    private const DEFAULT_PROMPT = 'Say hello and introduce yourself in one sentence. Respond in {lang}.';

    /**
     * Map TYPO3 backend language codes to human-readable names for
     * interpolation into the test prompt.
     *
     * @var array<string, string>
     */
    private const LANGUAGE_MAP = [
        'default' => 'English', 'en' => 'English', 'de' => 'German', 'fr' => 'French',
        'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'pt' => 'Portuguese',
        'da' => 'Danish', 'sv' => 'Swedish', 'no' => 'Norwegian', 'fi' => 'Finnish',
        'pl' => 'Polish', 'cs' => 'Czech', 'sk' => 'Slovak', 'hu' => 'Hungarian',
        'ro' => 'Romanian', 'bg' => 'Bulgarian', 'hr' => 'Croatian', 'sl' => 'Slovenian',
        'el' => 'Greek', 'tr' => 'Turkish', 'ru' => 'Russian', 'uk' => 'Ukrainian',
        'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean', 'ar' => 'Arabic',
    ];

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private Context $context,
    ) {}

    public function resolve(): string
    {
        $prompt = $this->loadConfiguredPrompt();
        $languageName = self::LANGUAGE_MAP[$this->resolveBackendUserLanguage()] ?? 'English';

        return str_replace('{lang}', $languageName, $prompt);
    }

    private function loadConfiguredPrompt(): string
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
            $testing = is_array($config['testing'] ?? null) ? $config['testing'] : [];
            $prompt = is_string($testing['testPrompt'] ?? null) ? $testing['testPrompt'] : self::DEFAULT_PROMPT;
        } catch (Throwable) {
            return self::DEFAULT_PROMPT;
        }

        return trim($prompt) !== '' ? $prompt : self::DEFAULT_PROMPT;
    }

    /**
     * Read the authenticated backend user's interface language
     * (the `be_users.lang` field).
     *
     * The Context `backend.user` aspect gates the lookup so non-backend
     * contexts (CLI, frontend, tests) degrade gracefully. The aspect does not
     * expose the user record, so the language itself is read from the backend
     * user object.
     *
     * Returns `'default'` (→ English) when:
     *  - the backend.user aspect is not registered (non-BE context, CLI, tests)
     *  - no backend user is logged in
     *  - the user has no language preference set
     */
    private function resolveBackendUserLanguage(): string
    {
        try {
            $isLoggedIn = (bool)$this->context->getAspect('backend.user')->get('isLoggedIn');
        } catch (AspectNotFoundException) {
            $isLoggedIn = false;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$isLoggedIn || !$backendUser instanceof BackendUserAuthentication) {
            return 'default';
        }

        // BackendUserAuthentication::$user is untyped and may be null until a
        // session is loaded; guard before the array access.
        $record = $backendUser->user;
        $lang = is_array($record) ? ($record['lang'] ?? null) : null;
        return is_string($lang) && $lang !== '' ? $lang : 'default';
    }
}
