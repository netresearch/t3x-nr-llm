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
use Netresearch\NrLlm\Provider\Exception\ProviderAuthenticationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderRateLimitException;
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
    protected int $timeout = 120;
    protected int $maxRetries = 3;
    protected string $organizationId = '';

    /** @var array<string, string> */
    protected array $customHeaders = [];

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
        $this->timeout = $this->getInt($config, 'timeout', 120);
        $this->maxRetries = $this->getInt($config, 'maxRetries', 3);
        $this->organizationId = $this->getString($config, 'organizationId');
        $this->customHeaders = $this->extractCustomHeaders($config);

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
        $messages = [];

        // The configuration's system prompt reaches this method via
        // `options['system_prompt']` (see LlmConfiguration::toOptionsArray()).
        // chatCompletion() reads the system instruction from the message list,
        // not from the options, so surface it as a leading system message here
        // — otherwise a configuration's system prompt is silently dropped on
        // every single-prompt completion (incl. task execution).
        $systemPrompt = $options['system_prompt'] ?? null;
        if (is_string($systemPrompt) && $systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $this->chatCompletion($messages, $options);
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
     * @param int|null $timeout per-request total-response timeout in seconds;
     *                          falls back to the provider's configured timeout
     *
     * @return ClientInterface HTTP client with authentication configured
     */
    protected function getHttpClient(?int $timeout = null): ClientInterface
    {
        if ($this->configuredHttpClient !== null) {
            return $this->configuredHttpClient;
        }

        $effective = ($timeout !== null && $timeout > 0) ? $timeout : $this->timeout;

        // For providers without API key, use httpClientFactory directly.
        // The authenticated path is gated by VaultHttpClient::sendRequest(),
        // which rejects disallowed hosts via isHostAllowed(); the raw factory
        // client has no such per-request gate, and the SSRF DNS-pin middleware
        // deliberately skips IP-literal targets (trusting an isHostAllowed()
        // call that never runs on this path). Gate the configured endpoint
        // host here so an api-key-less provider (e.g. Ollama) pointed at a
        // private/metadata IP literal cannot bypass the private-range filter.
        if ($this->apiKeyIdentifier === '') {
            $this->assertEndpointHostAllowed();
            return $this->httpClientFactory->create($effective > 0 ? $effective : null);
        }

        $client = $this->vault->http()->withAuthentication(
            $this->apiKeyIdentifier,
            $this->getSecretPlacement(),
            $this->getSecretPlacementOptions(),
        );

        return $effective > 0 ? $client->withTimeout($effective) : $client;
    }

    /**
     * Reject an api-key-less provider whose configured endpoint host is not
     * permitted by nr-vault's SSRF host filter (private / link-local /
     * loopback / carrier-grade-NAT / cloud-metadata ranges, unless the
     * operator has opted the host into `allowed_hosts`). This mirrors the gate
     * {@see VaultHttpClientInterface::sendRequest()} applies to every
     * authenticated request, closing the IP-literal bypass on the raw
     * factory-client path.
     *
     * @throws ProviderConfigurationException
     */
    private function assertEndpointHostAllowed(): void
    {
        $baseUrl = trim($this->baseUrl);
        if ($baseUrl === '') {
            // Nothing configured; no absolute request URL can be formed.
            return;
        }

        // parse_url only populates the host when an authority is present, so a
        // schemeless endpoint like "169.254.169.254:11434" would yield a null
        // host and slip past the gate. Prepend a protocol-relative "//" for
        // parsing when no scheme is present — this exposes the authority to
        // parse_url without asserting any concrete protocol.
        $forParsing = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $baseUrl) === 1
            ? $baseUrl
            : '//' . $baseUrl;
        $host = parse_url($forParsing, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            // A non-empty but unparseable endpoint is malformed; fail closed
            // rather than dispatch an unchecked request.
            throw new ProviderConfigurationException(
                sprintf('Could not determine the host of provider endpoint "%s".', $this->baseUrl),
                1751452801,
            );
        }

        if (!$this->httpClientFactory->isHostAllowed($host)) {
            throw new ProviderConfigurationException(
                sprintf('Provider endpoint host "%s" is not allowed by the SSRF host filter.', $host),
                1751452800,
            );
        }
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
    protected function sendRequest(string $endpoint, array $payload, string $method = 'POST', ?int $timeout = null): array
    {
        // Validate configuration before making API calls
        $this->validateConfiguration();

        $effectiveTimeout = ($timeout !== null && $timeout > 0) ? $timeout : $this->timeout;

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Content-Type', 'application/json');

        $request = $this->addProviderSpecificHeaders($request);
        $request = $this->applyCustomHeaders($request);

        if ($method === 'POST' && $payload !== []) {
            // JSON_INVALID_UTF8_SUBSTITUTE: a request payload carrying an invalid
            // byte (e.g. a tool result echoing non-UTF-8 log/env output back into
            // the conversation) must degrade to a replacement character, never
            // throw and abort the whole call.
            $body = $this->streamFactory->createStream(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            );
            $request = $request->withBody($body);
        }

        $httpClient = $this->getHttpClient($timeout);
        // Stamp a per-request audit reason (purpose + model) on the vault
        // client so the audit log records what each secret access served.
        // Test doubles injected via setHttpClient() are plain PSR-18
        // clients and skip this — the instanceof guard keeps them working.
        if ($httpClient instanceof VaultHttpClientInterface) {
            $httpClient = $httpClient->withReason($this->buildAuditReason($endpoint, $payload));
        }
        $attempt = 0;
        $lastException = null;
        // maxRetries counts retries after the initial attempt; max_retries = 0
        // still sends one request. Clamp negatives (reachable via the options
        // JSON maxRetries override, which configure() does not range-check) so
        // the loop always runs at least once.
        $maxAttempts = max(0, $this->maxRetries) + 1;

        while ($attempt < $maxAttempts) {
            $attemptStart = microtime(true);

            try {
                return $this->handleResponse($httpClient->sendRequest($request), $endpoint);
            } catch (ProviderResponseException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastException = $e;
                $attempt++;

                // A client-side timeout must not be retried: retrying would
                // multiply the caller's wait by maxAttempts. Guzzle raises the
                // total timeout (cURL error 28) as a ConnectException — the
                // same class as retryable connection failures — so the only
                // reliable discriminator is the elapsed wall time. cURL can
                // fire marginally before the integer limit, so allow a 0.5s
                // tolerance; misclassifying an equally-slow connection failure
                // as a timeout fails safe (fewer retries).
                if ($effectiveTimeout > 0 && (microtime(true) - $attemptStart) >= $effectiveTimeout - 0.5) {
                    throw new ProviderConnectionException(
                        sprintf(
                            'Provider request timed out after %d seconds (attempt %d; timeouts are not retried)',
                            $effectiveTimeout,
                            $attempt,
                        ),
                        0,
                        $e,
                    );
                }

                if ($attempt < $maxAttempts) {
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
            'Failed to connect to provider after ' . $attempt . ' attempts: '
            . $this->sanitizeErrorMessage($lastException?->getMessage() ?? self::UNKNOWN_ERROR),
            0,
            $lastException,
        );
    }

    /**
     * Resolve the per-request timeout from the call options.
     *
     * `options['timeout']` carries the configuration-level effective timeout
     * (see LlmConfiguration::getEffectiveTimeout()); it overrides the
     * provider's api_timeout for the single request. Returns null when the
     * options carry no usable value, so the provider timeout applies.
     *
     * @param array<string, mixed> $options
     */
    protected function resolveRequestTimeout(array $options): ?int
    {
        $raw = $options['timeout'] ?? null;
        if (!is_numeric($raw)) {
            return null;
        }

        $seconds = (int)$raw;

        return $seconds > 0 ? $seconds : null;
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
            throw $this->clientErrorException(
                $statusCode,
                $this->sanitizeErrorMessage($this->extractErrorMessage($error)),
                $body,
                $endpoint,
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
     * Apply operator-configured custom headers (options JSON `customHeaders`)
     * to an outgoing request. Applied after addProviderSpecificHeaders(), so
     * on a name collision the operator's value wins (withHeader replaces).
     * Authentication cannot be overridden on authenticated providers: the
     * vault HTTP client injects the Authorization/api-key header at send
     * time, after these headers are set.
     */
    protected function applyCustomHeaders(RequestInterface $request): RequestInterface
    {
        foreach ($this->customHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Extract and sanitize the `customHeaders` map from the provider config.
     * Entries with a non-string value, a name that is not a valid RFC 7230
     * token, or a value containing CR/LF are dropped: a malformed entry from
     * a hand-edited options row must not throw from PSR-7 withHeader() and
     * break every request, and the CR/LF guard blocks header injection from
     * the DB field.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, string>
     */
    private function extractCustomHeaders(array $config): array
    {
        $raw = $config['customHeaders'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $headers = [];
        foreach ($raw as $name => $value) {
            // PHP casts numeric-string array keys ("123") to int on decode;
            // cast back so such names reach the token check instead of being
            // dropped for not being strings.
            $name = (string)$name;
            if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
                continue;
            }

            if (!is_string($value) || str_contains($value, "\r") || str_contains($value, "\n")) {
                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
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
            throw $this->clientErrorException(
                $statusCode,
                $this->sanitizeErrorMessage($this->extractErrorMessage($error)),
                $body,
                $endpoint,
            );
        }

        throw new ProviderConnectionException(
            sprintf('Server returned status %d', $statusCode),
            $statusCode,
        );
    }

    /**
     * Map a 4xx client-error status to the most specific
     * {@see ProviderResponseException} subclass — 401 →
     * {@see ProviderAuthenticationException}, 429 →
     * {@see ProviderRateLimitException}, everything else the base class — so
     * callers can branch on the exception type (ADR-080). Every result is a
     * `ProviderResponseException` carrying `httpStatus`, so existing catches and
     * the `getCode() === 429` middleware keep working.
     */
    private function clientErrorException(int $statusCode, string $message, string $responseBody, string $endpoint): ProviderResponseException
    {
        return match ($statusCode) {
            401 => new ProviderAuthenticationException(message: $message, httpStatus: $statusCode, responseBody: $responseBody, endpoint: $endpoint),
            429 => new ProviderRateLimitException(message: $message, httpStatus: $statusCode, responseBody: $responseBody, endpoint: $endpoint),
            default => new ProviderResponseException(message: $message, httpStatus: $statusCode, responseBody: $responseBody, endpoint: $endpoint),
        };
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

    /**
     * Optionally attach the decoded raw provider response to the completion
     * metadata, gated by the private ``_capture_raw`` option the admin
     * playground sets (never a provider API field). Off in production, so raw
     * payloads are never retained — the parsed {@see CompletionResponse} is the
     * only thing kept.
     *
     * @param array<string, mixed>      $options  the effective call options
     * @param array<string, mixed>      $response the decoded provider response
     * @param array<string, mixed>|null $existing metadata to merge the raw body into
     *
     * @return array<string, mixed>|null
     */
    protected function rawResponseMetadata(array $options, array $response, ?array $existing = null): ?array
    {
        if (($options['_capture_raw'] ?? false) !== true) {
            return $existing;
        }

        return array_merge($existing ?? [], ['_raw' => $response]);
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
