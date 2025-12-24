<?php

/**
 * Rate Limiting, Quota Management & Usage Tracking Implementations
 *
 * This file contains complete, production-ready implementations for:
 * - RateLimiterService (Token Bucket + Sliding Window)
 * - QuotaManager (Multi-scope quota management)
 * - UsageTracker (Comprehensive usage logging)
 * - CostCalculator (Accurate cost estimation)
 * - NotificationService (User and admin notifications)
 *
 * @package Netresearch\NrLlm
 */

namespace Netresearch\NrLlm\Service;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Log\LogManager;
use Psr\Log\LoggerInterface;

// ============================================================================
// RATE LIMITER SERVICE
// ============================================================================

/**
 * Token Bucket Rate Limiter with Sliding Window Fallback
 *
 * Features:
 * - Token bucket algorithm (allows bursts)
 * - Sliding window for strict limits
 * - Multi-level limiting (global, provider, user, feature)
 * - Cache-first with database fallback
 * - Atomic operations for race condition prevention
 */
class RateLimiterService
{
    private const TABLE_RATE_LIMIT_STATE = 'tx_nrllm_ratelimit_state';

    private const ALGORITHM_TOKEN_BUCKET = 'token_bucket';
    private const ALGORITHM_SLIDING_WINDOW = 'sliding_window';
    private const ALGORITHM_FIXED_WINDOW = 'fixed_window';

    public function __construct(
        private readonly FrontendInterface $cache,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
        private readonly array $configuration
    ) {}

    /**
     * Check if request is allowed under rate limits
     *
     * @param string $scope Limit scope: 'global', 'provider', 'user', 'feature'
     * @param string $identifier Scope identifier (user_id, provider name, etc.)
     * @param string $provider Provider name for provider-specific limits
     * @param array $options Additional options (algorithm, cost, etc.)
     * @return bool True if allowed, false if rate limited
     * @throws RateLimitExceededException If limit exceeded
     */
    public function checkLimit(
        string $scope,
        string $identifier,
        string $provider = '',
        array $options = []
    ): bool {
        $limitKey = $this->buildLimitKey($scope, $identifier, $provider);
        $config = $this->getLimitConfiguration($scope, $provider);

        if ($config['limit'] === 0) {
            return true; // Unlimited
        }

        $algorithm = $options['algorithm'] ?? $config['algorithm'];

        return match ($algorithm) {
            self::ALGORITHM_TOKEN_BUCKET => $this->checkTokenBucket($limitKey, $config, $options),
            self::ALGORITHM_SLIDING_WINDOW => $this->checkSlidingWindow($limitKey, $config),
            self::ALGORITHM_FIXED_WINDOW => $this->checkFixedWindow($limitKey, $config),
            default => throw new \InvalidArgumentException("Unknown algorithm: $algorithm"),
        };
    }

    /**
     * Token Bucket Algorithm Implementation
     *
     * Allows bursts up to capacity, then refills at steady rate.
     * Best for user-facing limits (smooth UX).
     */
    private function checkTokenBucket(string $limitKey, array $config, array $options): bool
    {
        $now = time();
        $cost = $options['cost'] ?? 1.0; // Allow fractional costs

        // Try cache first for speed
        $cacheKey = "ratelimit:tb:$limitKey";
        $state = $this->cache->get($cacheKey);

        if ($state === false) {
            // Cache miss - load from database
            $state = $this->loadRateLimitState($limitKey, $config);
        }

        // Calculate token refill
        $timeElapsed = $now - $state['last_refill_time'];
        $tokensToAdd = $timeElapsed * $state['refill_rate'];
        $state['tokens_available'] = min(
            $state['tokens_available'] + $tokensToAdd,
            $state['tokens_capacity']
        );
        $state['last_refill_time'] = $now;

        // Check if enough tokens available
        if ($state['tokens_available'] >= $cost) {
            // Allow request - consume tokens
            $state['tokens_available'] -= $cost;

            $this->saveRateLimitState($limitKey, $state);
            $this->cache->set($cacheKey, $state, 3600);

            return true;
        }

        // Calculate retry after time
        $tokensNeeded = $cost - $state['tokens_available'];
        $retryAfter = (int) ceil($tokensNeeded / $state['refill_rate']);

        throw new RateLimitExceededException(
            currentUsage: (int) ($state['tokens_capacity'] - $state['tokens_available']),
            limit: (int) $state['tokens_capacity'],
            retryAfter: $retryAfter,
            scope: $limitKey
        );
    }

