<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\BudgetMeter;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(BudgetMeter::class)]
final class BudgetMeterTest extends AbstractUnitTestCase
{
    /**
     * @return iterable<string, array{float, float, int}>
     */
    public static function percentCases(): iterable
    {
        yield 'no limit' => [5.0, 0.0, 0];
        yield 'nothing used' => [0.0, 10.0, 0];
        yield 'a third' => [3.2, 10.0, 32];
        yield 'rounds half up' => [1.0, 8.0, 13];
        yield 'at the limit' => [10.0, 10.0, 100];
        yield 'over the limit stays full' => [25.0, 10.0, 100];
    }

    #[Test]
    #[DataProvider('percentCases')]
    public function percentIsTheShareOfTheLimitCappedAt100(float $used, float $limit, int $expected): void
    {
        self::assertSame($expected, (new BudgetMeter($used, $limit, true))->getPercent());
    }

    #[Test]
    public function aZeroLimitIsNoLimit(): void
    {
        self::assertFalse((new BudgetMeter(1.0, 0.0, false))->isLimited());
        self::assertTrue((new BudgetMeter(1.0, 1.0, false))->isLimited());
    }

    #[Test]
    public function costHasTwoDecimalsAndCountsNone(): void
    {
        $cost  = new BudgetMeter(1234.5, 10.0, true);
        $count = new BudgetMeter(1234567.0, 2000.0, false);

        // f:format.number's defaults, as the Analytics module prints cost.
        self::assertSame('1,234.50', $cost->getUsedFormatted());
        self::assertSame('10.00', $cost->getLimitFormatted());
        self::assertSame('1,234,567', $count->getUsedFormatted());
        self::assertSame('2,000', $count->getLimitFormatted());
    }
}
