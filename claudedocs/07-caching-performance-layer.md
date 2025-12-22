# Caching and Performance Layer - AI Base Extension

> Document: Caching & Performance Architecture
> Date: 2025-12-22
> Phase: 3 (Caching & Rate Limiting)

---

## Executive Summary

LLM API calls are EXPENSIVE (cost) and SLOW (latency). This document defines a comprehensive caching and performance optimization layer that:

- Reduces API costs by 60-80% through intelligent caching
- Improves response times from seconds to milliseconds for cached content
- Provides multiple cache backend options (file, Redis, database)
- Implements request batching and connection pooling
- Tracks cache efficiency with detailed metrics

**Key Performance Targets**:
- Cache hit rate: >60%
- Cache overhead: <10ms
- API call reduction: 70%+
- Storage efficiency: <100MB per 10k requests

---

## 1. Cache Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    Request Flow                              │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│  AiServiceManager                                            │
│  ├─ Access Control Check                                    │
│  ├─ Rate Limit Check                                        │
│  └─ Feature Routing                                         │
└──────────────────────────┬──────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│  CacheService (Layer 1: Application Cache)                  │
│  ├─ Cache Key Generation (deterministic hashing)            │
│  ├─ Cache Lookup (hit/miss tracking)                        │
│  ├─ TTL Management (per-feature configuration)              │
│  └─ Invalidation Strategy                                   │
└──────────────────────────┬──────────────────────────────────┘
                           │
                    Cache Miss? │
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│  Provider Execution                                          │
│  ├─ Connection Pool (reuse HTTP connections)                │
│  ├─ Request Batching (combine multiple prompts)             │
│  ├─ Response Streaming (progressive results)                │
│  └─ Async Processing (TYPO3 queue integration)              │
└──────────────────────────┬──────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│  Cache Storage Backend                                       │
│  ├─ File-based (default, no dependencies)                   │
│  ├─ Redis (high-performance, recommended)                   │
│  ├─ Database (reliable, TYPO3 native)                       │
│  └─ Memory (development only)                               │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. Cache Key Generation Strategy

### Algorithm Design

```php
Cache Key = hash(
    provider_identifier +
    model_name +
    normalized_prompt +
    options_fingerprint +
    feature_context
)
```

### Normalization Rules

1. **Prompt Normalization**:
   - Trim whitespace (leading/trailing)
   - Normalize line endings (LF)
   - Case-sensitive (preserve original case)
   - Preserve internal whitespace structure

2. **Options Fingerprint**:
   - Sort keys alphabetically
   - Serialize to deterministic JSON
   - Exclude non-deterministic options (callbacks, timestamps)

3. **Collision Resistance**:
   - Use SHA-256 for hashing
   - Include all parameters that affect output
   - Validate key uniqueness in tests

### TTL Strategy Per Feature

| Feature | TTL | Reasoning |
|---------|-----|-----------|
| Translation | 30 days | Content rarely changes, high reuse |
| Image Alt Text | 90 days | Images don't change, permanent descriptions |
| SEO Meta | 7 days | SEO strategy evolves, moderate volatility |
| Content Suggestions | 1 day | Time-sensitive, may reference current events |
| Embeddings | Permanent | Deterministic, immutable for same input |
| Content Enhancement | 3 days | Creative output, balance freshness vs cost |

### Cache Invalidation Triggers

1. **Manual Invalidation**:
   - Admin clears specific cache entries
   - Bulk clear by feature/provider
   - Emergency flush (API changes)

2. **TTL-Based**:
   - Automatic expiration per feature
   - Configurable per-site override
   - Stale-while-revalidate pattern

3. **Event-Based**:
   - Provider configuration change → clear provider cache
   - Model version update → clear model cache
   - Content update → clear related embeddings

---

## 3. Cache Backend Configuration

### TYPO3 Cache Registration

```php
// ext_localconf.php

if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_responses'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_responses'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
        'options' => [
            'defaultLifetime' => 86400, // 24 hours default
        ],
        'groups' => ['pages', 'all'],
    ];
}

// Redis backend (recommended for production)
if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_responses_redis'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_responses_redis'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\RedisBackend::class,
        'options' => [
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 5, // Dedicated database for AI cache
            'defaultLifetime' => 86400,
            'compression' => true, // Compress large responses
        ],
        'groups' => ['pages', 'all'],
    ];
}

// Database backend (fallback)
if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_responses_db'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_responses_db'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'options' => [
            'defaultLifetime' => 86400,
        ],
        'groups' => ['pages', 'all'],
    ];
}

// Memory backend (development only - WARNING: volatile!)
if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_responses_memory'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['ai_responses_memory'] = [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend::class,
        'options' => [],
        'groups' => [],
    ];
}
```

### Backend Selection Strategy

