<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\CacheManagerInterface;

/**
 * Array-payload cache middleware.
 *
 * Sits between the caller and a provider call to skip the call entirely
 * when the same request has been answered before. Opt-in per call: the
 * middleware does nothing unless the caller supplies a cache key via
 * ProviderCallContext metadata. This keeps non-deterministic operations
 * (stateful chat, vision) untouched even when this middleware is
 * registered in the pipeline.
 *
 * Caller responsibilities (usually a feature service):
 *  1. Compute a stable cache key from the provider id + operation +
 *     inputs + relevant options. Put it on the context metadata under
 *     `CacheMiddleware::METADATA_CACHE_KEY`. Absent / empty = skip.
 *  2. Optionally put a TTL (`METADATA_CACHE_TTL`, int seconds) and
 *     cache tags (`METADATA_CACHE_TAGS`, list<string>) on the context
 *     so the store call is parameterised correctly.
 *  3. Wrap the terminal so it returns an `array<string, mixed>`, which
 *     is the shape CacheManager stores. Non-array return values are
 *     never written to cache — the middleware quietly passes them
 *     through on miss so callers who do not opt in to serialisation
 *     are not surprised by empty cache slots.
 *
 * Why array-only
 * --------------
 * The TYPO3 cache framework (via CacheManager here) persists arrays,
 * not arbitrary objects. Typed response objects
 * (CompletionResponse / EmbeddingResponse / VisionResponse) therefore
 * live at the feature-service boundary: the feature service wraps the
 * provider call so the terminal returns `$response->toArray()`, then
 * reconstructs the typed response from whatever the pipeline returns
 * (cached array or freshly-computed array). Keeping the middleware
 * opinion-free on response shape is what lets it work for any future
 * operation without a per-type codec.
 *
 * Pipeline ordering recommendation
 * --------------------------------
 * Outer of Fallback (so a cache hit skips even the fallback attempt)
 * and outer of Budget (so a free cache hit is not counted against a
 * user's budget):
 *
 *   CacheMiddleware          <-- outermost; short-circuits on hit
 *     BudgetMiddleware       <-- pre-flight only on miss
 *       FallbackMiddleware   <-- swaps config on retryable failure
 *         UsageMiddleware    <-- records the call that actually ran
 *           <terminal>
 *
 * Callers who want cache accounting (count hits against budget / usage)
 * should put Cache *inside* those layers — it is a pipeline-assembly
 * choice, not a property of this middleware.
 */
final readonly class CacheMiddleware implements ProviderMiddlewareInterface
{
    public const METADATA_CACHE_KEY  = 'cacheKey';
    public const METADATA_CACHE_TTL  = 'cacheTtl';
    public const METADATA_CACHE_TAGS = 'cacheTags';

    private const DEFAULT_TTL_SECONDS = 3600;

    public function __construct(
        private CacheManagerInterface $cache,
    ) {}

    /**
     * @param callable(LlmConfiguration): mixed $next
     */
    public function handle(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $next,
    ): mixed {
        $key = $this->readKey($context);
        if ($key === null) {
            return $next($configuration);
        }

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = $next($configuration);

        if (\is_array($result)) {
            /** @var array<string, mixed> $result */
            $this->cache->set(
                $key,
                $result,
                $this->readTtl($context),
                $this->readTags($context),
            );
        }

        return $result;
    }

    private function readKey(ProviderCallContext $context): ?string
    {
        $value = $context->metadata[self::METADATA_CACHE_KEY] ?? null;
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function readTtl(ProviderCallContext $context): int
    {
        $value = $context->metadata[self::METADATA_CACHE_TTL] ?? null;
        if (\is_int($value) && $value > 0) {
            return $value;
        }

        return self::DEFAULT_TTL_SECONDS;
    }

    /**
     * @return array<int, string>
     */
    private function readTags(ProviderCallContext $context): array
    {
        $value = $context->metadata[self::METADATA_CACHE_TAGS] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $tag) {
            if (\is_string($tag) && $tag !== '') {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
