<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized;

use Exception;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Base class for specialised single-task AI services (REC #7).
 *
 * Concentrates the HTTP scaffolding that every specialised service —
 * DALL-E, FAL, Whisper, TTS, DeepL — was reimplementing on its own
 * (audit estimated ~40-50% duplication, ~300+ LOC reclaimable). Each
 * subclass declares its identity (domain / provider strings used in
 * exceptions and usage tracking) and the auth header scheme; the base
 * owns config loading, availability checks, JSON POST, and the
 * status-code → typed-exception mapping.
 *
 * Multipart-body construction lives in `MultipartBodyBuilderTrait`
 * (only Whisper and DALL-E need it — JSON-only services don't pay
 * for the trait's footprint).
 *
 * Constructor signature is identical across every consumer. Property
 * visibility intentionally `protected` (not `private`) so subclasses
 * can read `apiKey` / `baseUrl` when building service-specific
 * payloads — the alternative (passing them through accessor methods)
 * adds no encapsulation since the subclass is the only thing that
 * touches them anyway.
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractSpecializedService
{
    protected string $apiKey = '';
    protected string $baseUrl = '';
    protected int $timeout;

    public function __construct(
        protected readonly ClientInterface $httpClient,
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly UsageTrackerServiceInterface $usageTracker,
        protected readonly LoggerInterface $logger,
    ) {
        $this->timeout = $this->getDefaultTimeout();
        $this->loadConfiguration();
    }

    /**
     * Returns true once the service has a usable API key from
     * extension configuration. Stable across the lifetime of the
     * instance — if the config changes at runtime, callers must
     * rebuild the service via DI rather than poll.
     */
    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Service domain used by `ServiceUnavailableException` /
     * `ServiceConfigurationException` (`'image'`, `'speech'`,
     * `'translation'`, …) so log sinks and downstream catches can
     * filter by domain without parsing the message string.
     */
    abstract protected function getServiceDomain(): string;

    /**
     * Service provider identifier used by exception payloads and
     * usage-tracking calls (`'dall-e'`, `'fal'`, `'whisper'`,
     * `'tts'`, `'deepl'`).
     */
    abstract protected function getServiceProvider(): string;

    /**
     * Default base URL for the upstream API. Used when the extension
     * configuration does not override it. Each provider has a
     * documented default endpoint.
     */
    abstract protected function getDefaultBaseUrl(): string;

    /**
     * Default request timeout in seconds. Some services (Whisper —
     * audio transcription) want a higher default; speech synthesis
     * and translation are typically faster.
     */
    abstract protected function getDefaultTimeout(): int;

    /**
     * Service-specific config picking. Called from `loadConfiguration()`
     * with the already-fetched `nr_llm` config tree (or `[]` if loading
     * failed). Subclasses navigate to their own config branch — e.g.
     * `$config['providers']['openai']['apiKey']` for DALL-E or
     * `$config['translation']['deepl']['apiKey']` for DeepL — and set
     * `$this->apiKey`, `$this->baseUrl`, `$this->timeout` from it.
     *
     * Subclasses MUST set `$this->apiKey` and `$this->baseUrl`. The
     * base class pre-populates `$this->timeout` with
     * `getDefaultTimeout()` so subclasses only need to override it
     * when the config provides a timeout override.
     *
     * @param array<string, mixed> $config the full `nr_llm` config tree
     */
    abstract protected function loadServiceConfiguration(array $config): void;

    /**
     * Build the auth headers the upstream API expects. Three schemes
     * are in active use across the specialised services today:
     *  - `['Authorization' => 'Bearer ' . $this->apiKey]` (OpenAI)
     *  - `['Authorization' => 'Key '    . $this->apiKey]` (FAL)
     *  - `['Authorization' => 'DeepL-Auth-Key ' . $this->apiKey]`.
     *
     * Returning a multi-key array is supported for any future
     * provider that needs an extra header alongside auth (e.g.
     * `'X-Api-Version'`); the request builder applies all of them.
     *
     * @return array<string, string>
     */
    abstract protected function buildAuthHeaders(): array;

    /**
     * Throw a typed `ServiceUnavailableException` when the service
     * is not configured. Convenience for the `if (!$this->isAvailable())
     * throw …;` pattern that every service replicates.
     *
     * @throws ServiceUnavailableException
     */
    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw ServiceUnavailableException::notConfigured(
                $this->getServiceDomain(),
                $this->getServiceProvider(),
            );
        }
    }

    /**
     * Send a JSON request to the upstream API and return the decoded
     * response body. The endpoint is appended to `$this->baseUrl`
     * (with single-slash normalisation), auth headers from
     * `buildAuthHeaders()` are applied, and the payload is
     * `json_encode`d with `JSON_THROW_ON_ERROR`.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ServiceUnavailableException
     *
     * @return array<string, mixed>
     */
    protected function sendJsonRequest(string $endpoint, array $payload, string $method = 'POST'): array
    {
        $url = $this->buildEndpointUrl($endpoint);

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Content-Type', 'application/json');

        foreach ($this->buildAuthHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        return $this->executeRequest($request);
    }

    /**
     * Concatenate `$this->baseUrl` and a relative endpoint with
     * exactly one separating slash. `$endpoint` may be empty (the
     * service POSTs directly to the base URL — DALL-E TTS does this).
     */
    protected function buildEndpointUrl(string $endpoint): string
    {
        $base = rtrim($this->baseUrl, '/');
        $endpoint = ltrim($endpoint, '/');
        if ($endpoint === '') {
            return $base;
        }
        return $base . '/' . $endpoint;
    }

    /**
     * Send the prepared request, decode the JSON body, and map any
     * upstream error to a typed exception. Empty 2xx responses come
     * back as the empty array; non-2xx responses raise either
     * `ServiceConfigurationException` (auth) or
     * `ServiceUnavailableException` (everything else).
     *
     * Subclasses can override `decodeErrorMessage()` to extract the
     * upstream error message from a service-specific JSON shape;
     * everything else stays in the base.
     *
     * @throws ServiceUnavailableException
     * @throws ServiceConfigurationException
     *
     * @return array<string, mixed>
     */
    protected function executeRequest(RequestInterface $request): array
    {
        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $responseBody = (string)$response->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                if ($responseBody === '') {
                    return [];
                }
                $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                /** @var array<string, mixed> $result */
                $result = is_array($decoded) ? $decoded : [];
                return $result;
            }

            $errorMessage = $this->decodeErrorMessage($responseBody);

            $this->logger->error(sprintf('%s API error', $this->getProviderLabel()), [
                'status_code' => $statusCode,
                'error'       => $errorMessage,
            ]);

            throw $this->mapErrorStatus($statusCode, $errorMessage);
        } catch (ServiceUnavailableException|ServiceConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error(sprintf('%s API connection error', $this->getProviderLabel()), [
                'exception' => $e->getMessage(),
            ]);

            throw new ServiceUnavailableException(
                sprintf('Failed to connect to %s API: %s', $this->getProviderLabel(), $e->getMessage()),
                $this->getServiceDomain(),
                ['provider' => $this->getServiceProvider()],
                0,
                $e,
            );
        }
    }

    /**
     * Extract the error message from an upstream non-2xx body. The
     * default handles the most common shape — `{"error": {"message":
     * "..."}}` — used by every OpenAI-family service (DALL-E,
     * Whisper, TTS). Subclasses with a different shape (FAL uses
     * `detail`/`message`; DeepL uses `message`) override.
     */
    protected function decodeErrorMessage(string $responseBody): string
    {
        if ($responseBody === '') {
            return $this->unknownErrorLabel();
        }
        $error = json_decode($responseBody, true);
        if (!is_array($error)) {
            return $this->unknownErrorLabel();
        }
        $errorBranch = $error['error'] ?? null;
        if (is_array($errorBranch)) {
            $message = $errorBranch['message'] ?? null;
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }
        return $this->unknownErrorLabel();
    }

    /**
     * Map an upstream HTTP status code to a typed exception. The
     * default covers the auth (401/403) and rate-limit (429) cases
     * that every service handles identically; subclasses override
     * to add provider-specific branches (FAL has a 422 validation
     * branch, DALL-E distinguishes 400 validation, etc.).
     */
    protected function mapErrorStatus(int $statusCode, string $errorMessage): Throwable
    {
        return match ($statusCode) {
            401, 403 => ServiceConfigurationException::invalidApiKey(
                $this->getServiceDomain(),
                $this->getServiceProvider(),
            ),
            429 => new ServiceUnavailableException(
                sprintf('%s API rate limit exceeded', $this->getProviderLabel()),
                $this->getServiceDomain(),
                ['provider' => $this->getServiceProvider()],
            ),
            default => new ServiceUnavailableException(
                sprintf('%s API error: %s', $this->getProviderLabel(), $errorMessage),
                $this->getServiceDomain(),
                ['provider' => $this->getServiceProvider()],
            ),
        };
    }

    /**
     * Human-readable label for log messages and exception text.
     * Defaults to the provider identifier upper-cased and
     * dash-stripped (`'dall-e'` → `'DALL-E'`, `'tts'` → `'TTS'`).
     * Subclasses with a more specific brand name (`'OpenAI Whisper'`)
     * can override.
     */
    protected function getProviderLabel(): string
    {
        return strtoupper($this->getServiceProvider());
    }

    /**
     * Default fallback when an upstream error body has no usable
     * message. Subclasses can override to provide a more specific
     * label (e.g. `'Unknown DALL-E API error'`).
     */
    protected function unknownErrorLabel(): string
    {
        return sprintf('Unknown %s API error', $this->getProviderLabel());
    }

    /**
     * Load the `nr_llm` extension configuration and hand the
     * already-decoded tree to the subclass. Failures are logged at
     * `warning` level and leave `$this->apiKey` empty so
     * `isAvailable()` will report `false` — same fail-soft posture
     * the per-service implementations had.
     */
    private function loadConfiguration(): void
    {
        try {
            $config = $this->extensionConfiguration->get('nr_llm');
            if (!is_array($config)) {
                return;
            }
            /** @var array<string, mixed> $config */
            $this->loadServiceConfiguration($config);
        } catch (Exception $e) {
            $this->logger->warning(
                sprintf('Failed to load %s configuration', $this->getProviderLabel()),
                ['exception' => $e],
            );
        }
    }
}
