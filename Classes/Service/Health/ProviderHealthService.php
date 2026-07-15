<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Health;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Provider health advisor (ADR-063).
 *
 * Aggregates the recent telemetry window into per-provider scores
 * ({@see ProviderHealthRepository}) and caches the result for a short spell so
 * the opt-in fallback reorder — which runs on every retryable failure — does
 * not re-run the aggregate on each hop while a provider is flapping.
 *
 * Kept private (ADR-028): the middleware and any future consumer wire against
 * {@see ProviderHealthServiceInterface}; nothing resolves it by class name.
 */
final class ProviderHealthService implements ProviderHealthServiceInterface
{
    /** Rolling window the score reflects: the last 15 minutes of telemetry. */
    private const WINDOW_SECONDS = 900;

    private const CACHE_IDENTIFIER = 'nrllm_health';

    private const CACHE_ENTRY = 'scores';

    private const CACHE_LIFETIME = 60;

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly ProviderHealthRepositoryInterface $repository,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly Typo3CacheManager $cacheManager,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function scoreFor(string $provider): ProviderHealthScore
    {
        return $this->all()[$provider] ?? ProviderHealthScore::unknown($provider);
    }

    public function all(): array
    {
        $cached = $this->readCache();
        if ($cached !== null) {
            return $cached;
        }

        $scores = $this->repository->scoresSince(time() - self::WINDOW_SECONDS);
        $this->writeCache($scores);

        return $scores;
    }

    public function reorder(FallbackChain $chain): FallbackChain
    {
        if ($chain->count() < 2 || !$this->reorderEnabled()) {
            return $chain;
        }

        $scores = $this->all();

        // Precompute each candidate's health score and its original position,
        // then sort by descending score with the original position as a stable
        // tie-break — so equal-health (and unknown-health) entries keep their
        // configured order. The reorder is a hint, never a reshuffle.
        $scoreOf = [];
        $orderOf = [];
        foreach ($chain->configurationIdentifiers as $position => $identifier) {
            $provider            = $this->providerForIdentifier($identifier);
            $scoreOf[$identifier] = ($provider !== null && isset($scores[$provider]))
                ? $scores[$provider]->score
                : ProviderHealthScore::NEUTRAL_SCORE;
            $orderOf[$identifier] = $position;
        }

        $ordered = $chain->configurationIdentifiers;
        usort(
            $ordered,
            static fn(string $a, string $b): int => [$scoreOf[$b], $orderOf[$a]] <=> [$scoreOf[$a], $orderOf[$b]],
        );

        return new FallbackChain($ordered);
    }

    /**
     * Resolve the provider a configuration identifier points at, or null when
     * the configuration is missing.
     */
    private function providerForIdentifier(string $identifier): ?string
    {
        $configuration = $this->configurationRepository->findOneByIdentifier($identifier);
        if ($configuration === null) {
            return null;
        }

        $provider = $configuration->getProviderType();

        return $provider !== '' ? $provider : null;
    }

    private function reorderEnabled(): bool
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return false;
        }

        $health = \is_array($config['health'] ?? null) ? $config['health'] : [];

        // Default OFF: the configured fallback order stays the default.
        return \array_key_exists('reorderFallback', $health) && (bool)$health['reorderFallback'];
    }

    /**
     * @return array<string, ProviderHealthScore>|null
     */
    private function readCache(): ?array
    {
        try {
            $cached = $this->getCache()->get(self::CACHE_ENTRY);
        } catch (Throwable) {
            return null;
        }

        if (!\is_array($cached)) {
            return null;
        }

        foreach ($cached as $score) {
            if (!$score instanceof ProviderHealthScore) {
                return null;
            }
        }

        /** @var array<string, ProviderHealthScore> $cached */
        return $cached;
    }

    /**
     * @param array<string, ProviderHealthScore> $scores
     */
    private function writeCache(array $scores): void
    {
        try {
            $this->getCache()->set(self::CACHE_ENTRY, $scores, [], self::CACHE_LIFETIME);
        } catch (Throwable) {
            // Health scoring must not break on a cache write failure.
        }
    }

    private function getCache(): FrontendInterface
    {
        if (!$this->cache instanceof FrontendInterface) {
            $this->cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
        }

        return $this->cache;
    }
}
