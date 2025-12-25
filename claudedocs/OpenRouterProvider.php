<?php

declare(strict_types=1);

namespace Netresearch\AiBase\Service\Provider;

use Exception;
use Netresearch\AiBase\Domain\Model\CompletionResponse;
use Netresearch\AiBase\Domain\Model\EmbeddingResponse;
use Netresearch\AiBase\Domain\Model\TranslationResponse;
use Netresearch\AiBase\Domain\Model\VisionResponse;
use Netresearch\AiBase\Exception\ConfigurationException;
use Netresearch\AiBase\Exception\ProviderException;
use Netresearch\AiBase\Exception\QuotaExceededException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * OpenRouter Provider.
 *
 * Gateway to 100+ AI models from multiple providers via a single API.
 * OpenRouter provides OpenAI-compatible API with automatic fallback,
 * competitive pricing, and unified billing.
 *
 * Features:
 * - Single API key for 100+ models (Anthropic, OpenAI, Google, Meta, etc.)
 * - Automatic fallback to alternative models
 * - Exact cost tracking (no estimation needed)
 * - Competitive pricing (often cheaper than direct)
 * - Model routing based on cost/performance
 * - Credits system (no individual provider API keys needed)
 *
 * Supported Providers:
 * - Anthropic (Claude 3 family)
 * - OpenAI (GPT-4, GPT-3.5)
 * - Google (Gemini/PaLM)
 * - Meta (Llama 3)
 * - Mistral AI (Mixtral, Mistral)
 * - Cohere
 * - Many open-source models
 *
 * @see https://openrouter.ai/docs
 */
class OpenRouterProvider extends AbstractProvider
{
    private const API_BASE_URL = 'https://openrouter.ai/api/v1';
    private const MODELS_CACHE_KEY = 'openrouter_models';
    private const MODELS_CACHE_TTL = 86400; // 24 hours

    /** Routing strategies */
    private const ROUTING_STRATEGIES = [
        'cost_optimized' => 'Choose cheapest model that meets requirements',
        'performance' => 'Choose fastest model',
        'balanced' => 'Balance cost and performance',
        'explicit' => 'Use specified model only',
    ];

    private string $apiKey;
    private string $defaultModel;
    private bool $autoFallback;
    private array $fallbackModels;
    private string $routingStrategy;
    private float $budgetLimit;
    private string $siteUrl;
    private string $appName;
    private RequestFactory $requestFactory;
    private FrontendInterface $cache;
    private LoggerInterface $logger;

    public function __construct(
        array $configuration,
        RequestFactory $requestFactory,
        FrontendInterface $cache,
        LoggerInterface $logger,
    ) {
        parent::__construct($configuration);

        if (empty($configuration['apiKey'])) {
            throw new ConfigurationException('OpenRouter API key is required');
        }

        $this->apiKey = $configuration['apiKey'];
        $this->defaultModel = $configuration['model'] ?? 'anthropic/claude-3-sonnet';
        $this->autoFallback = $configuration['autoFallback'] ?? true;
        $this->fallbackModels = $this->parseFallbackModels($configuration['fallbackModels'] ?? '');
        $this->routingStrategy = $configuration['routingStrategy'] ?? 'balanced';
        $this->budgetLimit = (float)($configuration['budgetLimit'] ?? 100.0);
        $this->siteUrl = $configuration['siteUrl'] ?? 'https://localhost';
        $this->appName = $configuration['appName'] ?? 'TYPO3 AI Base';
        $this->requestFactory = $requestFactory;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        $model = $this->selectModel($options);

        $requestBody = [
            'model' => $model,
            'messages' => $this->buildMessages($prompt, $options),
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 1000,
        ];

        // Add OpenRouter-specific options
        if ($this->autoFallback) {
            $requestBody['route'] = 'fallback';
            if (!empty($this->fallbackModels)) {
                $requestBody['models'] = array_merge([$model], $this->fallbackModels);
            }
        }

        // Transforms (e.g., middle-out compression)
        if (!empty($options['transforms'])) {
            $requestBody['transforms'] = $options['transforms'];
        }

        $response = $this->makeRequest('POST', '/chat/completions', $requestBody);

        return $this->parseCompletionResponse($response);
    }

    public function stream(string $prompt, callable $callback, array $options = []): void
    {
        $model = $this->selectModel($options);

        $requestBody = [
            'model' => $model,
            'messages' => $this->buildMessages($prompt, $options),
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 1000,
            'stream' => true,
        ];

        if ($this->autoFallback && !empty($this->fallbackModels)) {
            $requestBody['route'] = 'fallback';
            $requestBody['models'] = array_merge([$model], $this->fallbackModels);
        }

        $this->makeStreamingRequest('/chat/completions', $requestBody, $callback);
    }

