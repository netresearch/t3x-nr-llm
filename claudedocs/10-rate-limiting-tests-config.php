<?php

/**
 * Rate Limiting & Quota Management - Tests & Configuration
 *
 * Comprehensive test suite and configuration examples
 *
 * @package Netresearch\NrLlm\Tests
 */

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Service\RateLimiterService;
use Netresearch\NrLlm\Service\QuotaManager;
use Netresearch\NrLlm\Service\UsageTracker;
use Netresearch\NrLlm\Service\CostCalculator;
use Netresearch\NrLlm\Service\NotificationService;
use Netresearch\NrLlm\Service\RateLimitExceededException;
use Netresearch\NrLlm\Service\QuotaExceededException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

// ============================================================================
// RATE LIMITER TESTS
// ============================================================================

class RateLimiterServiceTest extends UnitTestCase
{
    private RateLimiterService $subject;
    private $cacheMock;
    private $connectionPoolMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = $this->createMock(\TYPO3\CMS\Core\Cache\Frontend\FrontendInterface::class);
        $this->connectionPoolMock = $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $configuration = [
            'limits' => [
                'user' => [
                    'limit' => 100,
                    'window_size' => 3600,
                    'capacity' => 100,
                    'algorithm' => 'token_bucket',
                ],
            ],
        ];

