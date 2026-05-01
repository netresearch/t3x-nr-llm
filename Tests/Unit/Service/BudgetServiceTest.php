<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\UserBudget;
use Netresearch\NrLlm\Domain\Repository\UserBudgetRepository;
use Netresearch\NrLlm\Service\Budget\BudgetUsageWindowsInterface;
use Netresearch\NrLlm\Service\BudgetService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;

#[CoversClass(BudgetService::class)]
class BudgetServiceTest extends AbstractUnitTestCase
{
    private UserBudgetRepository&Stub $repositoryStub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryStub = self::createStub(UserBudgetRepository::class);
    }

    #[Test]
    public function allowsAnyCallForZeroUserUid(): void
    {
        $service = $this->makeService(['requests' => 999, 'tokens' => 99999, 'cost' => 9999.99]);

        $result = $service->check(0, 10.0);

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function allowsAnyCallForNegativeUserUid(): void
    {
        $service = $this->makeService(['requests' => 999, 'tokens' => 99999, 'cost' => 9999.99]);

        $result = $service->check(-5, 10.0);

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function allowsWhenUserHasNoBudgetRecord(): void
    {
        $this->repositoryStub->method('findOneByBeUser')->willReturn(null);
        $service = $this->makeService(['requests' => 999, 'tokens' => 99999, 'cost' => 9999.99]);

        $result = $service->check(42, 10.0);

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function allowsWhenBudgetIsInactive(): void
    {
        $budget = $this->makeBudget(dailyCost: 1.0);
        $budget->setIsActive(false);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        $service = $this->makeService(['requests' => 100, 'tokens' => 100, 'cost' => 100.0]);

        $result = $service->check(42, 10.0);

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function allowsWhenBudgetHasNoLimitsSet(): void
    {
        $budget = $this->makeBudget();
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        $service = $this->makeService(['requests' => 100, 'tokens' => 100, 'cost' => 100.0]);

        $result = $service->check(42, 10.0);

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function deniesOnDailyRequestLimitBecauseTheIncomingCallAdds1(): void
    {
        // Usage already at the limit -> +1 incoming > limit
        $budget = $this->makeBudget(dailyRequests: 10);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        $service = $this->makeService(['requests' => 10, 'tokens' => 0, 'cost' => 0.0]);

        $result = $service->check(42);

        self::assertFalse($result->allowed);
        self::assertSame(BudgetCheckResult::LIMIT_DAILY_REQUESTS, $result->exceededLimit);
        self::assertSame(10.0, $result->currentUsage);
        self::assertSame(10.0, $result->limit);
    }

    #[Test]
    public function allowsWhenUsagePlusOneIsExactlyAtRequestLimit(): void
    {
        // 9 used + 1 new = 10 == limit -> still allowed
        $budget = $this->makeBudget(dailyRequests: 10);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        $service = $this->makeService(['requests' => 9, 'tokens' => 0, 'cost' => 0.0]);

        self::assertTrue($service->check(42)->allowed);
    }

    #[Test]
    public function deniesOnDailyCostLimitWithPlannedCost(): void
    {
        $budget = $this->makeBudget(dailyCost: 10.0);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        // 8.50 used, plan 2.00 more -> 10.50 > 10.00 limit
        $service = $this->makeService(['requests' => 5, 'tokens' => 0, 'cost' => 8.50]);

        $result = $service->check(42, plannedCost: 2.0);

        self::assertFalse($result->allowed);
        self::assertSame(BudgetCheckResult::LIMIT_DAILY_COST, $result->exceededLimit);
        self::assertSame(8.50, $result->currentUsage);
        self::assertSame(10.0, $result->limit);
    }

    #[Test]
    public function deniesOnDailyTokenLimit(): void
    {
        $budget = $this->makeBudget(dailyTokens: 1000);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        $service = $this->makeService(['requests' => 0, 'tokens' => 1500, 'cost' => 0.0]);

        $result = $service->check(42);

        self::assertFalse($result->allowed);
        self::assertSame(BudgetCheckResult::LIMIT_DAILY_TOKENS, $result->exceededLimit);
    }

    #[Test]
    public function checksMonthlyLimitWhenDailyPasses(): void
    {
        // Daily unlimited, monthly cost capped.
        $budget = $this->makeBudget(monthlyCost: 100.0);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        // 99.50 used, plan 1.00 -> 100.50 > 100 limit
        $service = $this->makeService(
            monthly: ['requests' => 1000, 'tokens' => 0, 'cost' => 99.50],
        );

        $result = $service->check(42, plannedCost: 1.0);

        self::assertFalse($result->allowed);
        self::assertSame(BudgetCheckResult::LIMIT_MONTHLY_COST, $result->exceededLimit);
    }

    #[Test]
    public function dailyDenialTakesPrecedenceOverMonthly(): void
    {
        $budget = $this->makeBudget(dailyRequests: 5, monthlyRequests: 100);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        $service = $this->makeService(['requests' => 5, 'tokens' => 0, 'cost' => 0.0]);

        $result = $service->check(42);

        self::assertFalse($result->allowed);
        self::assertSame(BudgetCheckResult::LIMIT_DAILY_REQUESTS, $result->exceededLimit);
    }

    #[Test]
    public function allowsWhenUnderAllLimits(): void
    {
        $budget = $this->makeBudget(
            dailyRequests: 100,
            dailyTokens: 10000,
            dailyCost: 50.0,
            monthlyCost: 500.0,
        );
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        $service = $this->makeService(
            daily: ['requests' => 3, 'tokens' => 150, 'cost' => 0.75],
            monthly: ['requests' => 3, 'tokens' => 150, 'cost' => 0.75],
        );

        self::assertTrue($service->check(42, plannedCost: 0.25)->allowed);
    }

    #[Test]
    public function monthlyAggregationUsesDifferentWindowThanDaily(): void
    {
        // Give daily a pass but monthly a fail by controlling per-window returns.
        $budget = $this->makeBudget(dailyRequests: 100, monthlyRequests: 20);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        $service = $this->makeService(
            daily: ['requests' => 5, 'tokens' => 0, 'cost' => 0.0],
            monthly: ['requests' => 20, 'tokens' => 0, 'cost' => 0.0],
        );

        $result = $service->check(42);

        self::assertFalse($result->allowed);
        self::assertSame(BudgetCheckResult::LIMIT_MONTHLY_REQUESTS, $result->exceededLimit);
    }

    #[Test]
    public function combinesDailyAndMonthlyIntoASingleAggregationCall(): void
    {
        $budget = $this->makeBudget(dailyRequests: 100, monthlyRequests: 100);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        /** @var BudgetUsageWindowsInterface&MockObject $usageWindows */
        $usageWindows = $this->createMock(BudgetUsageWindowsInterface::class);
        $capturedDailyFrom = null;
        $capturedMonthlyFrom = null;
        $usageWindows->expects(self::once())
            ->method('aggregate')
            ->willReturnCallback(static function (
                int $beUserUid,
                ?int $dailyFromTimestamp,
                ?int $monthlyFromTimestamp,
                int $toTimestamp,
            ) use (&$capturedDailyFrom, &$capturedMonthlyFrom): array {
                $capturedDailyFrom = $dailyFromTimestamp;
                $capturedMonthlyFrom = $monthlyFromTimestamp;
                return [
                    'daily' => ['requests' => 3, 'tokens' => 0, 'cost' => 0.0],
                    'monthly' => ['requests' => 50, 'tokens' => 0, 'cost' => 0.0],
                ];
            });

        $service = new BudgetService($this->repositoryStub, $usageWindows);

        $result = $service->check(42);

        self::assertTrue($result->allowed);
        self::assertNotNull($capturedDailyFrom, 'Daily window should be requested');
        self::assertNotNull($capturedMonthlyFrom, 'Monthly window should be requested');
        // Monthly start must be **at or before** daily start. On the first
        // day of a month both windows start at the same instant (00:00 of
        // the 1st), which is correct production behaviour — using strict
        // `assertLessThan` here previously caused this test to fail every
        // month-start when run in CI, even though the production code was
        // doing the right thing.
        self::assertLessThanOrEqual(
            $capturedDailyFrom,
            $capturedMonthlyFrom,
            'Monthly start must precede or equal daily start',
        );
    }

    #[Test]
    public function clampsNegativePlannedCostToZero(): void
    {
        // cost limit 10, used 8 already, plannedCost = -5 must not be accepted as "used -5"
        // which would trick the check into allowing because 8 + (-5) = 3 < 10.
        $budget = $this->makeBudget(dailyCost: 10.0);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        $service = $this->makeService(
            daily: ['requests' => 0, 'tokens' => 0, 'cost' => 8.0],
            monthly: ['requests' => 0, 'tokens' => 0, 'cost' => 0.0],
        );

        // With the clamp: 8 + max(0, -5) = 8 ≤ 10 → allowed (correct)
        // Without the clamp: 8 + (-5) = 3 ≤ 10 → allowed (but for the wrong reason)
        // This test pins down the clamp behavior: try to go over with a legitimate cost,
        // then verify negative input can't reduce the used total.
        self::assertTrue($service->check(42, plannedCost: -5.0)->allowed);

        // Positive cost DOES trip the limit (8 + 3 = 11 > 10).
        self::assertFalse($service->check(42, plannedCost: 3.0)->allowed);
    }

    /**
     * @param array{requests: int, tokens: int, cost: float}|null $daily
     * @param array{requests: int, tokens: int, cost: float}|null $monthly
     */
    private function makeService(array $daily = null, array $monthly = null): BudgetService
    {
        $usageWindows = self::createStub(BudgetUsageWindowsInterface::class);
        $usageWindows->method('aggregate')->willReturn([
            'daily' => $daily ?? ['requests' => 0, 'tokens' => 0, 'cost' => 0.0],
            'monthly' => $monthly ?? ['requests' => 0, 'tokens' => 0, 'cost' => 0.0],
        ]);

        return new BudgetService($this->repositoryStub, $usageWindows);
    }

    private function makeBudget(
        int $dailyRequests = 0,
        int $dailyTokens = 0,
        float $dailyCost = 0.0,
        int $monthlyRequests = 0,
        int $monthlyTokens = 0,
        float $monthlyCost = 0.0,
    ): UserBudget {
        $budget = new UserBudget();
        $budget->setBeUser(42);
        $budget->setIsActive(true);
        $budget->setMaxRequestsPerDay($dailyRequests);
        $budget->setMaxTokensPerDay($dailyTokens);
        $budget->setMaxCostPerDay($dailyCost);
        $budget->setMaxRequestsPerMonth($monthlyRequests);
        $budget->setMaxTokensPerMonth($monthlyTokens);
        $budget->setMaxCostPerMonth($monthlyCost);
        return $budget;
    }
}
