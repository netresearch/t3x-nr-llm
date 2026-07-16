<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Budget;

use Netresearch\NrLlm\Service\Budget\UserBudgetUsageWindows;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The SQL rollup behind the per-user budget windows: one query must return
 * daily and monthly totals side by side (conditional SUMs), scoped to the
 * requested backend user and bounded by the wider (monthly) window.
 */
#[CoversClass(UserBudgetUsageWindows::class)]
final class UserBudgetUsageWindowsTest extends AbstractFunctionalTestCase
{
    private const NOW = 1_700_000_000;

    private const DAY = 86_400;

    private UserBudgetUsageWindows $windows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->windows = new UserBudgetUsageWindows($this->getService(ConnectionPool::class));
    }

    #[Test]
    public function bothWindowsUnsetShortCircuitToZeroTotals(): void
    {
        $result = $this->windows->aggregate(1, null, null, self::NOW);

        $empty = ['requests' => 0, 'tokens' => 0, 'cost' => 0.0];
        self::assertSame(['daily' => $empty, 'monthly' => $empty], $result);
    }

    #[Test]
    public function emptyTableYieldsZeroTotals(): void
    {
        $result = $this->windows->aggregate(1, self::NOW - self::DAY, self::NOW - 30 * self::DAY, self::NOW);

        self::assertSame(0, $result['daily']['requests']);
        self::assertSame(0, $result['daily']['tokens']);
        self::assertSame(0.0, $result['daily']['cost']);
        self::assertSame(0, $result['monthly']['requests']);
    }

    #[Test]
    public function separatesDailyFromMonthlyWindowAndIgnoresOlderRows(): void
    {
        // Inside the daily window (and therefore also the monthly one).
        $this->insertUsage(beUser: 1, requestDate: self::NOW - 2 * 3600, requests: 2, tokens: 100, cost: 0.5);
        // Inside the monthly window only.
        $this->insertUsage(beUser: 1, requestDate: self::NOW - 10 * self::DAY, requests: 3, tokens: 200, cost: 1.25);
        // Outside both windows — must not count at all.
        $this->insertUsage(beUser: 1, requestDate: self::NOW - 40 * self::DAY, requests: 7, tokens: 999, cost: 9.0);

        $result = $this->windows->aggregate(1, self::NOW - self::DAY, self::NOW - 30 * self::DAY, self::NOW);

        self::assertSame(2, $result['daily']['requests']);
        self::assertSame(100, $result['daily']['tokens']);
        self::assertSame(0.5, $result['daily']['cost']);
        self::assertSame(5, $result['monthly']['requests']);
        self::assertSame(300, $result['monthly']['tokens']);
        self::assertSame(1.75, $result['monthly']['cost']);
    }

    #[Test]
    public function onlyDailyWindowSetUsesDailyBoundAsSqlLowerBound(): void
    {
        $this->insertUsage(beUser: 1, requestDate: self::NOW - 3600, requests: 1, tokens: 10, cost: 0.25);
        // Before the daily bound — with no monthly window this row is out of scope.
        $this->insertUsage(beUser: 1, requestDate: self::NOW - 5 * self::DAY, requests: 4, tokens: 40, cost: 1.0);

        $result = $this->windows->aggregate(1, self::NOW - self::DAY, null, self::NOW);

        self::assertSame(1, $result['daily']['requests']);
        // The monthly window is unset (PHP_INT_MAX sentinel): nothing qualifies.
        self::assertSame(0, $result['monthly']['requests']);
    }

    #[Test]
    public function scopesToTheRequestedBackendUser(): void
    {
        $this->insertUsage(beUser: 1, requestDate: self::NOW - 3600, requests: 2, tokens: 20, cost: 0.5);
        $this->insertUsage(beUser: 2, requestDate: self::NOW - 3600, requests: 9, tokens: 90, cost: 9.0);

        $result = $this->windows->aggregate(1, self::NOW - self::DAY, self::NOW - 30 * self::DAY, self::NOW);

        self::assertSame(2, $result['daily']['requests']);
        self::assertSame(2, $result['monthly']['requests']);
    }

    private function insertUsage(int $beUser, int $requestDate, int $requests, int $tokens, float $cost): void
    {
        $this->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_service_usage')
            ->insert('tx_nrllm_service_usage', [
                'pid'            => 0,
                'service_type'   => 'completion',
                'be_user'        => $beUser,
                'request_count'  => $requests,
                'tokens_used'    => $tokens,
                'estimated_cost' => $cost,
                'request_date'   => $requestDate,
            ]);
    }
}
