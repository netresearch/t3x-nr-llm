<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\Discovery;

use JsonException;
use Netresearch\NrLlm\Service\SetupWizard\SecureHttpDispatchTrait;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One provider's model discovery, extracted from the ModelDiscovery facade.
 *
 * Carries the shared plumbing every discoverer needs — the SSRF-guarded
 * dispatch, the Bearer list fetch, the logged JSON decode, the failure
 * logging — so a provider class holds only what is specific to its API.
 * Bodies were moved verbatim from ModelDiscovery; behavioural differences
 * between providers (fallback policy, decode strictness, sort order) are
 * deliberate and documented there, not accidents of the split.
 *
 * Constructed by the facade from its own dependencies, not injected: the
 * discoverers are an implementation detail of ModelDiscovery, and the
 * facade must be able to fan its test-only HTTP seam out to them
 * ({@see SecureHttpDispatchTrait::setHttpClient()}).
 */
abstract class AbstractModelDiscoverer
{
    use ErrorMessageSanitizerTrait;
    // The trait declares dispatch() private; flattened into this class that
    // would make it invisible to the concrete discoverers — and the resulting
    // Error is a Throwable, so every discovery would silently catch it and
    // fall back. The alias widens it to protected for the children.
    use SecureHttpDispatchTrait {
        dispatch as protected;
    }

    /**
     * Audit-log reason the vault secure client records for every outbound
     * request, passed to `SecureHttpDispatchTrait::dispatch()`.
     */
    protected const VAULT_DISPATCH_REASON = 'LLM setup-wizard model discovery';

    /** Resource path appended to a provider endpoint to list available models. */
    protected const MODELS_PATH = '/models';

    /** Authorization header value prefix for Bearer-token providers. */
    protected const AUTH_BEARER_PREFIX = 'Bearer ';

    public function __construct(
        VaultServiceInterface $vault,
        SecureHttpClientFactory $httpClientFactory,
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        protected readonly LoggerInterface $logger,
    ) {
        // Initialise the readonly collaborators declared in
        // SecureHttpDispatchTrait (no promotion — the trait owns them).
        $this->vault = $vault;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * Discover this provider's models.
     *
     * The endpoint arrives normalised (no trailing slash) and includes the
     * API version path where the provider uses one; implementations append
     * only resource paths. Providers that need no key ignore `$apiKey`.
     */
    abstract public function discover(string $endpoint, string $apiKey): DiscoveryResult;

    /**
     * Log a failed discovery request.
     *
     * Never includes the API key: only the exception class and its
     * sanitised message are recorded.
     */
    protected function logDiscoveryFailure(string $adapterType, Throwable $e): void
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
    protected function logDiscoveryHttpError(string $adapterType, int $statusCode): void
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
    protected function decodeModelListBody(string $adapterType, string $body): ?array
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
    protected function fetchBearerModelList(string $adapterType, string $endpoint, string $apiKey): ?array
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
}
