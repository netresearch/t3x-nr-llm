<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Overview;

use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Cheap, token-free reachability probe for the overview's provider dots.
 *
 * Probes each **configured** provider record (not adapter types) via
 * {@see ProviderAdapterRegistry::testProviderConnection()}, which pings the
 * provider's model-list / health endpoint — it performs NO completion and
 * therefore consumes NO tokens and incurs NO cost. The whole result is cached
 * for {@see self::CACHE_LIFETIME} seconds so a backend page load (and the AJAX
 * poll behind it) never storms the providers.
 *
 * Loaded asynchronously from the overview via AJAX so a slow or unreachable
 * provider never blocks the page render.
 *
 * No vault status is reported: nr-vault exposes no backend health probe, and a
 * constant "up" would be a fake signal. Vault health is reflected implicitly —
 * an api-key provider's connection test retrieves its key through the vault, so
 * a vault failure surfaces as that provider being "down".
 */
final class ProviderReachabilityService
{
    private const CACHE_IDENTIFIER = 'nrllm_reachability';

    private const CACHE_ENTRY = 'status';

    private const CACHE_LIFETIME = 60;

    private const STATUS_UP = 'up';

    private const STATUS_DOWN = 'down';

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly ProviderRepository $providerRepository,
        private readonly ProviderAdapterRegistry $adapterRegistry,
        private readonly Typo3CacheManager $cacheManager,
    ) {}

    /**
     * Reachability of every active, configured provider.
     *
     * @return array{
     *     providers: list<array{identifier: string, name: string, status: string}>,
     * }
     */
    public function check(): array
    {
        $cache  = $this->getCache();
        $cached = $cache->get(self::CACHE_ENTRY);
        if (is_array($cached)) {
            /** @var array{providers: list<array{identifier: string, name: string, status: string}>} $cached */
            return $cached;
        }

        $providers = [];
        foreach ($this->providerRepository->findActive() as $provider) {
            if (!$provider instanceof Provider) {
                continue;
            }

            $providers[] = [
                'identifier' => $provider->getIdentifier(),
                'name'       => $provider->getName(),
                'status'     => $this->probe($provider),
            ];
        }

        $result = ['providers' => $providers];

        $cache->set(self::CACHE_ENTRY, $result, [], self::CACHE_LIFETIME);

        return $result;
    }

    /**
     * Probe one configured provider record. The registry builds the adapter
     * from the record and pings its health endpoint (no completion, no tokens),
     * returning a success flag; any failure — bad key, unreachable endpoint,
     * unavailable adapter — is reported as "down".
     */
    private function probe(Provider $provider): string
    {
        $result = $this->adapterRegistry->testProviderConnection($provider);

        return ($result['success'] ?? false) === true ? self::STATUS_UP : self::STATUS_DOWN;
    }

    private function getCache(): FrontendInterface
    {
        if (!$this->cache instanceof FrontendInterface) {
            $this->cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
        }

        return $this->cache;
    }
}
