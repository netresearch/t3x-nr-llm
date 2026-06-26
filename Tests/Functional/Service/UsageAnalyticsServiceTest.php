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
    private const LIT_2026_06_01 = '2026-06-01';
    private const LIT_2026_06_02 = '2026-06-02';

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

    private function insertBackendUser(int $uid, string $username): void
    {
        $now = (new DateTimeImmutable('now'))->getTimestamp();
        $this->connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => $uid,
            'pid' => 0,
            'username' => $username,
            'password' => '',
            'admin' => 0,
            'disable' => 1,
            'deleted' => 0,
            'tstamp' => $now,
            'crdate' => $now,
        ]);
    }

    private function insertBudget(int $beUser, float $maxCostPerMonth): void
    {
        $now = (new DateTimeImmutable('now'))->getTimestamp();
        $this->connectionPool->getConnectionForTable('tx_nrllm_user_budget')->insert('tx_nrllm_user_budget', [
            'pid' => 0,
            'be_user' => $beUser,
            'max_requests_per_day' => 0,
            'max_tokens_per_day' => 0,
            'max_cost_per_day' => 0.0,
            'max_requests_per_month' => 0,
            'max_tokens_per_month' => 0,
            'max_cost_per_month' => $maxCostPerMonth,
            'is_active' => 1,
            'tstamp' => $now,
            'crdate' => $now,
            'deleted' => 0,
            'hidden' => 0,
        ]);
    }

    #[Test]
    public function kpiTotalsSumAcrossTheWindow(): void
    {
        $this->insertRow(self::LIT_2026_06_01, ['estimated_cost' => 0.10, 'request_count' => 5, 'tokens_used' => 500, 'service_provider' => 'openai', 'model_id' => 'gpt-4o']);
        $this->insertRow(self::LIT_2026_06_02, ['estimated_cost' => 0.20, 'request_count' => 3, 'tokens_used' => 300, 'service_provider' => 'claude', 'model_id' => 'claude-sonnet']);

        $kpi = $this->service->getKpiTotals(new DateTimeImmutable(self::LIT_2026_06_01), new DateTimeImmutable(self::LIT_2026_06_02));

        self::assertEqualsWithDelta(0.30, $kpi['cost'], 0.0001);
        self::assertSame(8, $kpi['requests']);
        self::assertSame(800, $kpi['tokens']);
        self::assertSame(2, $kpi['providers']);
        self::assertSame(2, $kpi['models']);
    }

    #[Test]
    public function dailyTrendIsZeroFilledAndContinuous(): void
    {
        $this->insertRow(self::LIT_2026_06_01, ['estimated_cost' => 0.10]);
        $this->insertRow('2026-06-03', ['estimated_cost' => 0.30]);

        $trend = $this->service->getDailyTrend(new DateTimeImmutable(self::LIT_2026_06_01), new DateTimeImmutable('2026-06-03'));

        self::assertCount(3, $trend);
        self::assertSame(self::LIT_2026_06_02, $trend[1]['date']);
        self::assertSame(0.0, $trend[1]['cost']);
    }

    #[Test]
    public function breakdownByModelOrdersByCostDesc(): void
    {
        $this->insertRow(self::LIT_2026_06_01, ['model_id' => 'gpt-4o', 'estimated_cost' => 0.10]);
        $this->insertRow(self::LIT_2026_06_01, ['model_id' => 'claude-sonnet', 'estimated_cost' => 0.50, 'model_uid' => 2]);

        $byModel = $this->service->getBreakdownByModel(new DateTimeImmutable(self::LIT_2026_06_01), new DateTimeImmutable(self::LIT_2026_06_01));

        self::assertSame('claude-sonnet', $byModel[0]['label']);
        self::assertSame('gpt-4o', $byModel[1]['label']);
    }

    #[Test]
    public function getTotalsGroupedByKeysByColumnValue(): void
    {
        // Two rows share configuration_uid 5 (must SUM), one has 6.
        $this->insertRow(self::LIT_2026_06_01, ['configuration_uid' => 5, 'estimated_cost' => 0.01, 'request_count' => 1, 'tokens_used' => 100]);
        $this->insertRow(self::LIT_2026_06_01, ['configuration_uid' => 5, 'estimated_cost' => 0.01, 'request_count' => 1, 'tokens_used' => 100]);
        $this->insertRow(self::LIT_2026_06_01, ['configuration_uid' => 6, 'estimated_cost' => 0.05, 'request_count' => 3, 'tokens_used' => 300]);

        $totals = $this->service->getTotalsGroupedBy(
            'configuration_uid',
            new DateTimeImmutable(self::LIT_2026_06_01),
            new DateTimeImmutable(self::LIT_2026_06_01),
        );

        self::assertArrayHasKey(5, $totals);
        self::assertArrayHasKey(6, $totals);
        self::assertEqualsWithDelta(0.02, $totals[5]['cost'], 0.0001);
        self::assertSame(2, $totals[5]['requests']);
        self::assertSame(200, $totals[5]['tokens']);
        self::assertEqualsWithDelta(0.05, $totals[6]['cost'], 0.0001);
        self::assertSame(3, $totals[6]['requests']);
        self::assertSame(300, $totals[6]['tokens']);
    }

    #[Test]
    public function perUserUsageLabelsSystemForUidZero(): void
    {
        $this->insertRow(self::LIT_2026_06_01, ['be_user' => 0, 'estimated_cost' => 0.10]);

        $perUser = $this->service->getPerUserUsage(new DateTimeImmutable(self::LIT_2026_06_01), new DateTimeImmutable(self::LIT_2026_06_01));

        self::assertNotEmpty($perUser);
        self::assertSame('system', $perUser[0]['label']);
        self::assertNull($perUser[0]['budget']);
    }

    #[Test]
    public function perUserUsageReportsBudgetConsumptionForActiveBudget(): void
    {
        // Today's local midnight: inside both the current-month budget window
        // (real "now") and the query window passed below.
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $this->insertBackendUser(5, 'editor_anna');
        $this->insertBudget(5, 2.00);
        $this->insertRow($today, ['be_user' => 5, 'estimated_cost' => 0.30]);
        $this->insertRow($today, ['be_user' => 5, 'estimated_cost' => 0.20, 'model_uid' => 2, 'model_id' => 'gpt-4o-mini']);

        $perUser = $this->service->getPerUserUsage(
            new DateTimeImmutable('first day of this month 00:00:00'),
            new DateTimeImmutable('today 00:00:00'),
        );

        $entry = $this->findEntry($perUser, 5);
        self::assertNotNull($entry);
        self::assertIsArray($entry['budget']);
        self::assertEqualsWithDelta(2.0, $entry['budget']['limitCost'], 0.0001);
        self::assertEqualsWithDelta(0.50, $entry['budget']['usedCost'], 0.0001);
        self::assertEqualsWithDelta(25.0, $entry['budget']['percent'], 0.0001);
    }

    #[Test]
    public function perUserUsageCapsBudgetPercentAtOneHundred(): void
    {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $this->insertBackendUser(6, 'editor_bob');
        $this->insertBudget(6, 2.00);
        $this->insertRow($today, ['be_user' => 6, 'estimated_cost' => 3.00]);

        $perUser = $this->service->getPerUserUsage(
            new DateTimeImmutable('first day of this month 00:00:00'),
            new DateTimeImmutable('today 00:00:00'),
        );

        $entry = $this->findEntry($perUser, 6);
        self::assertNotNull($entry);
        self::assertIsArray($entry['budget']);
        self::assertSame(100.0, $entry['budget']['percent']);
    }

    /**
     * @param list<array<string, mixed>> $perUser
     *
     * @return array<string, mixed>|null
     */
    private function findEntry(array $perUser, int $beUserUid): ?array
    {
        foreach ($perUser as $entry) {
            if (($entry['beUserUid'] ?? null) === $beUserUid) {
                return $entry;
            }
        }

        return null;
    }
}
