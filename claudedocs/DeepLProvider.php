<?php

declare(strict_types=1);

namespace Netresearch\AiBase\Service\Provider;

use Netresearch\AiBase\Domain\Model\TranslationResponse;
use Netresearch\AiBase\Domain\Model\CompletionResponse;
use Netresearch\AiBase\Domain\Model\EmbeddingResponse;
use Netresearch\AiBase\Domain\Model\VisionResponse;
use Netresearch\AiBase\Exception\ProviderException;
use Netresearch\AiBase\Exception\ConfigurationException;
use Netresearch\AiBase\Exception\NotSupportedException;
use TYPO3\CMS\Core\Http\RequestFactory;
use Psr\Log\LoggerInterface;

/**
 * DeepL Translation Provider
 *
 * Specialized translation-only provider using DeepL API.
 * This provider ONLY supports translation and throws NotSupportedException
 * for all other AI operations (completion, embeddings, vision).
 *
 * Features:
 * - High-quality neural translation
 * - 31+ languages supported
 * - Formality control (formal/informal)
 * - HTML/XML tag preservation
 * - Glossary support for consistent terminology
 * - Document translation (PDF, DOCX, etc.)
 *
 * Design Decision:
 * DeepL is fundamentally different from LLM providers. Instead of trying
 * to make it fit the full ProviderInterface, we implement only what it
 * does best: translation. All other methods throw NotSupportedException
 * with clear error messages.
 *
 * @see https://www.deepl.com/docs-api
 */
class DeepLProvider extends AbstractProvider
{
    private const API_BASE_URL_FREE = 'https://api-free.deepl.com/v2';
    private const API_BASE_URL_PRO = 'https://api.deepl.com/v2';

    /**
     * Supported languages (source and target)
     */
    private const LANGUAGES = [
        'BG' => 'Bulgarian',
        'CS' => 'Czech',
        'DA' => 'Danish',
        'DE' => 'German',
        'EL' => 'Greek',
        'EN' => 'English',
        'ES' => 'Spanish',
        'ET' => 'Estonian',
        'FI' => 'Finnish',
        'FR' => 'French',
        'HU' => 'Hungarian',
        'ID' => 'Indonesian',
        'IT' => 'Italian',
        'JA' => 'Japanese',
        'KO' => 'Korean',
        'LT' => 'Lithuanian',
        'LV' => 'Latvian',
        'NB' => 'Norwegian (Bokmål)',
        'NL' => 'Dutch',
        'PL' => 'Polish',
        'PT' => 'Portuguese',
        'RO' => 'Romanian',
        'RU' => 'Russian',
        'SK' => 'Slovak',
        'SL' => 'Slovenian',
        'SV' => 'Swedish',
        'TR' => 'Turkish',
        'UK' => 'Ukrainian',
        'ZH' => 'Chinese',
    ];

    /**
     * Target-only language variants
     */
    private const TARGET_VARIANTS = [
        'EN-GB' => 'English (British)',
        'EN-US' => 'English (American)',
        'PT-BR' => 'Portuguese (Brazilian)',
        'PT-PT' => 'Portuguese (European)',
    ];

    /**
     * Formality options
     */
    private const FORMALITY_OPTIONS = [
        'default',
        'more',      // More formal
        'less',      // Less formal
        'prefer_more',
        'prefer_less',
    ];

    /**
     * Split sentence options
     */
    private const SPLIT_SENTENCES = [
        '0' => 'No splitting',
        '1' => 'Split on punctuation and newlines',
        'nonewlines' => 'Split on punctuation only',
    ];

    private string $apiKey;
    private string $tier; // 'free' or 'pro'
    private string $baseUrl;
    private string $defaultFormality;
    private bool $preserveHtml;
    private RequestFactory $requestFactory;
    private LoggerInterface $logger;

