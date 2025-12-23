<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Option\DeepLOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

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
 * @see https://developers.deepl.com/docs
 */
final class DeepLTranslator implements TranslatorInterface
{
    private const API_VERSION = 'v2';
    private const FREE_API_URL = 'https://api-free.deepl.com';
    private const PRO_API_URL = 'https://api.deepl.com';

    /**
     * DeepL supported source languages (ISO 639-1 codes).
     */
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

    /**
     * Languages that support formality control.
     */
    private const FORMALITY_SUPPORTED_LANGUAGES = [
        'de', 'fr', 'it', 'es', 'nl', 'pl', 'pt', 'pt-br', 'pt-pt', 'ru', 'ja',
    ];

    private string $apiKey = '';
    private string $baseUrl = '';
    private int $timeout = 30;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly UsageTrackerServiceInterface $usageTracker,
        private readonly LoggerInterface $logger,
    ) {
        $this->loadConfiguration();
    }

    public function getIdentifier(): string
    {
        return 'deepl';
    }

    public function getName(): string
    {
        return 'DeepL Translation';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslatorResult {
        $this->ensureAvailable();

        // Normalize language codes for DeepL
        $targetLanguage = $this->normalizeLanguageCode($targetLanguage, false);
        if ($sourceLanguage !== null) {
            $sourceLanguage = $this->normalizeLanguageCode($sourceLanguage, true);
        }

        // Build request payload
        $payload = $this->buildTranslatePayload($text, $targetLanguage, $sourceLanguage, $options);

        // Execute request
        $response = $this->sendRequest('translate', $payload);

        // Parse response
        $translations = $response['translations'] ?? [];
        if (empty($translations)) {
            throw new ServiceUnavailableException(
                'DeepL returned empty translation response',
                'translation',
                ['provider' => 'deepl']
            );
        }

        $translation = $translations[0];
        $detectedSourceLanguage = strtolower($translation['detected_source_language'] ?? $sourceLanguage ?? 'en');

        // Track usage
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
            ]
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): array {
        if (empty($texts)) {
            return [];
        }

        $this->ensureAvailable();

        // Normalize language codes
        $targetLanguage = $this->normalizeLanguageCode($targetLanguage, false);
        if ($sourceLanguage !== null) {
            $sourceLanguage = $this->normalizeLanguageCode($sourceLanguage, true);
        }

        // Build batch payload
        $payload = $this->buildBatchPayload($texts, $targetLanguage, $sourceLanguage, $options);

        // Execute request
        $response = $this->sendRequest('translate', $payload);

        // Parse response
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
                ]
            );
        }

        // Track batch usage
        $totalCharacters = array_sum(array_map('mb_strlen', $texts));
        $this->usageTracker->trackUsage('translation', 'deepl', [
            'characters' => $totalCharacters,
            'batch_size' => count($texts),
        ]);

        return $results;
    }

    public function getSupportedLanguages(): array
    {
        // Return union of source and target languages
        return array_values(array_unique(array_merge(
            self::SUPPORTED_SOURCE_LANGUAGES,
            array_map(
                fn(string $lang) => explode('-', $lang)[0], // Normalize to base codes
                self::SUPPORTED_TARGET_LANGUAGES
            )
        )));
    }

    public function detectLanguage(string $text): string
    {
        $this->ensureAvailable();

        // DeepL doesn't have a dedicated language detection endpoint
        // We use translation with auto-detection to get the source language
        $payload = [
            'text' => [substr($text, 0, 100)], // Use first 100 chars for detection
            'target_lang' => 'EN', // Translate to English for detection
        ];

        $response = $this->sendRequest('translate', $payload);

        $translations = $response['translations'] ?? [];
        if (empty($translations)) {
            return 'en'; // Fallback
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

        $response = $this->sendRequest('usage', [], 'GET');

        return [
            'character_count' => $response['character_count'] ?? 0,
            'character_limit' => $response['character_limit'] ?? 0,
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

        $response = $this->sendRequest('glossaries', [], 'GET');

        return $response['glossaries'] ?? [];
    }

    /**
     * Load configuration from extension settings.
     */
    private function loadConfiguration(): void
    {
        try {
            $config = $this->extensionConfiguration->get('nr_llm');

            $this->apiKey = (string)($config['translators']['deepl']['apiKey'] ?? '');
            $this->timeout = (int)($config['translators']['deepl']['timeout'] ?? 30);

            // Determine API URL based on API key type (free keys end with :fx)
            if ($this->apiKey !== '' && str_ends_with($this->apiKey, ':fx')) {
                $this->baseUrl = self::FREE_API_URL;
            } else {
                $this->baseUrl = (string)($config['translators']['deepl']['baseUrl'] ?? self::PRO_API_URL);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to load DeepL configuration', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure translator is available.
     *
     * @throws ServiceUnavailableException
     */
    private function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw ServiceUnavailableException::notConfigured('translation', 'deepl');
        }
    }

    /**
     * Normalize language code for DeepL API.
     *
     * @param string $languageCode ISO 639-1 code
     * @param bool $isSource Whether this is a source language
     * @return string DeepL-compatible language code
     */
    private function normalizeLanguageCode(string $languageCode, bool $isSource): string
    {
        $code = strtoupper($languageCode);

        // DeepL uses specific codes for some languages
        $mapping = [
            'NO' => 'NB', // Norwegian -> Norwegian Bokmål
            'ZH' => $isSource ? 'ZH' : 'ZH-HANS', // Chinese -> Simplified Chinese for targets
        ];

        return $mapping[$code] ?? $code;
    }

    /**
     * Build translate request payload.
     *
     * @return array<string, mixed>
     */
    private function buildTranslatePayload(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage,
        array $options
    ): array {
        $payload = [
            'text' => [$text],
            'target_lang' => $targetLanguage,
        ];

        if ($sourceLanguage !== null) {
            $payload['source_lang'] = $sourceLanguage;
        }

        // Apply DeepL-specific options
        $deepLOptions = isset($options['deepl']) && $options['deepl'] instanceof DeepLOptions
            ? $options['deepl']
            : DeepLOptions::fromArray($options);

        $optionsArray = $deepLOptions->toArray();

        // Formality (only if supported)
        if (isset($optionsArray['formality']) && $this->supportsFormality($targetLanguage)) {
            $payload['formality'] = $this->mapFormality($optionsArray['formality']);
        }

        // Glossary
        if (isset($optionsArray['glossary_id'])) {
            $payload['glossary_id'] = $optionsArray['glossary_id'];
        }

        // Formatting preservation
        if (isset($optionsArray['preserve_formatting'])) {
            $payload['preserve_formatting'] = $optionsArray['preserve_formatting'] ? '1' : '0';
        }

        // Tag handling
        if (isset($optionsArray['tag_handling'])) {
            $payload['tag_handling'] = $optionsArray['tag_handling'];

            if (isset($optionsArray['ignore_tags'])) {
                $payload['ignore_tags'] = implode(',', $optionsArray['ignore_tags']);
            }

            if (isset($optionsArray['non_splitting_tags'])) {
                $payload['non_splitting_tags'] = implode(',', $optionsArray['non_splitting_tags']);
            }
        }

        // Split sentences
        if (isset($optionsArray['split_sentences'])) {
            $payload['split_sentences'] = $optionsArray['split_sentences'] ? '1' : '0';
        }

        return $payload;
    }

    /**
     * Build batch translate request payload.
     *
     * @param array<int, string> $texts
     * @return array<string, mixed>
     */
    private function buildBatchPayload(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage,
        array $options
    ): array {
        $payload = [
            'text' => array_values($texts),
            'target_lang' => $targetLanguage,
        ];

        if ($sourceLanguage !== null) {
            $payload['source_lang'] = $sourceLanguage;
        }

        // Apply same options as single translation
        $deepLOptions = isset($options['deepl']) && $options['deepl'] instanceof DeepLOptions
            ? $options['deepl']
            : DeepLOptions::fromArray($options);

        $optionsArray = $deepLOptions->toArray();

        if (isset($optionsArray['formality']) && $this->supportsFormality($targetLanguage)) {
            $payload['formality'] = $this->mapFormality($optionsArray['formality']);
        }

        if (isset($optionsArray['glossary_id'])) {
            $payload['glossary_id'] = $optionsArray['glossary_id'];
        }

        if (isset($optionsArray['preserve_formatting'])) {
            $payload['preserve_formatting'] = $optionsArray['preserve_formatting'] ? '1' : '0';
        }

        if (isset($optionsArray['tag_handling'])) {
            $payload['tag_handling'] = $optionsArray['tag_handling'];
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
     * Send request to DeepL API.
     *
     * @param string $endpoint API endpoint (without version prefix)
     * @param array<string, mixed> $payload Request payload
     * @param string $method HTTP method
     * @return array<string, mixed> Response data
     * @throws ServiceUnavailableException
     */
    private function sendRequest(string $endpoint, array $payload, string $method = 'POST'): array
    {
        $url = sprintf('%s/%s/%s', rtrim($this->baseUrl, '/'), self::API_VERSION, ltrim($endpoint, '/'));

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Authorization', 'DeepL-Auth-Key ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('User-Agent', 'TYPO3-NrLlm/1.0');

        if ($method === 'POST' && $payload !== []) {
            $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
            $request = $request->withBody($body);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            }

            $error = json_decode($body, true) ?? [];
            $errorMessage = $error['message'] ?? 'Unknown DeepL API error';

            $this->logger->error('DeepL API error', [
                'status_code' => $statusCode,
                'error' => $errorMessage,
                'endpoint' => $endpoint,
            ]);

            throw match ($statusCode) {
                401, 403 => ServiceConfigurationException::invalidApiKey('translation', 'deepl'),
                429 => new ServiceUnavailableException('DeepL API rate limit exceeded', 'translation', ['provider' => 'deepl']),
                456 => new ServiceUnavailableException('DeepL API quota exceeded', 'translation', ['provider' => 'deepl']),
                default => new ServiceUnavailableException('DeepL API error: ' . $errorMessage, 'translation', ['provider' => 'deepl']),
            };
        } catch (ServiceUnavailableException|ServiceConfigurationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('DeepL API connection error', [
                'exception' => $e->getMessage(),
                'endpoint' => $endpoint,
            ]);

            throw new ServiceUnavailableException(
                'Failed to connect to DeepL API: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