```php
// LocalConfiguration.php override
'SYS' => [
    'caching' => [
        'cacheConfigurations' => [
            'ai_responses' => [
                // Production: Redis
                'backend' => \TYPO3\CMS\Core\Cache\Backend\RedisBackend::class,

                // Staging: Database
                // 'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,

                // Development: File
                // 'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
            ],
        ],
    ],
],
```

---

## 4. Performance Optimizations

### Request Batching

**Problem**: Sending 10 translation requests = 10 API calls = 10x latency + cost

**Solution**: Batch multiple prompts into single API call

```php
// Instead of:
for ($i = 0; $i < 10; $i++) {
    $results[$i] = $provider->translate($texts[$i], 'de');
}

// Use batching:
$results = $provider->translateBatch($texts, 'de');
```

**Implementation**:
- Collect requests during PHP request lifecycle
- Flush batch before response (or use queue for async)
- Split results back to individual callers
- Maximum batch size: 20 prompts (API limits)

### Connection Pooling

**Problem**: HTTP connection overhead for each API call

**Solution**: Reuse persistent connections with Guzzle

```php
// Guzzle HTTP client configuration
$client = new \GuzzleHttp\Client([
    'timeout' => 30,
    'connect_timeout' => 5,
    'http_errors' => false,
    'verify' => true,
    'headers' => [
        'User-Agent' => 'TYPO3-AI-Base/1.0',
    ],
    // Connection pooling
    'curl' => [
        CURLOPT_FORBID_REUSE => false,
        CURLOPT_FRESH_CONNECT => false,
    ],
]);
```

### Response Streaming

**Problem**: Waiting for full response blocks UI (GPT-4 can take 10-30s)

**Solution**: Stream responses progressively

```php
// Traditional (blocking):
$response = $provider->complete($prompt);
echo $response->getContent(); // Wait for full response

// Streaming (progressive):
$provider->stream($prompt, function($chunk) {
    echo $chunk; // Send partial results immediately
    flush();
});
```

**Use Cases**:
- Backend live preview (show AI typing)
- Frontend chat interfaces
- Long-form content generation

### Async Processing with TYPO3 Queue

**Problem**: API calls block page rendering (bad UX)

**Solution**: Queue non-urgent requests for background processing

```php
// Enqueue AI job
$this->queueService->enqueue('ai_translation', [
    'text' => $content,
    'target_language' => 'de',
    'record_uid' => 123,
]);

// Process in background (Scheduler/Queue worker)
class AiTranslationQueueProcessor
{
    public function process(array $job): void
    {
        $result = $this->aiService->translate(
            $job['text'],
            $job['target_language']
        );

        // Update record with result
        $this->updateRecord($job['record_uid'], $result);
    }
}
```

**Queue Strategy**:
- High Priority: Image alt text (user waiting)
- Medium Priority: SEO meta (batch overnight)
- Low Priority: Content suggestions (pre-warm cache)

---

## 5. Cache Reporting & Metrics

### Tracked Metrics

| Metric | Purpose | Alert Threshold |
|--------|---------|----------------|
| Hit Rate | Cache effectiveness | <40% |
| Miss Rate | Cache coverage gaps | >60% |
| Storage Size | Disk/memory usage | >500MB |
| Avg TTL | Cache freshness | <1 hour |
| Eviction Rate | Cache churn | >20%/hour |
| Cost Savings | ROI calculation | <$100/month |

### Database Schema for Metrics

```sql
CREATE TABLE tx_aibase_cache_metrics (
    uid INT PRIMARY KEY AUTO_INCREMENT,

    feature VARCHAR(100) NOT NULL,
    provider VARCHAR(50) NOT NULL,

    cache_hits INT DEFAULT 0,
    cache_misses INT DEFAULT 0,
    cache_writes INT DEFAULT 0,

    total_requests INT DEFAULT 0,
    cached_requests INT DEFAULT 0,

    storage_size_bytes BIGINT DEFAULT 0,
    avg_ttl_seconds INT DEFAULT 0,

    cost_saved_usd DECIMAL(10,6) DEFAULT 0,

    period_start INT NOT NULL,
    period_end INT NOT NULL,

    tstamp INT DEFAULT 0,
    crdate INT DEFAULT 0,

    KEY feature_provider (feature, provider),
    KEY period (period_start, period_end)
);
```

### Reporting Dashboard Data

```php
// Cache efficiency report
class CacheReportService
{
    public function getDailyReport(int $timestamp): array
    {
        return [
            'hit_rate' => 0.68, // 68%
            'total_requests' => 1250,
            'cache_hits' => 850,
            'cache_misses' => 400,
            'api_calls_saved' => 850,
            'cost_saved_usd' => 4.25,
            'storage_mb' => 145,
            'avg_response_time_ms' => 8,
            'top_cached_features' => [
                'translation' => ['hits' => 450, 'rate' => 0.85],
                'image_alt' => ['hits' => 300, 'rate' => 0.72],
                'seo_meta' => ['hits' => 100, 'rate' => 0.45],
            ],
        ];
    }
}
```