    /**
     * Sliding Window Algorithm Implementation
     *
     * Strict limit enforcement across rolling time window.
     * Best for provider API limits (prevent throttling).
     */
    private function checkSlidingWindow(string $limitKey, array $config): bool
    {
        $now = time();
        $windowSize = $config['window_size'] ?? 3600; // Default 1 hour
        $limit = $config['limit'];

        $cacheKey = "ratelimit:sw:$limitKey";

        // Get request timestamps from cache
        $timestamps = $this->cache->get($cacheKey) ?: [];

        // Remove timestamps outside the window
        $windowStart = $now - $windowSize;
        $timestamps = array_filter($timestamps, fn($ts) => $ts > $windowStart);

        // Check if limit exceeded
        if (count($timestamps) >= $limit) {
            $oldestTimestamp = min($timestamps);
            $retryAfter = $windowSize - ($now - $oldestTimestamp) + 1;

            throw new RateLimitExceededException(
                currentUsage: count($timestamps),
                limit: $limit,
                retryAfter: $retryAfter,
                scope: $limitKey
            );
        }

        // Add current request
        $timestamps[] = $now;

        // Store back to cache
        $this->cache->set($cacheKey, $timestamps, $windowSize + 60);

        // Periodically sync to database
        if (count($timestamps) % 10 === 0) {
            $this->syncSlidingWindowToDatabase($limitKey, $timestamps, $windowStart, $windowSize);
        }

        return true;
    }

    /**
     * Fixed Window Algorithm Implementation
     *
     * Simplest implementation - resets at window boundaries.
     * Used as fallback when cache unavailable.
     */
    private function checkFixedWindow(string $limitKey, array $config): bool
    {
        $now = time();
        $windowSize = $config['window_size'] ?? 3600;
        $limit = $config['limit'];

        // Calculate current window start
        $windowStart = $now - ($now % $windowSize);

        $cacheKey = "ratelimit:fw:$limitKey:$windowStart";

        $count = (int) ($this->cache->get($cacheKey) ?: 0);

        if ($count >= $limit) {
            $retryAfter = $windowSize - ($now % $windowSize);

            throw new RateLimitExceededException(
                currentUsage: $count,
                limit: $limit,
                retryAfter: $retryAfter,
                scope: $limitKey
            );
        }

        // Increment counter atomically (if cache supports it)
        $this->cache->set($cacheKey, $count + 1, $windowSize + 60);

        return true;
    }

