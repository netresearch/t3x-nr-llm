<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Testing;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Testing\FakeBudgetService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FakeBudgetService::class)]
final class FakeBudgetServiceTest extends TestCase
{
    #[Test]
    public function implementsTheRealInterface(): void
    {
        self::assertInstanceOf(BudgetServiceInterface::class, new FakeBudgetService());
    }

    #[Test]
    public function defaultsToAnAllowingResult(): void
    {
        $result = (new FakeBudgetService())->check(1, 0.5);

        self::assertTrue($result->allowed);
    }

    #[Test]
    public function returnsTheCannedResult(): void
    {
        $subject = new FakeBudgetService();
        $subject->checkResult = BudgetCheckResult::denied('cost_per_day', 9.0, 9.0, 'exhausted');

        $result = $subject->check(1, 0.5);

        self::assertFalse($result->allowed);
    }

    #[Test]
    public function recordsEveryCallWithItsArguments(): void
    {
        $configuration = new LlmConfiguration();

        $subject = new FakeBudgetService();
        $subject->check(42, 0.75, $configuration);

        self::assertCount(1, $subject->checkCalls);
        self::assertSame(42, $subject->checkCalls[0]['beUserUid']);
        self::assertSame(0.75, $subject->checkCalls[0]['plannedCost']);
        self::assertSame($configuration, $subject->checkCalls[0]['configuration']);
    }

    #[Test]
    public function throwsConfiguredThrowableInsteadOfReturning(): void
    {
        $subject = new FakeBudgetService();
        $subject->throwable = new RuntimeException('boom');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $subject->check(1);
    }

    #[Test]
    public function throwableIsOneShotAndTheNextCallReturnsAgain(): void
    {
        $subject = new FakeBudgetService();
        $subject->throwable = new RuntimeException('boom');

        try {
            $subject->check(1);
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // one-shot: cleared before throwing
        }

        self::assertNull($subject->throwable);
        self::assertTrue($subject->check(1)->allowed);
    }
}