        $this->subject = new RateLimiterService(
            $this->cacheMock,
            $this->connectionPoolMock,
            $loggerMock,
            $configuration
        );
    }

    /**
     * @test
     */
    public function tokenBucketAllowsRequestWhenTokensAvailable(): void
    {
        $state = [
            'tokens_available' => 50.0,
            'tokens_capacity' => 100.0,
            'last_refill_time' => time(),
            'refill_rate' => 100.0 / 3600,
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($state);

        $this->cacheMock
            ->expects(self::once())
            ->method('set');

        $result = $this->subject->checkLimit('user', '123', '', ['algorithm' => 'token_bucket']);

        self::assertTrue($result);
    }

    /**
     * @test
     */
    public function tokenBucketBlocksRequestWhenInsufficientTokens(): void
    {
        $this->expectException(RateLimitExceededException::class);

        $state = [
            'tokens_available' => 0.5,
            'tokens_capacity' => 100.0,
            'last_refill_time' => time(),
            'refill_rate' => 100.0 / 3600,
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($state);

        $this->subject->checkLimit('user', '123', '', ['algorithm' => 'token_bucket', 'cost' => 1.0]);
    }

    /**
     * @test
     */
    public function tokenBucketRefillsCorrectly(): void
    {
        $oneHourAgo = time() - 3600;

        $state = [
            'tokens_available' => 0.0,
            'tokens_capacity' => 100.0,
            'last_refill_time' => $oneHourAgo,
            'refill_rate' => 100.0 / 3600, // 100 tokens per hour
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($state);

        $this->cacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::callback(function ($savedState) {
                    // After 1 hour, should refill to full capacity
                    return $savedState['tokens_available'] >= 99.0;
                })
            );

        $result = $this->subject->checkLimit('user', '123');

        self::assertTrue($result);
    }

    /**
     * @test
     */
    public function slidingWindowAllowsRequestWithinLimit(): void
    {
        // Simulate 50 requests in the last hour
        $timestamps = array_fill(0, 50, time() - 1800); // 30 minutes ago

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($timestamps);

        $this->cacheMock
            ->expects(self::once())
            ->method('set');

        $result = $this->subject->checkLimit('user', '123', '', ['algorithm' => 'sliding_window']);

        self::assertTrue($result);
    }

    /**
     * @test
     */
    public function slidingWindowBlocksRequestAtLimit(): void
    {
        $this->expectException(RateLimitExceededException::class);

        // Simulate 100 requests (at limit)
        $timestamps = array_fill(0, 100, time() - 1800);

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($timestamps);

        $this->subject->checkLimit('user', '123', '', ['algorithm' => 'sliding_window']);
    }

    /**
     * @test
     */
    public function slidingWindowCleansOldTimestamps(): void
    {
        // Mix of old (outside window) and new (inside window) timestamps
        $timestamps = array_merge(
            array_fill(0, 50, time() - 7200), // 2 hours ago (outside 1hr window)
            array_fill(0, 40, time() - 1800)  // 30 min ago (inside window)
        );

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($timestamps);

        $this->cacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::callback(function ($savedTimestamps) {
                    // Should only have 41 timestamps (40 old + 1 new)
                    return count($savedTimestamps) === 41;
                })
            );

        $result = $this->subject->checkLimit('user', '123', '', ['algorithm' => 'sliding_window']);

        self::assertTrue($result);
    }

    /**
     * @test
     */
    public function getCurrentUsageReturnsCorrectData(): void
    {
        $state = [
            'tokens_available' => 30.0,
            'tokens_capacity' => 100.0,
            'last_refill_time' => time(),
            'refill_rate' => 100.0 / 3600,
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($state);

        $usage = $this->subject->getCurrentUsage('user', '123');

        self::assertEquals(70, $usage['used']);
        self::assertEquals(100, $usage['limit']);
        self::assertEquals(30, $usage['remaining']);
        self::assertArrayHasKey('reset_at', $usage);
    }

    /**
     * @test
     */
    public function fractionalCostsWorkCorrectly(): void
    {
        $state = [
            'tokens_available' => 10.0,
            'tokens_capacity' => 100.0,
            'last_refill_time' => time(),
            'refill_rate' => 100.0 / 3600,
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($state);

        $this->cacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::callback(function ($savedState) {
                    // Should deduct 2.5 tokens, leaving 7.5
                    return abs($savedState['tokens_available'] - 7.5) < 0.01;
                })
            );

        $result = $this->subject->checkLimit('user', '123', '', ['algorithm' => 'token_bucket', 'cost' => 2.5]);

        self::assertTrue($result);
    }
}

// ============================================================================
// QUOTA MANAGER TESTS
// ============================================================================

class QuotaManagerTest extends UnitTestCase
{
    private QuotaManager $subject;
    private $cacheMock;
    private $connectionPoolMock;
    private $notificationServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = $this->createMock(\TYPO3\CMS\Core\Cache\Frontend\FrontendInterface::class);
        $this->connectionPoolMock = $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $this->notificationServiceMock = $this->createMock(NotificationService::class);
        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $configuration = [
            'quotas' => [
                'default' => [
                    'daily_requests_limit' => 100,
                    'daily_cost_limit' => 10.0,
                    'warn_threshold' => 80.0,
                    'alert_threshold' => 90.0,
                ],
            ],
        ];

        $this->subject = new QuotaManager(
            $this->cacheMock,
            $this->connectionPoolMock,
            $this->notificationServiceMock,
            $loggerMock,
            $configuration
        );
    }

    /**
     * @test
     */
    public function checkQuotaAllowsRequestWhenAvailable(): void
    {
        $quota = [
            'uid' => 1,
            'quota_limit' => 100.0,
            'quota_used' => 50.0,
            'quota_reserved' => 0.0,
            'period_end' => time() + 3600,
            'warn_threshold' => 80.0,
            'alert_threshold' => 90.0,
            'last_warning_sent' => 0,
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn([$quota]);

        $result = $this->subject->checkQuota('user', 123, 'requests', 1.0);

        self::assertTrue($result);
    }

    /**
     * @test
     */
    public function checkQuotaBlocksRequestWhenExceeded(): void
    {
        $this->expectException(QuotaExceededException::class);

        $quota = [
            'uid' => 1,
            'quota_limit' => 100.0,
            'quota_used' => 100.0,
            'quota_reserved' => 0.0,
            'period_end' => time() + 3600,
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn([$quota]);

        $this->subject->checkQuota('user', 123, 'requests', 1.0);
    }

    /**
     * @test
     */
    public function checkQuotaSendsWarningAtThreshold(): void
    {
        $quota = [
            'uid' => 1,
            'quota_limit' => 100.0,
            'quota_used' => 80.0,
            'quota_reserved' => 0.0,
            'period_end' => time() + 3600,
            'warn_threshold' => 80.0,
            'alert_threshold' => 90.0,
            'last_warning_sent' => 0,
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn([$quota]);

        $this->notificationServiceMock
            ->expects(self::once())
            ->method('sendQuotaWarning')
            ->with($quota, 'warning');

        $result = $this->subject->checkQuota('user', 123, 'requests', 1.0);

        self::assertTrue($result);
    }

    /**
     * @test
     */
    public function consumeQuotaUpdatesUsage(): void
    {
        $quota = [
            'uid' => 1,
            'scope' => 'user',
            'scope_id' => 123,
            'quota_type' => 'cost',
            'quota_period' => 'daily',
            'quota_used' => 5.0,
            'quota_reserved' => 1.0,
            'quota_limit' => 10.0,
            'period_end' => time() + 3600,
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn([$quota]);

        $connectionMock = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);

        $connectionMock
            ->expects(self::once())
            ->method('update')
            ->with(
                'tx_nrllm_quotas',
                self::callback(function ($data) {
                    return $data['quota_used'] === 7.5 && $data['quota_reserved'] === 0.0;
                }),
                ['uid' => 1]
            );

        $this->connectionPoolMock
            ->expects(self::once())
            ->method('getConnectionForTable')
            ->willReturn($connectionMock);

        $this->subject->consumeQuota('user', 123, 'cost', 2.5, 1.0);
    }

    /**
     * @test
     */
    public function quotaPeriodRolloverResetsUsage(): void
    {
        // This would require mocking database queries for period rollover
        // Simplified test - check that ensureQuotaPeriodCurrent updates expired quotas
        self::markTestIncomplete('Requires complex database mocking');
    }

    /**
     * @test
     */
    public function getQuotaStatusReturnsCorrectInformation(): void
    {
        // Test quota status calculation
        self::markTestIncomplete('Requires database query mocking');
    }
}

// ============================================================================
// COST CALCULATOR TESTS
// ============================================================================

class CostCalculatorTest extends UnitTestCase
{
    private CostCalculator $subject;
    private $cacheMock;
    private $connectionPoolMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = $this->createMock(\TYPO3\CMS\Core\Cache\Frontend\FrontendInterface::class);
        $this->connectionPoolMock = $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->subject = new CostCalculator(
            $this->cacheMock,
            $this->connectionPoolMock,
            $loggerMock,
            []
        );
    }

    /**
     * @test
     */
    public function calculateCostReturnsCorrectValues(): void
    {
        $pricing = [
            'input_cost_per_1m' => 10.00,
            'output_cost_per_1m' => 30.00,
            'currency' => 'USD',
            'version' => 1,
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($pricing);

        $result = $this->subject->calculateCost(
            provider: 'openai',
            model: 'gpt-4-turbo',
            promptTokens: 1000,
            completionTokens: 500
        );

        // 1000 tokens input = 0.01 USD
        // 500 tokens output = 0.015 USD
        // Total = 0.025 USD
        self::assertEquals(0.010000, $result['input_cost']);
        self::assertEquals(0.015000, $result['output_cost']);
        self::assertEquals(0.025000, $result['total']);
        self::assertEquals('USD', $result['currency']);
        self::assertFalse($result['estimated']);
    }

    /**
     * @test
     */
    public function calculateCostHandlesMissingPricing(): void
    {
        $this->cacheMock
            ->expects(self::atLeastOnce())
            ->method('get')
            ->willReturn(false);

        // Mock database query returning no results
        $queryBuilderMock = $this->createMock(\TYPO3\CMS\Core\Database\Query\QueryBuilder::class);
        $queryBuilderMock->method('select')->willReturnSelf();
        $queryBuilderMock->method('from')->willReturnSelf();
        $queryBuilderMock->method('where')->willReturnSelf();
        $queryBuilderMock->method('orderBy')->willReturnSelf();
        $queryBuilderMock->method('setMaxResults')->willReturnSelf();

        $resultMock = $this->createMock(\Doctrine\DBAL\Result::class);
        $resultMock->method('fetchAssociative')->willReturn(false);
        $queryBuilderMock->method('executeQuery')->willReturn($resultMock);

        $this->connectionPoolMock
            ->expects(self::atLeastOnce())
            ->method('getQueryBuilderForTable')
            ->willReturn($queryBuilderMock);

        $result = $this->subject->calculateCost(
            provider: 'unknown',
            model: 'unknown',
            promptTokens: 1000,
            completionTokens: 500
        );

        self::assertEquals(0.0, $result['total']);
        self::assertTrue($result['estimated']);
    }

    /**
     * @test
     */
    public function estimateCostCalculatesFromPrompt(): void
    {
        $pricing = [
            'input_cost_per_1m' => 10.00,
            'output_cost_per_1m' => 30.00,
            'currency' => 'USD',
            'version' => 1,
        ];

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($pricing);

        $prompt = str_repeat('word ', 1000); // ~4000 characters = ~1000 tokens

        $result = $this->subject->estimateCost(
            provider: 'openai',
            model: 'gpt-4-turbo',
            prompt: $prompt,
            maxOutputTokens: 500
        );

        self::assertGreaterThan(0, $result['total']);
        self::assertArrayHasKey('breakdown', $result);
    }
}

// ============================================================================
// USAGE TRACKER TESTS
// ============================================================================

class UsageTrackerTest extends UnitTestCase
{
    private UsageTracker $subject;
    private $connectionPoolMock;
    private $costCalculatorMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPoolMock = $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $this->costCalculatorMock = $this->createMock(CostCalculator::class);
        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->subject = new UsageTracker(
            $this->connectionPoolMock,
            $this->costCalculatorMock,
            $loggerMock,
            ['usageTracking' => ['storeIpAddress' => false]]
        );
    }

    /**
     * @test
     */
    public function trackUsageInsertsRecord(): void
    {
        $this->costCalculatorMock
            ->expects(self::once())
            ->method('calculateCost')
            ->willReturn([
                'total' => 0.025,
                'currency' => 'USD',
                'pricing_version' => 1,
            ]);

        $connectionMock = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);

        $connectionMock
            ->expects(self::atLeastOnce())
            ->method('insert')
            ->with(
                'tx_nrllm_usage',
                self::callback(function ($data) {
                    return $data['user_id'] === 123
                        && $data['provider'] === 'openai'
                        && $data['estimated_cost'] === 0.025;
                })
            );

        $connectionMock
            ->expects(self::atLeastOnce())
            ->method('lastInsertId')
            ->willReturn(1);

        $this->connectionPoolMock
            ->expects(self::atLeastOnce())
            ->method('getConnectionForTable')
            ->willReturn($connectionMock);

        $context = [
            'user_id' => 123,
            'provider' => 'openai',
            'model' => 'gpt-4-turbo',
            'feature' => 'translation',
            'prompt_tokens' => 1000,
            'completion_tokens' => 500,
            'start_time' => microtime(true),
        ];

        $usageUid = $this->subject->trackUsage($context);

        self::assertEquals(1, $usageUid);
    }

    /**
     * @test
     */
    public function requestHashIsGeneratedCorrectly(): void
    {
        // Test that identical requests generate same hash
        self::markTestIncomplete('Requires reflection to test private method');
    }
}

// ============================================================================
// INTEGRATION TESTS
// ============================================================================

class RateLimitingIntegrationTest extends UnitTestCase
{
    /**
     * @test
     */
    public function fullRequestFlowWithRateLimitingAndQuotas(): void
    {
        // Test complete flow:
        // 1. Check rate limit
        // 2. Check quota
        // 3. Execute request
        // 4. Track usage
        // 5. Consume quota

        self::markTestIncomplete('Requires full service integration');
    }

    /**
     * @test
     */
    public function concurrentRequestsHandledCorrectly(): void
    {
        // Test atomic operations under concurrent load
        self::markTestIncomplete('Requires concurrency testing framework');
    }

    /**
     * @test
     */
    public function quotaExceededTriggersNotifications(): void
    {
        // Test notification flow
        self::markTestIncomplete('Requires notification service integration');
    }
}

// ============================================================================
// CONFIGURATION EXAMPLES
// ============================================================================

/**
 * Configuration/Services.yaml
 */
$servicesYaml = <<<YAML
    services:
      _defaults:
        autowire: true
        autoconfigure: true
        public: false

      # Rate Limiter
      Netresearch\\NrLlm\\Service\\RateLimiterService:
        public: true
        arguments:
          \$cache: '@cache.nrllm_ratelimit'
          \$configuration:
            limits:
              global:
                limit: 10000
                window_size: 3600
                capacity: 10000
                algorithm: 'sliding_window'
              provider:
                limit: 0  # Configured per-provider
                algorithm: 'token_bucket'
              user:
                limit: 100
                window_size: 3600
                capacity: 100
                algorithm: 'token_bucket'
              feature:
                limit: 0  # Configured per-feature
                algorithm: 'token_bucket'
            providers:
              openai:
                limit: 3500
                window_size: 60
                capacity: 3500
                algorithm: 'sliding_window'
              anthropic:
                limit: 1000
                window_size: 60
                capacity: 1000
                algorithm: 'sliding_window'

      # Quota Manager
      Netresearch\\NrLlm\\Service\\QuotaManager:
        public: true
        arguments:
          \$cache: '@cache.nrllm_quotas'
          \$configuration:
            quotas:
              default:
                hourly_request_limit: 100
                daily_request_limit: 1000
                monthly_request_limit: 10000
                daily_cost_limit: 10.00
                monthly_cost_limit: 200.00
                daily_token_limit: 100000
                monthly_token_limit: 1000000
                warn_threshold: 80.0
                alert_threshold: 90.0
              admin:
                daily_cost_limit: 100.00
                monthly_cost_limit: 2000.00

      # Cost Calculator
      Netresearch\\NrLlm\\Service\\CostCalculator:
        public: true
        arguments:
          \$cache: '@cache.nrllm_pricing'
          \$configuration:
            pricing:
              updateMode: 'auto_sync'
              exchangeRateApiKey: '%env(EXCHANGE_RATE_API_KEY)%'

      # Usage Tracker
      Netresearch\\NrLlm\\Service\\UsageTracker:
        public: true
        arguments:
          \$configuration:
            usageTracking:
              enabled: true
              storeIpAddress: false
              archiveAfterDays: 90

      # Notification Service
      Netresearch\\NrLlm\\Service\\NotificationService:
        public: true
        arguments:
          \$configuration:
            notifications:
              email:
                quota_warning: true
                quota_exceeded: true
                rate_limited: false
                admin_alert: true
              adminEmail: '%env(ADMIN_EMAIL)%'

      # Cache Definitions
      cache.nrllm_ratelimit:
        class: TYPO3\\CMS\\Core\\Cache\\Frontend\\VariableFrontend
        factory: ['@TYPO3\\CMS\\Core\\Cache\\CacheManager', 'getCache']
        arguments: ['nrllm_ratelimit']

      cache.nrllm_quotas:
        class: TYPO3\\CMS\\Core\\Cache\\Frontend\\VariableFrontend
        factory: ['@TYPO3\\CMS\\Core\\Cache\\CacheManager', 'getCache']
        arguments: ['nrllm_quotas']

      cache.nrllm_pricing:
        class: TYPO3\\CMS\\Core\\Cache\\Frontend\\VariableFrontend
        factory: ['@TYPO3\\CMS\\Core\\Cache\\CacheManager', 'getCache']
        arguments: ['nrllm_pricing']
    YAML;

/**
 * ext_localconf.php - Cache configuration
 */
$extLocalconf = <<<'PHP'
    <?php
    if (!defined('TYPO3')) {
        die('Access denied.');
    }

    // Register caches
    if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_ratelimit'])) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_ratelimit'] = [
            'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
            'backend' => \TYPO3\CMS\Core\Cache\Backend\RedisBackend::class,
            'options' => [
                'defaultLifetime' => 3600,
            ],
        ];
    }

    if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_quotas'])) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_quotas'] = [
            'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
            'backend' => \TYPO3\CMS\Core\Cache\Backend\RedisBackend::class,
            'options' => [
                'defaultLifetime' => 300,
            ],
        ];
    }

    if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_pricing'])) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['nrllm_pricing'] = [
            'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
            'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
            'options' => [
                'defaultLifetime' => 86400,
            ],
        ];
    }
    PHP;

/**
 * Scheduler Task for Cleanup
 */
class CleanupUsageDataTask extends \TYPO3\CMS\Scheduler\Task\AbstractTask
{
    public int $daysToKeep = 90;

    public function execute(): bool
    {
        $usageTracker = GeneralUtility::makeInstance(UsageTracker::class);
        $archivedCount = $usageTracker->archiveOldData($this->daysToKeep);

        $this->logger->info("Archived $archivedCount usage records");

        return true;
    }
}

/**
 * Scheduler Task for Pricing Updates
 */
class UpdatePricingTask extends \TYPO3\CMS\Scheduler\Task\AbstractTask
{
    public function execute(): bool
    {
        // Check provider websites for pricing updates
        // Alert admin if changes detected
        // Optionally auto-apply approved changes

        $costCalculator = GeneralUtility::makeInstance(CostCalculator::class);

        // Implementation would scrape provider pricing pages
        // or use APIs if available

        return true;
    }
}
