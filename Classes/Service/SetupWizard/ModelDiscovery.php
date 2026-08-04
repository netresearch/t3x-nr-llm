<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\Discovery\AbstractModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\AnthropicModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\GeminiModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\GroqModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\MistralModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\OllamaModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\OpenAiModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\OpenRouterModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Discovers available models from LLM provider APIs.
 *
 * A facade over one discoverer per provider
 * ({@see Discovery\AbstractModelDiscoverer}): this class routes by adapter
 * type, owns the connection test, and reports whether the last discovery was
 * served from a static fallback catalog. The provider-specific listing,
 * filtering and enrichment live in `Discovery/`.
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

    /**
     * One discoverer per adapter type, keyed by the adapter-type string
     * discover() routes on.
     *
     * @var array<string, AbstractModelDiscoverer>
     */
    private readonly array $discoverers;

    public function __construct(
        VaultServiceInterface $vault,
        SecureHttpClientFactory $httpClientFactory,
        private readonly RequestFactoryInterface $requestFactory,
        // Consumed only to construct the discoverers below — the facade itself
        // no longer streams anything.
        StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {
        // Initialise the readonly collaborators declared in
        // SecureHttpDispatchTrait (no promotion — the trait owns them).
        $this->vault = $vault;
        $this->httpClientFactory = $httpClientFactory;

        // Built here rather than injected: the discoverers are an
        // implementation detail of this facade, the constructor signature is
        // pinned by positional test wiring, and setHttpClient() below must be
        // able to fan the test seam out to every one of them.
        $this->discoverers = [
            'openai'     => new OpenAiModelDiscoverer($vault, $httpClientFactory, $requestFactory, $streamFactory, $logger),
            'anthropic'  => new AnthropicModelDiscoverer($vault, $httpClientFactory, $requestFactory, $streamFactory, $logger),
            'gemini'     => new GeminiModelDiscoverer($vault, $httpClientFactory, $requestFactory, $streamFactory, $logger),
            'openrouter' => new OpenRouterModelDiscoverer($vault, $httpClientFactory, $requestFactory, $streamFactory, $logger),
            'ollama'     => new OllamaModelDiscoverer($vault, $httpClientFactory, $requestFactory, $streamFactory, $logger),
            'mistral'    => new MistralModelDiscoverer($vault, $httpClientFactory, $requestFactory, $streamFactory, $logger),
            'groq'       => new GroqModelDiscoverer($vault, $httpClientFactory, $requestFactory, $streamFactory, $logger),
        ];
    }

    /**
     * Inject a custom HTTP client for this facade AND every discoverer.
     *
     * Shadows the trait method on purpose: the ~150 unit tests set the seam on
     * the facade after construction and expect it to reach whichever provider
     * path the test drives, so the seam fans out. Production never calls this.
     *
     * @internal test seam only
     */
    public function setHttpClient(ClientInterface $client): void
    {
        $this->configuredHttpClient = $client;
        foreach ($this->discoverers as $discoverer) {
            $discoverer->setHttpClient($client);
        }
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
                'ollama' => OllamaModelDiscoverer::baseUrl($base) . '/api/tags',
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

        $discoverer = $this->discoverers[$provider->adapterType] ?? null;
        if (!$discoverer instanceof AbstractModelDiscoverer) {
            // Together/Fireworks/Perplexity resolve to the OpenAI-compatible provider
            // for chat, but OpenAI discovery filters to OpenAI-specific model IDs, so
            // routing them there would surface OpenAI's catalog. Until per-provider
            // discovery exists their model ID is entered manually (generic placeholder).
            return $this->getDefaultModels($provider->adapterType);
        }

        $result = $discoverer->discover($endpoint, $apiKey);
        $this->lastDiscoveryUsedFallback = $result->usedFallback;

        return $result->models;
    }

    public function wasLastDiscoveryFromFallback(): bool
    {
        return $this->lastDiscoveryUsedFallback;
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
