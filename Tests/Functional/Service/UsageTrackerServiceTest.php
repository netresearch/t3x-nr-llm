<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
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

    private const TABLE = 'tx_nrllm_service_usage';

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

        self::assertIsArray($row);
        self::assertArrayHasKey('request_count', $row);
        self::assertArrayHasKey('characters_used', $row);
        self::assertArrayHasKey('estimated_cost', $row);
        self::assertIsNumeric($row['request_count']);
        self::assertIsNumeric($row['characters_used']);
        self::assertIsNumeric($row['estimated_cost']);
        self::assertSame(2, (int)$row['request_count']);
        self::assertSame(1250, (int)$row['characters_used']);
        self::assertEqualsWithDelta(0.0625, (float)$row['estimated_cost'], 0.0001);
    }

    #[Test]
    public function trackUsageWithCountsAsRequestFalseRecordsMetricsWithoutCountingRequest(): void
    {
        // #473: a provider sub-call (e.g. a translation's language detection)
        // records its tokens/cost but must not increment the request counter.
        $this->service->trackUsage(
            'chat',
            'openai',
            ['tokens' => 150, 'promptTokens' => 100, 'completionTokens' => 50, 'cost' => 0.01],
            modelId: 'gpt-4o-mini',
            countsAsRequest: false,
        );

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['request_count', 'tokens_used', 'estimated_cost'],
            self::TABLE,
            ['service_type' => 'chat', 'service_provider' => 'openai'],
        )->fetchAssociative();

        self::assertIsArray($row);
        self::assertIsNumeric($row['request_count']);
        self::assertIsNumeric($row['tokens_used']);
        self::assertIsNumeric($row['estimated_cost']);
        self::assertSame(0, (int)$row['request_count']);
        self::assertSame(150, (int)$row['tokens_used']);
        self::assertEqualsWithDelta(0.01, (float)$row['estimated_cost'], 0.0001);
    }

    #[Test]
    public function trackUsageWithCountsAsRequestFalseDoesNotIncrementExistingRequestCount(): void
    {
        // A counting call establishes the daily row; a following non-counting
        // sub-call aggregates its tokens but leaves request_count at 1 — one
        // logical operation is a single request of record (#473).
        $this->service->trackUsage('chat', 'openai', ['tokens' => 10], modelId: 'gpt-4o-mini');
        $this->service->trackUsage('chat', 'openai', ['tokens' => 150], modelId: 'gpt-4o-mini', countsAsRequest: false);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['request_count', 'tokens_used'],
            self::TABLE,
            ['service_type' => 'chat', 'service_provider' => 'openai'],
        )->fetchAssociative();

        self::assertIsArray($row);
        self::assertIsNumeric($row['request_count']);
        self::assertIsNumeric($row['tokens_used']);
        self::assertSame(1, (int)$row['request_count']);
        self::assertSame(160, (int)$row['tokens_used']);
    }

    #[Test]
    public function trackUsageKeepsSeparateRecordsForDifferentModelIds(): void
    {
        // Specialized calls carry model_uid=0 and only a model_id string — two
        // different models on the same day must yield two rows, not collide.
        $this->service->trackUsage('image', 'dall-e', ['images' => 1, 'cost' => 0.05], modelId: 'gpt-image-2');
        $this->service->trackUsage('image', 'dall-e', ['images' => 2, 'cost' => 0.08], modelId: 'dall-e-3');

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $count = $connection->count('*', self::TABLE, ['service_type' => 'image', 'service_provider' => 'dall-e']);
        self::assertSame(2, $count);

        $row = $connection->select(
            ['request_count', 'images_generated'],
            self::TABLE,
            ['service_type' => 'image', 'model_id' => 'gpt-image-2'],
        )->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(1, (int)$row['request_count']);
        self::assertSame(1, (int)$row['images_generated']);

        // Same model again aggregates into ITS row only.
        $this->service->trackUsage('image', 'dall-e', ['images' => 1, 'cost' => 0.05], modelId: 'gpt-image-2');
        $count = $connection->count('*', self::TABLE, ['service_type' => 'image', 'service_provider' => 'dall-e']);
        self::assertSame(2, $count);
    }

    #[Test]
    public function trackUsageKeepsSeparateRecordsForDifferentConfigurations(): void
    {
        // Two configurations on the same model (same everything else) must yield
        // two rows so per-configuration analytics are not misattributed.
        $this->service->trackUsage('completion', 'openai', ['tokens' => 10, 'cost' => 0.01], configurationUid: 1, modelId: 'gpt-4o');
        $this->service->trackUsage('completion', 'openai', ['tokens' => 20, 'cost' => 0.02], configurationUid: 2, modelId: 'gpt-4o');

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $count = $connection->count('*', self::TABLE, ['service_type' => 'completion', 'model_id' => 'gpt-4o']);
        self::assertSame(2, $count);

        // A repeat call on configuration 1 aggregates into ITS row only.
        $this->service->trackUsage('completion', 'openai', ['tokens' => 5, 'cost' => 0.01], configurationUid: 1, modelId: 'gpt-4o');
        $count = $connection->count('*', self::TABLE, ['service_type' => 'completion', 'model_id' => 'gpt-4o']);
        self::assertSame(2, $count);

        $row = $connection->select(
            ['request_count'],
            self::TABLE,
            ['service_type' => 'completion', 'model_id' => 'gpt-4o', 'configuration_uid' => 1],
        )->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(2, (int)$row['request_count']);
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

        self::assertIsArray($row);
        self::assertArrayHasKey('tokens_used', $row);
        self::assertIsNumeric($row['tokens_used']);
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

        self::assertIsArray($row);
        self::assertArrayHasKey('audio_seconds_used', $row);
        self::assertIsNumeric($row['audio_seconds_used']);
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

        self::assertIsArray($row);
        self::assertArrayHasKey('images_generated', $row);
        self::assertIsNumeric($row['images_generated']);
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

        self::assertIsArray($row);
        self::assertArrayHasKey('configuration_uid', $row);
        self::assertIsNumeric($row['configuration_uid']);
        self::assertSame(42, (int)$row['configuration_uid']);
    }

    #[Test]
    public function trackUsageAttributesToExplicitBeUserUid(): void
    {
        $this->service->trackUsage(
            'chat',
            'openai',
            ['tokens' => 10],
            beUserUid: 42,
        );

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['be_user'],
            self::TABLE,
            ['service_type' => 'chat'],
        )->fetchAssociative();

        self::assertIsArray($row);
        self::assertArrayHasKey('be_user', $row);
        self::assertIsNumeric($row['be_user']);
        self::assertSame(42, (int)$row['be_user']);
    }

    #[Test]
    public function trackUsageFallsBackToAmbientContextWhenBeUserUidIsNull(): void
    {
        // The functional container has no authenticated backend user, so the
        // ambient backend.user aspect resolves to 0 — the pre-existing default.
        $this->service->trackUsage(
            'chat',
            'openai',
            ['tokens' => 10],
        );

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['be_user'],
            self::TABLE,
            ['service_type' => 'chat'],
        )->fetchAssociative();

        self::assertIsArray($row);
        self::assertArrayHasKey('be_user', $row);
        self::assertIsNumeric($row['be_user']);
        self::assertSame(0, (int)$row['be_user']);
    }

    #[Test]
    public function trackUsageKeepsSeparateRecordsForDifferentBeUsers(): void
    {
        // be_user is part of the daily aggregation key — two users on the same
        // provider/model/day must not merge into one row.
        $this->service->trackUsage('chat', 'openai', ['tokens' => 10], beUserUid: 42);
        $this->service->trackUsage('chat', 'openai', ['tokens' => 10], beUserUid: 43);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $count = $connection->count('*', self::TABLE, ['service_type' => 'chat']);

        self::assertSame(2, $count);
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

        self::assertIsArray($row);
        self::assertArrayHasKey('request_count', $row);
        self::assertArrayHasKey('tokens_used', $row);
        self::assertArrayHasKey('characters_used', $row);
        self::assertIsNumeric($row['request_count']);
        self::assertIsNumeric($row['tokens_used']);
        self::assertIsNumeric($row['characters_used']);
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

        // Verify the report is returned (type is already array from service)
        self::assertNotEmpty($report);
    }

    #[Test]
    public function getCurrentMonthCostReturnsTotal(): void
    {
        $this->service->trackUsage('translation', 'deepl', ['characters' => 1000, 'cost' => 0.05]);
        $this->service->trackUsage('speech', 'whisper', ['audioSeconds' => 60, 'cost' => 0.006]);

        $totalCost = $this->service->getCurrentMonthCost();

        self::assertGreaterThanOrEqual(0.056, $totalCost);
    }

    #[Test]
    public function trackUsageStoresModelDimensionAndTokenSplit(): void
    {
        $this->service->trackUsage(
            'chat',
            'openai',
            ['tokens' => 1500, 'promptTokens' => 1000, 'completionTokens' => 500, 'cost' => 0.0125],
            7,
            42,
            'gpt-4o',
        );

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['model_uid', 'model_id', 'prompt_tokens', 'completion_tokens', 'tokens_used', 'configuration_uid', 'estimated_cost'],
            self::TABLE,
            ['service_type' => 'chat', 'service_provider' => 'openai'],
        )->fetchAssociative();

        self::assertIsArray($row);
        self::assertIsString($row['model_id']);
        self::assertIsNumeric($row['estimated_cost']);
        self::assertSame(42, (int)$row['model_uid']);
        self::assertSame('gpt-4o', $row['model_id']);
        self::assertSame(1000, (int)$row['prompt_tokens']);
        self::assertSame(500, (int)$row['completion_tokens']);
        self::assertSame(1500, (int)$row['tokens_used']);
        self::assertSame(7, (int)$row['configuration_uid']);
        self::assertEqualsWithDelta(0.0125, (float)$row['estimated_cost'], 0.0001);
    }

    #[Test]
    public function trackUsageKeepsSeparateRowsPerModelSameDay(): void
    {
        $this->service->trackUsage('chat', 'openai', ['tokens' => 100], null, 1, 'gpt-4o');
        $this->service->trackUsage('chat', 'openai', ['tokens' => 200], null, 2, 'gpt-4o-mini');

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $count = $connection->count('*', self::TABLE, ['service_type' => 'chat', 'service_provider' => 'openai']);

        self::assertSame(2, $count);
    }

    #[Test]
    public function trackUsageAggregatesSameModelSameDay(): void
    {
        $this->service->trackUsage('chat', 'openai', ['tokens' => 100, 'promptTokens' => 60, 'completionTokens' => 40], null, 1, 'gpt-4o');
        $this->service->trackUsage('chat', 'openai', ['tokens' => 150, 'promptTokens' => 90, 'completionTokens' => 60], null, 1, 'gpt-4o');

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(
            ['request_count', 'tokens_used', 'prompt_tokens', 'completion_tokens'],
            self::TABLE,
            ['service_type' => 'chat', 'model_uid' => 1],
        )->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame(2, (int)$row['request_count']);
        self::assertSame(250, (int)$row['tokens_used']);
        self::assertSame(150, (int)$row['prompt_tokens']);
        self::assertSame(100, (int)$row['completion_tokens']);
    }

    #[Test]
    public function getTodayUsageSumsAcrossModelsSameServiceAndProvider(): void
    {
        $this->service->trackUsage('chat', 'openai', ['tokens' => 100], null, 1, 'gpt-4o');
        $this->service->trackUsage('chat', 'openai', ['tokens' => 200], null, 2, 'gpt-4o-mini');

        $usage = $this->service->getTodayUsage('chat', 'openai');

        self::assertNotNull($usage);
        self::assertIsNumeric($usage['request_count']);
        self::assertIsNumeric($usage['tokens_used']);
        self::assertSame(2, (int)$usage['request_count']);
        self::assertSame(300, (int)$usage['tokens_used']);
    }

    #[Test]
    public function getTodayUsageReturnsNullWhenNoUsageForServiceProvider(): void
    {
        $usage = $this->service->getTodayUsage('chat', 'openai');

        self::assertNull($usage);
    }

    #[Test]
    public function trackUsageStoresTaskUid(): void
    {
        $this->service->trackUsage('chat', 'openai', ['tokens' => 100], null, 1, 'gpt-4o', 77);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(['task_uid'], self::TABLE, ['service_type' => 'chat'])->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame(77, (int)$row['task_uid']);
    }

    #[Test]
    public function trackUsageKeepsSeparateRowsPerTaskSameDay(): void
    {
        $this->service->trackUsage('chat', 'openai', ['tokens' => 100], null, 1, 'gpt-4o', 1);
        $this->service->trackUsage('chat', 'openai', ['tokens' => 200], null, 1, 'gpt-4o', 2);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $count = $connection->count('*', self::TABLE, ['service_type' => 'chat', 'service_provider' => 'openai']);

        self::assertSame(2, $count);
    }
}
