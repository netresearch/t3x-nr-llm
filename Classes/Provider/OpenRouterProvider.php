<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;

/**
 * OpenRouter Provider
 *
 * Gateway to 100+ AI models from multiple providers via a single OpenAI-compatible API.
 * OpenRouter provides automatic fallback, competitive pricing, and unified billing.
 *
 * Features:
 * - Single API key for 100+ models (Anthropic, OpenAI, Google, Meta, Mistral, etc.)
 * - Automatic fallback to alternative models
 * - Exact cost tracking (returned in response metadata)
 * - Model routing based on cost/performance/balance
 * - OpenAI-compatible API endpoints
 *
 * @see https://openrouter.ai/docs
 */
final class OpenRouterProvider extends AbstractProvider implements
    VisionCapableInterface,
    StreamingCapableInterface,
    ToolCapableInterface
{
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_EMBEDDINGS,
        self::FEATURE_VISION,
        self::FEATURE_STREAMING,
        self::FEATURE_TOOLS,
    ];

    private const DEFAULT_CHAT_MODEL = 'anthropic/claude-sonnet-4-5';
    private const DEFAULT_EMBEDDING_MODEL = 'openai/text-embedding-3-small';

    private const ROUTING_STRATEGIES = [
        'cost_optimized',
        'performance',
        'balanced',
        'explicit',
    ];

    /**
     * Site URL for OpenRouter attribution (HTTP-Referer header)
     */
    private string $siteUrl = '';

    /**
     * App name for OpenRouter attribution (X-Title header)
     */
    private string $appName = 'TYPO3 NR-LLM';

    /**
     * Routing strategy for model selection
     */
    private string $routingStrategy = 'balanced';

    /**
     * Enable automatic fallback to alternative models
     */
    private bool $autoFallback = true;

    /**
     * Comma-separated fallback model IDs
     *
     * @var array<int, string>
     */
    private array $fallbackModels = [];

    /**
     * Cached models list
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $cachedModels = null;

    public function getName(): string
    {
        return 'OpenRouter';
    }

    public function getIdentifier(): string
    {
        return 'openrouter';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://openrouter.ai/api/v1';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel !== '' ? $this->defaultModel : self::DEFAULT_CHAT_MODEL;
    }

    /**
     * Configure provider with additional OpenRouter-specific options
     */
    public function configure(array $config): void
    {
        parent::configure($config);

        if (isset($config['siteUrl'])) {
            $this->siteUrl = $config['siteUrl'];
        }

        if (isset($config['appName'])) {
            $this->appName = $config['appName'];
        }

        if (isset($config['routingStrategy']) && in_array($config['routingStrategy'], self::ROUTING_STRATEGIES, true)) {
            $this->routingStrategy = $config['routingStrategy'];
        }

        if (isset($config['autoFallback'])) {
            $this->autoFallback = (bool) $config['autoFallback'];
        }

        if (isset($config['fallbackModels']) && is_string($config['fallbackModels'])) {
            $this->fallbackModels = array_filter(
                array_map('trim', explode(',', $config['fallbackModels']))
            );
        }
    }

    /**
     * Get available models from OpenRouter API with caching
     *
     * Returns a curated static list with common models.
     * For dynamic list, use fetchAvailableModels().
     */
    public function getAvailableModels(): array
    {
        return [
            // Anthropic (December 2025)
            'anthropic/claude-opus-4-5' => 'Claude Opus 4.5 (Anthropic)',
            'anthropic/claude-sonnet-4-5' => 'Claude Sonnet 4.5 (Anthropic)',
            'anthropic/claude-opus-4-1' => 'Claude Opus 4.1 (Anthropic)',
            // OpenAI (December 2025)
            'openai/gpt-5.2' => 'GPT-5.2 (OpenAI)',
            'openai/gpt-5.2-pro' => 'GPT-5.2 Pro (OpenAI)',
            'openai/o3' => 'O3 Reasoning (OpenAI)',
            'openai/o4-mini' => 'O4 Mini (OpenAI)',
            // Google (December 2025)
            'google/gemini-3-flash' => 'Gemini 3 Flash (Google)',
            'google/gemini-3-pro' => 'Gemini 3 Pro (Google)',
            'google/gemini-2.5-flash' => 'Gemini 2.5 Flash (Google)',
            // Meta
            'meta-llama/llama-3.3-70b-instruct' => 'Llama 3.3 70B (Meta)',
            'meta-llama/llama-3.1-405b-instruct' => 'Llama 3.1 405B (Meta)',
            // Mistral
            'mistralai/mistral-large' => 'Mistral Large (Mistral AI)',
            'mistralai/pixtral-large' => 'Pixtral Large (Mistral AI)',
            // Cohere
            'cohere/command-r-plus' => 'Command R+ (Cohere)',
        ];
    }

    /**
     * Fetch live model list from OpenRouter API
     *
     * @param bool $forceRefresh Bypass cache and fetch fresh data
     * @return array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}>
     */
    public function fetchAvailableModels(bool $forceRefresh = false): array
    {
        if ($this->cachedModels !== null && !$forceRefresh) {
            return $this->cachedModels;
        }

        try {
            $response = $this->sendRequest('models', [], 'GET');

            $models = [];
            foreach ($response['data'] ?? [] as $model) {
                $models[$model['id']] = [
                    'name' => $model['name'] ?? $model['id'],
                    'context_length' => $model['context_length'] ?? 0,
                    'pricing' => [
                        'prompt' => (float) ($model['pricing']['prompt'] ?? 0),
                        'completion' => (float) ($model['pricing']['completion'] ?? 0),
                    ],
                    'capabilities' => [
                        'vision' => ($model['architecture']['modality'] ?? '') === 'multimodal',
                        'function_calling' => $model['supports_function_calling'] ?? false,
                    ],
                    'provider' => $this->extractProviderFromModelId($model['id']),
                ];
            }

            $this->cachedModels = $models;
            return $models;
        } catch (\Exception $e) {
            // Return empty array on failure, static list still available via getAvailableModels()
            return [];
        }
    }

    /**
     * Get OpenRouter credits/balance information
     *
     * @return array{balance: float, usage: float, is_free_tier: bool, rate_limit: array<string, mixed>}
     */
    public function getCredits(): array
    {
        $response = $this->sendRequest('auth/key', [], 'GET');

        return [
            'balance' => (float) ($response['data']['limit'] ?? 0),
            'usage' => (float) ($response['data']['usage'] ?? 0),
            'is_free_tier' => (bool) ($response['data']['is_free_tier'] ?? false),
            'rate_limit' => $response['data']['rate_limit'] ?? [],
        ];
    }

    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        $model = $this->selectModel($options);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        // Optional parameters
        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
        }

        if (isset($options['frequency_penalty'])) {
            $payload['frequency_penalty'] = $options['frequency_penalty'];
        }

        if (isset($options['presence_penalty'])) {
            $payload['presence_penalty'] = $options['presence_penalty'];
        }

        if (isset($options['stop'])) {
            $payload['stop'] = $options['stop'];
        }

        // OpenRouter-specific: automatic fallback
        if ($this->autoFallback) {
            $payload['route'] = 'fallback';
            if (!empty($this->fallbackModels)) {
                $payload['models'] = array_merge([$model], $this->fallbackModels);
            }
        }

        // OpenRouter-specific: transforms (e.g., middle-out compression)
        if (!empty($options['transforms'])) {
            $payload['transforms'] = $options['transforms'];
        }

        $response = $this->sendOpenRouterRequest('chat/completions', $payload);

        $choice = $response['choices'][0] ?? [];
        $usage = $response['usage'] ?? [];

        return new CompletionResponse(
            content: $choice['message']['content'] ?? '',
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: $usage['completion_tokens'] ?? 0
            ),
            finishReason: $choice['finish_reason'] ?? 'stop',
            provider: $this->getIdentifier(),
            metadata: [
                'actual_provider' => $response['provider'] ?? 'unknown',
                'cost' => $response['total_cost'] ?? null,
                'native_tokens' => [
                    'prompt' => $response['native_tokens_prompt'] ?? null,
                    'completion' => $response['native_tokens_completion'] ?? null,
                ],
            ]
        );
    }

    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $model = $this->selectModel($options);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        if (isset($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        // OpenRouter-specific: automatic fallback
        if ($this->autoFallback) {
            $payload['route'] = 'fallback';
            if (!empty($this->fallbackModels)) {
                $payload['models'] = array_merge([$model], $this->fallbackModels);
            }
        }

        $response = $this->sendOpenRouterRequest('chat/completions', $payload);

        $choice = $response['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $usage = $response['usage'] ?? [];

        $toolCalls = null;
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            $toolCalls = array_map(static fn($tc) => [
                'id' => $tc['id'],
                'type' => $tc['type'],
                'function' => [
                    'name' => $tc['function']['name'],
                    'arguments' => json_decode($tc['function']['arguments'], true),
                ],
            ], $message['tool_calls']);
        }

        return new CompletionResponse(
            content: $message['content'] ?? '',
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: $usage['completion_tokens'] ?? 0
            ),
            finishReason: $choice['finish_reason'] ?? 'stop',
            provider: $this->getIdentifier(),
            toolCalls: $toolCalls,
            metadata: [
                'actual_provider' => $response['provider'] ?? 'unknown',
                'cost' => $response['total_cost'] ?? null,
            ]
        );
    }

    public function supportsTools(): bool
    {
        return true;
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        $inputs = is_array($input) ? $input : [$input];

        $payload = [
            'model' => $options['model'] ?? self::DEFAULT_EMBEDDING_MODEL,
            'input' => $inputs,
        ];

        if (isset($options['dimensions'])) {
            $payload['dimensions'] = $options['dimensions'];
        }

        $response = $this->sendOpenRouterRequest('embeddings', $payload);

        $embeddings = array_map(
            static fn($item) => $item['embedding'],
            $response['data'] ?? []
        );

        $usage = $response['usage'] ?? [];

        return $this->createEmbeddingResponse(
            embeddings: $embeddings,
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: 0
            )
        );
    }

    public function analyzeImage(array $content, array $options = []): VisionResponse
    {
        // Select vision-capable model
        $model = $options['model'] ?? $this->selectVisionModel();

        $messages = [
            [
                'role' => 'user',
                'content' => $content,
            ],
        ];

        if (isset($options['system_prompt'])) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => $options['system_prompt'],
            ]);
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ];

        // OpenRouter-specific: automatic fallback for vision
        if ($this->autoFallback) {
            $payload['route'] = 'fallback';
        }

        $response = $this->sendOpenRouterRequest('chat/completions', $payload);

        $choice = $response['choices'][0] ?? [];
        $usage = $response['usage'] ?? [];

        return new VisionResponse(
            description: $choice['message']['content'] ?? '',
            model: $response['model'] ?? $payload['model'],
            usage: $this->createUsageStatistics(
                promptTokens: $usage['prompt_tokens'] ?? 0,
                completionTokens: $usage['completion_tokens'] ?? 0
            ),
            provider: $this->getIdentifier(),
            metadata: [
                'actual_provider' => $response['provider'] ?? 'unknown',
                'cost' => $response['total_cost'] ?? null,
            ]
        );
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function getSupportedImageFormats(): array
    {
        return ['png', 'jpeg', 'jpg', 'gif', 'webp'];
    }

    public function getMaxImageSize(): int
    {
        return 20 * 1024 * 1024; // 20 MB
    }

    public function streamChatCompletion(array $messages, array $options = []): \Generator
    {
        $model = $this->selectModel($options);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'stream' => true,
        ];

        // OpenRouter-specific: automatic fallback
        if ($this->autoFallback) {
            $payload['route'] = 'fallback';
            if (!empty($this->fallbackModels)) {
                $payload['models'] = array_merge([$model], $this->fallbackModels);
            }
        }

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Accept', 'text/event-stream');

        // Add OpenRouter-specific headers
        if ($this->siteUrl !== '') {
            $request = $request->withHeader('HTTP-Referer', $this->siteUrl);
        }
        if ($this->appName !== '') {
            $request = $request->withHeader('X-Title', $this->appName);
        }

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        $response = $this->httpClient->sendRequest($request);
        $stream = $response->getBody();

        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(1024);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);

                    if ($data === '[DONE]') {
                        return;
                    }

                    try {
                        $json = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                        $content = $json['choices'][0]['delta']['content'] ?? '';
                        if ($content !== '') {
                            yield $content;
                        }
                    } catch (\JsonException) {
                        // Skip malformed JSON
                    }
                }
            }
        }
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * Select model based on routing strategy
     *
     * @param array<string, mixed> $options
     */
    private function selectModel(array $options): string
    {
        // Explicit model specified in options
        if (!empty($options['model'])) {
            return $options['model'];
        }

        // Explicit strategy: use default model
        if ($this->routingStrategy === 'explicit') {
            return $this->getDefaultModel();
        }

        // Try to fetch available models for smart routing
        $models = $this->fetchAvailableModels();
        if (empty($models)) {
            return $this->getDefaultModel();
        }

        // Filter models by requirements
        $candidates = $this->filterModelsByRequirements($models, $options);
        if (empty($candidates)) {
            return $this->getDefaultModel();
        }

        return match ($this->routingStrategy) {
            'cost_optimized' => $this->selectCheapestModel($candidates),
            'performance' => $this->selectFastestModel($candidates),
            'balanced' => $this->selectBalancedModel($candidates),
            default => $this->getDefaultModel(),
        };
    }

    /**
     * Filter models by requirements from options
     *
     * @param array<string, array<string, mixed>> $models
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    private function filterModelsByRequirements(array $models, array $options): array
    {
        $filtered = $models;

        // Context length requirement
        if (!empty($options['min_context'])) {
            $filtered = array_filter(
                $filtered,
                static fn($model) => ($model['context_length'] ?? 0) >= $options['min_context']
            );
        }

        // Vision capability
        if (!empty($options['vision_required'])) {
            $filtered = array_filter(
                $filtered,
                static fn($model) => $model['capabilities']['vision'] ?? false
            );
        }

        // Function calling
        if (!empty($options['function_calling'])) {
            $filtered = array_filter(
                $filtered,
                static fn($model) => $model['capabilities']['function_calling'] ?? false
            );
        }

        return $filtered;
    }

    /**
     * Select cheapest model from candidates
     *
     * @param array<string, array<string, mixed>> $candidates
     */
    private function selectCheapestModel(array $candidates): string
    {
        $cheapest = null;
        $lowestCost = PHP_FLOAT_MAX;

        foreach ($candidates as $id => $model) {
            $avgCost = (($model['pricing']['prompt'] ?? 0) + ($model['pricing']['completion'] ?? 0)) / 2;
            if ($avgCost < $lowestCost) {
                $lowestCost = $avgCost;
                $cheapest = $id;
            }
        }

        return $cheapest ?? $this->getDefaultModel();
    }

    /**
     * Select fastest model (heuristic: flash/haiku/turbo models)
     *
     * @param array<string, array<string, mixed>> $candidates
     */
    private function selectFastestModel(array $candidates): string
    {
        $fastKeywords = ['flash', 'haiku', 'turbo', 'instant', 'mini'];

        foreach ($candidates as $id => $model) {
            foreach ($fastKeywords as $keyword) {
                if (stripos($id, $keyword) !== false) {
                    return $id;
                }
            }
        }

        return $this->getDefaultModel();
    }

    /**
     * Select balanced model (mid-tier quality and speed)
     *
     * @param array<string, array<string, mixed>> $candidates
     */
    private function selectBalancedModel(array $candidates): string
    {
        $balancedKeywords = ['sonnet', 'medium', '3.5', 'pro'];

        foreach ($candidates as $id => $model) {
            foreach ($balancedKeywords as $keyword) {
                if (stripos($id, $keyword) !== false) {
                    return $id;
                }
            }
        }

        return $this->getDefaultModel();
    }

    /**
     * Select vision-capable model
     */
    private function selectVisionModel(): string
    {
        $visionModels = [
            'anthropic/claude-sonnet-4-5',
            'anthropic/claude-opus-4-5',
            'openai/gpt-5.2',
            'openai/gpt-5.2-pro',
            'google/gemini-3-flash',
        ];

        // Check if default model supports vision
        $models = $this->fetchAvailableModels();
        $defaultModel = $this->getDefaultModel();

        if (isset($models[$defaultModel]['capabilities']['vision'])
            && $models[$defaultModel]['capabilities']['vision']) {
            return $defaultModel;
        }

        // Find first available vision model
        foreach ($visionModels as $model) {
            if (isset($models[$model]) || empty($models)) {
                return $model;
            }
        }

        return 'openai/gpt-5.2'; // Fallback
    }

    /**
     * Extract provider name from model ID (e.g., "anthropic/claude-3" → "anthropic")
     */
    private function extractProviderFromModelId(string $modelId): string
    {
        if (str_contains($modelId, '/')) {
            return explode('/', $modelId)[0];
        }

        return 'unknown';
    }

    /**
     * Send request to OpenRouter API with custom headers
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendOpenRouterRequest(string $endpoint, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey);

        // Add OpenRouter-specific headers for attribution
        if ($this->siteUrl !== '') {
            $request = $request->withHeader('HTTP-Referer', $this->siteUrl);
        }
        if ($this->appName !== '') {
            $request = $request->withHeader('X-Title', $this->appName);
        }

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        $response = $this->httpClient->sendRequest($request);
        $statusCode = $response->getStatusCode();
        $responseBody = $response->getBody()->getContents();

        if ($statusCode !== 200) {
            $this->handleOpenRouterError($statusCode, $responseBody);
        }

        return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Handle OpenRouter-specific errors
     */
    private function handleOpenRouterError(int $statusCode, string $responseBody): never
    {
        $decoded = json_decode($responseBody, true);
        $error = $decoded['error'] ?? [];
        $message = $error['message'] ?? 'Unknown OpenRouter API error';

        $errorMessage = match ($statusCode) {
            400 => "Bad request: {$message}",
            401 => 'Invalid OpenRouter API key',
            402 => 'Insufficient OpenRouter credits',
            403 => 'Forbidden',
            429 => 'Rate limit exceeded',
            503 => 'Model or provider unavailable',
            default => "OpenRouter API error ({$statusCode}): {$message}",
        };

        throw new ProviderException($errorMessage, $statusCode);
    }
}
