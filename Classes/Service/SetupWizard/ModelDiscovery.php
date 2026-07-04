<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard;

use JsonException;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Discovers available models from LLM provider APIs.
 *
 * Outbound requests are dispatched through the nr-vault secure HTTP client
 * (`$vault->http()`), which enforces the SSRF host guard, scheme validation,
 * redirect blocking and audit logging — the same hardened path the providers
 * and specialised services use. The wizard authenticates with the plaintext
 * key the operator just typed (it is not yet stored in the vault), so it
 * builds its own auth headers but still routes through the secure client and
 * pre-gates the target host via `SecureHttpClientFactory::isHostAllowed()`.
 *
 * Model information updated: June 2026
 */
final class ModelDiscovery implements ModelDiscoveryInterface
{
    use ErrorMessageSanitizerTrait;
    use SecureHttpDispatchTrait;

    /**
     * Audit-log reason the vault secure client records for every outbound
     * request, passed to `SecureHttpDispatchTrait::dispatch()`.
     */
    private const VAULT_DISPATCH_REASON = 'LLM setup-wizard model discovery';

    /** Resource path appended to a provider endpoint to list available models. */
    private const MODELS_PATH = '/models';

    /** Authorization header value prefix for Bearer-token providers. */
    private const AUTH_BEARER_PREFIX = 'Bearer ';

    /**
     * Whether the most recent discover() call substituted a static fallback
     * catalog for live API data (failed request, unexpected status, or
     * malformed/empty response).
     */
    private bool $lastDiscoveryUsedFallback = false;

    public function __construct(
        VaultServiceInterface $vault,
        SecureHttpClientFactory $httpClientFactory,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {
        // Initialise the readonly collaborators declared in
        // SecureHttpDispatchTrait (no promotion — the trait owns them).
        $this->vault = $vault;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * Test connection to provider.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(DetectedProvider $provider, string $apiKey): array
    {
        try {
            $base = rtrim($provider->endpoint, '/');
            $endpoint = match ($provider->adapterType) {
                // Ollama's base URL is a bare host (OllamaProvider adds "api/" per
                // request); model discovery must do the same to hit /api/tags. A legacy
                // or user-entered trailing "/api" is stripped first to avoid /api/api.
                'ollama' => $this->ollamaBaseUrl($base) . '/api/tags',
                default => $base . self::MODELS_PATH,
            };

            $request = $this->requestFactory->createRequest('GET', $endpoint);

            // Add authentication headers
            $request = match ($provider->adapterType) {
                'anthropic' => $request
                    ->withHeader('x-api-key', $apiKey)
                    ->withHeader('anthropic-version', '2023-06-01'),
                'gemini' => $request->withHeader('x-goog-api-key', $apiKey),
                'ollama' => $request, // No auth needed
                default => $request->withHeader('Authorization', self::AUTH_BEARER_PREFIX . $apiKey),
            };

            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'message' => sprintf('Connected to %s successfully', $provider->suggestedName),
                ];
            }

            $message = $statusCode === 401
                ? 'Authentication failed. Please check your API key.'
                : sprintf('Connection failed with status code %d', $statusCode);

            return [
                'success' => false,
                'message' => $message,
            ];
        } catch (Throwable $e) {
            // Don't echo the raw exception back to the client: it can carry the
            // target URL (incl. a `?key=` secret) or internal host details. Log
            // the sanitised detail server-side and return a generic message.
            $this->logger->warning(
                'LLM setup-wizard connection test failed',
                [
                    'provider'  => $provider->adapterType,
                    'exception' => $this->sanitizeErrorMessage($e->getMessage()),
                ],
            );

            return [
                'success' => false,
                'message' => 'Connection error. Please verify the endpoint and API key, then try again.',
            ];
        }
    }

