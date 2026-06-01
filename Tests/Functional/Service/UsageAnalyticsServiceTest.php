<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\UsageAnalyticsService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(UsageAnalyticsService::class)]
final class UsageAnalyticsServiceTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_service_usage';

    private UsageAnalyticsService $service;
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $service = $this->get(UsageAnalyticsService::class);
        self::assertInstanceOf(UsageAnalyticsService::class, $service);
        $this->service = $service;
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;
    }

    /**
     * @param array<string, int|float|string> $overrides
     */
    private function insertRow(string $date, array $overrides = []): void
    {
        $ts = (new DateTimeImmutable($date . ' 00:00:00'))->getTimestamp();
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, array_merge([
            'pid' => 0,
            'service_type' => 'chat',
            'service_provider' => 'openai',
            'configuration_uid' => 0,
            'model_uid' => 1,
            'model_id' => 'gpt-4o',
            'be_user' => 0,
            'request_count' => 1,
            'tokens_used' => 100,
            'prompt_tokens' => 60,
            'completion_tokens' => 40,
            'characters_used' => 0,
            'audio_seconds_used' => 0,
            'images_generated' => 0,
            'estimated_cost' => 0.01,
            'request_date' => $ts,
            'tstamp' => $ts,
            'crdate' => $ts,
        ], $overrides));
    }

    #[Test]
    public function kpiTotalsSumAcrossTheWindow(): void
    {
        $this->insertRow('2026-06-01', ['estimated_cost' => 0.10, 'request_count' => 5, 'tokens_used' => 500, 'service_provider' => 'openai', 'model_id' => 'gpt-4o']);
        $this->insertRow('2026-06-02', ['estimated_cost' => 0.20, 'request_count' => 3, 'tokens_used' => 300, 'service_provider' => 'claude', 'model_id' => 'claude-sonnet']);

        $kpi = $this->service->getKpiTotals(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-02'));

        self::assertEqualsWithDelta(0.30, $kpi['cost'], 0.0001);
        self::assertSame(8, $kpi['requests']);
        self::assertSame(800, $kpi['tokens']);
        self::assertSame(2, $kpi['providers']);
        self::assertSame(2, $kpi['models']);
    }

    #[Test]
    public function dailyTrendIsZeroFilledAndContinuous(): void
    {
        $this->insertRow('2026-06-01', ['estimated_cost' => 0.10]);
        $this->insertRow('2026-06-03', ['estimated_cost' => 0.30]);

        $trend = $this->service->getDailyTrend(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-03'));

        self::assertCount(3, $trend);
        self::assertSame('2026-06-02', $trend[1]['date']);
        self::assertSame(0.0, $trend[1]['cost']);
    }

    #[Test]
    public function breakdownByModelOrdersByCostDesc(): void
    {
        $this->insertRow('2026-06-01', ['model_id' => 'gpt-4o', 'estimated_cost' => 0.10]);
        $this->insertRow('2026-06-01', ['model_id' => 'claude-sonnet', 'estimated_cost' => 0.50, 'model_uid' => 2]);

        $byModel = $this->service->getBreakdownByModel(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-01'));

        self::assertSame('claude-sonnet', $byModel[0]['label']);
        self::assertSame('gpt-4o', $byModel[1]['label']);
    }

    #[Test]
    public function perUserUsageLabelsSystemForUidZero(): void
    {
        $this->insertRow('2026-06-01', ['be_user' => 0, 'estimated_cost' => 0.10]);

        $perUser = $this->service->getPerUserUsage(new DateTimeImmutable('2026-06-01'), new DateTimeImmutable('2026-06-01'));

        self::assertNotEmpty($perUser);
        self::assertSame('system', $perUser[0]['label']);
        self::assertNull($perUser[0]['budget']);
    }
}
