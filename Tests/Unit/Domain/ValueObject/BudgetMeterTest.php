<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\BudgetMeter;
use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;
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
        yield 'rounds down' => [1.0, 8.0, 12];
        // check() still allows a run below the limit: the bar must not read full.
        yield 'just below the limit is not full' => [0.996, 1.0, 99];
        yield 'at the limit' => [10.0, 10.0, 100];
        yield 'over the limit stays full' => [25.0, 10.0, 100];
    }

    #[Test]
    #[DataProvider('percentCases')]
    public function percentIsTheShareOfTheLimitCappedAt100(float $used, float $limit, int $expected): void
    {
        self::assertSame($expected, (new BudgetMeter($used, $limit, true, new LocalizedNumberFormatter('en')))->getPercent());
    }

    #[Test]
    public function aZeroLimitIsNoLimit(): void
    {
        self::assertFalse((new BudgetMeter(1.0, 0.0, false, new LocalizedNumberFormatter('en')))->isLimited());
        self::assertTrue((new BudgetMeter(1.0, 1.0, false, new LocalizedNumberFormatter('en')))->isLimited());
    }

    #[Test]
    public function costHasTwoDecimalsAndADollarSignAndCountsNeither(): void
    {
        $english = new LocalizedNumberFormatter('en');
        $cost    = new BudgetMeter(1234.5, 10.0, true, $english);
        $count   = new BudgetMeter(1234567.0, 2000.0, false, $english);

        self::assertSame('$1,234.50', $cost->getUsedFormatted());
        self::assertSame('$10.00', $cost->getLimitFormatted());
        self::assertSame('1,234,567', $count->getUsedFormatted());
        self::assertSame('2,000', $count->getLimitFormatted());
    }

    #[Test]
    public function aGermanReaderGetsGermanSeparatorsInTheJsonToo(): void
    {
        $german = new LocalizedNumberFormatter('de');
        $cost   = new BudgetMeter(11.4, 50.0, true, $german);
        $count  = new BudgetMeter(5138706.0, 0.0, false, $german);

        self::assertSame(['used' => "11,40\u{a0}\$", 'limit' => "50,00\u{a0}\$", 'limited' => true, 'percent' => 22], $cost->toArray());
        self::assertSame(['used' => '5.138.706', 'limit' => '0', 'limited' => false, 'percent' => 0], $count->toArray());
    }
}