---

## 6. Cache Warming Strategy

### Pre-Generation Scenarios

1. **Common Translations**:
   - Pre-translate standard UI strings
   - Batch process content on publish
   - Warm cache during off-peak hours

2. **Image Alt Text**:
   - Process all images on upload
   - Batch re-process existing media
   - Queue low-priority warming jobs

3. **SEO Meta**:
   - Generate on page creation
   - Refresh weekly via Scheduler
   - Batch update on SEO strategy change

### Warming Job Configuration

```php
// Scheduler task: Cache Warming
class CacheWarmingTask extends AbstractTask
{
    public function execute(): bool
    {
        // Identify high-traffic content without cache
        $records = $this->findUncachedContent();

        foreach ($records as $record) {
            // Enqueue warming job (low priority)
            $this->queueService->enqueue('cache_warming', [
                'feature' => 'image_alt',
                'record_uid' => $record['uid'],
                'priority' => QueuePriority::LOW,
            ]);
        }

        return true;
    }
}
```

---

## 7. Performance Benchmarks

### Expected Performance Characteristics

| Scenario | Without Cache | With Cache (File) | With Cache (Redis) |
|----------|---------------|-------------------|-------------------|
| Translation (100 chars) | 1200ms | 15ms | 3ms |
| Image Alt Text | 2500ms | 12ms | 2ms |
| SEO Meta Generation | 800ms | 10ms | 2ms |
| Content Embedding | 300ms | 8ms | 1ms |
| Batch (10 translations) | 12000ms | 150ms | 30ms |

### Cost Reduction Analysis

**Assumptions**:
- 1000 translation requests/day
- GPT-4 Turbo: $0.01/1K input tokens, $0.03/1K output tokens
- Avg request: 100 input + 150 output tokens

**Without Caching**:
- Daily cost: 1000 × ($0.001 + $0.0045) = $5.45/day
- Monthly cost: $163.50

**With 70% Cache Hit Rate**:
- API calls: 300/day (70% cached)
- Daily cost: 300 × ($0.001 + $0.0045) = $1.64/day
- Monthly cost: $49.20
- **Savings: $114.30/month (69.9%)**

---

## 8. Implementation Checklist

### Phase 3.1: Core Cache Service (Week 6, Days 1-2)
- [ ] CacheService class with key generation
- [ ] Cache key normalization utilities
- [ ] TTL configuration per feature
- [ ] Cache hit/miss tracking
- [ ] Unit tests for key generation

### Phase 3.2: Cache Backends (Week 6, Days 3-4)
- [ ] File backend configuration
- [ ] Redis backend configuration
- [ ] Database backend configuration
- [ ] Backend selection logic
- [ ] Backend health checks

### Phase 3.3: Performance Optimizations (Week 6, Day 5)
- [ ] Request batching implementation
- [ ] Connection pooling (Guzzle config)
- [ ] Response streaming support
- [ ] Queue integration for async

### Phase 3.4: Metrics & Reporting (Week 7, Days 1-2)
- [ ] Metrics database schema
- [ ] Metrics collection service
- [ ] Cache reporting dashboard
- [ ] Alert system for low hit rate
- [ ] Cost savings calculator

### Phase 3.5: Cache Management (Week 7, Days 3-5)
- [ ] Manual invalidation UI
- [ ] Bulk cache clearing
- [ ] Cache warming scheduler task
- [ ] Storage monitoring
- [ ] Documentation & examples

---

## 9. Configuration Examples

### Extension Configuration

```php
# ext_conf_template.txt

# cat=cache; type=options[file,redis,database,memory]; label=Cache Backend
cache.backend = redis

# cat=cache/redis; type=string; label=Redis Hostname
cache.redis.hostname = 127.0.0.1

# cat=cache/redis; type=int+; label=Redis Port
cache.redis.port = 6379

# cat=cache/redis; type=int+; label=Redis Database Number
cache.redis.database = 5

# cat=cache/redis; type=boolean; label=Enable Compression
cache.redis.compression = 1

# cat=cache/ttl; type=int+; label=Translation Cache TTL (seconds)
cache.ttl.translation = 2592000

# cat=cache/ttl; type=int+; label=Image Alt Text Cache TTL (seconds)
cache.ttl.imageAlt = 7776000

# cat=cache/ttl; type=int+; label=SEO Meta Cache TTL (seconds)
cache.ttl.seoMeta = 604800

# cat=cache/ttl; type=int+; label=Content Suggestions Cache TTL (seconds)
cache.ttl.contentSuggestions = 86400

# cat=cache/warming; type=boolean; label=Enable Cache Warming
cache.warming.enabled = 1

# cat=cache/warming; type=string; label=Warming Schedule (cron syntax)
cache.warming.schedule = 0 2 * * *

# cat=performance; type=boolean; label=Enable Request Batching
performance.batching.enabled = 1

# cat=performance; type=int+; label=Max Batch Size
performance.batching.maxSize = 20

# cat=performance; type=boolean; label=Enable Response Streaming
performance.streaming.enabled = 1

# cat=performance; type=boolean; label=Enable Async Queue Processing
performance.async.enabled = 1
```

