<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for UsageTrackerService.
 *
 * Tests usage tracking and aggregation for cost monitoring.
 */
#[CoversClass(UsageTrackerService::class)]
final class UsageTrackerServiceTest extends AbstractFunctionalTestCase
{
    private UsageTrackerService $service;
    private ConnectionPool $connectionPool;

    private const string TABLE = 'tx_nrllm_service_usage';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $service = $this->get(UsageTrackerService::class);
        self::assertInstanceOf(UsageTrackerService::class, $service);
        $this->service = $service;

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;
    }

    // =========================================================================
    // Usage Tracking
    // =========================================================================

    #[Test]
    public function trackUsageCreatesNewRecord(): void
    {
        $this->service->trackUsage(
            'translation',
            'deepl',
            ['characters' => 1000, 'cost' => 0.05],
        );

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $count = $connection->count('*', self::TABLE, [
            'service_type' => 'translation',
            'service_provider' => 'deepl',
        ]);

        self::assertSame(1, $count);
    }

    #[Test]
    public function trackUsageAggregatesMultipleRequestsSameDay(): void
    {
        // First request
        $this->service->trackUsage(
            'translation',
            'deepl',
            ['characters' => 500, 'cost' => 0.025],
        );

        // Second request same day
        $this->service->trackUsage(
            'translation',
            'deepl',
            ['characters' => 750, 'cost' => 0.0375],
        );

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['request_count', 'characters_used', 'estimated_cost'],
            self::TABLE,
            [
                'service_type' => 'translation',
                'service_provider' => 'deepl',
            ],
        )->fetchAssociative();

        self::assertNotFalse($row);
        self::assertSame(2, (int)$row['request_count']);
        self::assertSame(1250, (int)$row['characters_used']);
        self::assertEqualsWithDelta(0.0625, (float)$row['estimated_cost'], 0.0001);
    }

    #[Test]
    public function trackUsageKeepsSeparateRecordsForDifferentProviders(): void
    {
        $this->service->trackUsage('translation', 'deepl', ['characters' => 100]);
        $this->service->trackUsage('translation', 'google', ['characters' => 200]);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $count = $connection->count('*', self::TABLE, ['service_type' => 'translation']);

        self::assertSame(2, $count);
    }

    #[Test]
    public function trackUsageKeepsSeparateRecordsForDifferentServiceTypes(): void
    {
        $this->service->trackUsage('translation', 'deepl', ['characters' => 100]);
        $this->service->trackUsage('speech', 'whisper', ['audioSeconds' => 60]);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        $translationCount = $connection->count('*', self::TABLE, ['service_type' => 'translation']);
        $speechCount = $connection->count('*', self::TABLE, ['service_type' => 'speech']);

        self::assertSame(1, $translationCount);
        self::assertSame(1, $speechCount);
    }

    #[Test]
    public function trackUsageStoresTokenMetrics(): void
    {
        $this->service->trackUsage(
            'completion',
            'openai',
            ['tokens' => 1500, 'cost' => 0.003],
        );

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['tokens_used'],
            self::TABLE,
            ['service_type' => 'completion'],
        )->fetchAssociative();

        self::assertNotFalse($row);
        self::assertSame(1500, (int)$row['tokens_used']);
    }

    #[Test]
    public function trackUsageStoresAudioSecondsMetrics(): void
    {
        $this->service->trackUsage(
            'speech',
            'whisper',
            ['audioSeconds' => 120, 'cost' => 0.012],
        );

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['audio_seconds_used'],
            self::TABLE,
            ['service_type' => 'speech'],
        )->fetchAssociative();

        self::assertNotFalse($row);
        self::assertSame(120, (int)$row['audio_seconds_used']);
    }

    #[Test]
    public function trackUsageStoresImageGenerationMetrics(): void
    {
        $this->service->trackUsage(
            'image',
            'dall-e',
            ['images' => 4, 'cost' => 0.08],
        );

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['images_generated'],
            self::TABLE,
            ['service_type' => 'image'],
        )->fetchAssociative();

        self::assertNotFalse($row);
        self::assertSame(4, (int)$row['images_generated']);
    }

    #[Test]
    public function trackUsageStoresConfigurationUid(): void
    {
        $this->service->trackUsage(
            'translation',
            'deepl',
            ['characters' => 100],
            42,
        );

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['configuration_uid'],
            self::TABLE,
            ['service_type' => 'translation'],
        )->fetchAssociative();

        self::assertNotFalse($row);
        self::assertSame(42, (int)$row['configuration_uid']);
    }

    #[Test]
    public function trackUsageHandlesEmptyMetrics(): void
    {
        $this->service->trackUsage('test', 'provider', []);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['request_count', 'tokens_used', 'characters_used'],
            self::TABLE,
            ['service_type' => 'test'],
        )->fetchAssociative();

        self::assertNotFalse($row);
        self::assertSame(1, (int)$row['request_count']);
        self::assertSame(0, (int)$row['tokens_used']);
        self::assertSame(0, (int)$row['characters_used']);
    }

    // =========================================================================
    // Usage Statistics Retrieval
    // =========================================================================

    #[Test]
    public function getTodayUsageReturnsCorrectData(): void
    {
        // Track some usage first
        $this->service->trackUsage('translation', 'deepl', ['characters' => 5000, 'cost' => 0.25]);
        $this->service->trackUsage('translation', 'deepl', ['characters' => 3000, 'cost' => 0.15]);

        $usage = $this->service->getTodayUsage('translation', 'deepl');

        self::assertNotNull($usage);
        self::assertSame(2, (int)$usage['request_count']);
        self::assertSame(8000, (int)$usage['characters_used']);
        self::assertEqualsWithDelta(0.40, (float)$usage['estimated_cost'], 0.001);
    }

    #[Test]
    public function getTodayUsageReturnsNullForNoData(): void
    {
        $usage = $this->service->getTodayUsage('nonexistent', 'provider');

        self::assertNull($usage);
    }

    #[Test]
    public function getUsageReportReturnsDataForDateRange(): void
    {
        $this->service->trackUsage('translation', 'deepl', ['characters' => 1000, 'cost' => 0.05]);

        $from = new DateTimeImmutable('today');
        $to = new DateTimeImmutable('tomorrow');

        $report = $this->service->getUsageReport('translation', $from, $to);

        self::assertIsArray($report);
    }

    #[Test]
    public function getCurrentMonthCostReturnsTotal(): void
    {
        $this->service->trackUsage('translation', 'deepl', ['characters' => 1000, 'cost' => 0.05]);
        $this->service->trackUsage('speech', 'whisper', ['audioSeconds' => 60, 'cost' => 0.006]);

        $totalCost = $this->service->getCurrentMonthCost();

        self::assertGreaterThanOrEqual(0.056, $totalCost);
    }
}