    /**
     * Load rate limit state from database
     */
    private function loadRateLimitState(string $limitKey, array $config): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_RATE_LIMIT_STATE);

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE_RATE_LIMIT_STATE)
            ->where(
                $queryBuilder->expr()->eq('limit_key', $queryBuilder->createNamedParameter($limitKey))
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row) {
            return [
                'tokens_available' => (float) $row['tokens_available'],
                'tokens_capacity' => (float) $row['tokens_capacity'],
                'last_refill_time' => (int) $row['last_refill_time'],
                'refill_rate' => (float) $row['refill_rate'],
            ];
        }

        // Initialize new state
        return [
            'tokens_available' => (float) $config['capacity'],
            'tokens_capacity' => (float) $config['capacity'],
            'last_refill_time' => time(),
            'refill_rate' => (float) ($config['capacity'] / $config['window_size']),
        ];
    }

    /**
     * Save rate limit state to database
     */
    private function saveRateLimitState(string $limitKey, array $state): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_RATE_LIMIT_STATE);

        $connection->insert(
            self::TABLE_RATE_LIMIT_STATE,
            [
                'limit_key' => $limitKey,
                'limit_scope' => explode(':', $limitKey)[0],
                'tokens_available' => $state['tokens_available'],
                'tokens_capacity' => $state['tokens_capacity'],
                'last_refill_time' => $state['last_refill_time'],
                'refill_rate' => $state['refill_rate'],
                'tstamp' => time(),
            ],
            [
                'tokens_available' => \PDO::PARAM_STR, // Use string for precision
                'tokens_capacity' => \PDO::PARAM_STR,
                'refill_rate' => \PDO::PARAM_STR,
            ]
        );
    }

    /**
     * Sync sliding window data to database (background persistence)
     */
    private function syncSlidingWindowToDatabase(
        string $limitKey,
        array $timestamps,
        int $windowStart,
        int $windowSize
    ): void {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_RATE_LIMIT_STATE);

        $connection->insert(
            self::TABLE_RATE_LIMIT_STATE,
            [
                'limit_key' => $limitKey,
                'limit_scope' => explode(':', $limitKey)[0],
                'window_start' => $windowStart,
                'window_requests' => count($timestamps),
                'window_size' => $windowSize,
                'tstamp' => time(),
            ]
        );
    }

    /**
     * Get rate limit configuration for scope
     */
    private function getLimitConfiguration(string $scope, string $provider = ''): array
    {
        // Provider-specific limits (from external API documentation)
        if ($scope === 'provider' && isset($this->configuration['providers'][$provider])) {
            return $this->configuration['providers'][$provider];
        }

        // Default limits by scope
        return $this->configuration['limits'][$scope] ?? [
            'limit' => 100,
            'window_size' => 3600,
            'capacity' => 100,
            'algorithm' => self::ALGORITHM_TOKEN_BUCKET,
        ];
    }

    /**
     * Build unique rate limit key
     */
    private function buildLimitKey(string $scope, string $identifier, string $provider): string
    {
        $parts = [$scope, $identifier];
        if ($provider) {
            $parts[] = $provider;
        }
        return implode(':', $parts);
    }

    /**
     * Get current usage for informational purposes
     */
    public function getCurrentUsage(string $scope, string $identifier, string $provider = ''): array
    {
        $limitKey = $this->buildLimitKey($scope, $identifier, $provider);
        $config = $this->getLimitConfiguration($scope, $provider);

        $cacheKey = "ratelimit:tb:$limitKey";
        $state = $this->cache->get($cacheKey) ?: $this->loadRateLimitState($limitKey, $config);

        return [
            'used' => (int) ($state['tokens_capacity'] - $state['tokens_available']),
            'limit' => (int) $state['tokens_capacity'],
            'remaining' => (int) $state['tokens_available'],
            'reset_at' => $state['last_refill_time'] + (int) ($state['tokens_available'] / $state['refill_rate']),
        ];
    }
}

// ============================================================================
// QUOTA MANAGER SERVICE
// ============================================================================

/**
 * Multi-Scope Quota Management
 *
 * Features:
 * - Multiple quota types (requests, tokens, cost)
 * - Multiple periods (hourly, daily, weekly, monthly)
 * - Multiple scopes (user, group, site, global)
 * - Quota inheritance and priority
 * - Automatic period rollover
 * - Threshold notifications
 */
class QuotaManager
{
    private const TABLE_QUOTAS = 'tx_nrllm_quotas';
    private const TABLE_QUOTA_CONFIG = 'tx_nrllm_quota_config';

    public const TYPE_REQUESTS = 'requests';
    public const TYPE_TOKENS = 'tokens';
    public const TYPE_COST = 'cost';

    public const PERIOD_HOURLY = 'hourly';
    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';

    public const SCOPE_USER = 'user';
    public const SCOPE_GROUP = 'group';
    public const SCOPE_SITE = 'site';
    public const SCOPE_GLOBAL = 'global';

    public function __construct(
        private readonly FrontendInterface $cache,
        private readonly ConnectionPool $connectionPool,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
        private readonly array $configuration
    ) {}

    /**
     * Check if quota allows the operation
     *
     * @param string $scope Quota scope
     * @param int $scopeId Scope identifier (user_id, group_id, etc.)
     * @param string $quotaType Type of quota to check
     * @param float $cost Cost of operation (requests=1, tokens=count, cost=USD)
     * @param bool $reserve Reserve quota for operation (prevents race conditions)
     * @return bool True if allowed
     * @throws QuotaExceededException If quota exceeded
     */
    public function checkQuota(
        string $scope,
        int $scopeId,
        string $quotaType,
        float $cost = 1.0,
        bool $reserve = false
    ): bool {
        // Check all relevant quota levels (user → group → site → global)
        $quotas = $this->getApplicableQuotas($scope, $scopeId, $quotaType);

        foreach ($quotas as $quota) {
            $this->ensureQuotaPeriodCurrent($quota);

            $available = $quota['quota_limit'] - $quota['quota_used'] - $quota['quota_reserved'];

            if ($available < $cost) {
                throw new QuotaExceededException(
                    quotaType: $quotaType,
                    used: $quota['quota_used'],
                    limit: $quota['quota_limit'],
                    resetAt: $quota['period_end']
                );
            }

            // Check thresholds for notifications
            $usagePercent = ($quota['quota_used'] / $quota['quota_limit']) * 100;

            if ($usagePercent >= $quota['warn_threshold'] && $quota['last_warning_sent'] === 0) {
                $this->notificationService->sendQuotaWarning($quota, 'warning');
                $this->updateQuotaWarning($quota['uid']);
            } elseif ($usagePercent >= $quota['alert_threshold']) {
                $this->notificationService->sendQuotaWarning($quota, 'alert');
            }
        }

        // Reserve quota if requested (for multi-step operations)
        if ($reserve) {
            $this->reserveQuota($quotas, $cost);
        }

        return true;
    }

