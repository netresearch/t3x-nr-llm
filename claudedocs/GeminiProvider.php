<?php

declare(strict_types=1);

namespace Netresearch\AiBase\Service\Provider;

use Netresearch\AiBase\Domain\Model\AiResponse;
use Netresearch\AiBase\Domain\Model\CompletionResponse;
use Netresearch\AiBase\Domain\Model\EmbeddingResponse;
use Netresearch\AiBase\Domain\Model\VisionResponse;
use Netresearch\AiBase\Domain\Model\TranslationResponse;
use Netresearch\AiBase\Exception\ProviderException;
use Netresearch\AiBase\Exception\ConfigurationException;
use TYPO3\CMS\Core\Http\RequestFactory;
use Psr\Log\LoggerInterface;

/**
 * Google Gemini Provider
 *
 * Implements Google's Generative Language API with native multimodal support.
 *
 * Features:
 * - Native multimodal (text + images in single request)
 * - Configurable safety settings
 * - High-quality embeddings (text-embedding-004)
 * - Long context support (Gemini 1.5 Pro: 2M tokens)
 * - Competitive pricing
 *
 * @see https://ai.google.dev/docs
 */
class GeminiProvider extends AbstractProvider
{
    private const API_BASE_URL = 'https://generativelanguage.googleapis.com';
    private const API_VERSION = 'v1beta';

    private const DEFAULT_MODEL = 'gemini-1.5-flash';
    private const DEFAULT_EMBEDDING_MODEL = 'text-embedding-004';

    /**
     * Safety category thresholds
     */
    private const HARM_CATEGORIES = [
        'HARM_CATEGORY_HARASSMENT',
        'HARM_CATEGORY_HATE_SPEECH',
        'HARM_CATEGORY_SEXUALLY_EXPLICIT',
        'HARM_CATEGORY_DANGEROUS_CONTENT',
    ];

    private const SAFETY_THRESHOLDS = [
        'BLOCK_NONE',
        'BLOCK_ONLY_HIGH',
        'BLOCK_MEDIUM_AND_ABOVE',
        'BLOCK_LOW_AND_ABOVE',
    ];

    /**
     * Model pricing per 1M tokens (as of Dec 2024)
     */
    private const MODEL_PRICING = [
        'gemini-2.0-flash-exp' => ['input' => 0.0, 'output' => 0.0], // Free tier
        'gemini-1.5-pro' => [
            'input_128k' => 1.25,
            'input_above' => 2.50,
            'output_128k' => 5.00,
            'output_above' => 10.00,
        ],
        'gemini-1.5-flash' => [
            'input_128k' => 0.075,
            'input_above' => 0.15,
            'output_128k' => 0.30,
            'output_above' => 0.60,
        ],
    ];

    private const EMBEDDING_PRICING = 0.00001; // per 1K tokens

    private string $apiKey;
    private string $model;
    private string $safetyLevel;
    private RequestFactory $requestFactory;
    private LoggerInterface $logger;

    public function __construct(
        array $configuration,
        RequestFactory $requestFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($configuration);

        if (empty($configuration['apiKey'])) {
            throw new ConfigurationException('Gemini API key is required');
        }

        $this->apiKey = $configuration['apiKey'];
        $this->model = $configuration['model'] ?? self::DEFAULT_MODEL;
        $this->safetyLevel = $configuration['safetyLevel'] ?? 'BLOCK_MEDIUM_AND_ABOVE';
        $this->requestFactory = $requestFactory;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        $model = $options['model'] ?? $this->model;
        $endpoint = $this->buildEndpoint($model, 'generateContent');

        $requestBody = $this->buildCompletionRequest($prompt, $options);

        $response = $this->makeRequest('POST', $endpoint, $requestBody);

        return $this->parseCompletionResponse($response);
    }

    /**
     * @inheritDoc
     */
    public function stream(string $prompt, callable $callback, array $options = []): void
    {
        $model = $options['model'] ?? $this->model;
        $endpoint = $this->buildEndpoint($model, 'streamGenerateContent');

        $requestBody = $this->buildCompletionRequest($prompt, $options);

        $this->makeStreamingRequest($endpoint, $requestBody, $callback);
    }

    /**
     * @inheritDoc
     */
    public function embed(string|array $text, array $options = []): EmbeddingResponse
    {
        $model = $options['model'] ?? self::DEFAULT_EMBEDDING_MODEL;
        $endpoint = sprintf(
            '%s/%s/models/%s:embedContent',
            self::API_BASE_URL,
            self::API_VERSION,
            $model
        );

        $texts = is_array($text) ? $text : [$text];
        $embeddings = [];

        foreach ($texts as $singleText) {
            $requestBody = [
                'content' => [
                    'parts' => [
                        ['text' => $singleText]
                    ]
                ]
            ];

            $response = $this->makeRequest('POST', $endpoint, $requestBody);
            $embeddings[] = $response['embedding']['values'] ?? [];
        }

        return new EmbeddingResponse(
            embeddings: $embeddings,
            model: $model,
            tokenUsage: [
                'total_tokens' => count($texts) * 100, // Approximate
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function analyzeImage(string $imageUrl, string $prompt, array $options = []): VisionResponse
    {
        $model = $options['model'] ?? $this->model;
        $endpoint = $this->buildEndpoint($model, 'generateContent');

        // Gemini supports native multimodal
        $requestBody = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                        $this->prepareImagePart($imageUrl, $options),
                    ],
                ],
            ],
            'generationConfig' => $this->buildGenerationConfig($options),
            'safetySettings' => $this->buildSafetySettings(),
        ];