    /**
     * Discover models from provider.
     *
     * Endpoint URLs include the API version path (e.g. https://api.openai.com/v1).
     * Discovery methods append only the resource path (e.g. /models).
     *
     * @return array<DiscoveredModel>
     */
    public function discover(DetectedProvider $provider, string $apiKey): array
    {
        $this->lastDiscoveryUsedFallback = false;
        $endpoint = rtrim($provider->endpoint, '/');

        return match ($provider->adapterType) {
            'openai' => $this->discoverOpenAI($endpoint, $apiKey),
            'anthropic' => $this->discoverAnthropic($endpoint, $apiKey),
            'gemini' => $this->discoverGemini($endpoint, $apiKey),
            'openrouter' => $this->discoverOpenRouter($endpoint, $apiKey),
            'ollama' => $this->discoverOllama($endpoint),
            'mistral' => $this->discoverMistral($endpoint, $apiKey),
            'groq' => $this->discoverGroq($endpoint, $apiKey),
            default => $this->getDefaultModels($provider->adapterType),
        };
    }

    public function wasLastDiscoveryFromFallback(): bool
    {
        return $this->lastDiscoveryUsedFallback;
    }

    /**
     * Mark the current discovery as served from a static fallback catalog.
     *
     * @param array<DiscoveredModel> $models
     *
     * @return array<DiscoveredModel>
     */
    private function asFallback(array $models): array
    {
        $this->lastDiscoveryUsedFallback = true;

        return $models;
    }

    /**
     * Log a failed discovery request.
     *
     * Never includes the API key: only the exception class and its
     * sanitised message are recorded.
     */
    private function logDiscoveryFailure(string $adapterType, Throwable $e): void
    {
        $this->logger->warning('LLM model discovery request failed', [
            'provider' => $adapterType,
            'exception' => $e::class,
            'message' => $this->sanitizeErrorMessage($e->getMessage()),
        ]);
    }

    /**
     * Log a discovery response with an unexpected HTTP status
     * (e.g. 401 for an invalid or missing API key).
     */
    private function logDiscoveryHttpError(string $adapterType, int $statusCode): void
    {
        $this->logger->warning('LLM model discovery returned an unexpected HTTP status', [
            'provider' => $adapterType,
            'status' => $statusCode,
        ]);
    }

    /**
     * Decode a provider's models-listing JSON body. On malformed JSON, log a
     * warning with the provider and a short body sample (so a broken upstream
     * response is distinguishable from an empty one) and return null — callers
     * then keep their existing fallback. Never includes the API key.
     *
     * @return array<int|string, mixed>|null
     */
    private function decodeModelListBody(string $adapterType, string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning('LLM model discovery received a malformed JSON response', [
                'provider' => $adapterType,
                'message' => $e->getMessage(),
                'sample' => substr($body, 0, 200),
            ]);

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Discover OpenAI models via API.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverOpenAI(string $endpoint, string $apiKey): array
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $endpoint . self::MODELS_PATH)
                ->withHeader('Authorization', self::AUTH_BEARER_PREFIX . $apiKey)
                ->withHeader('Content-Type', 'application/json');

            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            if ($response->getStatusCode() !== 200) {
                $this->logDiscoveryHttpError('openai', $response->getStatusCode());

                return $this->asFallback($this->getOpenAIFallbackModels());
            }

            $data = $this->decodeModelListBody('openai', $response->getBody()->getContents());
            $dataList = is_array($data) && isset($data['data']) && is_array($data['data'])
                ? $data['data']
                : [];

            $models = [];
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

            return $models !== [] ? $models : $this->asFallback($this->getOpenAIFallbackModels());
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('openai', $e);

