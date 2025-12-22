<?php

/**
 * Caching and Performance Layer - Full PHP Implementation
 *
 * This file contains all PHP classes for the AI Base caching system.
 * In production, these would be split into individual files under Classes/Service/Cache/
 *
 * @package Netresearch\AiBase
 */

declare(strict_types=1);

namespace Netresearch\AiBase\Service\Cache;

use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use Psr\Log\LoggerInterface;
use Netresearch\AiBase\Domain\Model\AiRequest;
use Netresearch\AiBase\Domain\Model\AiResponse;

// ============================================================================
// 1. CACHE SERVICE (Main Class)
// ============================================================================

/**
 * Main caching service for AI responses
 *
 * Responsibilities:
 * - Generate deterministic cache keys
 * - Manage cache storage and retrieval
 * - Track hit/miss metrics
 * - Handle cache invalidation
 *
 * @package Netresearch\AiBase\Service\Cache
 */
class CacheService implements SingletonInterface
{
    private const CACHE_KEY_PREFIX = 'ai_';
    private const CACHE_KEY_VERSION = 'v1'; // Increment to invalidate all caches

    public function __construct(
        private readonly VariableFrontend $cache,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly CacheMetricsService $metricsService,
        private readonly LoggerInterface $logger,
        private readonly CacheKeyGenerator $keyGenerator
    ) {}

    /**
     * Get cached AI response or execute and cache
     */
    public function get(
        AiRequest $request,
        callable $provider,
        ?int $ttl = null
    ): AiResponse {
        $cacheKey = $this->generateCacheKey($request);

        // Try cache first
        if ($this->cache->has($cacheKey)) {
            $this->metricsService->recordHit(
                $request->getFeature(),
                $request->getProvider()
            );

            $this->logger->debug('Cache hit', [
                'key' => $cacheKey,
                'feature' => $request->getFeature(),
            ]);

            return $this->cache->get($cacheKey);
        }

        // Cache miss - execute provider
        $this->metricsService->recordMiss(
            $request->getFeature(),
            $request->getProvider()
        );

        $this->logger->debug('Cache miss', [
            'key' => $cacheKey,
            'feature' => $request->getFeature(),
        ]);

        $response = $provider();

        // Store in cache
        $ttl = $ttl ?? $this->getTtlForFeature($request->getFeature());
        $this->set($cacheKey, $response, $ttl);

        return $response;
    }

