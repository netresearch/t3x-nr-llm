<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Task;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\TaskBudgetVerdict;
use Netresearch\NrLlm\Domain\ValueObject\TaskCostEstimate;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Task\TaskRunBudgetPreflight;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(TaskRunBudgetPreflight::class)]
#[CoversClass(TaskBudgetVerdict::class)]
final class TaskRunBudgetPreflightTest extends AbstractUnitTestCase
{
    #[Test]
    public function aLimitAlreadyReachedIsADenialNamingIt(): void
    {
        $budget = $this->createMock(BudgetServiceInterface::class);
        $budget->expects(self::once())
            ->method('check')
            ->with(42, 0.0)
            ->willReturn(BudgetCheckResult::denied(BudgetCheckResult::LIMIT_DAILY_REQUESTS, 10.0, 10.0));

        $verdict = (new TaskRunBudgetPreflight($budget))->verdict(42, null, $this->priced());

        self::assertSame(TaskBudgetVerdict::DENIED, $verdict->state);
        self::assertSame(BudgetCheckResult::LIMIT_DAILY_REQUESTS, $verdict->exceededLimit);
    }

    #[Test]
    public function theUpperEstimateIsCheckedSecondWithTheSameUserAndConfiguration(): void
    {
        $configuration = new LlmConfiguration();
        $calls         = [];
        $budget        = $this->createMock(BudgetServiceInterface::class);
        $budget->expects(self::exactly(2))
            ->method('check')
            ->willReturnCallback(function (int $uid, float $planned, ?LlmConfiguration $config) use (&$calls, $configuration): BudgetCheckResult {
                self::assertSame(42, $uid);
                self::assertSame($configuration, $config);
                $calls[] = $planned;

                return $planned > 0.0
                    ? BudgetCheckResult::denied(BudgetCheckResult::LIMIT_MONTHLY_COST, 9.99, 10.0)
                    : BudgetCheckResult::allowed();
            });

        $verdict = (new TaskRunBudgetPreflight($budget))->verdict(42, $configuration, $this->priced());

        self::assertSame([0.0, 0.03], $calls);
        self::assertSame(TaskBudgetVerdict::EXCEEDS_AT_UPPER_ESTIMATE, $verdict->state);
        self::assertSame(BudgetCheckResult::LIMIT_MONTHLY_COST, $verdict->exceededLimit);
    }

    #[Test]
    public function bothChecksPassingIsAllowed(): void
    {
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(BudgetCheckResult::allowed());

        $verdict = (new TaskRunBudgetPreflight($budget))->verdict(42, null, $this->priced());

        self::assertSame(TaskBudgetVerdict::ALLOWED, $verdict->state);
        self::assertSame(BudgetCheckResult::LIMIT_NONE, $verdict->exceededLimit);
    }

    #[Test]
    public function anUnpricedRunIsCheckedOnlyAsTheExecutionChecksIt(): void
    {
        $budget = $this->createMock(BudgetServiceInterface::class);
        $budget->expects(self::once())->method('check')->with(42, 0.0)->willReturn(BudgetCheckResult::allowed());

        $verdict = (new TaskRunBudgetPreflight($budget))->verdict(42, null, new TaskCostEstimate(100, null, true));

        self::assertSame(TaskBudgetVerdict::ALLOWED, $verdict->state);
    }

    private function priced(): TaskCostEstimate
    {
        return new TaskCostEstimate(100, 1000, true, 0.001, 0.03);
    }
}
