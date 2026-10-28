<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Analytics;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Analytics\BillingMonth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Month boundaries in the server timezone. Europe/Berlin, because its
 * months include a daylight-saving change: a month that is not 30 or 31
 * times 86,400 seconds long is where a fixed-length calculation breaks.
 */
#[CoversClass(BillingMonth::class)]
final class BillingMonthTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{int, int, string, string}>
     */
    public static function months(): iterable
    {
        yield 'October 2026, ends on daylight saving' => [2026, 10, '2026-10-01 00:00:00', '2026-10-31 23:59:59'];
        yield 'March 2026, starts daylight saving' => [2026, 3, '2026-03-01 00:00:00', '2026-03-31 23:59:59'];
        yield 'February of a leap year' => [2028, 2, '2028-02-01 00:00:00', '2028-02-29 23:59:59'];
        yield 'December, year change' => [2026, 12, '2026-12-01 00:00:00', '2026-12-31 23:59:59'];
    }

    #[Test]
    #[DataProvider('months')]
    public function aMonthRunsFromItsFirstToItsLastSecondInServerTime(int $year, int $month, string $first, string $last): void
    {
        $billingMonth = BillingMonth::of($year, $month);

        self::assertSame($first, $billingMonth->start->format('Y-m-d H:i:s'));
        self::assertSame($last, $billingMonth->lastSecond->format('Y-m-d H:i:s'));
        self::assertSame('Europe/Berlin', $billingMonth->start->getTimezone()->getName());
        // The last second plus one is the next month's first bucket.
        self::assertSame(
            $billingMonth->lastSecond->getTimestamp() + 1,
            (new DateTimeImmutable($first))->modify('first day of next month')->getTimestamp(),
        );
    }

    #[Test]
    public function theNextMonthsMidnightBucketLiesOutsideAndTheLastDaysInside(): void
    {
        $october = BillingMonth::of(2026, 10);

        $lastDay   = (new DateTimeImmutable('2026-10-31 00:00:00'))->getTimestamp();
        $nextFirst = (new DateTimeImmutable('2026-11-01 00:00:00'))->getTimestamp();

        self::assertLessThanOrEqual($october->lastSecond->getTimestamp(), $lastDay);
        self::assertGreaterThan($october->lastSecond->getTimestamp(), $nextFirst);
    }

    #[Test]
    public function theDefaultIsThePreviousCompleteMonth(): void
    {
        self::assertSame('2026-09', BillingMonth::previous(new DateTimeImmutable('2026-10-28 14:00:00'))->key());
        self::assertSame('2025-12', BillingMonth::previous(new DateTimeImmutable('2026-01-01 00:00:00'))->key());
        // 31 March minus one month is not 31 February.
        self::assertSame('2026-02', BillingMonth::previous(new DateTimeImmutable('2026-03-31 12:00:00'))->key());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function parameters(): iterable
    {
        yield 'a past month' => ['2026-03', '2026-03'];
        yield 'the running month' => ['2026-10', '2026-10'];
        yield 'a month not yet begun' => ['2026-11', '2026-09'];
        yield 'month 13' => ['2026-13', '2026-09'];
        yield 'month 00' => ['2026-00', '2026-09'];
        yield 'no leading zero' => ['2026-3', '2026-09'];
        yield 'trailing text' => ['2026-03x', '2026-09'];
        yield 'an array' => [['2026-03'], '2026-09'];
        yield 'nothing' => [null, '2026-09'];
    }

    #[Test]
    #[DataProvider('parameters')]
    public function aRequestValueIsReadOnlyAsAMonthThatHasBegun(mixed $value, string $expected): void
    {
        self::assertSame($expected, BillingMonth::fromParameter($value, new DateTimeImmutable('2026-10-28 14:00:00'))->key());
    }

    #[Test]
    public function theSelectorOffersTheRunningMonthAndTwelveBefore(): void
    {
        $keys = array_map(
            static fn (BillingMonth $month): string => $month->key(),
            BillingMonth::choices(new DateTimeImmutable('2026-03-31 12:00:00')),
        );

        self::assertCount(13, $keys);
        self::assertSame('2026-03', $keys[0]);
        self::assertSame('2026-02', $keys[1]);
        self::assertSame('2025-03', $keys[12]);
    }

    #[Test]
    public function onlyTheMonthContainingNowIsRunning(): void
    {
        $now = new DateTimeImmutable('2026-10-28 14:00:00');

        self::assertTrue(BillingMonth::of(2026, 10)->isRunning($now));
        self::assertFalse(BillingMonth::of(2026, 9)->isRunning($now));
    }
}
