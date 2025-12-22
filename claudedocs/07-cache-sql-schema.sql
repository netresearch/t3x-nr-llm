-- ============================================================================
-- AI Base Extension - Cache Metrics Database Schema
-- ============================================================================
--
-- This file defines the database schema for cache performance metrics tracking.
-- Tables track hit rates, storage size, cost savings, and cache efficiency.
--
-- @package Netresearch\AiBase
-- @version 1.0.0
-- ============================================================================

-- ============================================================================
-- Cache Metrics Table
-- ============================================================================
--
-- Stores aggregated cache performance metrics per feature and provider.
-- Metrics are typically aggregated per day but can be customized.
--
-- Key Metrics:
-- - cache_hits: Number of successful cache retrievals
-- - cache_misses: Number of cache misses requiring API calls
-- - cache_writes: Number of new cache entries created
-- - storage_size_bytes: Total storage used by cached responses
-- - cost_saved_usd: Estimated cost savings from cache hits
--
CREATE TABLE tx_aibase_cache_metrics (
    uid INT(11) NOT NULL AUTO_INCREMENT,
    pid INT(11) DEFAULT 0 NOT NULL,

    -- Feature and provider identification
    feature VARCHAR(100) DEFAULT '' NOT NULL,
    provider VARCHAR(50) DEFAULT '' NOT NULL,

    -- Cache performance counters
    cache_hits INT(11) DEFAULT 0 NOT NULL,
    cache_misses INT(11) DEFAULT 0 NOT NULL,
    cache_writes INT(11) DEFAULT 0 NOT NULL,

    -- Request totals
    total_requests INT(11) DEFAULT 0 NOT NULL,
    cached_requests INT(11) DEFAULT 0 NOT NULL,

    -- Storage metrics
    storage_size_bytes BIGINT DEFAULT 0 NOT NULL,
    avg_ttl_seconds INT(11) DEFAULT 0 NOT NULL,

    -- Cost tracking
    cost_saved_usd DECIMAL(10,6) DEFAULT 0.000000 NOT NULL,
    api_calls_saved INT(11) DEFAULT 0 NOT NULL,

    -- Time period for this metric (typically daily aggregation)
    period_start INT(11) DEFAULT 0 NOT NULL,
    period_end INT(11) DEFAULT 0 NOT NULL,

    -- TYPO3 standard fields
    tstamp INT(11) DEFAULT 0 NOT NULL,
    crdate INT(11) DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY feature_provider (feature, provider),
    KEY period (period_start, period_end),
    KEY feature_period (feature, period_start),
    KEY provider_period (provider, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Cache Invalidation Log
-- ============================================================================
--
-- Tracks manual and automatic cache invalidation events.
-- Useful for debugging cache issues and auditing cache management.
--
CREATE TABLE tx_aibase_cache_invalidations (
    uid INT(11) NOT NULL AUTO_INCREMENT,

    -- Invalidation details
    invalidation_type VARCHAR(50) DEFAULT '' NOT NULL, -- manual, ttl, event, flush
    scope VARCHAR(50) DEFAULT '' NOT NULL, -- all, feature, provider, specific_key
    scope_value VARCHAR(255) DEFAULT '' NOT NULL, -- feature name, provider name, or cache key

    -- Invalidation impact
    entries_removed INT(11) DEFAULT 0 NOT NULL,
    storage_freed_bytes BIGINT DEFAULT 0 NOT NULL,

    -- User and reason
    backend_user_id INT(11) DEFAULT 0 NOT NULL,
    reason TEXT,

    -- Timestamp
    tstamp INT(11) DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY invalidation_type (invalidation_type),
    KEY scope (scope),
    KEY tstamp (tstamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Cache Warming Jobs
-- ============================================================================
--
-- Queue table for cache warming tasks.
-- Stores pending jobs for pre-generating cache entries.
--
CREATE TABLE tx_aibase_cache_warming_jobs (
    uid INT(11) NOT NULL AUTO_INCREMENT,

    -- Job identification
    job_type VARCHAR(50) DEFAULT '' NOT NULL, -- image_alt, translation, seo_meta
    record_uid INT(11) DEFAULT 0 NOT NULL,
    table_name VARCHAR(255) DEFAULT '' NOT NULL,

    -- Job parameters (JSON)
    parameters TEXT,

    -- Job status
    status VARCHAR(20) DEFAULT 'pending' NOT NULL, -- pending, processing, completed, failed
    priority TINYINT(4) DEFAULT 5 NOT NULL, -- 1=highest, 10=lowest

    -- Execution details
    attempts INT(11) DEFAULT 0 NOT NULL,
    max_attempts INT(11) DEFAULT 3 NOT NULL,
    last_error TEXT,

    -- Timestamps
    scheduled_at INT(11) DEFAULT 0 NOT NULL,
    started_at INT(11) DEFAULT 0 NOT NULL,
    completed_at INT(11) DEFAULT 0 NOT NULL,

    tstamp INT(11) DEFAULT 0 NOT NULL,
    crdate INT(11) DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY status (status),
    KEY priority (priority),
    KEY scheduled_at (scheduled_at),
    KEY job_type (job_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Cache Performance Alerts
-- ============================================================================
--
-- Stores alerts for cache performance issues.
-- Triggers when metrics fall below thresholds.
--
CREATE TABLE tx_aibase_cache_alerts (
    uid INT(11) NOT NULL AUTO_INCREMENT,

    -- Alert details
    alert_type VARCHAR(50) DEFAULT '' NOT NULL, -- low_hit_rate, high_storage, high_miss_rate
    severity VARCHAR(20) DEFAULT 'warning' NOT NULL, -- info, warning, critical

    -- Context
    feature VARCHAR(100) DEFAULT '' NOT NULL,
    provider VARCHAR(50) DEFAULT '' NOT NULL,

    -- Metrics that triggered alert
    metric_name VARCHAR(100) DEFAULT '' NOT NULL,
    metric_value DECIMAL(12,4) DEFAULT 0.0000 NOT NULL,
    threshold_value DECIMAL(12,4) DEFAULT 0.0000 NOT NULL,

    -- Alert message
    message TEXT,

    -- Notification status
    notified TINYINT(1) DEFAULT 0 NOT NULL,
    notified_at INT(11) DEFAULT 0 NOT NULL,

    -- Resolution
    resolved TINYINT(1) DEFAULT 0 NOT NULL,
    resolved_at INT(11) DEFAULT 0 NOT NULL,
    resolved_by INT(11) DEFAULT 0 NOT NULL,
    resolution_notes TEXT,

    -- Timestamps
    tstamp INT(11) DEFAULT 0 NOT NULL,
    crdate INT(11) DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY alert_type (alert_type),
    KEY severity (severity),
    KEY notified (notified),
    KEY resolved (resolved),
    KEY feature_provider (feature, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Sample Queries for Reporting
-- ============================================================================

-- 1. Daily Cache Hit Rate Report
-- Returns hit rate per feature for the last 7 days
/*
SELECT
    DATE(FROM_UNIXTIME(period_start)) as date,
    feature,
    SUM(cache_hits) as total_hits,
    SUM(cache_misses) as total_misses,
    SUM(cache_hits + cache_misses) as total_requests,
    ROUND(SUM(cache_hits) / NULLIF(SUM(cache_hits + cache_misses), 0) * 100, 2) as hit_rate_percent
FROM tx_aibase_cache_metrics
WHERE period_start >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY))
GROUP BY DATE(FROM_UNIXTIME(period_start)), feature
ORDER BY date DESC, feature;
*/

-- 2. Provider Performance Comparison
-- Compare cache performance across providers
/*
SELECT
    provider,
    SUM(cache_hits) as total_hits,
    SUM(cache_misses) as total_misses,
    ROUND(SUM(cache_hits) / NULLIF(SUM(cache_hits + cache_misses), 0) * 100, 2) as hit_rate_percent,
    ROUND(SUM(storage_size_bytes) / 1024 / 1024, 2) as storage_mb,
    ROUND(SUM(cost_saved_usd), 2) as total_saved_usd
FROM tx_aibase_cache_metrics
WHERE period_start >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
GROUP BY provider
ORDER BY total_saved_usd DESC;
*/

-- 3. Storage Usage by Feature
-- Identify features consuming most cache storage
/*
SELECT
    feature,
    ROUND(SUM(storage_size_bytes) / 1024 / 1024, 2) as storage_mb,
    COUNT(*) as metric_records,
    AVG(avg_ttl_seconds / 86400) as avg_ttl_days
FROM tx_aibase_cache_metrics
WHERE period_start >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
GROUP BY feature
ORDER BY storage_mb DESC;
*/

-- 4. Cost Savings Report
-- Calculate total cost savings from caching
/*
SELECT
    DATE_FORMAT(FROM_UNIXTIME(period_start), '%Y-%m') as month,
    SUM(cost_saved_usd) as monthly_savings,
    SUM(api_calls_saved) as api_calls_saved,
    SUM(cache_hits) as total_cache_hits
FROM tx_aibase_cache_metrics
GROUP BY DATE_FORMAT(FROM_UNIXTIME(period_start), '%Y-%m')
ORDER BY month DESC
LIMIT 12;
*/

-- 5. Cache Efficiency Trend
-- Track cache hit rate over time
/*
SELECT
    DATE(FROM_UNIXTIME(period_start)) as date,
    ROUND(SUM(cache_hits) / NULLIF(SUM(cache_hits + cache_misses), 0) * 100, 2) as hit_rate_percent,
    SUM(cache_hits) as hits,
    SUM(cache_misses) as misses
FROM tx_aibase_cache_metrics
WHERE period_start >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 90 DAY))
GROUP BY DATE(FROM_UNIXTIME(period_start))
ORDER BY date ASC;
*/

-- ============================================================================
-- Maintenance Queries
-- ============================================================================

-- Archive old metrics (>1 year)
/*
CREATE TABLE tx_aibase_cache_metrics_archive LIKE tx_aibase_cache_metrics;

INSERT INTO tx_aibase_cache_metrics_archive
SELECT * FROM tx_aibase_cache_metrics
WHERE period_start < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 YEAR));

DELETE FROM tx_aibase_cache_metrics
WHERE period_start < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 YEAR));
*/

-- Clean up completed warming jobs (>30 days old)
/*
DELETE FROM tx_aibase_cache_warming_jobs
WHERE status = 'completed'
AND completed_at < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY));
*/

-- Clean up resolved alerts (>90 days old)
/*
DELETE FROM tx_aibase_cache_alerts
WHERE resolved = 1
AND resolved_at < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 90 DAY));
*/

-- ============================================================================
-- Index Optimization
-- ============================================================================

-- Add composite index for common query patterns
-- ALTER TABLE tx_aibase_cache_metrics ADD INDEX feature_provider_period (feature, provider, period_start);

-- Add covering index for hit rate calculations
-- ALTER TABLE tx_aibase_cache_metrics ADD INDEX hit_rate_index (period_start, feature, provider, cache_hits, cache_misses);

-- ============================================================================
-- Table Partitioning (for large installations)
-- ============================================================================

-- Partition by month for better query performance on large datasets
/*
ALTER TABLE tx_aibase_cache_metrics
PARTITION BY RANGE (period_start) (
    PARTITION p202401 VALUES LESS THAN (UNIX_TIMESTAMP('2024-02-01')),
    PARTITION p202402 VALUES LESS THAN (UNIX_TIMESTAMP('2024-03-01')),
    PARTITION p202403 VALUES LESS THAN (UNIX_TIMESTAMP('2024-04-01')),
    -- Add more partitions as needed
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
*/

-- ============================================================================
-- End of Schema
-- ============================================================================