    public function __construct(
        array $configuration,
        RequestFactory $requestFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($configuration);

        if (empty($configuration['apiKey'])) {
            throw new ConfigurationException('DeepL API key is required');
        }

        $this->apiKey = $configuration['apiKey'];
        $this->tier = $configuration['tier'] ?? 'free';
        $this->baseUrl = $this->tier === 'pro'
            ? self::API_BASE_URL_PRO
            : self::API_BASE_URL_FREE;
        $this->defaultFormality = $configuration['formality'] ?? 'default';
        $this->preserveHtml = $configuration['preserveHtml'] ?? true;
        $this->requestFactory = $requestFactory;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException DeepL only supports translation
     */
    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        throw new NotSupportedException(
            'DeepL provider does not support completion. Use translate() instead, ' .
            'or switch to an LLM provider (OpenAI, Anthropic, Gemini).'
        );
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException DeepL only supports translation
     */
    public function stream(string $prompt, callable $callback, array $options = []): void
    {
        throw new NotSupportedException(
            'DeepL provider does not support streaming. ' .
            'Use an LLM provider (OpenAI, Anthropic, Gemini) for streaming.'
        );
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException DeepL only supports translation
     */
    public function embed(string|array $text, array $options = []): EmbeddingResponse
    {
        throw new NotSupportedException(
            'DeepL provider does not support embeddings. ' .
            'Use OpenAI or Gemini for embedding generation.'
        );
    }

    /**
     * @inheritDoc
     * @throws NotSupportedException DeepL only supports translation
     */
    public function analyzeImage(string $imageUrl, string $prompt, array $options = []): VisionResponse
    {
        throw new NotSupportedException(
            'DeepL provider does not support vision/image analysis. ' .
            'Use OpenAI GPT-4V, Gemini, or Anthropic Claude for vision tasks.'
        );
    }

    /**
     * @inheritDoc
     *
     * This is the ONLY method that DeepL supports.
     * High-quality neural translation with formality control and glossary support.
     */
    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslationResponse {
        // Validate target language
        $targetLanguage = strtoupper($targetLanguage);
        if (!$this->isLanguageSupported($targetLanguage, 'target')) {
            throw new ProviderException(
                "Unsupported target language: {$targetLanguage}",
                ['supported_languages' => array_keys(self::LANGUAGES + self::TARGET_VARIANTS)]
            );
        }

        // Validate source language (if provided)
        if ($sourceLanguage !== null) {
            $sourceLanguage = strtoupper($sourceLanguage);
            if (!$this->isLanguageSupported($sourceLanguage, 'source')) {
                throw new ProviderException(
                    "Unsupported source language: {$sourceLanguage}",
                    ['supported_languages' => array_keys(self::LANGUAGES)]
                );
            }
        }

        // Build request body
        $requestBody = $this->buildTranslationRequest(
            $text,
            $targetLanguage,
            $sourceLanguage,
            $options
        );

        // Make API request
        $response = $this->makeRequest('POST', '/translate', $requestBody);

        return $this->parseTranslationResponse($response, $targetLanguage);
    }

    /**
     * Translate multiple texts in a single request (batch translation)
     *
     * @param array $texts Array of texts to translate
     * @param string $targetLanguage Target language code
     * @param string|null $sourceLanguage Source language code (null = auto-detect)
     * @param array $options Additional options
     * @return array Array of TranslationResponse objects
     */
    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): array {
        $targetLanguage = strtoupper($targetLanguage);

        $requestBody = $this->buildTranslationRequest(
            $texts,
            $targetLanguage,
            $sourceLanguage,
            $options
        );

        $response = $this->makeRequest('POST', '/translate', $requestBody);

        $results = [];
        foreach ($response['translations'] as $translation) {
            $results[] = new TranslationResponse(
                translation: $translation['text'],
                sourceLanguage: $translation['detected_source_language'] ?? $sourceLanguage ?? 'auto',
                targetLanguage: $targetLanguage,
                confidence: 0.95, // DeepL doesn't provide confidence scores
                alternatives: [],
                metadata: [
                    'provider' => 'deepl',
                    'detected_source' => $translation['detected_source_language'] ?? null,
                ]
            );
        }

        return $results;
    }

    /**
     * Get usage statistics (characters used vs limit)
     *
     * @return array Usage information
     */
    public function getUsage(): array
    {
        $response = $this->makeRequest('GET', '/usage');

        return [
            'character_count' => $response['character_count'] ?? 0,
            'character_limit' => $response['character_limit'] ?? 0,
            'usage_percent' => $this->calculateUsagePercent(
                $response['character_count'] ?? 0,
                $response['character_limit'] ?? 0
            ),
        ];
    }

    /**
     * Get list of supported languages
     *
     * @param string $type 'source' or 'target'
     * @return array Language codes and names
     */
    public function getSupportedLanguages(string $type = 'target'): array
    {
        $response = $this->makeRequest('GET', '/languages', ['type' => $type]);

        $languages = [];
        foreach ($response as $lang) {
            $languages[$lang['language']] = $lang['name'];
        }

        return $languages;
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities(): array
    {
        return [
            'completion' => false,
            'streaming' => false,
            'vision' => false,
            'embeddings' => false,
            'translation' => true, // ONLY translation
            'formality_control' => true,
            'glossary_support' => true,
            'html_preservation' => true,
            'document_translation' => true,
            'batch_translation' => true,
            'supported_languages' => array_keys(self::LANGUAGES),
            'supported_target_variants' => array_keys(self::TARGET_VARIANTS),
        ];
    }

    /**
     * @inheritDoc
     */
    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        // DeepL charges per character, not tokens
        // Rough estimate: 1 token ≈ 4 characters
        $characters = $inputTokens * 4;

        // Pricing (approximate as of Dec 2024)
        $pricePerMillionChars = match($this->tier) {
            'free' => 0.0, // Free tier
            'pro' => 25.0, // $25 per million characters
            default => 0.0,
        };

        return ($characters / 1_000_000) * $pricePerMillionChars;
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        try {
            $this->getUsage();
            return true;
        } catch (\Exception $e) {
            $this->logger->error('DeepL availability check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Build translation request body
     */
    private function buildTranslationRequest(
        string|array $text,
        string $targetLanguage,
        ?string $sourceLanguage,
        array $options
    ): array {
        $body = [
            'text' => is_array($text) ? $text : [$text],
            'target_lang' => $targetLanguage,
        ];

        if ($sourceLanguage !== null) {
            $body['source_lang'] = $sourceLanguage;
        }

        // Formality
        $formality = $options['formality'] ?? $this->defaultFormality;
        if ($formality !== 'default' && $this->supportsFormality($targetLanguage)) {
            $body['formality'] = $formality;
        }

        // Preserve formatting
        if ($options['preserve_formatting'] ?? false) {
            $body['preserve_formatting'] = '1';
        }

        // Tag handling
        if ($this->preserveHtml || ($options['tag_handling'] ?? false)) {
            $body['tag_handling'] = $options['tag_handling'] ?? 'html';
        }

        // Split sentences
        if (isset($options['split_sentences'])) {
            $body['split_sentences'] = $options['split_sentences'];
        }

        // Glossary
        if (isset($options['glossary_id'])) {
            $body['glossary_id'] = $options['glossary_id'];
        }

        return $body;
    }

    /**
     * Parse translation response
     */
    private function parseTranslationResponse(array $response, string $targetLanguage): TranslationResponse
    {
        if (empty($response['translations'])) {
            throw new ProviderException('No translations in DeepL response');
        }

        $translation = $response['translations'][0];

        return new TranslationResponse(
            translation: $translation['text'],
            sourceLanguage: $translation['detected_source_language'] ?? 'auto',
            targetLanguage: $targetLanguage,
            confidence: 0.95, // DeepL doesn't provide confidence
            alternatives: [],
            metadata: [
                'provider' => 'deepl',
                'detected_source' => $translation['detected_source_language'] ?? null,
            ]
        );
    }

    /**
     * Check if language is supported
     */
    private function isLanguageSupported(string $language, string $type): bool
    {
        if ($type === 'target') {
            return isset(self::LANGUAGES[$language]) || isset(self::TARGET_VARIANTS[$language]);
        }

        return isset(self::LANGUAGES[$language]);
    }

    /**
     * Check if language supports formality control
     */
    private function supportsFormality(string $language): bool
    {
        // DeepL formality support (as of Dec 2024)
        return in_array($language, [
            'DE', 'FR', 'IT', 'ES', 'NL', 'PL', 'PT-BR', 'PT-PT', 'JA', 'RU'
        ]);
    }

    /**
     * Calculate usage percentage
     */
    private function calculateUsagePercent(int $used, int $limit): float
    {
        if ($limit === 0) {
            return 0.0;
        }

        return round(($used / $limit) * 100, 2);
    }

    /**
     * Make HTTP request to DeepL API
     */
    private function makeRequest(string $method, string $endpoint, array $params = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $options = [
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
            ],
        ];

        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
        } else {
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            $options['body'] = http_build_query($params);
        }

        $response = $this->requestFactory->request($url, $method, $options);

        $statusCode = $response->getStatusCode();
        $responseBody = json_decode($response->getBody()->getContents(), true);

        if ($statusCode !== 200) {
            $this->handleError($statusCode, $responseBody);
        }

        return $responseBody;
    }

    /**
     * Handle API errors
     */
    private function handleError(int $statusCode, ?array $response): void
    {
        $message = $response['message'] ?? 'Unknown DeepL API error';

        $errorMessage = match($statusCode) {
            400 => "Bad request: {$message}",
            403 => 'Invalid DeepL API key',
            404 => 'Resource not found',
            413 => 'Request too large',
            414 => 'URL too long',
            429 => 'Too many requests',
            456 => 'Quota exceeded',
            503 => 'DeepL service unavailable',
            529 => 'Too many requests',
            default => "DeepL API error ({$statusCode}): {$message}",
        };

        throw new ProviderException(
            $errorMessage,
            [
                'status_code' => $statusCode,
                'response' => $response,
                'provider' => 'deepl'
            ]
        );
    }
}
