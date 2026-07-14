<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;

/**
 * Builds the `CacheMiddleware` metadata for an embeddings call.
 *
 * Both embedding entry points ({@see LlmServiceManager::embed()} and
 * {@see LlmServiceManager::embedForConfiguration()}) carried an inline copy of
 * the same structure — guard on a positive `cache_ttl`, then attach a cache
 * key, ttl and tags so `CacheMiddleware` can short-circuit. The two copies
 * intentionally derive the key from different things (the ad-hoc path keys by
 * provider identifier, the configuration path by configuration identifier plus
 * the effective model), so only the *shape* is shared here: each caller passes
 * its own namespace, key payload and scope tag. A `cache_ttl` of zero (the
 * `EmbeddingOptions::noCache()` contract) yields an empty array so the caller's
 * metadata is left untouched and the middleware becomes a no-op.
 */
final readonly class EmbedCacheKeyBuilder
{
    public function __construct(
        private CacheManagerInterface $cacheManager,
    ) {}

    /**
     * @param array<string, mixed> $keyPayload the fields that make two calls
     *                                         share (or not share) a cache entry
     *
     * @return array<string, mixed> cache metadata to merge onto the call
     *                              context, or an empty array when caching is off
     */
    public function build(int $cacheTtl, string $namespace, array $keyPayload, string $scopeTag): array
    {
        if ($cacheTtl <= 0) {
            return [];
        }

        return [
            CacheMiddleware::METADATA_CACHE_KEY => $this->cacheManager->generateCacheKey(
                $namespace,
                'embeddings',
                $keyPayload,
            ),
            CacheMiddleware::METADATA_CACHE_TTL  => $cacheTtl,
            CacheMiddleware::METADATA_CACHE_TAGS => [
                'nrllm_embeddings',
                // Sanitize the scope tag: configuration/provider identifiers can
                // use the dotted preset scheme (nr_ai_search.embeddings), and the
                // cache frontend rejects a tag containing a dot on set().
                $this->cacheManager->sanitizeCacheTag($scopeTag),
            ],
        ];
    }
}
