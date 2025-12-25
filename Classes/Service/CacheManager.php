<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\SingletonInterface;

final class CacheManager implements CacheManagerInterface, SingletonInterface
{
    private const CACHE_IDENTIFIER = 'nrllm_responses';

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly Typo3CacheManager $cacheManager,
    ) {}

    private function getCache(): FrontendInterface
    {
        if ($this->cache === null) {
            $this->cache = $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
        }
        return $this->cache;
    }

    /**
     * Generate a cache key for LLM requests.
     *
     * @param array<string, mixed> $params
     */
    public function generateCacheKey(string $provider, string $operation, array $params): string
    {
        $normalized = $this->normalizeParams($params);
        $hash = hash('xxh128', json_encode($normalized, JSON_THROW_ON_ERROR));
        return sprintf('%s_%s_%s', $provider, $operation, $hash);
    }

    /**
     * Get cached response if available.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $cacheKey): ?array
    {
        $cache = $this->getCache();

        if (!$cache->has($cacheKey)) {
            return null;
        }

        $data = $cache->get($cacheKey);
        return is_array($data) ? $data : null;
    }

    /**
     * Store response in cache.
     *
     * @param array<string, mixed> $data
     * @param array<string>        $tags
     */
    public function set(string $cacheKey, array $data, int $lifetime = 3600, array $tags = []): void
    {
        $defaultTags = ['nrllm', 'nrllm_response'];
        $allTags = array_unique(array_merge($defaultTags, $tags));

        $this->getCache()->set($cacheKey, $data, $allTags, $lifetime);
    }

    /**
     * Check if a cache entry exists.
     */
    public function has(string $cacheKey): bool
    {
        return $this->getCache()->has($cacheKey);
    }

    /**
     * Remove a specific cache entry.
     */
    public function remove(string $cacheKey): void
    {
        $this->getCache()->remove($cacheKey);
    }

    /**
     * Flush all LLM caches.
     */
    public function flush(): void
    {
        $this->getCache()->flush();
    }

    /**
     * Flush cache entries by tag.
     */
    public function flushByTag(string $tag): void
    {
        $this->getCache()->flushByTag($tag);
    }

    /**
     * Flush cache entries by provider.
     */
    public function flushByProvider(string $provider): void
    {
        $this->flushByTag('nrllm_provider_' . $provider);
    }

    /**
     * Cache a completion response.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $options
     * @param array<string, mixed>                             $response
     */
    public function cacheCompletion(
        string $provider,
        array $messages,
        array $options,
        array $response,
        int $lifetime = 3600,
    ): string {
        $cacheKey = $this->generateCacheKey($provider, 'completion', [
            'messages' => $messages,
            'options' => $options,
        ]);

        $tags = [
            'nrllm_completion',
            'nrllm_provider_' . $provider,
        ];

        if (isset($options['model'])) {
            $tags[] = 'nrllm_model_' . str_replace(['.', '-'], '_', $options['model']);
        }

        $this->set($cacheKey, $response, $lifetime, $tags);

        return $cacheKey;
    }

    /**
     * Get cached completion if available.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $options
     *
     * @return array<string, mixed>|null
     */
    public function getCachedCompletion(string $provider, array $messages, array $options): ?array
    {
        $cacheKey = $this->generateCacheKey($provider, 'completion', [
            'messages' => $messages,
            'options' => $options,
        ]);

        return $this->get($cacheKey);
    }

    /**
     * Cache embeddings response.
     *
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     * @param array<string, mixed>      $response
     */
    public function cacheEmbeddings(
        string $provider,
        string|array $input,
        array $options,
        array $response,
        int $lifetime = 86400, // 24 hours for embeddings
    ): string {
        $cacheKey = $this->generateCacheKey($provider, 'embeddings', [
            'input' => $input,
            'options' => $options,
        ]);

        $tags = [
            'nrllm_embeddings',
            'nrllm_provider_' . $provider,
        ];

        $this->set($cacheKey, $response, $lifetime, $tags);

        return $cacheKey;
    }

    /**
     * Get cached embeddings if available.
     *
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     *
     * @return array<string, mixed>|null
     */
    public function getCachedEmbeddings(string $provider, string|array $input, array $options): ?array
    {
        $cacheKey = $this->generateCacheKey($provider, 'embeddings', [
            'input' => $input,
            'options' => $options,
        ]);

        return $this->get($cacheKey);
    }

    /**
     * Normalize parameters for consistent cache keys.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function normalizeParams(array $params): array
    {
        // Sort arrays recursively for consistent hashing
        $this->sortRecursive($params);

        // Remove parameters that shouldn't affect caching
        unset($params['stream']);
        unset($params['user']);

        return $params;
    }

    /**
     * Sort array recursively for consistent hashing.
     *
     * @param array<string, mixed> $array
     */
    private function sortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->sortRecursive($value);
            }
        }
        ksort($array);
    }
}
