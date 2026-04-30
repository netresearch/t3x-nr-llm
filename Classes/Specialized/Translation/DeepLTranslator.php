<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Attribute\AsTranslator;
use Netresearch\NrLlm\Specialized\AbstractSpecializedService;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Option\DeepLOptions;
use Throwable;

/**
 * DeepL translation service integration.
 *
 * Provides high-quality neural machine translation via DeepL API.
 * Supports both DeepL Free and DeepL Pro APIs.
 *
 * Features:
 * - 30+ supported languages
 * - Formality control (formal/informal tone)
 * - Glossary support for consistent terminology
 * - HTML/XML tag handling with formatting preservation
 * - Document translation (PDFs, DOCX, etc.)
 *
 * Inherits HTTP scaffolding from `AbstractSpecializedService` —
 * including config loading, availability check, and error mapping.
 * Owns its own request execution because DeepL needs the
 * `User-Agent` header and accepts both POST (translate) and GET
 * (usage / glossaries) requests; that's not generic enough for the
 * base's `sendJsonRequest()` helper.
 *
 * @see https://developers.deepl.com/docs
 */
#[AsTranslator]
final class DeepLTranslator extends AbstractSpecializedService implements TranslatorInterface
{
    private const API_VERSION = 'v2';
    private const FREE_API_URL = 'https://api-free.deepl.com';
    private const PRO_API_URL = 'https://api.deepl.com';

    /** DeepL supported source languages (ISO 639-1 codes). */
    private const SUPPORTED_SOURCE_LANGUAGES = [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr',
        'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'nb', 'nl', 'pl',
        'pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'tr', 'uk', 'zh',
        'ar', // Arabic added 2024
    ];

    /**
     * DeepL supported target languages.
     * Note: Some languages have regional variants (e.g., EN-GB, EN-US, PT-BR, PT-PT).
     */
    private const SUPPORTED_TARGET_LANGUAGES = [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'en-gb', 'en-us', 'es', 'et',
        'fi', 'fr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'nb', 'nl',
        'pl', 'pt', 'pt-br', 'pt-pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'tr',
        'uk', 'zh', 'zh-hans', 'zh-hant',
        'ar', // Arabic added 2024
    ];

    /** Languages that support formality control. */
    private const FORMALITY_SUPPORTED_LANGUAGES = [
        'de', 'fr', 'it', 'es', 'nl', 'pl', 'pt', 'pt-br', 'pt-pt', 'ru', 'ja',
    ];

    public function getIdentifier(): string
    {
        return 'deepl';
    }

    public function getName(): string
    {
        return 'DeepL Translation';
    }

