<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Exception;
use Generator;
use JsonException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Override;

/**
 * OpenRouter Provider.
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
    /** @var array<string> */
    protected array $supportedFeatures = [
        self::FEATURE_CHAT,
        self::FEATURE_COMPLETION,
        self::FEATURE_EMBEDDINGS,
        self::FEATURE_VISION,
        self::FEATURE_STREAMING,
        self::FEATURE_TOOLS,
    ];

    private const string DEFAULT_CHAT_MODEL = 'anthropic/claude-sonnet-4-5';
    private const string DEFAULT_EMBEDDING_MODEL = 'openai/text-embedding-3-small';

    /** @var array<int, string> */
    private const array ROUTING_STRATEGIES = [
        'cost_optimized',
        'performance',
        'balanced',
        'explicit',
    ];

    /** Site URL for OpenRouter attribution (HTTP-Referer header) */
    private string $siteUrl = '';

    /** App name for OpenRouter attribution (X-Title header) */
    private string $appName = 'TYPO3 NR-LLM';

    /** Routing strategy for model selection */
    private string $routingStrategy = 'balanced';

    /** Enable automatic fallback to alternative models */
    private bool $autoFallback = true;

    /**
     * Comma-separated fallback model IDs.
     *
     * @var array<int, string>
     */
    private array $fallbackModels = [];

    /**
     * Cached models list.
     *
     * @var array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}>|null
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

    #[Override]
    public function getDefaultModel(): string
    {
        return $this->defaultModel !== '' ? $this->defaultModel : self::DEFAULT_CHAT_MODEL;
    }

    /**
     * Configure provider with additional OpenRouter-specific options.
     *
     * @param array<string, mixed> $config
     */
    #[Override]
    public function configure(array $config): void
    {
        parent::configure($config);

        $this->siteUrl = $this->getString($config, 'siteUrl');
        $this->appName = $this->getString($config, 'appName', 'TYPO3 NR-LLM');

        $routingStrategy = $this->getString($config, 'routingStrategy', 'balanced');
        if (in_array($routingStrategy, self::ROUTING_STRATEGIES, true)) {
            $this->routingStrategy = $routingStrategy;
        }

        $this->autoFallback = $this->getBool($config, 'autoFallback', true);

        $fallbackModelsString = $this->getString($config, 'fallbackModels');
        if ($fallbackModelsString !== '') {
            $this->fallbackModels = array_filter(
                array_map(trim(...), explode(',', $fallbackModelsString)),
            );
        }
    }

    /**
     * Get available models from OpenRouter API with caching.
     *
     * Returns a curated static list with common models.
     * For dynamic list, use fetchAvailableModels().
     *
     * @return array<string, string>
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
     * Fetch live model list from OpenRouter API.
     *
     * @param bool $forceRefresh Bypass cache and fetch fresh data
     *
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
            $data = $this->getList($response, 'data');
            foreach ($data as $model) {
                $modelArray = $this->asArray($model);
                $modelId = $this->getString($modelArray, 'id');
                $architecture = $this->getArray($modelArray, 'architecture');
                $pricing = $this->getArray($modelArray, 'pricing');

                $models[$modelId] = [
                    'name' => $this->getString($modelArray, 'name', $modelId),
                    'context_length' => $this->getInt($modelArray, 'context_length'),
                    'pricing' => [
                        'prompt' => $this->asFloat($pricing['prompt'] ?? 0),
                        'completion' => $this->asFloat($pricing['completion'] ?? 0),
                    ],
                    'capabilities' => [
                        'vision' => $this->getString($architecture, 'modality') === 'multimodal',
                        'function_calling' => $this->getBool($modelArray, 'supports_function_calling'),
                    ],
                    'provider' => $this->extractProviderFromModelId($modelId),
                ];
            }

            /** @var array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $models */
            $this->cachedModels = $models;
            return $models;
        } catch (Exception) {
            // Return empty array on failure, static list still available via getAvailableModels()
            return [];
        }
    }

    /**
     * Get OpenRouter credits/balance information.
     *
     * @return array{balance: float, usage: float, is_free_tier: bool, rate_limit: array<string, mixed>}
     */
    public function getCredits(): array
    {
        $response = $this->sendRequest('auth/key', [], 'GET');
        $data = $this->getArray($response, 'data');

        return [
            'balance' => $this->asFloat($data['limit'] ?? 0),
            'usage' => $this->asFloat($data['usage'] ?? 0),
            'is_free_tier' => $this->getBool($data, 'is_free_tier'),
            'rate_limit' => $this->getArray($data, 'rate_limit'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     */
    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        $model = $this->selectModel($options);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $this->getFloat($options, 'temperature', 0.7),
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
        ];

        // Optional parameters
        if (isset($options['top_p'])) {
            $payload['top_p'] = $this->getFloat($options, 'top_p');
        }

        if (isset($options['frequency_penalty'])) {
            $payload['frequency_penalty'] = $this->getFloat($options, 'frequency_penalty');
        }

        if (isset($options['presence_penalty'])) {
            $payload['presence_penalty'] = $this->getFloat($options, 'presence_penalty');
        }

        if (isset($options['stop'])) {
            $payload['stop'] = $options['stop'];
        }

        // OpenRouter-specific: automatic fallback
        if ($this->autoFallback) {
            $payload['route'] = 'fallback';
            if ($this->fallbackModels !== []) {
                $payload['models'] = array_merge([$model], $this->fallbackModels);
            }
        }

        // OpenRouter-specific: transforms (e.g., middle-out compression)
        $transforms = $this->getArray($options, 'transforms');
        if ($transforms !== []) {
            $payload['transforms'] = $transforms;
        }

        $response = $this->sendOpenRouterRequest('chat/completions', $payload);

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        return new CompletionResponse(
            content: $this->getString($message, 'content'),
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: $this->getInt($usage, 'completion_tokens'),
            ),
            finishReason: $this->getString($choice, 'finish_reason', 'stop'),
            provider: $this->getIdentifier(),
            metadata: [
                'actual_provider' => $this->getString($response, 'provider', 'unknown'),
                'cost' => $response['total_cost'] ?? null,
                'native_tokens' => [
                    'prompt' => $response['native_tokens_prompt'] ?? null,
                    'completion' => $response['native_tokens_completion'] ?? null,
                ],
            ],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed>             $options
     */
    public function chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse
    {
        $model = $this->selectModel($options);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools,
            'temperature' => $this->getFloat($options, 'temperature', 0.7),
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
        ];

        if (isset($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        // OpenRouter-specific: automatic fallback
        if ($this->autoFallback) {
            $payload['route'] = 'fallback';
            if ($this->fallbackModels !== []) {
                $payload['models'] = array_merge([$model], $this->fallbackModels);
            }
        }

        $response = $this->sendOpenRouterRequest('chat/completions', $payload);

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        $toolCalls = null;
        $rawToolCalls = $this->getArray($message, 'tool_calls');
        if ($rawToolCalls !== []) {
            $toolCalls = [];
            foreach ($rawToolCalls as $tc) {
                $tcArray = $this->asArray($tc);
                $function = $this->getArray($tcArray, 'function');
                $arguments = $this->getString($function, 'arguments');
                $decodedArgs = json_decode($arguments, true);

                $toolCalls[] = [
                    'id' => $this->getString($tcArray, 'id'),
                    'type' => $this->getString($tcArray, 'type'),
                    'function' => [
                        'name' => $this->getString($function, 'name'),
                        'arguments' => is_array($decodedArgs) ? $decodedArgs : [],
                    ],
                ];
            }
        }

        return new CompletionResponse(
            content: $this->getString($message, 'content'),
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: $this->getInt($usage, 'completion_tokens'),
            ),
            finishReason: $this->getString($choice, 'finish_reason', 'stop'),
            provider: $this->getIdentifier(),
            toolCalls: $toolCalls,
            metadata: [
                'actual_provider' => $this->getString($response, 'provider', 'unknown'),
                'cost' => $response['total_cost'] ?? null,
            ],
        );
    }

    public function supportsTools(): bool
    {
        return true;
    }

    /**
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     */
    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        $inputs = is_array($input) ? $input : [$input];
        $model = $this->getString($options, 'model', self::DEFAULT_EMBEDDING_MODEL);

        $payload = [
            'model' => $model,
            'input' => $inputs,
        ];

        if (isset($options['dimensions'])) {
            $payload['dimensions'] = $this->getInt($options, 'dimensions');
        }

        $response = $this->sendOpenRouterRequest('embeddings', $payload);

        $data = $this->getList($response, 'data');
        /** @var array<int, array<int, float>> $embeddings */
        $embeddings = [];
        foreach ($data as $item) {
            $itemArray = $this->asArray($item);
            $embedding = $this->getArray($itemArray, 'embedding');
            /** @var array<int, float> $floatEmbedding */
            $floatEmbedding = array_map(fn($v): float => $this->asFloat($v), $embedding);
            $embeddings[] = $floatEmbedding;
        }

        $usage = $this->getArray($response, 'usage');

        return $this->createEmbeddingResponse(
            embeddings: $embeddings,
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: 0,
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @param array<string, mixed>             $options
     */
    public function analyzeImage(array $content, array $options = []): VisionResponse
    {
        // Select vision-capable model
        $model = $this->getString($options, 'model', $this->selectVisionModel());

        $messages = [
            [
                'role' => 'user',
                'content' => $content,
            ],
        ];

        $systemPrompt = $this->getNullableString($options, 'system_prompt');
        if ($systemPrompt !== null) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => $systemPrompt,
            ]);
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
        ];

        // OpenRouter-specific: automatic fallback for vision
        if ($this->autoFallback) {
            $payload['route'] = 'fallback';
        }

        $response = $this->sendOpenRouterRequest('chat/completions', $payload);

        $choices = $this->getList($response, 'choices');
        $choice = $this->asArray($choices[0] ?? []);
        $message = $this->getArray($choice, 'message');
        $usage = $this->getArray($response, 'usage');

        return new VisionResponse(
            description: $this->getString($message, 'content'),
            model: $this->getString($response, 'model', $model),
            usage: $this->createUsageStatistics(
                promptTokens: $this->getInt($usage, 'prompt_tokens'),
                completionTokens: $this->getInt($usage, 'completion_tokens'),
            ),
            provider: $this->getIdentifier(),
            metadata: [
                'actual_provider' => $this->getString($response, 'provider', 'unknown'),
                'cost' => $response['total_cost'] ?? null,
            ],
        );
    }

    public function supportsVision(): bool
    {
        return true;
    }

    /**
     * @return array<string>
     */
    public function getSupportedImageFormats(): array
    {
        return ['png', 'jpeg', 'jpg', 'gif', 'webp'];
    }

    public function getMaxImageSize(): int
    {
        return 20 * 1024 * 1024; // 20 MB
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatCompletion(array $messages, array $options = []): Generator
    {
        $model = $this->selectModel($options);

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $this->getFloat($options, 'temperature', 0.7),
            'max_tokens' => $this->getInt($options, 'max_tokens', 4096),
            'stream' => true,
        ];

        // OpenRouter-specific: automatic fallback
        if ($this->autoFallback) {
            $payload['route'] = 'fallback';
            if ($this->fallbackModels !== []) {
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

        $response = $this->getHttpClient()->sendRequest($request);
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
                        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $json = $this->asArray($decoded);
                            $choices = $this->getList($json, 'choices');
                            $firstChoice = $this->asArray($choices[0] ?? []);
                            $delta = $this->getArray($firstChoice, 'delta');
                            $content = $this->getString($delta, 'content');
                            if ($content !== '') {
                                yield $content;
                            }
                        }
                    } catch (JsonException) {
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
     * Select model based on routing strategy.
     *
     * @param array<string, mixed> $options
     */
    private function selectModel(array $options): string
    {
        // Explicit model specified in options
        $model = $this->getString($options, 'model');
        if ($model !== '') {
            return $model;
        }

        // Explicit strategy: use default model
        if ($this->routingStrategy === 'explicit') {
            return $this->getDefaultModel();
        }

        // Try to fetch available models for smart routing
        $models = $this->fetchAvailableModels();
        if ($models === []) {
            return $this->getDefaultModel();
        }

        // Filter models by requirements
        $candidates = $this->filterModelsByRequirements($models, $options);
        if ($candidates === []) {
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
     * Filter models by requirements from options.
     *
     * @param array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $models
     * @param array<string, mixed>                                                                                                                        $options
     *
     * @return array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}>
     */
    private function filterModelsByRequirements(array $models, array $options): array
    {
        $filtered = $models;

        // Context length requirement
        $minContext = $this->getInt($options, 'min_context');
        if ($minContext > 0) {
            $filtered = array_filter(
                $filtered,
                static fn($model) => ($model['context_length'] ?? 0) >= $minContext,
            );
        }

        // Vision capability
        if ($this->getBool($options, 'vision_required')) {
            $filtered = array_filter(
                $filtered,
                static fn($model) => $model['capabilities']['vision'] ?? false,
            );
        }

        // Function calling
        if ($this->getBool($options, 'function_calling')) {
            $filtered = array_filter(
                $filtered,
                static fn($model) => $model['capabilities']['function_calling'] ?? false,
            );
        }

        return $filtered;
    }

    /**
     * Select cheapest model from candidates.
     *
     * @param array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $candidates
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
     * Select fastest model (heuristic: flash/haiku/turbo models).
     *
     * @param array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $candidates
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
     * Select balanced model (mid-tier quality and speed).
     *
     * @param array<string, array{name: string, context_length: int, pricing: array<string, float>, capabilities: array<string, bool>, provider: string}> $candidates
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
     * Select vision-capable model.
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
            if (isset($models[$model]) || $models === []) {
                return $model;
            }
        }

        return 'openai/gpt-5.2'; // Fallback
    }

    /**
     * Extract provider name from model ID (e.g., "anthropic/claude-3" → "anthropic").
     */
    private function extractProviderFromModelId(string $modelId): string
    {
        if (str_contains($modelId, '/')) {
            return explode('/', $modelId)[0];
        }

        return 'unknown';
    }

    /**
     * Send request to OpenRouter API with custom headers.
     *
     * @param array<string, mixed> $payload
     *
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

        $response = $this->getHttpClient()->sendRequest($request);
        $statusCode = $response->getStatusCode();
        $responseBody = $response->getBody()->getContents();

        if ($statusCode !== 200) {
            $this->handleOpenRouterError($statusCode, $responseBody);
        }

        return $this->decodeJsonResponse($responseBody);
    }

    /**
     * Handle OpenRouter-specific errors.
     */
    private function handleOpenRouterError(int $statusCode, string $responseBody): never
    {
        $decoded = json_decode($responseBody, true);
        $decodedArray = is_array($decoded) ? $decoded : [];
        $error = $this->getArray($decodedArray, 'error');
        $message = $this->getString($error, 'message', 'Unknown OpenRouter API error');

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