    public function embed(string|array $text, array $options = []): EmbeddingResponse
    {
        // Select embedding model
        $model = $options['model'] ?? 'openai/text-embedding-3-small';

        $texts = is_array($text) ? $text : [$text];

        $requestBody = [
            'model' => $model,
            'input' => $texts,
        ];

        $response = $this->makeRequest('POST', '/embeddings', $requestBody);

        return new EmbeddingResponse(
            embeddings: array_map(
                fn($item) => $item['embedding'],
                $response['data'],
            ),
            model: $model,
            tokenUsage: [
                'prompt_tokens' => $response['usage']['prompt_tokens'] ?? 0,
                'total_tokens' => $response['usage']['total_tokens'] ?? 0,
            ],
            cost: $response['total_cost'] ?? 0.0,
        );
    }

    public function analyzeImage(string $imageUrl, string $prompt, array $options = []): VisionResponse
    {
        // Select vision-capable model
        $model = $options['model'] ?? $this->selectVisionModel();

        $requestBody = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                    ],
                ],
            ],
            'max_tokens' => $options['max_tokens'] ?? 1000,
        ];

        $response = $this->makeRequest('POST', '/chat/completions', $requestBody);

        $content = $response['choices'][0]['message']['content'] ?? '';

        return new VisionResponse(
            description: $content,
            confidence: 0.9,
            metadata: [
                'model' => $response['model'] ?? $model,
                'provider' => $response['provider'] ?? 'unknown',
            ],
            tokenUsage: $this->extractTokenUsage($response),
            cost: $response['total_cost'] ?? 0.0,
        );
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = [],
    ): TranslationResponse {
        $prompt = $this->buildTranslationPrompt($text, $targetLanguage, $sourceLanguage);

        $response = $this->complete($prompt, [
            'temperature' => 0.3,
            ...$options,
        ]);

        return new TranslationResponse(
            translation: $response->getContent(),
            sourceLanguage: $sourceLanguage ?? 'auto',
            targetLanguage: $targetLanguage,
            confidence: 0.9,
            alternatives: [],
            tokenUsage: $response->getTokenUsage(),
            cost: $response->getCost(),
        );
    }

    /**
     * Get available models from OpenRouter.
     *
     * @param bool $refresh Force refresh cache
     *
     * @return array List of available models with metadata
     */
    public function getAvailableModels(bool $refresh = false): array
    {
        if (!$refresh) {
            $cached = $this->cache->get(self::MODELS_CACHE_KEY);
            if ($cached !== false) {
                return $cached;
            }
        }

        $response = $this->makeRequest('GET', '/models');

        $models = [];
        foreach ($response['data'] as $model) {
            $models[$model['id']] = [
                'name' => $model['name'] ?? $model['id'],
                'context_length' => $model['context_length'] ?? 0,
                'pricing' => [
                    'prompt' => $model['pricing']['prompt'] ?? 0,
                    'completion' => $model['pricing']['completion'] ?? 0,
                ],
                'capabilities' => [
                    'vision' => $model['architecture']['modality'] === 'multimodal' ?? false,
                    'function_calling' => $model['supports_function_calling'] ?? false,
                ],
                'provider' => $this->extractProviderFromModelId($model['id']),
            ];
        }

        $this->cache->set(self::MODELS_CACHE_KEY, $models, [], self::MODELS_CACHE_TTL);

        return $models;
    }

    /**
     * Get usage credits information.
     *
     * @return array Credits information
     */
    public function getCredits(): array
    {
        $response = $this->makeRequest('GET', '/auth/key');

        return [
            'balance' => $response['data']['limit'] ?? 0,
            'usage' => $response['data']['usage'] ?? 0,
            'is_free_tier' => $response['data']['is_free_tier'] ?? false,
            'rate_limit' => $response['data']['rate_limit'] ?? [],
        ];
    }

    public function getCapabilities(): array
    {
        return [
            'completion' => true,
            'streaming' => true,
            'vision' => true,
            'embeddings' => true,
            'translation' => true,
            'function_calling' => true,
            'json_mode' => true,
            'multi_provider' => true, // Unique to OpenRouter
            'automatic_fallback' => true,
            'exact_cost_tracking' => true, // No estimation needed
            'available_models' => array_keys($this->getAvailableModels()),
        ];
    }

    /**
     * @inheritDoc
     *
     * OpenRouter provides exact cost in response, so this is for pre-estimation only
     */
    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model ??= $this->defaultModel;

        $models = $this->getAvailableModels();

        if (!isset($models[$model])) {
            $this->logger->warning("Model not found for cost estimation: {$model}");
            return 0.0;
        }

        $pricing = $models[$model]['pricing'];

        // Pricing is per token (not per 1M tokens)
        $inputCost = $inputTokens * ($pricing['prompt'] ?? 0);
        $outputCost = $outputTokens * ($pricing['completion'] ?? 0);

        return $inputCost + $outputCost;
    }

    public function isAvailable(): bool
    {
        try {
            $this->getCredits();
            return true;
        } catch (Exception $e) {
            $this->logger->error('OpenRouter availability check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Select best model based on routing strategy.
     */
    private function selectModel(array $options): string
    {
        // Explicit model specified
        if (!empty($options['model'])) {
            return $options['model'];
        }

        // Use default model if explicit strategy
        if ($this->routingStrategy === 'explicit') {
            return $this->defaultModel;
        }

        $models = $this->getAvailableModels();

        // Filter models based on requirements
        $candidates = $this->filterModelsByRequirements($models, $options);

        if (empty($candidates)) {
            return $this->defaultModel;
        }

        // Select based on strategy
        return match ($this->routingStrategy) {
            'cost_optimized' => $this->selectCheapestModel($candidates),
            'performance' => $this->selectFastestModel($candidates),
            'balanced' => $this->selectBalancedModel($candidates),
            default => $this->defaultModel,
        };
    }

    /**
     * Filter models by requirements.
     */
    private function filterModelsByRequirements(array $models, array $options): array
    {
        $filtered = $models;

        // Context length requirement
        if (!empty($options['min_context'])) {
            $filtered = array_filter(
                $filtered,
                fn($model) => $model['context_length'] >= $options['min_context'],
            );
        }

        // Vision capability
        if (!empty($options['vision_required'])) {
            $filtered = array_filter(
                $filtered,
                fn($model) => $model['capabilities']['vision'] ?? false,
            );
        }

        // Function calling
        if (!empty($options['function_calling'])) {
            $filtered = array_filter(
                $filtered,
                fn($model) => $model['capabilities']['function_calling'] ?? false,
            );
        }

        return $filtered;
    }

    /**
     * Select cheapest model from candidates.
     */
    private function selectCheapestModel(array $candidates): string
    {
        $cheapest = null;
        $lowestCost = PHP_FLOAT_MAX;

        foreach ($candidates as $id => $model) {
            $avgCost = ($model['pricing']['prompt'] + $model['pricing']['completion']) / 2;
            if ($avgCost < $lowestCost) {
                $lowestCost = $avgCost;
                $cheapest = $id;
            }
        }

        return $cheapest ?? $this->defaultModel;
    }

    /**
     * Select fastest model (heuristic: smallest models are usually fastest).
     */
    private function selectFastestModel(array $candidates): string
    {
        // Heuristic: flash/haiku/turbo models are usually fastest
        $fastKeywords = ['flash', 'haiku', 'turbo', 'instant'];

        foreach ($candidates as $id => $model) {
            foreach ($fastKeywords as $keyword) {
                if (stripos($id, $keyword) !== false) {
                    return $id;
                }
            }
        }

        return $this->defaultModel;
    }

    /**
     * Select balanced model (mid-tier pricing and performance).
     */
    private function selectBalancedModel(array $candidates): string
    {
        // Prefer sonnet/medium tier models
        $balancedKeywords = ['sonnet', 'medium', '3.5'];

        foreach ($candidates as $id => $model) {
            foreach ($balancedKeywords as $keyword) {
                if (stripos($id, $keyword) !== false) {
                    return $id;
                }
            }
        }

        return $this->defaultModel;
    }

    /**
     * Select vision-capable model.
     */
    private function selectVisionModel(): string
    {
        $visionModels = [
            'anthropic/claude-3-opus',
            'openai/gpt-4-turbo',
            'google/gemini-pro-vision',
        ];

        // Check if default model supports vision
        $models = $this->getAvailableModels();
        if (isset($models[$this->defaultModel]['capabilities']['vision'])
            && $models[$this->defaultModel]['capabilities']['vision']) {
            return $this->defaultModel;
        }

        // Find first available vision model
        foreach ($visionModels as $model) {
            if (isset($models[$model])) {
                return $model;
            }
        }

        return 'openai/gpt-4-turbo'; // Fallback
    }

    /**
     * Build messages array from prompt.
     */
    private function buildMessages(string $prompt, array $options): array
    {
        $messages = [];

        if (!empty($options['system'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $options['system'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        return $messages;
    }

    /**
     * Build translation prompt.
     */
    private function buildTranslationPrompt(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage,
    ): string {
        $prompt = "Translate the following text to {$targetLanguage}";

        if ($sourceLanguage) {
            $prompt .= " from {$sourceLanguage}";
        }

        $prompt .= ". Only output the translation, nothing else.\n\n{$text}";

        return $prompt;
    }

    /**
     * Parse completion response.
     */
    private function parseCompletionResponse(array $response): CompletionResponse
    {
        if (empty($response['choices'])) {
            throw new ProviderException('No choices in OpenRouter response');
        }

        $choice = $response['choices'][0];

        return new CompletionResponse(
            content: $choice['message']['content'] ?? '',
            model: $response['model'] ?? $this->defaultModel,
            finishReason: $choice['finish_reason'] ?? 'stop',
            tokenUsage: $this->extractTokenUsage($response),
            cost: $response['total_cost'] ?? 0.0,
            metadata: [
                'provider' => $response['provider'] ?? 'unknown',
                'native_tokens' => [
                    'prompt' => $response['native_tokens_prompt'] ?? 0,
                    'completion' => $response['native_tokens_completion'] ?? 0,
                ],
            ],
        );
    }

    /**
     * Extract token usage from response.
     */
    private function extractTokenUsage(array $response): array
    {
        $usage = $response['usage'] ?? [];

        return [
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
        ];
    }

    /**
     * Parse fallback models configuration.
     */
    private function parseFallbackModels(string $fallbackModelsStr): array
    {
        if (empty($fallbackModelsStr)) {
            return [];
        }

        return array_map('trim', explode(',', $fallbackModelsStr));
    }

    /**
     * Extract provider name from model ID.
     */
    private function extractProviderFromModelId(string $modelId): string
    {
        if (str_contains($modelId, '/')) {
            return explode('/', $modelId)[0];
        }

        return 'unknown';
    }

    /**
     * Make HTTP request to OpenRouter API.
     */
    private function makeRequest(string $method, string $endpoint, ?array $body = null): array
    {
        $url = self::API_BASE_URL . $endpoint;

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'HTTP-Referer' => $this->siteUrl,
                'X-Title' => $this->appName,
                'Content-Type' => 'application/json',
            ],
        ];

        if ($body !== null && $method !== 'GET') {
            $options['body'] = json_encode($body);
        }

        $response = $this->requestFactory->request($url, $method, $options);

        $statusCode = $response->getStatusCode();
        $responseBody = json_decode($response->getBody()->getContents(), true);

        if ($statusCode !== 200) {
            $this->handleError($statusCode, $responseBody);
        }

        // Check budget after successful request
        if (isset($responseBody['total_cost'])) {
            $this->checkBudget($responseBody['total_cost']);
        }

        return $responseBody;
    }

    /**
     * Make streaming request.
     */
    private function makeStreamingRequest(string $endpoint, array $body, callable $callback): void
    {
        $url = self::API_BASE_URL . $endpoint;

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'HTTP-Referer' => $this->siteUrl,
                'X-Title' => $this->appName,
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
                $data = substr($line, 6);

                if ($data === '[DONE]') {
                    break;
                }

                $chunk = json_decode($data, true);
                $content = $chunk['choices'][0]['delta']['content'] ?? '';

                if ($content) {
                    $callback($content);
                }
            }
        }
    }

    /**
     * Check budget and throw exception if exceeded.
     */
    private function checkBudget(float $cost): void
    {
        // This is a simple check - in production, track cumulative monthly spend
        if ($cost > $this->budgetLimit) {
            throw new QuotaExceededException(
                "Single request cost ({$cost}) exceeds budget limit ({$this->budgetLimit})",
            );
        }
    }

    /**
     * Handle API errors.
     */
    private function handleError(int $statusCode, ?array $response): void
    {
        $error = $response['error'] ?? [];
        $message = $error['message'] ?? 'Unknown OpenRouter API error';

        $errorMessage = match ($statusCode) {
            400 => "Bad request: {$message}",
            401 => 'Invalid OpenRouter API key',
            402 => 'Insufficient credits',
            403 => 'Forbidden',
            429 => 'Rate limit exceeded',
            503 => 'Model or provider unavailable',
            default => "OpenRouter API error ({$statusCode}): {$message}",
        };

        if ($statusCode === 402) {
            throw new QuotaExceededException($errorMessage);
        }

        throw new ProviderException(
            $errorMessage,
            [
                'status_code' => $statusCode,
                'response' => $response,
                'provider' => 'openrouter',
            ],
        );
    }

    /**
     * Read line from stream.
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
        return trim($line);
    }
}