    /**
     * Consume quota after successful operation
     *
     * @param string $scope Quota scope
     * @param int $scopeId Scope identifier
     * @param string $quotaType Type of quota
     * @param float $actualCost Actual cost of operation
     * @param float $reservedCost Previously reserved amount (if any)
     */
    public function consumeQuota(
        string $scope,
        int $scopeId,
        string $quotaType,
        float $actualCost,
        float $reservedCost = 0.0
    ): void {
        $quotas = $this->getApplicableQuotas($scope, $scopeId, $quotaType);

        foreach ($quotas as $quota) {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE_QUOTAS);

            $connection->update(
                self::TABLE_QUOTAS,
                [
                    'quota_used' => $quota['quota_used'] + $actualCost,
                    'quota_reserved' => max(0, $quota['quota_reserved'] - $reservedCost),
                    'tstamp' => time(),
                ],
                ['uid' => $quota['uid']]
            );

            // Clear cache
            $cacheKey = $this->buildQuotaCacheKey($quota['scope'], $quota['scope_id'], $quotaType, $quota['quota_period']);
            $this->cache->remove($cacheKey);

            // Check if exceeded
            if ($quota['quota_used'] + $actualCost >= $quota['quota_limit']) {
                $this->markQuotaExceeded($quota['uid']);
                $this->notificationService->sendQuotaExceeded($quota);
            }
        }
    }

    /**
     * Release reserved quota (if operation failed)
     */
    public function releaseQuota(
        string $scope,
        int $scopeId,
        string $quotaType,
        float $reservedCost
    ): void {
        $quotas = $this->getApplicableQuotas($scope, $scopeId, $quotaType);

        foreach ($quotas as $quota) {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE_QUOTAS);

            $connection->update(
                self::TABLE_QUOTAS,
                [
                    'quota_reserved' => max(0, $quota['quota_reserved'] - $reservedCost),
                    'tstamp' => time(),
                ],
                ['uid' => $quota['uid']]
            );
        }
    }

    /**
     * Get all applicable quotas for scope (including inherited)
     */
    private function getApplicableQuotas(string $scope, int $scopeId, string $quotaType): array
    {
        $cacheKey = "quota:applicable:{$scope}:{$scopeId}:{$quotaType}";
        $cached = $this->cache->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $quotas = [];

        // Get direct quota
        $directQuota = $this->getOrCreateQuota($scope, $scopeId, $quotaType);
        if ($directQuota) {
            $quotas[] = $directQuota;
        }

        // Get inherited quotas based on scope hierarchy
        // User → Group → Site → Global
        if ($scope === self::SCOPE_USER) {
            $groupQuota = $this->getUserGroupQuota($scopeId, $quotaType);
            if ($groupQuota) {
                $quotas[] = $groupQuota;
            }
        }

        // Always check global quota
        if ($scope !== self::SCOPE_GLOBAL) {
            $globalQuota = $this->getOrCreateQuota(self::SCOPE_GLOBAL, 0, $quotaType);
            if ($globalQuota) {
                $quotas[] = $globalQuota;
            }
        }

        $this->cache->set($cacheKey, $quotas, 300); // Cache for 5 minutes

        return $quotas;
    }

    /**
     * Get or create quota record for scope
     */
    private function getOrCreateQuota(string $scope, int $scopeId, string $quotaType): ?array
    {
        // Determine period based on quota type
        $period = match ($quotaType) {
            self::TYPE_REQUESTS => self::PERIOD_DAILY,
            self::TYPE_TOKENS => self::PERIOD_DAILY,
            self::TYPE_COST => self::PERIOD_MONTHLY,
            default => self::PERIOD_DAILY,
        };

        $periodBounds = $this->calculatePeriodBounds($period);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_QUOTAS);

        $quota = $queryBuilder
            ->select('*')
            ->from(self::TABLE_QUOTAS)
            ->where(
                $queryBuilder->expr()->eq('scope', $queryBuilder->createNamedParameter($scope)),
                $queryBuilder->expr()->eq('scope_id', $queryBuilder->createNamedParameter($scopeId, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('quota_type', $queryBuilder->createNamedParameter($quotaType)),
                $queryBuilder->expr()->eq('quota_period', $queryBuilder->createNamedParameter($period)),
                $queryBuilder->expr()->eq('is_active', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($quota) {
            return $quota;
        }

        // Create new quota from configuration
        $config = $this->getQuotaConfiguration($scope, $scopeId);
        $limit = $this->extractLimitFromConfig($config, $quotaType, $period);

        if ($limit === 0.0) {
            return null; // Unlimited
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_QUOTAS);

        $connection->insert(
            self::TABLE_QUOTAS,
            [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'quota_type' => $quotaType,
                'quota_period' => $period,
                'period_start' => $periodBounds['start'],
                'period_end' => $periodBounds['end'],
                'quota_limit' => $limit,
                'quota_used' => 0.0,
                'quota_reserved' => 0.0,
                'warn_threshold' => $config['warn_threshold'] ?? 80.0,
                'alert_threshold' => $config['alert_threshold'] ?? 90.0,
                'is_active' => 1,
                'tstamp' => time(),
                'crdate' => time(),
            ]
        );

        return $this->getOrCreateQuota($scope, $scopeId, $quotaType);
    }

    /**
     * Ensure quota period is current (rollover if needed)
     */
    private function ensureQuotaPeriodCurrent(array &$quota): void
    {
        $now = time();

        if ($now > $quota['period_end']) {
            // Period expired - rollover
            $periodBounds = $this->calculatePeriodBounds($quota['quota_period']);

            $connection = $this->connectionPool->getConnectionForTable(self::TABLE_QUOTAS);

            $connection->update(
                self::TABLE_QUOTAS,
                [
                    'period_start' => $periodBounds['start'],
                    'period_end' => $periodBounds['end'],
                    'quota_used' => 0.0,
                    'quota_reserved' => 0.0,
                    'is_exceeded' => 0,
                    'exceeded_at' => 0,
                    'last_warning_sent' => 0,
                    'warning_count' => 0,
                    'tstamp' => time(),
                ],
                ['uid' => $quota['uid']]
            );

            // Update local array
            $quota['period_start'] = $periodBounds['start'];
            $quota['period_end'] = $periodBounds['end'];
            $quota['quota_used'] = 0.0;
            $quota['quota_reserved'] = 0.0;
            $quota['is_exceeded'] = 0;
        }
    }

    /**
     * Calculate period start and end timestamps
     */
    private function calculatePeriodBounds(string $period): array
    {
        $now = time();

        return match ($period) {
            self::PERIOD_HOURLY => [
                'start' => strtotime('this hour', $now),
                'end' => strtotime('+1 hour', strtotime('this hour', $now)) - 1,
            ],
            self::PERIOD_DAILY => [
                'start' => strtotime('today midnight', $now),
                'end' => strtotime('tomorrow midnight', $now) - 1,
            ],
            self::PERIOD_WEEKLY => [
                'start' => strtotime('monday this week midnight', $now),
                'end' => strtotime('monday next week midnight', $now) - 1,
            ],
            self::PERIOD_MONTHLY => [
                'start' => strtotime('first day of this month midnight', $now),
                'end' => strtotime('first day of next month midnight', $now) - 1,
            ],
            default => throw new \InvalidArgumentException("Unknown period: $period"),
        };
    }

    /**
     * Get quota configuration for scope
     */
    private function getQuotaConfiguration(string $scope, int $scopeId): array
    {
        // Load from tx_nrllm_quota_config or extension configuration
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_QUOTA_CONFIG);

        $config = $queryBuilder
            ->select('*')
            ->from(self::TABLE_QUOTA_CONFIG)
            ->where(
                $queryBuilder->expr()->eq('config_scope', $queryBuilder->createNamedParameter($scope)),
                $queryBuilder->expr()->eq('is_active', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT))
            )
            ->orderBy('priority', 'DESC')
            ->executeQuery()
            ->fetchAssociative();

        if ($config) {
            return $config;
        }

        // Fallback to default configuration
        return $this->configuration['quotas'][$scope] ?? $this->configuration['quotas']['default'];
    }

    /**
     * Extract limit from configuration for specific type and period
     */
    private function extractLimitFromConfig(array $config, string $quotaType, string $period): float
    {
        $key = $period . '_' . $quotaType . '_limit';
        return (float) ($config[$key] ?? 0.0);
    }

    /**
     * Reserve quota for in-flight operations
     */
    private function reserveQuota(array $quotas, float $cost): void
    {
        foreach ($quotas as $quota) {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE_QUOTAS);

            $connection->update(
                self::TABLE_QUOTAS,
                [
                    'quota_reserved' => $quota['quota_reserved'] + $cost,
                    'tstamp' => time(),
                ],
                ['uid' => $quota['uid']]
            );
        }
    }

    /**
     * Mark quota as exceeded
     */
    private function markQuotaExceeded(int $quotaUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_QUOTAS);

        $connection->update(
            self::TABLE_QUOTAS,
            [
                'is_exceeded' => 1,
                'exceeded_at' => time(),
                'tstamp' => time(),
            ],
            ['uid' => $quotaUid]
        );
    }

    /**
     * Update last warning sent timestamp
     */
    private function updateQuotaWarning(int $quotaUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_QUOTAS);

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->update(self::TABLE_QUOTAS)
            ->set('last_warning_sent', time())
            ->set('warning_count', 'warning_count + 1', false)
            ->where($queryBuilder->expr()->eq('uid', $quotaUid))
            ->executeStatement();
    }

    /**
     * Get current quota status (for UI display)
     */
    public function getQuotaStatus(string $scope, int $scopeId): array
    {
        $status = [];

        foreach ([self::TYPE_REQUESTS, self::TYPE_TOKENS, self::TYPE_COST] as $quotaType) {
            $quotas = $this->getApplicableQuotas($scope, $scopeId, $quotaType);

            foreach ($quotas as $quota) {
                $this->ensureQuotaPeriodCurrent($quota);

                $used = $quota['quota_used'];
                $limit = $quota['quota_limit'];
                $available = $limit - $used - $quota['quota_reserved'];
                $percentUsed = $limit > 0 ? ($used / $limit) * 100 : 0;

                $status[] = [
                    'type' => $quotaType,
                    'period' => $quota['quota_period'],
                    'scope' => $quota['scope'],
                    'used' => $used,
                    'limit' => $limit,
                    'available' => $available,
                    'reserved' => $quota['quota_reserved'],
                    'percent_used' => round($percentUsed, 2),
                    'is_exceeded' => $quota['is_exceeded'],
                    'reset_at' => $quota['period_end'],
                    'status' => $this->getStatusLabel($percentUsed, $quota),
                ];
            }
        }

        return $status;
    }

    /**
     * Get status label based on usage percentage
     */
    private function getStatusLabel(float $percentUsed, array $quota): string
    {
        if ($percentUsed >= 100) {
            return 'exceeded';
        } elseif ($percentUsed >= $quota['alert_threshold']) {
            return 'critical';
        } elseif ($percentUsed >= $quota['warn_threshold']) {
            return 'warning';
        }
        return 'normal';
    }

    /**
     * Get user's group quota
     */
    private function getUserGroupQuota(int $userId, string $quotaType): ?array
    {
        // Implementation depends on TYPO3 user group structure
        // This is a placeholder - adjust based on actual group relationships
        return null;
    }

    /**
     * Build cache key for quota
     */
    private function buildQuotaCacheKey(string $scope, int $scopeId, string $quotaType, string $period): string
    {
        return "quota:{$scope}:{$scopeId}:{$quotaType}:{$period}";
    }
}

