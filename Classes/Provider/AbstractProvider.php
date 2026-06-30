<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

abstract class AbstractProvider implements ProviderInterface
{
    use ErrorMessageSanitizerTrait;
    use ResponseParserTrait;

    /** Placeholder for provider error payloads that carry no message. */
    private const UNKNOWN_ERROR = 'Unknown error';

    /**
     * Feature constants for provider capabilities.
     *
     * @deprecated Use ModelCapability enum instead
     */
    protected const FEATURE_CHAT = 'chat';
    /** @deprecated Use ModelCapability enum instead */
    protected const FEATURE_COMPLETION = 'completion';
    /** @deprecated Use ModelCapability enum instead */
    protected const FEATURE_EMBEDDINGS = 'embeddings';
    /** @deprecated Use ModelCapability enum instead */
    protected const FEATURE_VISION = 'vision';
    /** @deprecated Use ModelCapability enum instead */
    protected const FEATURE_STREAMING = 'streaming';
    /** @deprecated Use ModelCapability enum instead */
    protected const FEATURE_TOOLS = 'tools';

    protected string $apiKeyIdentifier = '';
    protected string $baseUrl = '';
    protected string $defaultModel = '';
    protected int $timeout = 30;
    protected int $maxRetries = 3;

    /** @var array<string> */
    protected array $supportedFeatures = [];

    private ?ClientInterface $configuredHttpClient = null;

    public function __construct(
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        protected readonly LoggerInterface $logger,
        protected readonly VaultServiceInterface $vault,
        protected readonly SecureHttpClientFactory $httpClientFactory,
    ) {}

    abstract public function getName(): string;

    abstract public function getIdentifier(): string;

    /**
     * @param array<string, mixed> $config
     */
    public function configure(array $config): void
    {
        $this->apiKeyIdentifier = $this->getString($config, 'apiKeyIdentifier');
        $this->baseUrl = $this->getString($config, 'baseUrl', $this->getDefaultBaseUrl());
        $this->defaultModel = $this->getString($config, 'defaultModel', $this->getDefaultModel());
        $this->timeout = $this->getInt($config, 'timeout', 30);
        $this->maxRetries = $this->getInt($config, 'maxRetries', 3);

        // Reset HTTP client when configuration changes
        $this->configuredHttpClient = null;
    }

    abstract protected function getDefaultBaseUrl(): string;

    public function isAvailable(): bool
    {
        return $this->apiKeyIdentifier !== '' && $this->vault->exists($this->apiKeyIdentifier);
    }

    public function supportsFeature(string|ModelCapability $feature): bool
    {
        $featureValue = $feature instanceof ModelCapability ? $feature->value : $feature;
        return in_array($featureValue, $this->supportedFeatures, true);
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        return $this->chatCompletion([
            ['role' => 'user', 'content' => $prompt],
        ], $options);
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    /**
     * Set a custom HTTP client (primarily for testing).
     *
     * @param ClientInterface $client The HTTP client to use
     *
     * @internal This method is intended for testing purposes
     */
    public function setHttpClient(ClientInterface $client): void
    {
        $this->configuredHttpClient = $client;
    }

    /**
     * Get HTTP client configured for authenticated requests.
     *
     * Uses nr-vault's VaultHttpClient for automatic secret injection
     * with audit logging. For providers without API keys (like Ollama),
     * uses the httpClientFactory directly.
     *
     * @return ClientInterface HTTP client with authentication configured
     */
    protected function getHttpClient(): ClientInterface
    {
        if ($this->configuredHttpClient !== null) {
            return $this->configuredHttpClient;
        }

        // For providers without API key, use httpClientFactory directly
        if ($this->apiKeyIdentifier === '') {
            return $this->httpClientFactory->create();
        }

        return $this->vault->http()->withAuthentication(
            $this->apiKeyIdentifier,
            $this->getSecretPlacement(),
            $this->getSecretPlacementOptions(),
        );
    }

    /**
     * Get the secret placement for authentication.
     *
     * Default is Bearer token. Providers can override for different auth methods.
     */
    protected function getSecretPlacement(): SecretPlacement
    {
        return SecretPlacement::Bearer;
    }

    /**
     * Get additional options for secret placement.
     *
     * Providers can override to specify custom header names, query params, etc.
     *
     * @return array{headerName?: string, queryParam?: string, reason?: string}
     */
    protected function getSecretPlacementOptions(): array
    {
        return ['reason' => sprintf('LLM API call to %s', $this->getName())];
    }

    /**
     * Retrieve the API key from vault.
     *
     * Use sparingly - prefer letting VaultHttpClient handle authentication.
     * This is needed for providers that embed the key in URLs (e.g., Gemini).
     */
    protected function retrieveApiKey(): string
    {
        if ($this->apiKeyIdentifier === '') {
            return '';
        }

        return $this->vault->retrieve($this->apiKeyIdentifier) ?? '';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    protected function sendRequest(string $endpoint, array $payload, string $method = 'POST'): array
    {
        // Validate configuration before making API calls
        $this->validateConfiguration();

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Content-Type', 'application/json');

        $request = $this->addProviderSpecificHeaders($request);

        if ($method === 'POST' && $payload !== []) {
            $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
            $request = $request->withBody($body);
        }

        $httpClient = $this->getHttpClient();
        // Stamp a per-request audit reason (purpose + model) on the vault
        // client so the audit log records what each secret access served.
        // Test doubles injected via setHttpClient() are plain PSR-18
        // clients and skip this — the instanceof guard keeps them working.
        if ($httpClient instanceof VaultHttpClientInterface) {
            $httpClient = $httpClient->withReason($this->buildAuditReason($endpoint, $payload));
        }
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                return $this->handleResponse($httpClient->sendRequest($request), $endpoint);
            } catch (ProviderResponseException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    // Exponential backoff capped at 30s. Short-circuit at
                    // attempt >= 9 (100000 * 2**9 = 51.2s already exceeds the
                    // cap) so the 2 ** $attempt term cannot overflow the float→int
                    // cast into a negative value and make usleep() throw.
                    $backoffMicros = $attempt >= 9 ? 30_000_000 : (int)(100000 * (2 ** $attempt));
                    usleep($backoffMicros);
                }
            }
        }

