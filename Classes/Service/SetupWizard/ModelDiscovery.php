<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Discovers available models from LLM provider APIs.
 *
 * Model information updated: December 2025
 */
final readonly class ModelDiscovery implements ModelDiscoveryInterface
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Test connection to provider.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(DetectedProvider $provider, string $apiKey): array
    {
        try {
            $endpoint = match ($provider->adapterType) {
                'anthropic' => $provider->endpoint . '/v1/messages',
                'gemini' => $provider->endpoint . '/v1/models',
                'ollama' => $provider->endpoint . '/api/tags',
                default => $provider->endpoint . '/v1/models',
            };

            $request = $this->requestFactory->createRequest('GET', $endpoint);

            // Add authentication headers
            $request = match ($provider->adapterType) {
                'anthropic' => $request
                    ->withHeader('x-api-key', $apiKey)
                    ->withHeader('anthropic-version', '2023-06-01'),
                'gemini' => $request->withHeader('x-goog-api-key', $apiKey),
                'ollama' => $request, // No auth needed
                default => $request->withHeader('Authorization', 'Bearer ' . $apiKey),
            };

            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'message' => sprintf('Connected to %s successfully', $provider->suggestedName),
                ];
            }

            if ($statusCode === 401) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed. Please check your API key.',
                ];
            }

            return [
                'success' => false,
                'message' => sprintf('Connection failed with status code %d', $statusCode),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Discover models from provider.
     *
     * @return array<DiscoveredModel>
     */
    public function discover(DetectedProvider $provider, string $apiKey): array
    {
        return match ($provider->adapterType) {
            'openai' => $this->discoverOpenAI($provider->endpoint, $apiKey),
            'anthropic' => $this->getAnthropicModels(),
            'gemini' => $this->discoverGemini($provider->endpoint, $apiKey),
            'openrouter' => $this->discoverOpenRouter($provider->endpoint, $apiKey),
            'ollama' => $this->discoverOllama($provider->endpoint),
            'mistral' => $this->discoverMistral($provider->endpoint, $apiKey),
            'groq' => $this->discoverGroq($provider->endpoint, $apiKey),
            default => $this->getDefaultModels($provider->adapterType),
        };
    }

    /**
     * Discover OpenAI models via API.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverOpenAI(string $endpoint, string $apiKey): array
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $endpoint . '/v1/models')
                ->withHeader('Authorization', 'Bearer ' . $apiKey)
                ->withHeader('Content-Type', 'application/json');

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                return $this->getOpenAIFallbackModels();
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $models = [];

            if (!is_array($data)) {
                return $this->getOpenAIFallbackModels();
            }

            $dataList = $data['data'] ?? [];
            if (!is_array($dataList)) {
                return $this->getOpenAIFallbackModels();
            }

            foreach ($dataList as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $modelId = $model['id'] ?? '';
                if (!is_string($modelId) || $modelId === '' || !$this->isRelevantOpenAIModel($modelId)) {
                    continue;
                }

                $models[] = $this->enrichOpenAIModel($modelId);
            }

            // Sort by recommendation
            usort($models, fn(DiscoveredModel $a, DiscoveredModel $b) => $b->recommended <=> $a->recommended);

            return $models !== [] ? $models : $this->getOpenAIFallbackModels();
        } catch (Throwable) {
            return $this->getOpenAIFallbackModels();
        }
    }

    /**
     * Check if OpenAI model is relevant (not deprecated/internal).
     */
    private function isRelevantOpenAIModel(string $modelId): bool
    {
        // Include GPT-5.x, GPT-4o, o4, o3 models
        $patterns = [
            '/^gpt-5/',
            '/^gpt-4o/',
            '/^o[34]-/',
            '/^gpt-image/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $modelId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enrich OpenAI model with known specifications.
     */
    private function enrichOpenAIModel(string $modelId): DiscoveredModel
    {
        // December 2025 OpenAI model specifications
        $specs = [
            'gpt-5.2' => [
                'name' => 'GPT-5.2 Thinking',
                'description' => 'Flagship model for coding, reasoning, and agentic tasks',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming', 'reasoning'],
                'contextLength' => 400000,
                'maxOutputTokens' => 128000,
                'costInput' => 175, // $1.75 per 1M = 175 cents per 1M
                'costOutput' => 1400, // $14 per 1M
                'recommended' => true,
            ],
            'gpt-5.2-pro' => [
                'name' => 'GPT-5.2 Pro',
                'description' => 'Extended thinking for complex tasks',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming', 'reasoning'],
                'contextLength' => 400000,
                'maxOutputTokens' => 128000,
                'costInput' => 350,
                'costOutput' => 2800,
                'recommended' => false,
            ],
            'gpt-5.2-chat-latest' => [
                'name' => 'GPT-5.2 Instant',
                'description' => 'Fast responses for interactive use',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 400000,
                'maxOutputTokens' => 32000,
                'costInput' => 100,
                'costOutput' => 400,
                'recommended' => true,
            ],
            'gpt-5' => [
                'name' => 'GPT-5',
                'description' => 'Previous generation flagship model',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming', 'reasoning'],
                'contextLength' => 200000,
                'maxOutputTokens' => 64000,
                'costInput' => 150,
                'costOutput' => 600,
                'recommended' => false,
            ],
            'gpt-5-mini' => [
                'name' => 'GPT-5 Mini',
                'description' => 'Smaller, faster, cost-effective',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 128000,
                'maxOutputTokens' => 32000,
                'costInput' => 30,
                'costOutput' => 120,
                'recommended' => true,
            ],
            'o4-mini' => [
                'name' => 'O4 Mini',
                'description' => 'Fast reasoning for math, coding, visual tasks',
                'capabilities' => ['chat', 'vision', 'tools', 'reasoning'],
                'contextLength' => 200000,
                'maxOutputTokens' => 100000,
                'costInput' => 110,
                'costOutput' => 440,
                'recommended' => false,
            ],
            'gpt-4o' => [
                'name' => 'GPT-4o',
                'description' => 'Legacy multimodal model',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 128000,
                'maxOutputTokens' => 16384,
                'costInput' => 250,
                'costOutput' => 1000,
                'recommended' => false,
            ],
        ];

        $spec = $specs[$modelId] ?? [
            'name' => $modelId,
            'description' => 'OpenAI model',
            'capabilities' => ['chat'],
            'contextLength' => 0,
            'maxOutputTokens' => 0,
            'costInput' => 0,
            'costOutput' => 0,
            'recommended' => false,
        ];

        return new DiscoveredModel(
            modelId: $modelId,
            name: $spec['name'],
            description: $spec['description'],
            capabilities: $spec['capabilities'],
            contextLength: $spec['contextLength'],
            maxOutputTokens: $spec['maxOutputTokens'],
            costInput: $spec['costInput'],
            costOutput: $spec['costOutput'],
            recommended: $spec['recommended'],
        );
    }

    /**
     * Get OpenAI fallback models (when API discovery fails).
     *
     * @return array<DiscoveredModel>
     */
    private function getOpenAIFallbackModels(): array
    {
        return [
            $this->enrichOpenAIModel('gpt-5.2'),
            $this->enrichOpenAIModel('gpt-5.2-chat-latest'),
            $this->enrichOpenAIModel('gpt-5-mini'),
            $this->enrichOpenAIModel('o4-mini'),
        ];
    }

    /**
     * Get Anthropic models (no discovery API available).
     *
     * December 2025: Claude 4.x family
     *
     * @return array<DiscoveredModel>
     */
    private function getAnthropicModels(): array
    {
        return [
            new DiscoveredModel(
                modelId: 'claude-opus-4-5',
                name: 'Claude Opus 4.5',
                description: 'Most intelligent, best for coding, agents, and computer use',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 32000,
                costInput: 500, // $5 per 1M
                costOutput: 2500, // $25 per 1M
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'claude-sonnet-4-5',
                name: 'Claude Sonnet 4.5',
                description: 'Balanced performance and cost, 1M context available',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 32000,
                costInput: 300, // $3 per 1M
                costOutput: 1500, // $15 per 1M
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'claude-haiku-4-5',
                name: 'Claude Haiku 4.5',
                description: 'Fast and cost-effective for simple tasks',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 16000,
                costInput: 100, // $1 per 1M
                costOutput: 500, // $5 per 1M
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'claude-opus-4',
                name: 'Claude Opus 4',
                description: 'Previous generation Opus model',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 16000,
                costInput: 1500,
                costOutput: 7500,
                recommended: false,
            ),
        ];
    }

    /**
     * Discover Gemini models.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverGemini(string $endpoint, string $apiKey): array
    {
        try {
            $url = $endpoint . '/v1/models?key=' . $apiKey;
            $request = $this->requestFactory->createRequest('GET', $url);
            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                return $this->getGeminiFallbackModels();
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $models = [];

            if (!is_array($data)) {
                return $this->getGeminiFallbackModels();
            }

            $modelList = $data['models'] ?? [];
            if (!is_array($modelList)) {
                return $this->getGeminiFallbackModels();
            }

            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $modelName = $model['name'] ?? '';
                if (!is_string($modelName)) {
                    continue;
                }
                $modelId = str_replace('models/', '', $modelName);
                if ($modelId === '' || !$this->isRelevantGeminiModel($modelId)) {
                    continue;
                }

                /** @var array<string, mixed> $model */
                $models[] = $this->enrichGeminiModel($modelId, $model);
            }

            return $models !== [] ? $models : $this->getGeminiFallbackModels();
        } catch (Throwable) {
            return $this->getGeminiFallbackModels();
        }
    }

    /**
     * Check if Gemini model is relevant.
     */
    private function isRelevantGeminiModel(string $modelId): bool
    {
        return str_starts_with($modelId, 'gemini-3')
               || str_starts_with($modelId, 'gemini-2.5')
               || str_starts_with($modelId, 'gemini-2.0');
    }

    /**
     * Enrich Gemini model with specifications.
     *
     * @param array<string, mixed> $apiData
     */
    private function enrichGeminiModel(string $modelId, array $apiData): DiscoveredModel
    {
        // December 2025 Gemini specifications
        $specs = [
            'gemini-3-flash' => [
                'name' => 'Gemini 3 Flash',
                'description' => 'Frontier intelligence built for speed',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'costInput' => 50, // $0.50 per 1M
                'costOutput' => 300, // $3 per 1M
                'recommended' => true,
            ],
            'gemini-3-pro' => [
                'name' => 'Gemini 3 Pro',
                'description' => 'Advanced reasoning for agentic workflows',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming', 'reasoning'],
                'costInput' => 125,
                'costOutput' => 500,
                'recommended' => true,
            ],
            'gemini-2.5-flash' => [
                'name' => 'Gemini 2.5 Flash',
                'description' => 'Previous generation fast model',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'costInput' => 35,
                'costOutput' => 150,
                'recommended' => false,
            ],
            'gemini-2.0-flash' => [
                'name' => 'Gemini 2.0 Flash',
                'description' => 'Cost-effective general purpose',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'costInput' => 10,
                'costOutput' => 40,
                'recommended' => false,
            ],
        ];

        $spec = $specs[$modelId] ?? null;

        // Extract values with proper type casting
        $displayName = isset($apiData['displayName']) && is_string($apiData['displayName'])
            ? $apiData['displayName']
            : $modelId;
        $apiDescription = isset($apiData['description']) && is_string($apiData['description'])
            ? $apiData['description']
            : 'Gemini model';
        $inputTokenLimit = isset($apiData['inputTokenLimit']) && is_int($apiData['inputTokenLimit'])
            ? $apiData['inputTokenLimit']
            : 1000000;
        $outputTokenLimit = isset($apiData['outputTokenLimit']) && is_int($apiData['outputTokenLimit'])
            ? $apiData['outputTokenLimit']
            : 8192;

        return new DiscoveredModel(
            modelId: $modelId,
            name: $spec['name'] ?? $displayName,
            description: $spec['description'] ?? $apiDescription,
            capabilities: $spec['capabilities'] ?? ['chat', 'vision'],
            contextLength: $inputTokenLimit,
            maxOutputTokens: $outputTokenLimit,
            costInput: $spec['costInput'] ?? 0,
            costOutput: $spec['costOutput'] ?? 0,
            recommended: $spec['recommended'] ?? false,
        );
    }

    /**
     * Get Gemini fallback models.
     *
     * @return array<DiscoveredModel>
     */
    private function getGeminiFallbackModels(): array
    {
        return [
            new DiscoveredModel(
                modelId: 'gemini-3-flash',
                name: 'Gemini 3 Flash',
                description: 'Frontier intelligence built for speed',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 1000000,
                maxOutputTokens: 65536,
                costInput: 50,
                costOutput: 300,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'gemini-3-pro',
                name: 'Gemini 3 Pro',
                description: 'Advanced reasoning for agentic workflows',
                capabilities: ['chat', 'vision', 'tools', 'streaming', 'reasoning'],
                contextLength: 1000000,
                maxOutputTokens: 65536,
                costInput: 125,
                costOutput: 500,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'gemini-2.5-flash',
                name: 'Gemini 2.5 Flash',
                description: 'Previous generation fast model',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 1000000,
                maxOutputTokens: 8192,
                costInput: 35,
                costOutput: 150,
                recommended: false,
            ),
        ];
    }

    /**
     * Discover Ollama models via API.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverOllama(string $endpoint): array
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $endpoint . '/api/tags');
            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $models = [];

            $modelList = is_array($data) && isset($data['models']) && is_array($data['models'])
                ? $data['models']
                : [];

            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $modelId = isset($model['name']) && is_string($model['name']) ? $model['name'] : '';
                if ($modelId === '') {
                    continue;
                }

                // Get model details via /api/show to retrieve context length
                $modelDetails = $this->getOllamaModelDetails($endpoint, $modelId);

                $models[] = new DiscoveredModel(
                    modelId: $modelId,
                    name: $modelId,
                    description: $modelDetails['description'],
                    capabilities: $modelDetails['capabilities'],
                    contextLength: $modelDetails['contextLength'],
                    maxOutputTokens: $modelDetails['maxOutputTokens'],
                    costInput: 0,
                    costOutput: 0,
                    recommended: true,
                );
            }

            return $models;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get detailed model info from Ollama's /api/show endpoint.
     *
     * @return array{description: string, capabilities: array<string>, contextLength: int, maxOutputTokens: int}
     */
    private function getOllamaModelDetails(string $endpoint, string $modelId): array
    {
        $defaults = [
            'description' => 'Local Ollama model',
            'capabilities' => ['chat'],
            'contextLength' => 0,
            'maxOutputTokens' => 0,
        ];

        try {
            // Create body with model name
            $body = json_encode(['name' => $modelId], JSON_THROW_ON_ERROR);
            $stream = $this->streamFactory->createStream($body);

            $request = $this->requestFactory->createRequest('POST', $endpoint . '/api/show')
                ->withHeader('Content-Type', 'application/json')
                ->withBody($stream);

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                return $defaults;
            }

            $data = json_decode($response->getBody()->getContents(), true);
            if (!is_array($data)) {
                return $defaults;
            }

            // Extract model parameters
            $modelInfo = isset($data['model_info']) && is_array($data['model_info'])
                ? $data['model_info']
                : [];

            // Context length is in model_info or parameters
            $contextLength = 0;
            $parameters = isset($data['parameters']) && is_string($data['parameters'])
                ? $data['parameters']
                : '';

            // Try to get from model_info (newer Ollama versions)
            foreach ($modelInfo as $key => $value) {
                if (str_contains(strtolower((string)$key), 'context') && is_numeric($value)) {
                    $contextLength = (int)$value;
                    break;
                }
            }

            // Try to parse from parameters string (e.g., "num_ctx 32768")
            if ($contextLength === 0 && $parameters !== '') {
                if (preg_match('/num_ctx\s+(\d+)/i', $parameters, $matches)) {
                    $contextLength = (int)$matches[1];
                }
            }

            // Detect capabilities based on model family
            $capabilities = ['chat'];
            $modelIdLower = strtolower($modelId);

            // Vision models
            if (str_contains($modelIdLower, 'vision') || str_contains($modelIdLower, 'llava')) {
                $capabilities[] = 'vision';
            }

            // Tool-use models (qwen, llama 3.x, mistral, etc.)
            if (str_contains($modelIdLower, 'qwen')
                || str_contains($modelIdLower, 'llama3')
                || str_contains($modelIdLower, 'mistral')
                || str_contains($modelIdLower, 'mixtral')) {
                $capabilities[] = 'tools';
            }

            // Ollama doesn't expose max output tokens, so derive sensible defaults
            // Most models can output up to 1/4 of context, with reasonable caps
            $maxOutputTokens = $this->estimateOllamaMaxOutput($modelIdLower, $contextLength);

            return [
                'description' => 'Local Ollama model',
                'capabilities' => $capabilities,
                'contextLength' => $contextLength,
                'maxOutputTokens' => $maxOutputTokens,
            ];
        } catch (Throwable) {
            return $defaults;
        }
    }

    /**
     * Estimate max output tokens for Ollama models.
     *
     * Ollama doesn't expose this, so we use model-specific defaults
     * or derive from context length.
     */
    private function estimateOllamaMaxOutput(string $modelIdLower, int $contextLength): int
    {
        // Known model limits (December 2025)
        $knownLimits = [
            'qwen' => 8192,      // Qwen models typically 8K output
            'llama3' => 8192,    // Llama 3.x models
            'llama-3' => 8192,
            'mistral' => 8192,   // Mistral models
            'mixtral' => 8192,
            'gemma' => 8192,     // Gemma models
            'phi' => 4096,       // Phi models (smaller)
            'codellama' => 16384, // Code models need longer output
            'deepseek' => 8192,
            'yi' => 4096,
        ];

        // Check for known model families
        foreach ($knownLimits as $family => $limit) {
            if (str_contains($modelIdLower, $family)) {
                return $limit;
            }
        }

        // If we have context length, use 1/4 of it (capped at 16K)
        if ($contextLength > 0) {
            return min((int)($contextLength / 4), 16384);
        }

        // Default fallback
        return 4096;
    }

    /**
     * Discover OpenRouter models.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverOpenRouter(string $endpoint, string $apiKey): array
    {
        try {
            $request = $this->requestFactory->createRequest('GET', 'https://openrouter.ai/api/v1/models')
                ->withHeader('Authorization', 'Bearer ' . $apiKey);

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $models = [];

            $modelList = is_array($data) && isset($data['data']) && is_array($data['data'])
                ? $data['data']
                : [];

            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $modelId = isset($model['id']) && is_string($model['id']) ? $model['id'] : '';
                if ($modelId === '') {
                    continue;
                }

                $pricing = isset($model['pricing']) && is_array($model['pricing']) ? $model['pricing'] : [];
                $modelName = isset($model['name']) && is_string($model['name']) ? $model['name'] : $modelId;
                $modelDescription = isset($model['description']) && is_string($model['description'])
                    ? $model['description']
                    : 'OpenRouter model';
                $contextLength = isset($model['context_length']) && is_numeric($model['context_length'])
                    ? (int)$model['context_length']
                    : 0;
                $promptCost = isset($pricing['prompt']) && is_numeric($pricing['prompt'])
                    ? (float)$pricing['prompt']
                    : 0.0;
                $completionCost = isset($pricing['completion']) && is_numeric($pricing['completion'])
                    ? (float)$pricing['completion']
                    : 0.0;

                $models[] = new DiscoveredModel(
                    modelId: $modelId,
                    name: $modelName,
                    description: $modelDescription,
                    capabilities: ['chat'],
                    contextLength: $contextLength,
                    maxOutputTokens: 0,
                    costInput: (int)($promptCost * 100000000),
                    costOutput: (int)($completionCost * 100000000),
                    recommended: false,
                );
            }

            // Limit to first 20 models
            return array_slice($models, 0, 20);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Discover Mistral models.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverMistral(string $endpoint, string $apiKey): array
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $endpoint . '/v1/models')
                ->withHeader('Authorization', 'Bearer ' . $apiKey);

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                return $this->getMistralFallbackModels();
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $models = [];

            $modelList = is_array($data) && isset($data['data']) && is_array($data['data'])
                ? $data['data']
                : [];

            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $modelId = isset($model['id']) && is_string($model['id']) ? $model['id'] : '';
                if ($modelId === '') {
                    continue;
                }

                $models[] = new DiscoveredModel(
                    modelId: $modelId,
                    name: $modelId,
                    description: 'Mistral AI model',
                    capabilities: ['chat', 'tools'],
                    contextLength: 0,
                    maxOutputTokens: 0,
                    costInput: 0,
                    costOutput: 0,
                    recommended: str_contains($modelId, 'large') || str_contains($modelId, 'medium'),
                );
            }

            return $models !== [] ? $models : $this->getMistralFallbackModels();
        } catch (Throwable) {
            return $this->getMistralFallbackModels();
        }
    }

    /**
     * Get Mistral fallback models.
     *
     * @return array<DiscoveredModel>
     */
    private function getMistralFallbackModels(): array
    {
        return [
            new DiscoveredModel(
                modelId: 'mistral-large-latest',
                name: 'Mistral Large',
                description: 'Flagship model for complex tasks',
                capabilities: ['chat', 'tools', 'streaming'],
                contextLength: 128000,
                maxOutputTokens: 8192,
                costInput: 200,
                costOutput: 600,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'mistral-medium-latest',
                name: 'Mistral Medium',
                description: 'Balanced performance',
                capabilities: ['chat', 'tools', 'streaming'],
                contextLength: 32000,
                maxOutputTokens: 8192,
                costInput: 100,
                costOutput: 300,
                recommended: true,
            ),
        ];
    }

    /**
     * Discover Groq models.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverGroq(string $endpoint, string $apiKey): array
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $endpoint . '/openai/v1/models')
                ->withHeader('Authorization', 'Bearer ' . $apiKey);

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = json_decode($response->getBody()->getContents(), true);
            $models = [];

            $modelList = is_array($data) && isset($data['data']) && is_array($data['data'])
                ? $data['data']
                : [];

            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $modelId = isset($model['id']) && is_string($model['id']) ? $model['id'] : '';
                if ($modelId === '') {
                    continue;
                }

                $contextWindow = isset($model['context_window']) && is_numeric($model['context_window'])
                    ? (int)$model['context_window']
                    : 0;

                $models[] = new DiscoveredModel(
                    modelId: $modelId,
                    name: $modelId,
                    description: 'Groq-accelerated model',
                    capabilities: ['chat'],
                    contextLength: $contextWindow,
                    maxOutputTokens: 0,
                    costInput: 0,
                    costOutput: 0,
                    recommended: true,
                );
            }

            return $models;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Get default models for unknown adapter types.
     *
     * @return array<DiscoveredModel>
     */
    private function getDefaultModels(string $adapterType): array
    {
        return [
            new DiscoveredModel(
                modelId: 'default',
                name: 'Default Model',
                description: 'Default model for ' . $adapterType,
                capabilities: ['chat'],
                recommended: true,
            ),
        ];
    }
}
