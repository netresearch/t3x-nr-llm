<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\DTO;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(BudgetCheckResult::class)]
class BudgetCheckResultTest extends AbstractUnitTestCase
{
    #[Test]
    public function allowedFactoryReturnsPermissiveResult(): void
    {
        $result = BudgetCheckResult::allowed();

        self::assertTrue($result->allowed);
        self::assertSame(BudgetCheckResult::LIMIT_NONE, $result->exceededLimit);
        self::assertSame('', $result->reason);
        self::assertSame(0.0, $result->currentUsage);
        self::assertSame(0.0, $result->limit);
    }

    #[Test]
    public function deniedFactorySetsFieldsAndGeneratesReason(): void
    {
        $result = BudgetCheckResult::denied(
            BudgetCheckResult::LIMIT_DAILY_COST,
            currentUsage: 9.5,
            limit: 10.0,
        );

        self::assertFalse($result->allowed);
        self::assertSame(BudgetCheckResult::LIMIT_DAILY_COST, $result->exceededLimit);
        self::assertSame(9.5, $result->currentUsage);
        self::assertSame(10.0, $result->limit);
        self::assertStringContainsString('AI budget exhausted', $result->reason);
        self::assertStringContainsString('daily cost', $result->reason);
        self::assertStringContainsString('9.50', $result->reason);
        self::assertStringContainsString('10', $result->reason);
    }

    #[Test]
    public function deniedReasonUsesHumanLabelNotInternalKey(): void
    {
        $result = BudgetCheckResult::denied(
            BudgetCheckResult::LIMIT_MONTHLY_TOKENS,
            currentUsage: 1_000_000,
            limit: 500_000,
        );

        self::assertStringContainsString('monthly token usage', $result->reason);
        self::assertStringNotContainsString('monthly_tokens', $result->reason);
    }

    #[Test]
    public function deniedReasonFallsBackToInternalKeyForUnknownLimit(): void
    {
        // Guard against a future LIMIT_* constant without a label entry.
        $result = BudgetCheckResult::denied(
            'custom_limit',
            currentUsage: 1.0,
            limit: 2.0,
        );

        self::assertStringContainsString('custom_limit', $result->reason);
    }

    #[Test]
    public function deniedFactoryAcceptsCustomReason(): void
    {
        $result = BudgetCheckResult::denied(
            BudgetCheckResult::LIMIT_MONTHLY_TOKENS,
            currentUsage: 1_000_000,
            limit: 500_000,
            reason: 'Monthly token cap exceeded',
        );

        self::assertSame('Monthly token cap exceeded', $result->reason);
    }

    #[Test]
    public function deniedReasonFormatsIntegersWithoutDecimals(): void
    {
        $result = BudgetCheckResult::denied(
            BudgetCheckResult::LIMIT_DAILY_REQUESTS,
            currentUsage: 5.0,
            limit: 10.0,
        );

        self::assertStringContainsString('5 of 10', $result->reason);
        self::assertStringNotContainsString('.00', $result->reason);
    }

    #[Test]
    public function allLimitConstantsAreDistinctAndNonEmpty(): void
    {
        $limits = [
            BudgetCheckResult::LIMIT_DAILY_REQUESTS,
            BudgetCheckResult::LIMIT_DAILY_TOKENS,
            BudgetCheckResult::LIMIT_DAILY_COST,
            BudgetCheckResult::LIMIT_MONTHLY_REQUESTS,
            BudgetCheckResult::LIMIT_MONTHLY_TOKENS,
            BudgetCheckResult::LIMIT_MONTHLY_COST,
        ];

        self::assertCount(6, array_unique($limits));
        foreach ($limits as $limit) {
            self::assertNotSame('', $limit);
        }
    }
}