        throw new ProviderConnectionException(
            'Failed to connect to provider after ' . $this->maxRetries . ' attempts: '
            . $this->sanitizeErrorMessage($lastException?->getMessage() ?? self::UNKNOWN_ERROR),
            0,
            $lastException,
        );
    }

    /**
     * Map an HTTP response to the decoded payload or the matching typed
     * exception, mirroring the status handling expected by {@see sendRequest()}.
     *
     * @throws ProviderResponseException   on a 4xx response
     * @throws ProviderConnectionException on any other non-2xx response
     *
     * @return array<string, mixed>
     */
    private function handleResponse(ResponseInterface $response, string $endpoint): array
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            $body = (string)$response->getBody();

            return $this->decodeJsonResponse($body);
        }

        if ($statusCode >= 400 && $statusCode < 500) {
            $body = (string)$response->getBody();
            $decoded = json_decode($body, true);
            $error = is_array($decoded) ? $this->asArray($decoded) : ['error' => ['message' => self::UNKNOWN_ERROR]];
            throw new ProviderResponseException(
                message: $this->sanitizeErrorMessage($this->extractErrorMessage($error)),
                httpStatus: $statusCode,
                responseBody: $body,
                endpoint: $endpoint,
            );
        }

        throw new ProviderConnectionException(
            sprintf('Server returned status %d', $statusCode),
            $statusCode,
        );
    }

    protected function addProviderSpecificHeaders(RequestInterface $request): RequestInterface
    {
        return $request;
    }

    /**
     * Build the per-request audit reason recorded by the vault secure
     * client, e.g. `'LLM chat call to OpenAI (gpt-4o)'`. The purpose is
     * derived from the endpoint shape (covers the chat/embedding endpoint
     * names of every bundled provider); the model comes from the request
     * payload when present (Gemini encodes it in the URL instead, so its
     * reason carries purpose + provider only). MUST never include prompt
     * text or other payload content.
     *
     * @param array<string, mixed> $payload
     */
    protected function buildAuditReason(string $endpoint, array $payload): string
    {
        $purpose = match (true) {
            str_contains($endpoint, 'embed') => 'embedding',
            str_contains($endpoint, 'chat'),
            str_contains($endpoint, 'messages'),
            str_contains($endpoint, 'generateContent'),
            str_contains($endpoint, 'api/generate') => 'chat',
            default => 'API',
        };

        $model = isset($payload['model']) && is_string($payload['model']) ? $payload['model'] : '';

        $reason = sprintf('LLM %s call to %s', $purpose, $this->getName());
        if ($model !== '') {
            $reason .= sprintf(' (%s)', $model);
        }

        return $reason;
    }

    /**
     * Map a non-2xx streaming response to the same typed exceptions that
     * `sendRequest()` raises, so a failed stream surfaces the error instead
     * of silently yielding an empty generator.
     *
     * Streaming bypasses `sendRequest()` (the SSE body must be read lazily),
     * and the fallback middleware excludes streaming from its retry/fallback
     * handling — so surfacing the typed error here is the correct contract.
     *
     * @throws ProviderResponseException   on a 4xx response
     * @throws ProviderConnectionException on any other non-2xx response
     */
    protected function assertStreamingResponseOk(ResponseInterface $response, string $endpoint): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        $body = (string)$response->getBody();

        if ($statusCode >= 400 && $statusCode < 500) {
            $decoded = json_decode($body, true);
            $error = is_array($decoded) ? $this->asArray($decoded) : ['error' => ['message' => self::UNKNOWN_ERROR]];
            throw new ProviderResponseException(
                message: $this->sanitizeErrorMessage($this->extractErrorMessage($error)),
                httpStatus: $statusCode,
                responseBody: $body,
                endpoint: $endpoint,
            );
        }

        throw new ProviderConnectionException(
            sprintf('Server returned status %d', $statusCode),
            $statusCode,
        );
    }

    /**
     * @param array<string, mixed> $error
     */
    protected function extractErrorMessage(array $error): string
    {
        $errorValue = $error['error'] ?? null;

        // Nested {"error":{"message":...}} (OpenAI format).
        if (is_array($errorValue)) {
            $message = $this->getNullableString($this->asArray($errorValue), 'message');
            if ($message !== null && $message !== '') {
                return $message;
            }
        }

        // Flat {"error":"..."} string (Ollama and others). This must be checked
        // independently of the nested branch above: when `error` is a string,
        // getArray() yields [] for it, so guarding the flat case on a non-empty
        // nested array (the previous bug) silently dropped the real message and
        // degraded everything to "Unknown provider error".
        if (is_string($errorValue) && $errorValue !== '') {
            return $errorValue;
        }

        // Direct top-level {"message":...} (some providers).
        $message = $this->getNullableString($error, 'message');

        return $message ?? 'Unknown provider error';
    }

    protected function validateConfiguration(): void
    {
        if ($this->apiKeyIdentifier === '') {
            throw new ProviderConfigurationException(
                sprintf('API key identifier is required for provider %s', $this->getName()),
                1307337100,
            );
        }
    }

    protected function createUsageStatistics(int $promptTokens, int $completionTokens): UsageStatistics
    {
        // Token counts come from untrusted provider responses; a negative value
        // (malformed payload) would corrupt usage and cost accounting downstream.
        $promptTokens     = max(0, $promptTokens);
        $completionTokens = max(0, $completionTokens);

        return new UsageStatistics(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $promptTokens + $completionTokens,
        );
    }

    protected function createCompletionResponse(
        string $content,
        string $model,
        UsageStatistics $usage,
        ?string $finishReason = null,
        ?string $thinking = null,
    ): CompletionResponse {
        return new CompletionResponse(
            content: $content,
            model: $model,
            usage: $usage,
            finishReason: $finishReason ?? 'stop',
            provider: $this->getIdentifier(),
            thinking: $thinking,
        );
    }

    /**
     * Extract and remove <think>...</think> blocks from content.
     *
     * @return array{string, string|null} [cleanContent, thinkingContent]
     */
    protected function extractThinkingBlocks(string $content): array
    {
        $thinking = null;
        if (preg_match_all('#<think>([\s\S]*?)</think>#i', $content, $matches)) {
            $thinking = trim(implode("\n", $matches[1]));
            // Replace with space to prevent word-gluing (e.g. "foo<think>...</think>bar" → "foo bar")
            $cleaned = preg_replace('#<think>[\s\S]*?</think>#i', ' ', $content) ?? $content;
            // Normalize horizontal whitespace (spaces/tabs) but preserve newlines for formatting
            $content = trim((string)preg_replace('/[ \t]+/', ' ', $cleaned));
        }

        return [$content, $thinking !== '' ? $thinking : null];
    }

    /**
     * @param array<int, array<int, float>> $embeddings
     */
    protected function createEmbeddingResponse(
        array $embeddings,
        string $model,
        UsageStatistics $usage,
    ): EmbeddingResponse {
        return new EmbeddingResponse(
            embeddings: $embeddings,
            model: $model,
            usage: $usage,
            provider: $this->getIdentifier(),
        );
    }

    /**
     * Test the connection to the provider.
     *
     * Default implementation makes a simple HTTP request to verify connectivity.
     * Providers can override this for provider-specific connection tests.
     *
     *
     * @throws ProviderConnectionException on connection failure
     *
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    public function testConnection(): array
    {
        // Default: use getAvailableModels which makes an API call for most providers
        // Providers that return static lists should override this method
        $models = $this->getAvailableModels();

        return [
            'success' => true,
            'message' => sprintf('Connection successful. Found %d models.', count($models)),
            'models' => $models,
        ];
    }

    /**
     * Shared real-connectivity check for OpenAI-compatible providers: a GET to
     * the `models` endpoint, parsing the `data[].id` list. Exceptions are NOT
     * caught — a connection/HTTP failure must propagate so testConnection()
     * reports failure per ProviderInterface. The static-model-list providers
     * (Claude/Groq/Mistral/OpenRouter) override testConnection() to call this.
     *
     * @return array{success: bool, message: string, models: array<string, string>}
     */
    protected function testConnectionViaModelsList(): array
    {
        $response = $this->sendRequest('models', [], 'GET');
        $data = $this->getList($response, 'data');

        $models = [];
        foreach ($data as $model) {
            $modelArray = $this->asArray($model);
            $id = $this->getString($modelArray, 'id');
            if ($id !== '') {
                $models[$id] = $id;
            }
        }

        return [
            'success' => true,
            'message' => sprintf('Connection successful. Found %d models.', count($models)),
            'models' => $models,
        ];
    }
}
