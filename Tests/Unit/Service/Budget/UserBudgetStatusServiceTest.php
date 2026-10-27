<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Budget;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Model\UserBudget;
use Netresearch\NrLlm\Domain\Repository\UserBudgetRepository;
use Netresearch\NrLlm\Domain\ValueObject\BudgetMeter;
use Netresearch\NrLlm\Domain\ValueObject\BudgetWindowStatus;
use Netresearch\NrLlm\Domain\ValueObject\UserBudgetStatus;
use Netresearch\NrLlm\Service\Budget\BudgetUsageWindowsInterface;
use Netresearch\NrLlm\Service\Budget\UserBudgetStatusService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The read model behind the AI Tasks budget panel (NEXT-170) must show what
 * BudgetService::check() compares: the same record, the same windows, and
 * "no personal limit" exactly where check() lets every call through.
 */
#[CoversClass(UserBudgetStatusService::class)]
#[CoversClass(UserBudgetStatus::class)]
#[CoversClass(BudgetWindowStatus::class)]
#[CoversClass(BudgetMeter::class)]
final class UserBudgetStatusServiceTest extends AbstractUnitTestCase
{
    private const DAILY = ['requests' => 3, 'tokens' => 1500, 'cost' => 0.32];

    private const MONTHLY = ['requests' => 40, 'tokens' => 52000, 'cost' => 3.2];

    #[Test]
    public function readsBothWindowsForTheGivenUserWithTheStartsCheckUses(): void
    {
        $windows = $this->createMock(BudgetUsageWindowsInterface::class);
        $windows->expects(self::once())
            ->method('aggregate')
            ->willReturnCallback(function (int $uid, ?int $dayFrom, ?int $monthFrom, int $to): array {
                self::assertSame(42, $uid);
                self::assertSame((new DateTimeImmutable('today'))->getTimestamp(), $dayFrom);
                self::assertSame((new DateTimeImmutable('midnight first day of this month'))->getTimestamp(), $monthFrom);
                self::assertEqualsWithDelta(time(), $to, 5);

                return ['daily' => self::DAILY, 'monthly' => self::MONTHLY];
            });

        $repository = self::createStub(UserBudgetRepository::class);
        $repository->method('findOneByBeUser')->willReturn(null);

        $status = (new UserBudgetStatusService($repository, $windows))->forUser(42);

        self::assertSame(3.0, $status->today->requests->used);
        self::assertSame(1500.0, $status->today->tokens->used);
        self::assertSame(0.32, $status->today->cost->used);
        self::assertSame(40.0, $status->month->requests->used);
        self::assertSame(3.2, $status->month->cost->used);
    }

    #[Test]
    public function looksUpTheBudgetOfTheGivenUser(): void
    {
        $repository = $this->createMock(UserBudgetRepository::class);
        $repository->expects(self::once())->method('findOneByBeUser')->with(42)->willReturn(null);

        (new UserBudgetStatusService($repository, $this->windows()))->forUser(42);
    }

    #[Test]
    public function anActiveBudgetWithALimitCarriesItsLimitsPerWindow(): void
    {
        $budget = new UserBudget();
        $budget->setMaxRequestsPerDay(10);
        $budget->setMaxCostPerDay(1.5);
        $budget->setMaxCostPerMonth(10.0);
        $budget->setMaxTokensPerMonth(100000);

        $status = $this->statusFor($budget);

        self::assertTrue($status->hasPersonalLimit);
        self::assertSame(10.0, $status->today->requests->limit);
        self::assertSame(0.0, $status->today->tokens->limit);
        self::assertSame(1.5, $status->today->cost->limit);
        self::assertSame(0.0, $status->month->requests->limit);
        self::assertSame(100000.0, $status->month->tokens->limit);
        self::assertSame(10.0, $status->month->cost->limit);
        self::assertSame(32, $status->month->cost->getPercent());
    }

    #[Test]
    public function noRecordReadsAsNoPersonalLimitAndStillShowsUsage(): void
    {
        $status = $this->statusFor(null);

        self::assertFalse($status->hasPersonalLimit);
        self::assertFalse($status->today->cost->isLimited());
        self::assertSame(0.32, $status->today->cost->used);
    }

    #[Test]
    public function anInactiveBudgetReadsAsNoPersonalLimit(): void
    {
        $budget = new UserBudget();
        $budget->setMaxCostPerMonth(10.0);
        $budget->setIsActive(false);

        $status = $this->statusFor($budget);

        // check() allows every call against a switched-off record, so the
        // panel must not show a limit nobody enforces.
        self::assertFalse($status->hasPersonalLimit);
        self::assertFalse($status->month->cost->isLimited());
        self::assertSame(3.2, $status->month->cost->used);
    }

    #[Test]
    public function aBudgetWithoutAnyLimitReadsAsNoPersonalLimit(): void
    {
        $status = $this->statusFor(new UserBudget());

        self::assertFalse($status->hasPersonalLimit);
    }

    #[Test]
    public function uidZeroReadsNothing(): void
    {
        // be_user = 0 holds every caller's unattributed usage (CLI, scheduler).
        $windows = $this->createMock(BudgetUsageWindowsInterface::class);
        $windows->expects(self::never())->method('aggregate');
        $repository = $this->createMock(UserBudgetRepository::class);
        $repository->expects(self::never())->method('findOneByBeUser');

        $status = (new UserBudgetStatusService($repository, $windows))->forUser(0);

        self::assertFalse($status->hasPersonalLimit);
        self::assertSame(0.0, $status->month->cost->used);
        self::assertSame(0.0, $status->today->requests->used);
    }

    #[Test]
    public function toArrayCarriesTheFormattedValuesTheRefreshWrites(): void
    {
        $budget = new UserBudget();
        $budget->setMaxCostPerMonth(10.0);

        $array = $this->statusFor($budget)->toArray();

        self::assertTrue($array['hasPersonalLimit']);
        self::assertSame(['used' => '3.20', 'limit' => '10.00', 'limited' => true, 'percent' => 32], $array['month']['cost']);
        self::assertSame(['used' => '1,500', 'limit' => '0', 'limited' => false, 'percent' => 0], $array['today']['tokens']);
    }

    private function statusFor(?UserBudget $budget): UserBudgetStatus
    {
        $repository = self::createStub(UserBudgetRepository::class);
        $repository->method('findOneByBeUser')->willReturn($budget);

        return (new UserBudgetStatusService($repository, $this->windows()))->forUser(42);
    }

    private function windows(): BudgetUsageWindowsInterface
    {
        $windows = self::createStub(BudgetUsageWindowsInterface::class);
        $windows->method('aggregate')->willReturn(['daily' => self::DAILY, 'monthly' => self::MONTHLY]);

        return $windows;
    }
}