        $response = $this->makeRequest('POST', $endpoint, $requestBody);

        return new VisionResponse(
            description: $this->extractText($response),
            confidence: $this->extractConfidence($response),
            metadata: [
                'safety_ratings' => $response['candidates'][0]['safetyRatings'] ?? [],
                'finish_reason' => $response['candidates'][0]['finishReason'] ?? 'UNKNOWN',
            ],
            tokenUsage: $this->extractTokenUsage($response)
        );
    }

    /**
     * @inheritDoc
     */
    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslationResponse {
        // Gemini uses completion API for translation
        $prompt = $this->buildTranslationPrompt($text, $targetLanguage, $sourceLanguage);

        $response = $this->complete($prompt, [
            'temperature' => 0.3, // Lower temperature for translation
            ...$options
        ]);

        return new TranslationResponse(
            translation: $response->getContent(),
            sourceLanguage: $sourceLanguage ?? 'auto',
            targetLanguage: $targetLanguage,
            confidence: 0.95, // Gemini doesn't provide confidence for translation
            alternatives: [],
            tokenUsage: $response->getTokenUsage()
        );
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities(): array
    {
        return [
            'completion' => true,
            'streaming' => true,
            'vision' => true,
            'embeddings' => true,
            'translation' => true, // Via completion
            'function_calling' => true,
            'json_mode' => true,
            'multimodal' => true, // Native multimodal support
            'max_context' => $this->getMaxContext(),
            'safety_filtering' => true,
        ];
    }

    /**
     * @inheritDoc
     */
    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model = $model ?? $this->model;

        if (!isset(self::MODEL_PRICING[$model])) {
            return 0.0;
        }

        $pricing = self::MODEL_PRICING[$model];

        // Free tier models
        if (isset($pricing['input']) && $pricing['input'] === 0.0) {
            return 0.0;
        }

        // Tiered pricing based on context length
        $contextThreshold = 128000;
        $inputCost = $inputTokens <= $contextThreshold
            ? ($inputTokens / 1_000_000) * $pricing['input_128k']
            : ($inputTokens / 1_000_000) * $pricing['input_above'];

        $outputCost = $outputTokens <= $contextThreshold
            ? ($outputTokens / 1_000_000) * $pricing['output_128k']
            : ($outputTokens / 1_000_000) * $pricing['output_above'];

        return $inputCost + $outputCost;
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(): bool
    {
        try {
            $endpoint = sprintf(
                '%s/%s/models',
                self::API_BASE_URL,
                self::API_VERSION
            );

            $this->makeRequest('GET', $endpoint);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Gemini availability check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Build API endpoint URL
     */
    private function buildEndpoint(string $model, string $operation): string
    {
        return sprintf(
            '%s/%s/models/%s:%s',
            self::API_BASE_URL,
            self::API_VERSION,
            $model,
            $operation
        );
    }

    /**
     * Build completion request body
     */
    private function buildCompletionRequest(string $prompt, array $options): array
    {
        $contents = [];

        // System instruction (if provided)
        if (!empty($options['system'])) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $options['system']]],
            ];
        }

        // User prompt
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $prompt]],
        ];

        return [
            'contents' => $contents,
            'generationConfig' => $this->buildGenerationConfig($options),
            'safetySettings' => $this->buildSafetySettings($options),
        ];
    }

    /**
     * Build generation configuration
     */
    private function buildGenerationConfig(array $options): array
    {
        $config = [
            'temperature' => $options['temperature'] ?? 0.7,
            'topK' => $options['topK'] ?? 40,
            'topP' => $options['topP'] ?? 0.95,
            'maxOutputTokens' => $options['max_tokens'] ?? 1024,
        ];

        if (!empty($options['stop_sequences'])) {
            $config['stopSequences'] = $options['stop_sequences'];
        }

        // JSON mode
        if ($options['response_format'] === 'json') {
            $config['responseMimeType'] = 'application/json';
        }

        return $config;
    }

    /**
     * Build safety settings
     */
    private function buildSafetySettings(array $options = []): array
    {
        $threshold = $options['safety_level'] ?? $this->safetyLevel;

        $settings = [];
        foreach (self::HARM_CATEGORIES as $category) {
            $settings[] = [
                'category' => $category,
                'threshold' => $threshold,
            ];
        }

        return $settings;
    }

    /**
     * Prepare image part for multimodal request
     */
    private function prepareImagePart(string $imageUrl, array $options): array
    {
        // Check if it's a URL or base64
        if (str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://')) {
            // Download and encode
            $imageData = file_get_contents($imageUrl);
            $base64 = base64_encode($imageData);
            $mimeType = $this->detectMimeType($imageUrl);
        } else {
            // Assume it's already base64
            $base64 = $imageUrl;
            $mimeType = $options['mime_type'] ?? 'image/jpeg';
        }

        return [
            'inline_data' => [
                'mime_type' => $mimeType,
                'data' => $base64,
            ],
        ];
    }

    /**
     * Parse completion response
     */
    private function parseCompletionResponse(array $response): CompletionResponse
    {
        if (empty($response['candidates'])) {
            throw new ProviderException('No candidates in Gemini response');
        }

        $candidate = $response['candidates'][0];

        // Check finish reason
        $finishReason = $candidate['finishReason'] ?? 'UNKNOWN';
        if ($finishReason === 'SAFETY') {
            throw new ProviderException('Content blocked by safety filters', [
                'safety_ratings' => $candidate['safetyRatings'] ?? []
            ]);
        }

        return new CompletionResponse(
            content: $this->extractText($response),
            model: $this->model,
            finishReason: $finishReason,
            tokenUsage: $this->extractTokenUsage($response),
            metadata: [
                'safety_ratings' => $candidate['safetyRatings'] ?? [],
            ]
        );
    }

    /**
     * Extract text from response
     */
    private function extractText(array $response): string
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];

        $texts = array_map(fn($part) => $part['text'] ?? '', $parts);

        return implode('', $texts);
    }

    /**
     * Extract token usage
     */
    private function extractTokenUsage(array $response): array
    {
        $usage = $response['usageMetadata'] ?? [];

        return [
            'prompt_tokens' => $usage['promptTokenCount'] ?? 0,
            'completion_tokens' => $usage['candidatesTokenCount'] ?? 0,
            'total_tokens' => $usage['totalTokenCount'] ?? 0,
        ];
    }

    /**
     * Extract confidence from safety ratings
     */
    private function extractConfidence(array $response): float
    {
        $safetyRatings = $response['candidates'][0]['safetyRatings'] ?? [];

        // If all safety ratings are NEGLIGIBLE, confidence is high
        $allSafe = true;
        foreach ($safetyRatings as $rating) {
            if (($rating['probability'] ?? 'UNKNOWN') !== 'NEGLIGIBLE') {
                $allSafe = false;
                break;
            }
        }

        return $allSafe ? 0.95 : 0.75;
    }

    /**
     * Get max context for current model
     */
    private function getMaxContext(): int
    {
        return match($this->model) {
            'gemini-1.5-pro' => 2_000_000,
            'gemini-1.5-flash' => 1_000_000,
            default => 32_000,
        };
    }

    /**
     * Build translation prompt
     */
    private function buildTranslationPrompt(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage
    ): string {
        $prompt = "Translate the following text to {$targetLanguage}";

        if ($sourceLanguage) {
            $prompt .= " from {$sourceLanguage}";
        }

        $prompt .= ". Only output the translation, nothing else.\n\n{$text}";

        return $prompt;
    }

    /**
     * Detect MIME type from URL
     */
    private function detectMimeType(string $url): string
    {
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));

        return match($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * Make HTTP request to Gemini API
     */
    private function makeRequest(string $method, string $endpoint, ?array $body = null): array
    {
        $url = $endpoint . '?key=' . $this->apiKey;

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        if ($body !== null) {
            $options['body'] = json_encode($body);
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
     * Make streaming request
     */
    private function makeStreamingRequest(string $endpoint, array $body, callable $callback): void
    {
        $url = $endpoint . '?key=' . $this->apiKey . '&alt=sse';

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'stream' => true,
        ];

        $response = $this->requestFactory->request($url, 'POST', $options);

        $stream = $response->getBody();

        while (!$stream->eof()) {
            $line = $this->readLine($stream);

            if (str_starts_with($line, 'data: ')) {
                $data = json_decode(substr($line, 6), true);
                $text = $this->extractText($data);

                if ($text) {
                    $callback($text);
                }
            }
        }
    }

    /**
     * Handle API errors
     */
    private function handleError(int $statusCode, array $response): void
    {
        $error = $response['error'] ?? [];
        $message = $error['message'] ?? 'Unknown Gemini API error';
        $code = $error['code'] ?? $statusCode;

        throw new ProviderException(
            "Gemini API error ({$code}): {$message}",
            ['status_code' => $statusCode, 'response' => $response]
        );
    }

    /**
     * Read line from stream
     */
    private function readLine($stream): string
    {
        $line = '';
        while (!$stream->eof()) {
            $char = $stream->read(1);
            if ($char === "\n") {
                break;
            }
            $line .= $char;
        }
        return $line;
    }
}
