<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\CircuitBreaker;

use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Cache-backed circuit state store (ADR-063).
 *
 * State lives in the `nrllm_circuit` cache (no hardcoded backend, see
 * {@see \Configuration/Caching.php}), so it inherits whatever the instance
 * configured — Redis/Valkey shares one circuit across every web worker, which
 * is exactly what a circuit breaker wants (one worker seeing a provider fail
 * protects the others). A vanilla instance falls back to the DB cache backend
 * transparently.
 *
 * Fail-soft by construction: any cache error on load returns a closed circuit
 * (fail-open — never wedge a provider shut because the cache hiccuped) and any
 * error on save is logged and swallowed (never break the guarded call).
 */
final class CacheCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    private const CACHE_IDENTIFIER = 'nrllm_circuit';

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly Typo3CacheManager $cacheManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function load(string $provider): CircuitState
    {
        try {
            $cached = $this->getCache()->get($this->entryIdentifier($provider));
        } catch (Throwable $e) {
            $this->logger->warning('Circuit breaker state load failed; treating circuit as closed', [
                'provider'  => $provider,
                'exception' => $e,
            ]);

            return CircuitState::closed();
        }

        if (!\is_array($cached)) {
            return CircuitState::closed();
        }

        /** @var array<string, mixed> $cached */
        return CircuitState::fromArray($cached);
    }

    public function save(string $provider, CircuitState $state, int $lifetimeSeconds): void
    {
        try {
            $this->getCache()->set(
                $this->entryIdentifier($provider),
                $state->toArray(),
                [],
                max(1, $lifetimeSeconds),
            );
        } catch (Throwable $e) {
            // Observability of provider health must never break the call itself.
            $this->logger->warning('Circuit breaker state save failed; state not persisted', [
                'provider'  => $provider,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Map a provider identifier to a valid cache entry identifier. Provider keys
     * carry characters the cache frontend rejects (the ad-hoc scheme
     * "ad-hoc:chat:openai" contains colons), so the readable prefix is paired
     * with a hash of the raw provider to stay within the allowed charset
     * without risking a collision between two providers that sanitise alike.
     */
    private function entryIdentifier(string $provider): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $provider) ?? '';

        return 'circuit_' . $safe . '_' . substr(hash('sha256', $provider), 0, 12);
    }

    private function getCache(): FrontendInterface
    {
        if (!$this->cache instanceof FrontendInterface) {
            $this->cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
        }

        return $this->cache;
    }
}