    public static function getPriority(): int
    {
        return 90;
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = [],
    ): TranslatorResult {
        $this->ensureAvailable();

        $targetLanguage = $this->normalizeLanguageCode($targetLanguage, false);
        if ($sourceLanguage !== null) {
            $sourceLanguage = $this->normalizeLanguageCode($sourceLanguage, true);
        }

        $payload = $this->buildTranslatePayload($text, $targetLanguage, $sourceLanguage, $options);

        $response = $this->sendDeeplRequest('translate', $payload);

        /** @var array<int, array{text: string, detected_source_language?: string}> $translations */
        $translations = $response['translations'] ?? [];
        if ($translations === []) {
            throw new ServiceUnavailableException(
                'DeepL returned empty translation response',
                'translation',
                ['provider' => 'deepl'],
            );
        }

        $translation = $translations[0];
        $detectedSourceLanguage = strtolower($translation['detected_source_language'] ?? $sourceLanguage ?? 'en');

        $this->usageTracker->trackUsage('translation', 'deepl', [
            'characters' => mb_strlen($text),
        ]);

        return new TranslatorResult(
            translatedText: $translation['text'],
            sourceLanguage: $detectedSourceLanguage,
            targetLanguage: strtolower($targetLanguage),
            translator: 'deepl',
            confidence: 0.95, // DeepL has high accuracy
            metadata: [
                'detected_source_language' => $detectedSourceLanguage,
                'billed_characters' => $this->countBilledCharacters($text),
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

        $this->ensureAvailable();

        $targetLanguage = $this->normalizeLanguageCode($targetLanguage, false);
        if ($sourceLanguage !== null) {
            $sourceLanguage = $this->normalizeLanguageCode($sourceLanguage, true);
        }

        $payload = $this->buildBatchPayload($texts, $targetLanguage, $sourceLanguage, $options);

        $response = $this->sendDeeplRequest('translate', $payload);

        /** @var array<int, array{text: string, detected_source_language?: string}> $translations */
        $translations = $response['translations'] ?? [];
        $results = [];

        foreach ($translations as $index => $translation) {
            $detectedSourceLanguage = strtolower($translation['detected_source_language'] ?? $sourceLanguage ?? 'en');

            $results[] = new TranslatorResult(
                translatedText: $translation['text'],
                sourceLanguage: $detectedSourceLanguage,
                targetLanguage: strtolower($targetLanguage),
                translator: 'deepl',
                confidence: 0.95,
                metadata: [
                    'detected_source_language' => $detectedSourceLanguage,
                    'billed_characters' => $this->countBilledCharacters($texts[$index] ?? ''),
                ],
            );
        }

        $totalCharacters = array_sum(array_map(mb_strlen(...), $texts));
        $this->usageTracker->trackUsage('translation', 'deepl', [
            'characters' => $totalCharacters,
            'batch_size' => count($texts),
        ]);

        return $results;
    }

    public function getSupportedLanguages(): array
    {
        return array_values(array_unique(array_merge(
            self::SUPPORTED_SOURCE_LANGUAGES,
            array_map(
                fn(string $lang) => explode('-', $lang)[0],
                self::SUPPORTED_TARGET_LANGUAGES,
            ),
        )));
    }

    public function detectLanguage(string $text): string
    {
        $this->ensureAvailable();

        $payload = [
            'text' => [substr($text, 0, 100)],
            'target_lang' => 'EN',
        ];

        $response = $this->sendDeeplRequest('translate', $payload);

        /** @var array<int, array{text: string, detected_source_language?: string}> $translations */
        $translations = $response['translations'] ?? [];
        if ($translations === []) {
            return 'en';
        }

        return strtolower($translations[0]['detected_source_language'] ?? 'en');
    }

    public function supportsLanguagePair(string $sourceLanguage, string $targetLanguage): bool
    {
        $sourceLang = $this->normalizeLanguageCode($sourceLanguage, true);
        $targetLang = $this->normalizeLanguageCode($targetLanguage, false);

        $sourceSupported = in_array(strtolower($sourceLang), self::SUPPORTED_SOURCE_LANGUAGES, true);
        $targetSupported = in_array(strtolower($targetLang), self::SUPPORTED_TARGET_LANGUAGES, true);

        return $sourceSupported && $targetSupported;
    }

    /**
     * Check if formality is supported for target language.
     */
    public function supportsFormality(string $targetLanguage): bool
    {
        $normalizedLang = strtolower($this->normalizeLanguageCode($targetLanguage, false));
        $baseLang = explode('-', $normalizedLang)[0];

        return in_array($baseLang, self::FORMALITY_SUPPORTED_LANGUAGES, true)
            || in_array($normalizedLang, self::FORMALITY_SUPPORTED_LANGUAGES, true);
    }

    /**
     * Get current API usage statistics.
     *
     * @return array{character_count: int, character_limit: int}
     */
    public function getUsage(): array
    {
        $this->ensureAvailable();

        $response = $this->sendDeeplRequest('usage', [], 'GET');

        $characterCount = $response['character_count'] ?? 0;
        $characterLimit = $response['character_limit'] ?? 0;

        return [
            'character_count' => is_int($characterCount) ? $characterCount : 0,
            'character_limit' => is_int($characterLimit) ? $characterLimit : 0,
        ];
    }

    /**
     * Get available glossaries.
     *
     * @return array<int, array{glossary_id: string, name: string, source_lang: string, target_lang: string}>
     */
    public function getGlossaries(): array
    {
        $this->ensureAvailable();

        $response = $this->sendDeeplRequest('glossaries', [], 'GET');

        /** @var array<int, array{glossary_id: string, name: string, source_lang: string, target_lang: string}> $glossaries */
        $glossaries = $response['glossaries'] ?? [];

        return $glossaries;
    }

    protected function getServiceDomain(): string
    {
        return 'translation';
    }

    protected function getServiceProvider(): string
    {
        return 'deepl';
    }

    protected function getDefaultBaseUrl(): string
    {
        return self::PRO_API_URL;
    }

    protected function getDefaultTimeout(): int
    {
        return 30;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function loadServiceConfiguration(array $config): void
    {
        $translators = $config['translators'] ?? null;
        $deeplConfig = is_array($translators) && is_array($translators['deepl'] ?? null)
            ? $translators['deepl']
            : [];

        // is_string() / is_numeric() guards: extension config is YAML
        // and the documented shape is not a runtime guarantee. Direct
        // assignment would TypeError on a non-string apiKey and defeat
        // the base's fail-soft contract.
        $apiKey = $deeplConfig['apiKey'] ?? null;
        $this->apiKey = is_string($apiKey) ? $apiKey : '';

        $timeout = $deeplConfig['timeout'] ?? null;
        $this->timeout = is_numeric($timeout) ? (int)$timeout : $this->getDefaultTimeout();

        // DeepL Free vs Pro routing: free keys end with `:fx`. The Pro
        // URL is the documented default; explicit `baseUrl` override
        // wins over both.
        if ($this->apiKey !== '' && str_ends_with($this->apiKey, ':fx')) {
            $this->baseUrl = self::FREE_API_URL;
        } else {
            $baseUrl = $deeplConfig['baseUrl'] ?? null;
            $this->baseUrl = is_string($baseUrl) ? $baseUrl : self::PRO_API_URL;
        }
    }

    protected function buildAuthHeaders(): array
    {
        return [
            'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
            'User-Agent'    => 'TYPO3-NrLlm/1.0',
        ];
    }

    protected function getProviderLabel(): string
    {
        return 'DeepL';
    }

    /**
     * DeepL surfaces a top-level `message` key (no nested `error.message`).
     */
    protected function decodeErrorMessage(string $responseBody): string
    {
        if ($responseBody === '') {
            return $this->unknownErrorLabel();
        }
        $error = json_decode($responseBody, true);
        if (!is_array($error)) {
            return $this->unknownErrorLabel();
        }
        $message = $error['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }
        return $this->unknownErrorLabel();
    }

    /**
     * DeepL surfaces a custom 456 quota-exceeded status that's worth
     * a distinct exception payload so consumers can branch on it.
     */
    protected function mapErrorStatus(int $statusCode, string $errorMessage): Throwable
    {
        if ($statusCode === 456) {
            return new ServiceUnavailableException(
                'DeepL API quota exceeded',
                $this->getServiceDomain(),
                ['provider' => $this->getServiceProvider()],
            );
        }
        return parent::mapErrorStatus($statusCode, $errorMessage);
    }

    /**
     * Normalize language code for DeepL API.
     *
     * @param string $languageCode ISO 639-1 code
     * @param bool   $isSource     Whether this is a source language
     *
     * @return string DeepL-compatible language code
     */
    private function normalizeLanguageCode(string $languageCode, bool $isSource): string
    {
        $code = strtoupper($languageCode);

        $mapping = [
            'NO' => 'NB',
            'ZH' => $isSource ? 'ZH' : 'ZH-HANS',
        ];

        return $mapping[$code] ?? $code;
    }

    /**
     * Build translate request payload.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildTranslatePayload(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage,
        array $options,
    ): array {
        $payload = [
            'text' => [$text],
            'target_lang' => $targetLanguage,
        ];

        if ($sourceLanguage !== null) {
            $payload['source_lang'] = $sourceLanguage;
        }

        $deepLOptions = isset($options['deepl']) && $options['deepl'] instanceof DeepLOptions
            ? $options['deepl']
            : DeepLOptions::fromArray($options);

        if ($deepLOptions->formality !== null && $this->supportsFormality($targetLanguage)) {
            $payload['formality'] = $this->mapFormality($deepLOptions->formality);
        }

        if ($deepLOptions->glossaryId !== null) {
            $payload['glossary_id'] = $deepLOptions->glossaryId;
        }

        if ($deepLOptions->preserveFormatting !== null) {
            $payload['preserve_formatting'] = $deepLOptions->preserveFormatting ? '1' : '0';
        }

        if ($deepLOptions->tagHandling !== null) {
            $payload['tag_handling'] = $deepLOptions->tagHandling;

            if ($deepLOptions->ignoreTags !== null) {
                $payload['ignore_tags'] = implode(',', $deepLOptions->ignoreTags);
            }

            if ($deepLOptions->nonSplittingTags !== null) {
                $payload['non_splitting_tags'] = implode(',', $deepLOptions->nonSplittingTags);
            }
        }

        if ($deepLOptions->splitSentences !== null) {
            $payload['split_sentences'] = $deepLOptions->splitSentences ? '1' : '0';
        }

        return $payload;
    }

    /**
     * Build batch translate request payload.
     *
     * @param array<int, string>   $texts
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildBatchPayload(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage,
        array $options,
    ): array {
        $payload = [
            'text' => array_values($texts),
            'target_lang' => $targetLanguage,
        ];

        if ($sourceLanguage !== null) {
            $payload['source_lang'] = $sourceLanguage;
        }

        $deepLOptions = isset($options['deepl']) && $options['deepl'] instanceof DeepLOptions
            ? $options['deepl']
            : DeepLOptions::fromArray($options);

        if ($deepLOptions->formality !== null && $this->supportsFormality($targetLanguage)) {
            $payload['formality'] = $this->mapFormality($deepLOptions->formality);
        }

        if ($deepLOptions->glossaryId !== null) {
            $payload['glossary_id'] = $deepLOptions->glossaryId;
        }

        if ($deepLOptions->preserveFormatting !== null) {
            $payload['preserve_formatting'] = $deepLOptions->preserveFormatting ? '1' : '0';
        }

        if ($deepLOptions->tagHandling !== null) {
            $payload['tag_handling'] = $deepLOptions->tagHandling;
        }

        return $payload;
    }

    /**
     * Map generic formality to DeepL formality values.
     */
    private function mapFormality(string $formality): string
    {
        return match ($formality) {
            'formal', 'more' => 'more',
            'informal', 'less' => 'less',
            'prefer_more' => 'prefer_more',
            'prefer_less' => 'prefer_less',
            default => 'default',
        };
    }

    /**
     * Count billed characters (DeepL billing).
     *
     * DeepL bills based on character count including whitespace.
     */
    private function countBilledCharacters(string $text): int
    {
        return mb_strlen($text);
    }

    /**
     * Send request to DeepL API. Custom variant of the base's
     * `sendJsonRequest()` that adds the API version prefix to the
     * URL, supports GET as well as POST, and reuses the base's
     * `executeRequest()` for status / error handling.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ServiceUnavailableException
     * @throws ServiceConfigurationException
     *
     * @return array<string, mixed>
     */
    private function sendDeeplRequest(string $endpoint, array $payload, string $method = 'POST'): array
    {
        $url = sprintf(
            '%s/%s/%s',
            rtrim($this->baseUrl, '/'),
            self::API_VERSION,
            ltrim($endpoint, '/'),
        );

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Content-Type', 'application/json');
        foreach ($this->buildAuthHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($method === 'POST' && $payload !== []) {
            $request = $request->withBody(
                $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)),
            );
        }

        return $this->executeRequest($request);
    }
}
