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
use Netresearch\NrLlm\Service\BudgetService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(BudgetService::class)]
class BudgetServiceTest extends AbstractUnitTestCase
{
    private UserBudgetRepository&Stub $repositoryStub;
    private ConnectionPool&Stub $connectionPoolStub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryStub = self::createStub(UserBudgetRepository::class);
        $this->connectionPoolStub = self::createStub(ConnectionPool::class);
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
        $service = $this->makeService(['requests' => 1000, 'tokens' => 0, 'cost' => 99.50]);

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

        $service = $this->makeService(['requests' => 3, 'tokens' => 150, 'cost' => 0.75]);

        self::assertTrue($service->check(42, plannedCost: 0.25)->allowed);
    }

    #[Test]
    public function monthlyAggregationUsesDifferentWindowThanDaily(): void
    {
        // Give daily a pass but monthly a fail by controlling per-window returns.
        $budget = $this->makeBudget(dailyRequests: 100, monthlyRequests: 20);
        $this->repositoryStub->method('findOneByBeUser')->willReturn($budget);

        // Note: cannot declare this anon class `readonly` because we mutate
        // $callCount across invocations. BudgetService is NOT marked readonly
        // at the class level for exactly this reason — it only relies on its
        // constructor-promoted fields being readonly.
        $service = new class ($this->repositoryStub, $this->connectionPoolStub) extends BudgetService {
            public int $callCount = 0;

            protected function aggregateUsage(int $beUserUid, int $fromTimestamp, int $toTimestamp): array
            {
                $this->callCount++;
                // First call is daily, second is monthly
                return $this->callCount === 1
                    ? ['requests' => 5, 'tokens' => 0, 'cost' => 0.0]
                    : ['requests' => 20, 'tokens' => 0, 'cost' => 0.0];
            }
        };

        $result = $service->check(42);

        self::assertFalse($result->allowed);
        self::assertSame(BudgetCheckResult::LIMIT_MONTHLY_REQUESTS, $result->exceededLimit);
        self::assertSame(2, $service->callCount);
    }

    /**
     * @param array{requests: int, tokens: int, cost: float} $fakeUsage
     */
    private function makeService(array $fakeUsage): BudgetService
    {
        return new class ($this->repositoryStub, $this->connectionPoolStub, $fakeUsage) extends BudgetService {
            /**
             * @param array{requests: int, tokens: int, cost: float} $fakeUsage
             */
            public function __construct(
                UserBudgetRepository $repository,
                ConnectionPool $connectionPool,
                private readonly array $fakeUsage,
            ) {
                parent::__construct($repository, $connectionPool);
            }

            protected function aggregateUsage(int $beUserUid, int $fromTimestamp, int $toTimestamp): array
            {
                return $this->fakeUsage;
            }
        };
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
