<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Routing;

use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * The call history routing reads (ADR-256): the last seven days of telemetry,
 * aggregated once and cached for five minutes, so a decision costs a cache read
 * rather than a GROUP BY per call.
 *
 * Seven days, not the fifteen minutes provider health uses. Health asks "is
 * this provider failing right now"; this asks "what does a call to this model
 * usually take", and a quarter of an hour holds a handful of calls on most
 * installations — fewer than {@see ModelCallStats::MIN_SAMPLES} for any model
 * but the busiest.
 *
 * @internal
 */
final readonly class ModelCallHistory implements ModelCallHistoryInterface
{
    private const WINDOW_SECONDS = 7 * 86400;

    /** The health cache: same table, same kind of aggregate, short-lived. */
    private const CACHE_IDENTIFIER = 'nrllm_health';

    private const CACHE_ENTRY = 'callHistory';

    private const CACHE_LIFETIME = 300;

    public function __construct(
        private ModelCallHistoryRepository $repository,
        private Typo3CacheManager $cacheManager,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function enabled(): bool
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return false;
        }

        $routing = is_array($config['routing'] ?? null) ? $config['routing'] : [];

        // Default OFF: these figures change which model serves a call.
        return array_key_exists('callHistory', $routing) && (bool) $routing['callHistory'];
    }

    /**
     * Read through the cache on every call, with no copy kept on the object:
     * the service is shared, and a long-running worker holding a copy would
     * rank by the figures of the day it started.
     */
    public function all(): array
    {
        $cached = $this->readCache();
        if ($cached !== null) {
            return $cached;
        }

        $stats = $this->repository->statsSince(time() - self::WINDOW_SECONDS);
        $this->writeCache($stats);

        return $stats;
    }

    /**
     * @return array<string, ModelCallStats>|null
     */
    private function readCache(): ?array
    {
        try {
            $cached = $this->cache()->get(self::CACHE_ENTRY);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($cached)) {
            return null;
        }

        foreach ($cached as $key => $stats) {
            if (!is_string($key) || !$stats instanceof ModelCallStats) {
                return null;
            }
        }

        /** @var array<string, ModelCallStats> $cached */
        return $cached;
    }

    /**
     * @param array<string, ModelCallStats> $stats
     */
    private function writeCache(array $stats): void
    {
        try {
            $this->cache()->set(self::CACHE_ENTRY, $stats, [], self::CACHE_LIFETIME);
        } catch (Throwable) {
            // Routing must not break on a cache write failure.
        }
    }

    private function cache(): FrontendInterface
    {
        return $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
    }
}