---

## 10. Testing Strategy

### Cache Key Tests

```php
// Test deterministic key generation
$key1 = $this->service->generateCacheKey('openai', 'gpt-4', 'Hello world', []);
$key2 = $this->service->generateCacheKey('openai', 'gpt-4', 'Hello world', []);
$this->assertEquals($key1, $key2);

// Test normalization
$key1 = $this->service->generateCacheKey('openai', 'gpt-4', "  Hello\nworld  ", []);
$key2 = $this->service->generateCacheKey('openai', 'gpt-4', "Hello\nworld", []);
$this->assertEquals($key1, $key2);

// Test collision resistance
$key1 = $this->service->generateCacheKey('openai', 'gpt-4', 'Hello', ['temp' => 0.7]);
$key2 = $this->service->generateCacheKey('openai', 'gpt-4', 'Hello', ['temp' => 0.8]);
$this->assertNotEquals($key1, $key2);
```

### Cache Hit/Miss Tests

```php
// Test cache miss → hit cycle
$this->assertFalse($this->cache->has($key));
$this->cache->set($key, $response, 3600);
$this->assertTrue($this->cache->has($key));
$this->assertEquals($response, $this->cache->get($key));

// Test TTL expiration
$this->cache->set($key, $response, 1);
sleep(2);
$this->assertFalse($this->cache->has($key));
```

### Performance Tests

```php
// Test cache overhead
$start = microtime(true);
$this->cache->get($key); // Cache hit
$duration = (microtime(true) - $start) * 1000;
$this->assertLessThan(10, $duration, 'Cache overhead >10ms');

// Test batching performance
$start = microtime(true);
$results = $this->provider->translateBatch($texts, 'de');
$duration = microtime(true) - $start;
$avgPerItem = $duration / count($texts);
$this->assertLessThan(0.5, $avgPerItem, 'Batch avg >500ms per item');
```

---

## 11. Monitoring & Alerts

### Key Performance Indicators

```yaml
cache_hit_rate:
  warning: <50%
  critical: <30%
  action: "Investigate cache key generation or TTL settings"

cache_storage_size:
  warning: >200MB
  critical: >500MB
  action: "Reduce TTL or implement LRU eviction"

avg_response_time:
  warning: >50ms
  critical: >100ms
  action: "Check cache backend performance"

cost_savings:
  warning: <$50/month
  critical: <$20/month
  action: "Cache not effective, review strategy"
```

### Alert Integration

```php
// Hook into cache metrics collection
if ($metrics['hit_rate'] < 0.4) {
    $this->notificationService->send([
        'level' => 'warning',
        'title' => 'Low AI Cache Hit Rate',
        'message' => sprintf(
            'Cache hit rate is %.1f%% (target: >60%%)',
            $metrics['hit_rate'] * 100
        ),
        'actions' => [
            'Review cache key generation',
            'Increase TTL for stable features',
            'Enable cache warming',
        ],
    ]);
}
```

---

## 12. Migration & Rollout

### Phase 1: Baseline (No Cache)
- Deploy without caching enabled
- Collect baseline metrics (cost, latency)
- Establish performance baseline

### Phase 2: File Cache (Development)
- Enable file-based cache in dev
- Validate cache key generation
- Test TTL strategies
- Monitor hit rates

### Phase 3: Redis Cache (Staging)
- Deploy Redis backend
- Benchmark performance improvement
- Load test with production-like traffic
- Validate cost savings

### Phase 4: Production Rollout
- Enable Redis cache in production
- Monitor metrics dashboard
- Gradual rollout (10% → 50% → 100%)
- Document lessons learned

---

## Document Summary

This caching and performance layer provides:

1. **Intelligent Caching**: Deterministic key generation with per-feature TTL
2. **Flexible Backends**: File, Redis, Database, Memory options
3. **Performance Optimizations**: Batching, pooling, streaming, async
4. **Comprehensive Metrics**: Hit rates, cost savings, storage monitoring
5. **Cache Management**: Warming, invalidation, reporting

**Expected Outcomes**:
- 70%+ reduction in API calls
- <10ms cache overhead
- $100+/month cost savings
- Sub-second response times for cached content

Next: Implement core CacheService class and integration tests.
