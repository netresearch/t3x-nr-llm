<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\UsageAnalyticsService;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A usage report restricted to a list of backend users (ADR-277): every
 * reader counts only those users' rows, and an empty list counts nothing.
 */
#[CoversClass(UsageAnalyticsService::class)]
final class UsageAnalyticsServiceScopeTest extends AbstractFunctionalTestCase
{
    private UsageAnalyticsServiceInterface $service;

    private DateTimeImmutable $from;

    private DateTimeImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->getService(UsageAnalyticsService::class);
        $this->from    = new DateTimeImmutable('today -2 days');
        $this->to      = new DateTimeImmutable('today 23:59:59');

        $this->insertRow(11, 'inside', 1.5);
        $this->insertRow(12, 'outside', 900.0);
        // Calls nobody is attributed to (a scheduler run, a CLI command).
        $this->insertRow(0, 'system', 800.0);
    }

    #[Test]
    public function totalsCountOnlyTheListedUsers(): void
    {
        $kpi = $this->service->forUsers([11])->getKpiTotals($this->from, $this->to);

        self::assertSame(1.5, $kpi['cost']);
        self::assertSame(1, $kpi['requests']);
        self::assertSame(10, $kpi['tokens']);
        self::assertSame(1, $kpi['providers']);
        self::assertSame(1, $kpi['models']);
    }

    #[Test]
    public function everyBreakdownCountsOnlyTheListedUsers(): void
    {
        $scoped = $this->service->forUsers([11]);

        foreach ([
            'provider' => $scoped->getBreakdownByProvider($this->from, $this->to),
            'model'    => $scoped->getBreakdownByModel($this->from, $this->to),
            'service'  => $scoped->getBreakdownByService($this->from, $this->to),
            'source'   => $scoped->getBreakdownBySourceExtension($this->from, $this->to),
        ] as $name => $rows) {
            self::assertCount(1, $rows, $name);
            self::assertStringEndsWith('inside', $rows[0]['label'], $name);
            self::assertSame(1.5, $rows[0]['cost'], $name);
        }
    }

    #[Test]
    public function theGroupedTotalsCountOnlyTheListedUsers(): void
    {
        $scoped = $this->service->forUsers([11]);

        self::assertSame(['prov-inside'], array_keys($scoped->getTotalsGroupedBy('service_provider', $this->from, $this->to)));
        $rows = $scoped->getTotalsGroupedByColumns(['model_id'], $this->from, $this->to);
        self::assertCount(1, $rows);
        self::assertSame('model-inside', $rows[0]['model_id']);
    }

    #[Test]
    public function theTrendCountsOnlyTheListedUsers(): void
    {
        $trend = $this->service->forUsers([11])->getDailyTrend($this->from, $this->to);

        self::assertSame(1.5, array_sum(array_column($trend, 'cost')));
        self::assertSame(1, array_sum(array_column($trend, 'requests')));
    }

    #[Test]
    public function thePerUserListNamesOnlyTheListedUsers(): void
    {
        $perUser = $this->service->forUsers([11, 12])->getPerUserUsage($this->from, $this->to);

        self::assertSame([12, 11], array_column($perUser, 'beUserUid'));
        self::assertNotContains(0, array_column($perUser, 'beUserUid'));
    }

    #[Test]
    public function anEmptyListCountsNothing(): void
    {
        $this->assertNothingIsCounted($this->service->forUsers([]));
    }

    #[Test]
    public function theUnattributedRowsAreNeverAUser(): void
    {
        // be_user 0 is "system", not a person: listing it selects nothing.
        $this->assertNothingIsCounted($this->service->forUsers([0, -5]));
    }

    #[Test]
    public function theUnrestrictedServiceStillCountsEveryone(): void
    {
        self::assertSame(1701.5, $this->service->getKpiTotals($this->from, $this->to)['cost']);
        self::assertCount(3, $this->service->getPerUserUsage($this->from, $this->to));
    }

    private function assertNothingIsCounted(UsageAnalyticsServiceInterface $scoped): void
    {
        self::assertSame(
            ['cost' => 0.0, 'requests' => 0, 'tokens' => 0, 'providers' => 0, 'models' => 0],
            $scoped->getKpiTotals($this->from, $this->to),
        );
        self::assertSame([], $scoped->getBreakdownByProvider($this->from, $this->to));
        self::assertSame([], $scoped->getBreakdownByModel($this->from, $this->to));
        self::assertSame([], $scoped->getBreakdownByService($this->from, $this->to));
        self::assertSame([], $scoped->getBreakdownBySourceExtension($this->from, $this->to));
        self::assertSame([], $scoped->getTotalsGroupedBy('service_provider', $this->from, $this->to));
        self::assertSame([], $scoped->getTotalsGroupedByColumns(['model_id'], $this->from, $this->to));
        self::assertSame([], $scoped->getPerUserUsage($this->from, $this->to));
        self::assertSame(0.0, array_sum(array_column($scoped->getDailyTrend($this->from, $this->to), 'cost')));
    }

    private function insertRow(int $beUser, string $marker, float $cost): void
    {
        $ts = (new DateTimeImmutable('today'))->getTimestamp();
        $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_service_usage')
            ->insert('tx_nrllm_service_usage', [
                'pid'               => 0,
                'service_type'      => 'svc-' . $marker,
                'service_provider'  => 'prov-' . $marker,
                'model_id'          => 'model-' . $marker,
                'source_extension'  => 'ext_' . $marker,
                'be_user'           => $beUser,
                'request_count'     => 1,
                'tokens_used'       => 10,
                'prompt_tokens'     => 6,
                'completion_tokens' => 4,
                'estimated_cost'    => $cost,
                'request_date'      => $ts,
                'tstamp'            => $ts,
                'crdate'            => $ts,
            ]);
    }
}