    /**
     * Store response in cache
     */
    public function set(string $cacheKey, AiResponse $response, int $ttl): void
    {
        try {
            $this->cache->set($cacheKey, $response, [], $ttl);

            $this->metricsService->recordWrite(
                $response->getRequest()->getFeature(),
                $response->getRequest()->getProvider(),
                $this->estimateResponseSize($response)
            );

            $this->logger->debug('Cache write', [
                'key' => $cacheKey,
                'ttl' => $ttl,
                'size' => $this->estimateResponseSize($response),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Cache write failed', [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if cache entry exists
     */
    public function has(string $cacheKey): bool
    {
        return $this->cache->has($cacheKey);
    }

    /**
     * Remove specific cache entry
     */
    public function remove(string $cacheKey): void
    {
        $this->cache->remove($cacheKey);

        $this->logger->info('Cache entry removed', [
            'key' => $cacheKey,
        ]);
    }

    /**
     * Clear cache by feature
     */
    public function clearByFeature(string $feature): int
    {
        $count = 0;
        $pattern = $this->getCacheKeyPattern($feature);

        foreach ($this->cache->getByTag($feature) as $identifier => $entry) {
            $this->cache->remove($identifier);
            $count++;
        }

        $this->logger->info('Cache cleared by feature', [
            'feature' => $feature,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Clear cache by provider
     */
    public function clearByProvider(string $provider): int
    {
        $count = 0;

        foreach ($this->cache->getByTag($provider) as $identifier => $entry) {
            $this->cache->remove($identifier);
            $count++;
        }

        $this->logger->info('Cache cleared by provider', [
            'provider' => $provider,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Flush entire AI cache
     */
    public function flush(): void
    {
        $this->cache->flush();

        $this->logger->warning('AI cache completely flushed');
    }

    /**
     * Generate deterministic cache key for request
     */
    private function generateCacheKey(AiRequest $request): string
    {
        return $this->keyGenerator->generate($request);
    }

    /**
     * Get cache key pattern for feature
     */
    private function getCacheKeyPattern(string $feature): string
    {
        return self::CACHE_KEY_PREFIX . self::CACHE_KEY_VERSION . '_' . $feature . '_*';
    }

    /**
     * Get TTL for specific feature
     */
    private function getTtlForFeature(string $feature): int
    {
        $ttlMap = [
            'translation' => 2592000, // 30 days
            'image_alt' => 7776000,   // 90 days
            'seo_meta' => 604800,     // 7 days
            'content_suggestions' => 86400, // 1 day
            'embeddings' => 0,        // Permanent (no expiration)
            'content_enhancement' => 259200, // 3 days
        ];

        // Try extension configuration first
        try {
            $configKey = 'cache.ttl.' . $feature;
            $ttl = $this->extensionConfiguration->get('ai_base', $configKey);
            if ($ttl !== null) {
                return (int)$ttl;
            }
        } catch (\Exception $e) {
            // Fallback to map
        }

        return $ttlMap[$feature] ?? 3600; // Default: 1 hour
    }

    /**
     * Estimate response size in bytes
     */
    private function estimateResponseSize(AiResponse $response): int
    {
        return strlen(serialize($response));
    }
}

// ============================================================================
// 2. CACHE KEY GENERATOR
// ============================================================================

/**
 * Generates deterministic, collision-resistant cache keys
 *
 * Key Format: ai_v1_<feature>_<hash>
 * Hash Input: provider + model + normalized_prompt + options_fingerprint
 *
 * @package Netresearch\AiBase\Service\Cache
 */
class CacheKeyGenerator implements SingletonInterface
{
    private const CACHE_KEY_PREFIX = 'ai_';
    private const CACHE_KEY_VERSION = 'v1';
    private const HASH_ALGORITHM = 'sha256';

    public function __construct(
        private readonly PromptNormalizer $normalizer
    ) {}

    /**
     * Generate cache key for AI request
     */
    public function generate(AiRequest $request): string
    {
        $components = [
            'provider' => $request->getProvider(),
            'model' => $request->getModel(),
            'prompt' => $this->normalizer->normalize($request->getPrompt()),
            'options' => $this->generateOptionsFingerprint($request->getOptions()),
            'feature' => $request->getFeature(),
        ];

        $hash = hash(
            self::HASH_ALGORITHM,
            json_encode($components, JSON_THROW_ON_ERROR)
        );

        // Truncate hash for readability (still 128-bit security)
        $shortHash = substr($hash, 0, 32);

        return sprintf(
            '%s%s_%s_%s',
            self::CACHE_KEY_PREFIX,
            self::CACHE_KEY_VERSION,
            $request->getFeature(),
            $shortHash
        );
    }

    /**
     * Generate deterministic fingerprint for options array
     */
    private function generateOptionsFingerprint(array $options): string
    {
        // Remove non-deterministic options
        $filteredOptions = $this->filterDeterministicOptions($options);

        // Sort keys for consistency
        ksort($filteredOptions);

        // Recursively sort nested arrays
        array_walk_recursive($filteredOptions, function(&$value) {
            if (is_array($value)) {
                ksort($value);
            }
        });

        return json_encode($filteredOptions, JSON_THROW_ON_ERROR);
    }

    /**
     * Filter out non-deterministic options
     */
    private function filterDeterministicOptions(array $options): array
    {
        $nonDeterministicKeys = [
            'callback',
            'stream_callback',
            'timestamp',
            'request_id',
            'timeout',
            'user_id', // User-specific data doesn't affect AI output
        ];

        return array_filter(
            $options,
            fn($key) => !in_array($key, $nonDeterministicKeys, true),
            ARRAY_FILTER_USE_KEY
        );
    }
}

// ============================================================================
// 3. PROMPT NORMALIZER
// ============================================================================

/**
 * Normalizes prompts for consistent cache key generation
 *
 * Rules:
 * - Trim leading/trailing whitespace
 * - Normalize line endings to LF
 * - Preserve case (case-sensitive)
 * - Preserve internal whitespace structure
 *
 * @package Netresearch\AiBase\Service\Cache
 */
class PromptNormalizer implements SingletonInterface
{
    /**
     * Normalize prompt text
     */
    public function normalize(string $prompt): string
    {
        // 1. Normalize line endings (CRLF, CR → LF)
        $normalized = str_replace(["\r\n", "\r"], "\n", $prompt);

        // 2. Trim leading/trailing whitespace
        $normalized = trim($normalized);

        // 3. Normalize multiple consecutive spaces (optional - preserves structure)
        // Disabled by default to preserve formatting intent
        // $normalized = preg_replace('/[ \t]+/', ' ', $normalized);

        return $normalized;
    }

    /**
     * Normalize for case-insensitive comparison (optional)
     */
    public function normalizeCaseInsensitive(string $prompt): string
    {
        return mb_strtolower($this->normalize($prompt), 'UTF-8');
    }
}

// ============================================================================
// 4. CACHE METRICS SERVICE
// ============================================================================

/**
 * Tracks cache performance metrics
 *
 * Metrics:
 * - Hit/miss counts per feature
 * - Storage size per feature
 * - Cost savings calculation
 * - Cache efficiency reports
 *
 * @package Netresearch\AiBase\Service\Cache
 */
class CacheMetricsService implements SingletonInterface
{
    private array $sessionMetrics = [];

    public function __construct(
        private readonly CacheMetricsRepository $repository,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Record cache hit
     */
    public function recordHit(string $feature, string $provider): void
    {
        $this->incrementMetric($feature, $provider, 'hits');
    }

    /**
     * Record cache miss
     */
    public function recordMiss(string $feature, string $provider): void
    {
        $this->incrementMetric($feature, $provider, 'misses');
    }

    /**
     * Record cache write
     */
    public function recordWrite(string $feature, string $provider, int $size): void
    {
        $this->incrementMetric($feature, $provider, 'writes');
        $this->addToMetric($feature, $provider, 'storage_bytes', $size);
    }

    /**
     * Get current session metrics
     */
    public function getSessionMetrics(): array
    {
        return $this->sessionMetrics;
    }

    /**
     * Calculate hit rate for feature
     */
    public function getHitRate(string $feature, string $provider): float
    {
        $hits = $this->getMetric($feature, $provider, 'hits');
        $misses = $this->getMetric($feature, $provider, 'misses');
        $total = $hits + $misses;

        return $total > 0 ? $hits / $total : 0.0;
    }

    /**
     * Persist session metrics to database
     */
    public function persistMetrics(): void
    {
        foreach ($this->sessionMetrics as $key => $metrics) {
            [$feature, $provider] = explode(':', $key);

            $this->repository->saveMetrics(
                $feature,
                $provider,
                $metrics['hits'] ?? 0,
                $metrics['misses'] ?? 0,
                $metrics['writes'] ?? 0,
                $metrics['storage_bytes'] ?? 0
            );
        }

        $this->sessionMetrics = [];
    }

    /**
     * Generate cache efficiency report
     */
    public function generateReport(int $periodStart, int $periodEnd): array
    {
        $metrics = $this->repository->getMetricsForPeriod($periodStart, $periodEnd);

        $totalHits = array_sum(array_column($metrics, 'cache_hits'));
        $totalMisses = array_sum(array_column($metrics, 'cache_misses'));
        $totalRequests = $totalHits + $totalMisses;
        $hitRate = $totalRequests > 0 ? $totalHits / $totalRequests : 0.0;

        $totalStorageBytes = array_sum(array_column($metrics, 'storage_size_bytes'));
        $totalCostSaved = array_sum(array_column($metrics, 'cost_saved_usd'));

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_requests' => $totalRequests,
            'cache_hits' => $totalHits,
            'cache_misses' => $totalMisses,
            'hit_rate' => round($hitRate, 4),
            'hit_rate_percentage' => round($hitRate * 100, 2),
            'storage_size_mb' => round($totalStorageBytes / 1024 / 1024, 2),
            'cost_saved_usd' => round($totalCostSaved, 2),
            'api_calls_saved' => $totalHits,
            'by_feature' => $this->aggregateByFeature($metrics),
            'by_provider' => $this->aggregateByProvider($metrics),
        ];
    }

    /**
     * Increment metric counter
     */
    private function incrementMetric(string $feature, string $provider, string $metric): void
    {
        $key = $this->getMetricKey($feature, $provider);

        if (!isset($this->sessionMetrics[$key])) {
            $this->sessionMetrics[$key] = [];
        }

        if (!isset($this->sessionMetrics[$key][$metric])) {
            $this->sessionMetrics[$key][$metric] = 0;
        }

        $this->sessionMetrics[$key][$metric]++;
    }

    /**
     * Add to metric value
     */
    private function addToMetric(string $feature, string $provider, string $metric, int $value): void
    {
        $key = $this->getMetricKey($feature, $provider);

        if (!isset($this->sessionMetrics[$key])) {
            $this->sessionMetrics[$key] = [];
        }

        if (!isset($this->sessionMetrics[$key][$metric])) {
            $this->sessionMetrics[$key][$metric] = 0;
        }

        $this->sessionMetrics[$key][$metric] += $value;
    }

    /**
     * Get metric value
     */
    private function getMetric(string $feature, string $provider, string $metric): int
    {
        $key = $this->getMetricKey($feature, $provider);
        return $this->sessionMetrics[$key][$metric] ?? 0;
    }

    /**
     * Generate metric key
     */
    private function getMetricKey(string $feature, string $provider): string
    {
        return $feature . ':' . $provider;
    }

    /**
     * Aggregate metrics by feature
     */
    private function aggregateByFeature(array $metrics): array
    {
        $aggregated = [];

        foreach ($metrics as $metric) {
            $feature = $metric['feature'];

            if (!isset($aggregated[$feature])) {
                $aggregated[$feature] = [
                    'hits' => 0,
                    'misses' => 0,
                    'requests' => 0,
                    'hit_rate' => 0.0,
                ];
            }

            $aggregated[$feature]['hits'] += $metric['cache_hits'];
            $aggregated[$feature]['misses'] += $metric['cache_misses'];
            $aggregated[$feature]['requests'] = $aggregated[$feature]['hits'] + $aggregated[$feature]['misses'];

            if ($aggregated[$feature]['requests'] > 0) {
                $aggregated[$feature]['hit_rate'] = round(
                    $aggregated[$feature]['hits'] / $aggregated[$feature]['requests'],
                    4
                );
            }
        }

        return $aggregated;
    }

    /**
     * Aggregate metrics by provider
     */
    private function aggregateByProvider(array $metrics): array
    {
        $aggregated = [];

        foreach ($metrics as $metric) {
            $provider = $metric['provider'];

            if (!isset($aggregated[$provider])) {
                $aggregated[$provider] = [
                    'hits' => 0,
                    'misses' => 0,
                    'requests' => 0,
                    'hit_rate' => 0.0,
                ];
            }

            $aggregated[$provider]['hits'] += $metric['cache_hits'];
            $aggregated[$provider]['misses'] += $metric['cache_misses'];
            $aggregated[$provider]['requests'] = $aggregated[$provider]['hits'] + $aggregated[$provider]['misses'];

            if ($aggregated[$provider]['requests'] > 0) {
                $aggregated[$provider]['hit_rate'] = round(
                    $aggregated[$provider]['hits'] / $aggregated[$provider]['requests'],
                    4
                );
            }
        }

        return $aggregated;
    }
}

// ============================================================================
// 5. CACHE METRICS REPOSITORY
// ============================================================================

/**
 * Database persistence for cache metrics
 *
 * Table: tx_aibase_cache_metrics
 *
 * @package Netresearch\AiBase\Domain\Repository
 */
class CacheMetricsRepository
{
    public function __construct(
        private readonly \TYPO3\CMS\Core\Database\ConnectionPool $connectionPool
    ) {}

    /**
     * Save metrics to database
     */
    public function saveMetrics(
        string $feature,
        string $provider,
        int $hits,
        int $misses,
        int $writes,
        int $storageBytes
    ): void {
        $connection = $this->connectionPool->getConnectionForTable('tx_aibase_cache_metrics');

        $periodStart = strtotime('today');
        $periodEnd = strtotime('tomorrow') - 1;

        // Check if record exists for today
        $existing = $connection->select(
            ['uid', 'cache_hits', 'cache_misses', 'cache_writes', 'storage_size_bytes'],
            'tx_aibase_cache_metrics',
            [
                'feature' => $feature,
                'provider' => $provider,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        )->fetchAssociative();

        if ($existing) {
            // Update existing record
            $connection->update(
                'tx_aibase_cache_metrics',
                [
                    'cache_hits' => $existing['cache_hits'] + $hits,
                    'cache_misses' => $existing['cache_misses'] + $misses,
                    'cache_writes' => $existing['cache_writes'] + $writes,
                    'storage_size_bytes' => $existing['storage_size_bytes'] + $storageBytes,
                    'total_requests' => $existing['cache_hits'] + $existing['cache_misses'] + $hits + $misses,
                    'tstamp' => time(),
                ],
                ['uid' => $existing['uid']]
            );
        } else {
            // Insert new record
            $connection->insert(
                'tx_aibase_cache_metrics',
                [
                    'feature' => $feature,
                    'provider' => $provider,
                    'cache_hits' => $hits,
                    'cache_misses' => $misses,
                    'cache_writes' => $writes,
                    'total_requests' => $hits + $misses,
                    'storage_size_bytes' => $storageBytes,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'tstamp' => time(),
                    'crdate' => time(),
                ]
            );
        }
    }

    /**
     * Get metrics for time period
     */
    public function getMetricsForPeriod(int $periodStart, int $periodEnd): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_aibase_cache_metrics');

        return $queryBuilder
            ->select('*')
            ->from('tx_aibase_cache_metrics')
            ->where(
                $queryBuilder->expr()->gte('period_start', $queryBuilder->createNamedParameter($periodStart)),
                $queryBuilder->expr()->lte('period_end', $queryBuilder->createNamedParameter($periodEnd))
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Get metrics by feature
     */
    public function getMetricsByFeature(string $feature, int $days = 30): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_aibase_cache_metrics');
        $periodStart = strtotime("-{$days} days");

        return $queryBuilder
            ->select('*')
            ->from('tx_aibase_cache_metrics')
            ->where(
                $queryBuilder->expr()->eq('feature', $queryBuilder->createNamedParameter($feature)),
                $queryBuilder->expr()->gte('period_start', $queryBuilder->createNamedParameter($periodStart))
            )
            ->orderBy('period_start', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

// ============================================================================
// 6. CACHE WARMING SERVICE
// ============================================================================

/**
 * Pre-generates cache entries for common requests
 *
 * Strategies:
 * - Process all images on upload
 * - Pre-translate standard UI strings
 * - Batch process content on publish
 *
 * @package Netresearch\AiBase\Service\Cache
 */
class CacheWarmingService
{
    public function __construct(
        private readonly CacheService $cacheService,
        private readonly \Netresearch\AiBase\Service\AiServiceManager $aiService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Warm cache for image alt text
     */
    public function warmImageAltText(array $imageUids): int
    {
        $count = 0;

        foreach ($imageUids as $uid) {
            try {
                // Check if already cached
                $request = $this->buildImageAltRequest($uid);
                $cacheKey = $this->cacheService->generateCacheKey($request);

                if (!$this->cacheService->has($cacheKey)) {
                    // Generate and cache
                    $this->aiService->generateImageAltText($uid);
                    $count++;

                    $this->logger->debug('Cache warmed for image', [
                        'uid' => $uid,
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('Cache warming failed for image', [
                    'uid' => $uid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Warm cache for translations
     */
    public function warmTranslations(array $texts, array $targetLanguages): int
    {
        $count = 0;

        foreach ($texts as $text) {
            foreach ($targetLanguages as $language) {
                try {
                    $request = $this->buildTranslationRequest($text, $language);
                    $cacheKey = $this->cacheService->generateCacheKey($request);

                    if (!$this->cacheService->has($cacheKey)) {
                        $this->aiService->translate($text, $language);
                        $count++;

                        $this->logger->debug('Cache warmed for translation', [
                            'language' => $language,
                            'text_length' => strlen($text),
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Cache warming failed for translation', [
                        'language' => $language,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $count;
    }

    /**
     * Warm cache for SEO meta
     */
    public function warmSeoMeta(array $pageUids): int
    {
        $count = 0;

        foreach ($pageUids as $uid) {
            try {
                $request = $this->buildSeoMetaRequest($uid);
                $cacheKey = $this->cacheService->generateCacheKey($request);

                if (!$this->cacheService->has($cacheKey)) {
                    $this->aiService->generateSeoMeta($uid);
                    $count++;

                    $this->logger->debug('Cache warmed for SEO meta', [
                        'page_uid' => $uid,
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('Cache warming failed for SEO meta', [
                    'page_uid' => $uid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Build request object for cache key generation
     */
    private function buildImageAltRequest(int $imageUid): AiRequest
    {
        // Implementation depends on AiRequest model
        // This is a placeholder
        return new AiRequest([
            'feature' => 'image_alt',
            'provider' => 'openai',
            'model' => 'gpt-4-vision',
            'prompt' => "Generate alt text for image {$imageUid}",
            'options' => [],
        ]);
    }

    private function buildTranslationRequest(string $text, string $targetLanguage): AiRequest
    {
        return new AiRequest([
            'feature' => 'translation',
            'provider' => 'openai',
            'model' => 'gpt-4',
            'prompt' => "Translate to {$targetLanguage}: {$text}",
            'options' => ['target_language' => $targetLanguage],
        ]);
    }

    private function buildSeoMetaRequest(int $pageUid): AiRequest
    {
        return new AiRequest([
            'feature' => 'seo_meta',
            'provider' => 'openai',
            'model' => 'gpt-4',
            'prompt' => "Generate SEO meta for page {$pageUid}",
            'options' => [],
        ]);
    }
}

// ============================================================================
// 7. REQUEST BATCHING SERVICE
// ============================================================================

/**
 * Batches multiple AI requests into single API call
 *
 * Benefits:
 * - Reduces API overhead (1 call instead of N)
 * - Lower latency (parallel processing by provider)
 * - Cost efficiency (bulk pricing)
 *
 * Limitations:
 * - Max batch size: 20 (API limits)
 * - Same provider/model only
 * - Synchronous only (no streaming)
 *
 * @package Netresearch\AiBase\Service\Performance
 */
class RequestBatchingService
{
    private const MAX_BATCH_SIZE = 20;

    private array $pendingBatches = [];

    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Add request to batch queue
     */
    public function enqueue(AiRequest $request, callable $callback): void
    {
        $batchKey = $this->getBatchKey($request);

        if (!isset($this->pendingBatches[$batchKey])) {
            $this->pendingBatches[$batchKey] = [
                'provider' => $request->getProvider(),
                'model' => $request->getModel(),
                'feature' => $request->getFeature(),
                'requests' => [],
                'callbacks' => [],
            ];
        }

        $this->pendingBatches[$batchKey]['requests'][] = $request;
        $this->pendingBatches[$batchKey]['callbacks'][] = $callback;

        // Auto-flush if batch is full
        if (count($this->pendingBatches[$batchKey]['requests']) >= self::MAX_BATCH_SIZE) {
            $this->flushBatch($batchKey);
        }
    }

    /**
     * Flush all pending batches
     */
    public function flushAll(): void
    {
        foreach (array_keys($this->pendingBatches) as $batchKey) {
            $this->flushBatch($batchKey);
        }
    }

    /**
     * Flush specific batch
     */
    private function flushBatch(string $batchKey): void
    {
        if (!isset($this->pendingBatches[$batchKey])) {
            return;
        }

        $batch = $this->pendingBatches[$batchKey];
        $requestCount = count($batch['requests']);

        if ($requestCount === 0) {
            return;
        }

        $this->logger->info('Flushing batch', [
            'key' => $batchKey,
            'size' => $requestCount,
        ]);

        try {
            // Execute batch (provider-specific implementation)
            $responses = $this->executeBatch($batch['requests']);

            // Invoke callbacks with results
            foreach ($responses as $index => $response) {
                $callback = $batch['callbacks'][$index];
                $callback($response);
            }

            $this->logger->info('Batch executed successfully', [
                'key' => $batchKey,
                'size' => $requestCount,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Batch execution failed', [
                'key' => $batchKey,
                'size' => $requestCount,
                'error' => $e->getMessage(),
            ]);

            // Fallback: execute individually
            foreach ($batch['requests'] as $index => $request) {
                try {
                    $response = $this->executeIndividual($request);
                    $batch['callbacks'][$index]($response);
                } catch (\Exception $individualError) {
                    $this->logger->error('Individual fallback failed', [
                        'index' => $index,
                        'error' => $individualError->getMessage(),
                    ]);
                }
            }
        }

        // Clear batch
        unset($this->pendingBatches[$batchKey]);
    }

    /**
     * Generate batch key (groups compatible requests)
     */
    private function getBatchKey(AiRequest $request): string
    {
        return sprintf(
            '%s:%s:%s',
            $request->getProvider(),
            $request->getModel(),
            $request->getFeature()
        );
    }

    /**
     * Execute batch request (provider-specific)
     */
    private function executeBatch(array $requests): array
    {
        // This would delegate to the appropriate provider's batch method
        // Example: $provider->completeBatch($prompts)

        // Placeholder implementation
        return array_map(
            fn($request) => $this->executeIndividual($request),
            $requests
        );
    }

    /**
     * Execute individual request (fallback)
     */
    private function executeIndividual(AiRequest $request): AiResponse
    {
        // Placeholder - would delegate to provider
        throw new \RuntimeException('Individual execution not implemented');
    }
}

// ============================================================================
// 8. USAGE EXAMPLE
// ============================================================================

/**
 * Example: How to use the caching layer
 */
class ExampleUsage
{
    public function __construct(
        private readonly CacheService $cacheService,
        private readonly \Netresearch\AiBase\Service\Provider\ProviderInterface $provider
    ) {}

    /**
     * Example 1: Simple cache usage
     */
    public function translateWithCache(string $text, string $targetLanguage): string
    {
        $request = new AiRequest([
            'feature' => 'translation',
            'provider' => 'openai',
            'model' => 'gpt-4',
            'prompt' => "Translate to {$targetLanguage}: {$text}",
            'options' => ['target_language' => $targetLanguage],
        ]);

        // Cache handles everything
        $response = $this->cacheService->get(
            $request,
            fn() => $this->provider->translate($text, $targetLanguage),
            ttl: 2592000 // 30 days
        );

        return $response->getContent();
    }

    /**
     * Example 2: Manual cache check
     */
    public function checkCacheStatus(AiRequest $request): array
    {
        $cacheKey = $this->cacheService->generateCacheKey($request);

        return [
            'cached' => $this->cacheService->has($cacheKey),
            'cache_key' => $cacheKey,
        ];
    }

    /**
     * Example 3: Cache invalidation
     */
    public function invalidateProviderCache(string $provider): int
    {
        return $this->cacheService->clearByProvider($provider);
    }
}