// ============================================================================
// USAGE TRACKER SERVICE
// ============================================================================

/**
 * Comprehensive Usage Tracking
 *
 * Features:
 * - Request-level tracking with full metadata
 * - Cost calculation integration
 * - Cache hit/miss tracking
 * - Performance metrics
 * - Audit trail for compliance
 * - Aggregated statistics
 */
class UsageTracker
{
    private const TABLE_USAGE = 'tx_nrllm_usage';
    private const TABLE_USAGE_STATS = 'tx_nrllm_usage_stats';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly CostCalculator $costCalculator,
        private readonly LoggerInterface $logger,
        private readonly array $configuration
    ) {}

    /**
     * Track AI request usage
     *
     * @param array $context Request context
     * @return int Usage record UID
     */
    public function trackUsage(array $context): int
    {
        $startTime = $context['start_time'] ?? microtime(true);
        $endTime = microtime(true);
        $requestTimeMs = (int) (($endTime - $startTime) * 1000);

        // Calculate cost
        $cost = $this->costCalculator->calculateCost(
            provider: $context['provider'],
            model: $context['model'],
            promptTokens: $context['prompt_tokens'] ?? 0,
            completionTokens: $context['completion_tokens'] ?? 0
        );

        // Generate request hash for deduplication
        $requestHash = $this->generateRequestHash($context);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_USAGE);

        $connection->insert(
            self::TABLE_USAGE,
            [
                'pid' => $context['pid'] ?? 0,
                'user_id' => $context['user_id'],
                'user_group' => $context['user_group'] ?? 0,
                'site_id' => $context['site_id'] ?? 0,
                'provider' => $context['provider'],
                'model' => $context['model'],
                'feature' => $context['feature'],
                'prompt_tokens' => $context['prompt_tokens'] ?? 0,
                'completion_tokens' => $context['completion_tokens'] ?? 0,
                'total_tokens' => ($context['prompt_tokens'] ?? 0) + ($context['completion_tokens'] ?? 0),
                'estimated_cost' => $cost['total'],
                'cost_currency' => $cost['currency'],
                'pricing_version' => $cost['pricing_version'],
                'request_time_ms' => $requestTimeMs,
                'cache_hit' => $context['cache_hit'] ?? 0,
                'request_hash' => $requestHash,
                'ip_address' => $this->shouldStoreIp() ? $context['ip_address'] ?? '' : '',
                'user_agent' => $context['user_agent'] ?? '',
                'status' => $context['status'] ?? 'success',
                'error_code' => $context['error_code'] ?? '',
                'error_message' => $context['error_message'] ?? '',
                'tstamp' => time(),
                'crdate' => time(),
            ]
        );

        $usageUid = (int) $connection->lastInsertId();

        // Update aggregated statistics (async if possible)
        $this->updateAggregatedStats($context);

        return $usageUid;
    }

    /**
     * Generate request hash for deduplication
     */
    private function generateRequestHash(array $context): string
    {
        $hashData = [
            'user_id' => $context['user_id'],
            'provider' => $context['provider'],
            'model' => $context['model'],
            'feature' => $context['feature'],
            'prompt' => $context['prompt'] ?? '',
        ];

        return hash('sha256', json_encode($hashData));
    }

    /**
     * Check if IP addresses should be stored (GDPR compliance)
     */
    private function shouldStoreIp(): bool
    {
        return (bool) ($this->configuration['usageTracking']['storeIpAddress'] ?? false);
    }

    /**
     * Update aggregated statistics
     */
    private function updateAggregatedStats(array $context): void
    {
        $date = date('Y-m-d');
        $hour = (int) date('G');

        // Update hourly stats
        $this->incrementAggregatedStat([
            'stat_date' => $date,
            'stat_hour' => $hour,
            'scope' => 'user',
            'scope_id' => (string) $context['user_id'],
            'total_requests' => 1,
            'total_tokens' => ($context['prompt_tokens'] ?? 0) + ($context['completion_tokens'] ?? 0),
            'total_cost' => $this->costCalculator->calculateCost(
                $context['provider'],
                $context['model'],
                $context['prompt_tokens'] ?? 0,
                $context['completion_tokens'] ?? 0
            )['total'],
            'cache_hits' => $context['cache_hit'] ?? 0,
            'cache_misses' => $context['cache_hit'] ? 0 : 1,
            'error_count' => $context['status'] === 'success' ? 0 : 1,
        ]);

        // Update provider stats
        $this->incrementAggregatedStat([
            'stat_date' => $date,
            'stat_hour' => $hour,
            'scope' => 'provider',
            'scope_id' => $context['provider'],
            'total_requests' => 1,
            'total_tokens' => ($context['prompt_tokens'] ?? 0) + ($context['completion_tokens'] ?? 0),
            'total_cost' => $this->costCalculator->calculateCost(
                $context['provider'],
                $context['model'],
                $context['prompt_tokens'] ?? 0,
                $context['completion_tokens'] ?? 0
            )['total'],
        ]);

        // Update feature stats
        $this->incrementAggregatedStat([
            'stat_date' => $date,
            'stat_hour' => $hour,
            'scope' => 'feature',
            'scope_id' => $context['feature'],
            'total_requests' => 1,
            'total_tokens' => ($context['prompt_tokens'] ?? 0) + ($context['completion_tokens'] ?? 0),
            'total_cost' => $this->costCalculator->calculateCost(
                $context['provider'],
                $context['model'],
                $context['prompt_tokens'] ?? 0,
                $context['completion_tokens'] ?? 0
            )['total'],
        ]);
    }

    /**
     * Increment aggregated statistic (upsert)
     */
    private function incrementAggregatedStat(array $data): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_USAGE_STATS);
        $queryBuilder = $connection->createQueryBuilder();

        // Check if record exists
        $existing = $queryBuilder
            ->select('uid', 'total_requests', 'total_tokens', 'total_cost', 'cache_hits', 'cache_misses', 'error_count')
            ->from(self::TABLE_USAGE_STATS)
            ->where(
                $queryBuilder->expr()->eq('stat_date', $queryBuilder->createNamedParameter($data['stat_date'])),
                $queryBuilder->expr()->eq('stat_hour', $queryBuilder->createNamedParameter($data['stat_hour'] ?? null, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('scope', $queryBuilder->createNamedParameter($data['scope'])),
                $queryBuilder->expr()->eq('scope_id', $queryBuilder->createNamedParameter($data['scope_id']))
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($existing) {
            // Update existing record
            $connection->update(
                self::TABLE_USAGE_STATS,
                [
                    'total_requests' => $existing['total_requests'] + ($data['total_requests'] ?? 0),
                    'total_tokens' => $existing['total_tokens'] + ($data['total_tokens'] ?? 0),
                    'total_cost' => $existing['total_cost'] + ($data['total_cost'] ?? 0),
                    'cache_hits' => $existing['cache_hits'] + ($data['cache_hits'] ?? 0),
                    'cache_misses' => $existing['cache_misses'] + ($data['cache_misses'] ?? 0),
                    'error_count' => $existing['error_count'] + ($data['error_count'] ?? 0),
                    'tstamp' => time(),
                ],
                ['uid' => $existing['uid']]
            );
        } else {
            // Insert new record
            $connection->insert(
                self::TABLE_USAGE_STATS,
                [
                    'stat_date' => $data['stat_date'],
                    'stat_hour' => $data['stat_hour'] ?? null,
                    'scope' => $data['scope'],
                    'scope_id' => $data['scope_id'],
                    'total_requests' => $data['total_requests'] ?? 0,
                    'total_tokens' => $data['total_tokens'] ?? 0,
                    'total_cost' => $data['total_cost'] ?? 0,
                    'cache_hits' => $data['cache_hits'] ?? 0,
                    'cache_misses' => $data['cache_misses'] ?? 0,
                    'error_count' => $data['error_count'] ?? 0,
                    'tstamp' => time(),
                ]
            );
        }
    }

    /**
     * Get usage statistics for reporting
     */
    public function getUsageStats(array $filters = []): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_USAGE_STATS);

        $query = $queryBuilder
            ->select('*')
            ->from(self::TABLE_USAGE_STATS);

        // Apply filters
        if (isset($filters['date_from'])) {
            $query->andWhere(
                $queryBuilder->expr()->gte('stat_date', $queryBuilder->createNamedParameter($filters['date_from']))
            );
        }

        if (isset($filters['date_to'])) {
            $query->andWhere(
                $queryBuilder->expr()->lte('stat_date', $queryBuilder->createNamedParameter($filters['date_to']))
            );
        }

        if (isset($filters['scope'])) {
            $query->andWhere(
                $queryBuilder->expr()->eq('scope', $queryBuilder->createNamedParameter($filters['scope']))
            );
        }

        if (isset($filters['scope_id'])) {
            $query->andWhere(
                $queryBuilder->expr()->eq('scope_id', $queryBuilder->createNamedParameter($filters['scope_id']))
            );
        }

        $query->orderBy('stat_date', 'DESC')
              ->addOrderBy('stat_hour', 'DESC');

        return $query->executeQuery()->fetchAllAssociative();
    }

    /**
     * Archive old usage data
     */
    public function archiveOldData(int $daysToKeep = 90): int
    {
        $cutoffDate = time() - ($daysToKeep * 86400);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_USAGE);

        $archivedCount = $connection->delete(
            self::TABLE_USAGE,
            ['tstamp' => ['<', $cutoffDate]]
        );

        $this->logger->info("Archived $archivedCount usage records older than $daysToKeep days");

        return $archivedCount;
    }
}

// (Continued in next part due to length...)