            return $this->asFallback($this->getOpenAIFallbackModels());
        }
    }

    /**
     * Check if OpenAI model is relevant (not deprecated/internal).
     */
    private function isRelevantOpenAIModel(string $modelId): bool
    {
        // Include current-generation models
        $patterns = [
            '/^gpt-5/',
            '/^gpt-4o/',
            '/^gpt-4-turbo/',
            '/^gpt-4\./',       // gpt-4.1, gpt-4.1-mini, etc.
            '/^o[1234]-/',      // o1, o3, o4 series
            '/^gpt-image/',
            '/^chatgpt-/',      // chatgpt-4o-latest etc.
            '/^tts-/',          // tts-1, tts-1-hd (text-to-speech)
            '/-tts$/',          // gpt-4o-mini-tts etc.
            '/^whisper-/',      // whisper-1 (transcription)
            '/-transcribe$/',   // gpt-4o-transcribe etc.
            '/^dall-e-/',       // dall-e-3 (image generation)
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $modelId)) {
                return true;
            }
        }

        // Exclude known irrelevant models
        if (str_starts_with($modelId, 'text-embedding')
            || str_starts_with($modelId, 'babbage')
            || str_starts_with($modelId, 'davinci')
            || str_contains($modelId, 'instruct')
            || str_contains($modelId, 'realtime')
            || str_contains($modelId, '-search')
        ) {
            return false;
        }

        // Include anything else with a gpt- or o- prefix
        return str_starts_with($modelId, 'gpt-') || preg_match('/^o\d/', $modelId) === 1;
    }

    /**
     * Enrich OpenAI model with known specifications.
     */
    private function enrichOpenAIModel(string $modelId): DiscoveredModel
    {
        $spec = $this->openAIModelSpecs()[$modelId] ?? [
            'name' => $modelId,
            'description' => 'OpenAI model',
            'capabilities' => $this->defaultOpenAICapabilities($modelId),
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
     * June 2026 OpenAI model specifications, keyed by model id.
     *
     * @return array<string, array{name: string, description: string, capabilities: array<string>, contextLength: int, maxOutputTokens: int, costInput: int, costOutput: int, recommended: bool}>
     */
    private function openAIModelSpecs(): array
    {
        return [
            'gpt-5.5' => [
                'name' => 'GPT-5.5',
                'description' => 'Latest flagship model with enhanced reasoning',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming', 'reasoning'],
                'contextLength' => 400000,
                'maxOutputTokens' => 128000,
                'costInput' => 500,
                'costOutput' => 3000,
                'recommended' => true,
            ],
            'gpt-5.3' => [
                'name' => 'GPT-5.3',
                'description' => 'Flagship model with enhanced reasoning',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming', 'reasoning'],
                'contextLength' => 400000,
                'maxOutputTokens' => 128000,
                'costInput' => 175,
                'costOutput' => 1400,
                'recommended' => true,
            ],
            'gpt-5.3-chat-latest' => [
                'name' => 'GPT-5.3 Chat',
                'description' => 'Fast responses for interactive use',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 400000,
                'maxOutputTokens' => 32000,
                'costInput' => 100,
                'costOutput' => 400,
                'recommended' => true,
            ],
            'gpt-5.3-mini' => [
                'name' => 'GPT-5.3 Mini',
                'description' => 'Small, fast, cost-effective',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 200000,
                'maxOutputTokens' => 32000,
                'costInput' => 30,
                'costOutput' => 120,
                'recommended' => true,
            ],
            'gpt-5.2' => [
                'name' => 'GPT-5.2 Thinking',
                'description' => 'Flagship model for coding, reasoning, and agentic tasks',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming', 'reasoning'],
                'contextLength' => 400000,
                'maxOutputTokens' => 128000,
                'costInput' => 175,
                'costOutput' => 1400,
                'recommended' => false,
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
                'recommended' => false,
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
                'recommended' => false,
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
            'o3' => [
                'name' => 'O3',
                'description' => 'Advanced reasoning model',
                'capabilities' => ['chat', 'vision', 'tools', 'reasoning'],
                'contextLength' => 200000,
                'maxOutputTokens' => 100000,
                'costInput' => 200,
                'costOutput' => 800,
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
            'gpt-4.1' => [
                'name' => 'GPT-4.1',
                'description' => 'Coding and instruction-following model',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 1047576,
                'maxOutputTokens' => 32768,
                'costInput' => 200,
                'costOutput' => 800,
                'recommended' => false,
            ],
            'gpt-4.1-mini' => [
                'name' => 'GPT-4.1 Mini',
                'description' => 'Fast coding model',
                'capabilities' => ['chat', 'vision', 'tools', 'streaming'],
                'contextLength' => 1047576,
                'maxOutputTokens' => 32768,
                'costInput' => 40,
                'costOutput' => 160,
                'recommended' => false,
            ],
            // Specialized models — see specializedSpec() for the shared shape.
            'gpt-image-2' => self::specializedSpec('GPT Image 2', 'Image generation model', 'image'),
            'tts-1' => self::specializedSpec('TTS-1', 'Text-to-speech model optimized for speed', 'text_to_speech'),
            'tts-1-hd' => self::specializedSpec('TTS-1 HD', 'Text-to-speech model optimized for quality', 'text_to_speech'),
            'whisper-1' => self::specializedSpec('Whisper', 'Speech-to-text transcription model', 'transcription'),
        ];
    }

    /**
     * Derive default capabilities from the model id shape for OpenAI models
     * without an explicit spec entry. The returned values match the
     * ModelCapability enum (image / text_to_speech / transcription / chat).
     *
     * @return array<string>
     */
    private function defaultOpenAICapabilities(string $modelId): array
    {
        return match (true) {
            str_starts_with($modelId, 'dall-e-'),
            str_starts_with($modelId, 'gpt-image') => ['image'],
            str_starts_with($modelId, 'tts-'),
            str_ends_with($modelId, '-tts') => ['text_to_speech'],
            str_starts_with($modelId, 'whisper-'),
            str_ends_with($modelId, '-transcribe') => ['transcription'],
            default => ['chat'],
        };
    }

    /**
     * Build a spec entry for a specialized (non-chat) model.
     *
     * Context length, max output tokens, and token-based costs do not apply
     * to these models (priced per image / character / minute), hence 0.
     * The capability value matches the ModelCapability enum.
     *
     * @return array{name: string, description: string, capabilities: array<string>, contextLength: int, maxOutputTokens: int, costInput: int, costOutput: int, recommended: bool}
     */
    private static function specializedSpec(string $name, string $description, string $capability): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'capabilities' => [$capability],
            'contextLength' => 0,
            'maxOutputTokens' => 0,
            'costInput' => 0,
            'costOutput' => 0,
            'recommended' => false,
        ];
    }

    /**
     * Get OpenAI fallback models (when API discovery fails).
     *
     * @return array<DiscoveredModel>
     */
    private function getOpenAIFallbackModels(): array
    {
        return [
            $this->enrichOpenAIModel('gpt-5.5'),
            $this->enrichOpenAIModel('gpt-5.3'),
            $this->enrichOpenAIModel('gpt-5.3-chat-latest'),
            $this->enrichOpenAIModel('gpt-5.3-mini'),
            $this->enrichOpenAIModel('gpt-5.2'),
            $this->enrichOpenAIModel('gpt-5.2-chat-latest'),
            $this->enrichOpenAIModel('gpt-5-mini'),
            $this->enrichOpenAIModel('gpt-4o'),
            $this->enrichOpenAIModel('o4-mini'),
            $this->enrichOpenAIModel('o3'),
            $this->enrichOpenAIModel('gpt-image-2'),
            $this->enrichOpenAIModel('tts-1'),
            $this->enrichOpenAIModel('tts-1-hd'),
            $this->enrichOpenAIModel('whisper-1'),
        ];
    }

    /**
     * Discover Anthropic models via API.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverAnthropic(string $endpoint, string $apiKey): array
    {
        try {
            $request = $this->requestFactory->createRequest('GET', $endpoint . self::MODELS_PATH)
                ->withHeader('x-api-key', $apiKey)
                ->withHeader('anthropic-version', '2023-06-01');

            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            if ($response->getStatusCode() !== 200) {
                $this->logDiscoveryHttpError('anthropic', $response->getStatusCode());

                return $this->asFallback($this->getAnthropicFallbackModels());
            }

            $data = $this->decodeModelListBody('anthropic', $response->getBody()->getContents());
            $modelList = is_array($data) && isset($data['data']) && is_array($data['data'])
                ? $data['data']
                : [];

            $models = [];
            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $modelId = $model['id'] ?? '';
                if (!is_string($modelId) || $modelId === '') {
                    continue;
                }

                /** @var array<string, mixed> $model */
                $models[] = $this->enrichAnthropicModel($modelId, $model);
            }

            // Sort: recommended first, then by name
            usort($models, fn(DiscoveredModel $a, DiscoveredModel $b) => $b->recommended <=> $a->recommended);

            return $models !== [] ? $models : $this->asFallback($this->getAnthropicFallbackModels());
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('anthropic', $e);

            return $this->asFallback($this->getAnthropicFallbackModels());
        }
    }

    /**
     * Enrich Anthropic model with known specifications.
     *
     * @param array<string, mixed> $apiData
     */
    private function enrichAnthropicModel(string $modelId, array $apiData): DiscoveredModel
    {
        $specs = [
            'claude-opus-4-5' => [
                'name' => 'Claude Opus 4.5',
                'description' => 'Most intelligent, best for coding, agents, and computer use',
                'costInput' => 500,
                'costOutput' => 2500,
                'recommended' => true,
            ],
            'claude-sonnet-4-5' => [
                'name' => 'Claude Sonnet 4.5',
                'description' => 'Balanced performance and cost',
                'costInput' => 300,
                'costOutput' => 1500,
                'recommended' => true,
            ],
            'claude-haiku-4-5' => [
                'name' => 'Claude Haiku 4.5',
                'description' => 'Fast and cost-effective for simple tasks',
                'costInput' => 100,
                'costOutput' => 500,
                'recommended' => true,
            ],
            'claude-opus-4' => [
                'name' => 'Claude Opus 4',
                'description' => 'Previous generation Opus',
                'costInput' => 1500,
                'costOutput' => 7500,
                'recommended' => false,
            ],
            'claude-sonnet-4' => [
                'name' => 'Claude Sonnet 4',
                'description' => 'Previous generation Sonnet',
                'costInput' => 300,
                'costOutput' => 1500,
                'recommended' => false,
            ],
        ];

        // Match by prefix (API returns dated versions like claude-opus-4-5-20251101)
        $spec = $specs[$modelId] ?? null;
        if ($spec === null) {
            foreach ($specs as $prefix => $s) {
                if (str_starts_with($modelId, $prefix)) {
                    $spec = $s;
                    break;
                }
            }
        }

        $displayName = isset($apiData['display_name']) && is_string($apiData['display_name'])
            ? $apiData['display_name']
            : ($spec['name'] ?? $modelId);

        return new DiscoveredModel(
            modelId: $modelId,
            name: $displayName,
            description: $spec['description'] ?? 'Anthropic model',
            capabilities: ['chat', 'vision', 'tools', 'streaming'],
            contextLength: 200000,
            maxOutputTokens: 32000,
            costInput: $spec['costInput'] ?? 0,
            costOutput: $spec['costOutput'] ?? 0,
            recommended: $spec['recommended'] ?? false,
        );
    }

    /**
     * Get Anthropic fallback models (when API discovery fails).
     *
     * @return array<DiscoveredModel>
     */
    private function getAnthropicFallbackModels(): array
    {
        return [
            new DiscoveredModel(
                modelId: 'claude-opus-4-5-20251101',
                name: 'Claude Opus 4.5',
                description: 'Most intelligent, best for coding, agents, and computer use',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 32000,
                costInput: 500,
                costOutput: 2500,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'claude-sonnet-4-5-20250929',
                name: 'Claude Sonnet 4.5',
                description: 'Balanced performance and cost',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 32000,
                costInput: 300,
                costOutput: 1500,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'claude-haiku-4-5-20251001',
                name: 'Claude Haiku 4.5',
                description: 'Fast and cost-effective for simple tasks',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 16000,
                costInput: 100,
                costOutput: 500,
                recommended: true,
            ),
            new DiscoveredModel(
                modelId: 'claude-opus-4-20250514',
                name: 'Claude Opus 4',
                description: 'Previous generation Opus',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 16000,
                costInput: 1500,
                costOutput: 7500,
                recommended: false,
            ),
            new DiscoveredModel(
                modelId: 'claude-sonnet-4-20250514',
                name: 'Claude Sonnet 4',
                description: 'Previous generation Sonnet',
                capabilities: ['chat', 'vision', 'tools', 'streaming'],
                contextLength: 200000,
                maxOutputTokens: 16000,
                costInput: 300,
                costOutput: 1500,
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
            $url = $endpoint . '/models';
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('x-goog-api-key', $apiKey);
            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            if ($response->getStatusCode() !== 200) {
                $this->logDiscoveryHttpError('gemini', $response->getStatusCode());

                return $this->asFallback($this->getGeminiFallbackModels());
            }

            $data = $this->decodeModelListBody('gemini', $response->getBody()->getContents());
            $modelList = is_array($data) && isset($data['models']) && is_array($data['models'])
                ? $data['models']
                : [];

            $models = [];
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

            return $models !== [] ? $models : $this->asFallback($this->getGeminiFallbackModels());
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('gemini', $e);

            return $this->asFallback($this->getGeminiFallbackModels());
        }
    }

    /**
     * Check if Gemini model is relevant.
     */
    private function isRelevantGeminiModel(string $modelId): bool
    {
        // Include all gemini models, exclude deprecated/embedding-only
        if (!str_starts_with($modelId, 'gemini-')) {
            return false;
        }

        // Exclude embedding models (not usable for chat)
        // and very old models (gemini-1.0, gemini-pro)
        if (str_contains($modelId, 'embedding')
            || str_starts_with($modelId, 'gemini-1.0')
            || str_starts_with($modelId, 'gemini-pro')
        ) {
            return false;
        }

        return true;
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
     * Ollama's base URL is a bare host (OllamaProvider prefixes every request path with
     * "api/"); strip a trailing "/api" (a legacy default or user-entered value) so
     * appended discovery paths do not become "/api/api/...".
     */
    private function ollamaBaseUrl(string $endpoint): string
    {
        return (string)preg_replace('#/api/*$#', '', rtrim($endpoint, '/'));
    }

    /**
     * Discover Ollama models via API.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverOllama(string $endpoint): array
    {
        try {
            $endpoint = $this->ollamaBaseUrl($endpoint);
            $request = $this->requestFactory->createRequest('GET', $endpoint . '/api/tags');
            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            if ($response->getStatusCode() !== 200) {
                $this->logDiscoveryHttpError('ollama', $response->getStatusCode());

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
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('ollama', $e);

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

            $request = $this->requestFactory->createRequest('POST', $this->ollamaBaseUrl($endpoint) . '/api/show')
                ->withHeader('Content-Type', 'application/json')
                ->withBody($stream);

            $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

            $data = $response->getStatusCode() === 200
                ? json_decode($response->getBody()->getContents(), true)
                : null;
            if (!is_array($data)) {
                return $defaults;
            }

            $contextLength = $this->extractOllamaContextLength($data);
            $modelIdLower = strtolower($modelId);
            $capabilities = $this->detectOllamaCapabilities($modelIdLower);

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
     * Extract the context length from an Ollama `/api/show` response.
     *
     * Context length is reported either in `model_info` (newer Ollama
     * versions) or in the `parameters` string (e.g. "num_ctx 32768").
     *
     * @param array<int|string, mixed> $data
     */
    private function extractOllamaContextLength(array $data): int
    {
        // Try to get from model_info (newer Ollama versions)
        $modelInfo = isset($data['model_info']) && is_array($data['model_info'])
            ? $data['model_info']
            : [];
        foreach ($modelInfo as $key => $value) {
            if (str_contains(strtolower((string)$key), 'context') && is_numeric($value)) {
                return (int)$value;
            }
        }

        // Try to parse from parameters string (e.g., "num_ctx 32768")
        $parameters = isset($data['parameters']) && is_string($data['parameters'])
            ? $data['parameters']
            : '';
        if ($parameters !== '' && preg_match('/num_ctx\s+(\d+)/i', $parameters, $matches) === 1) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * Detect Ollama model capabilities based on the model family.
     *
     * @return array<string>
     */
    private function detectOllamaCapabilities(string $modelIdLower): array
    {
        $capabilities = ['chat'];

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

        return $capabilities;
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
     * Fetch a Bearer-authenticated `/models` listing and return the decoded
     * `data` list.
     *
     * Returns null when the endpoint answered with a non-200 status (already
     * logged); network-level failures bubble up as exceptions so callers
     * keep their provider-specific fallback handling.
     *
     *
     * @throws Throwable when the request fails
     *
     * @return array<int|string, mixed>|null
     */
    private function fetchBearerModelList(string $adapterType, string $endpoint, string $apiKey): ?array
    {
        $request = $this->requestFactory->createRequest('GET', $endpoint . self::MODELS_PATH)
            ->withHeader('Authorization', self::AUTH_BEARER_PREFIX . $apiKey);

        $response = $this->dispatch($request, self::VAULT_DISPATCH_REASON);

        if ($response->getStatusCode() !== 200) {
            $this->logDiscoveryHttpError($adapterType, $response->getStatusCode());

            return null;
        }

        $data = json_decode($response->getBody()->getContents(), true);

        return is_array($data) && isset($data['data']) && is_array($data['data'])
            ? $data['data']
            : [];
    }

    /**
     * Discover OpenRouter models.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverOpenRouter(string $endpoint, string $apiKey): array
    {
        try {
            $modelList = $this->fetchBearerModelList('openrouter', $endpoint, $apiKey);
            if ($modelList === null) {
                return [];
            }

            $models = [];
            foreach ($modelList as $model) {
                if (!is_array($model)) {
                    continue;
                }
                $discovered = $this->mapOpenRouterModel($model);
                if ($discovered !== null) {
                    $models[] = $discovered;
                }
            }

            return $models;
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('openrouter', $e);

            return [];
        }
    }

    /**
     * Map a single OpenRouter API model entry to a DiscoveredModel.
     *
     * @param array<int|string, mixed> $model
     */
    private function mapOpenRouterModel(array $model): ?DiscoveredModel
    {
        $modelId = isset($model['id']) && is_string($model['id']) ? $model['id'] : '';
        if ($modelId === '') {
            return null;
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

        return new DiscoveredModel(
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

    /**
     * Discover Mistral models.
     *
     * @return array<DiscoveredModel>
     */
    private function discoverMistral(string $endpoint, string $apiKey): array
    {
        try {
            $modelList = $this->fetchBearerModelList('mistral', $endpoint, $apiKey);
            if ($modelList === null) {
                return $this->asFallback($this->getMistralFallbackModels());
            }

            $models = [];
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

            return $models !== [] ? $models : $this->asFallback($this->getMistralFallbackModels());
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('mistral', $e);

            return $this->asFallback($this->getMistralFallbackModels());
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
            $modelList = $this->fetchBearerModelList('groq', $endpoint, $apiKey);
            if ($modelList === null) {
                return [];
            }

            $models = [];
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
        } catch (Throwable $e) {
            $this->logDiscoveryFailure('groq', $e);

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
