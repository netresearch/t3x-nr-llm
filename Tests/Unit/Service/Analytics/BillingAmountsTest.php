<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Analytics;

use Netresearch\NrLlm\Service\Analytics\BillingAmounts;
use Netresearch\NrLlm\Service\Analytics\BillingMarkup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BillingAmounts::class)]
#[CoversClass(BillingMarkup::class)]
final class BillingAmountsTest extends TestCase
{
    #[Test]
    public function tenPercentOnOneHundredIsAddedOnce(): void
    {
        $amounts = BillingAmounts::of(100.0, BillingMarkup::fromConfiguredValue('10'));

        self::assertSame(10, $amounts->markupPercent);
        self::assertSame(10.0, $amounts->markup);
        // 110, not 121 (applied twice) and not 100 (not applied).
        self::assertSame(110.0, $amounts->total);
        self::assertSame('100.000000', BillingAmounts::decimal($amounts->costMicros));
        self::assertSame('10.000000', BillingAmounts::decimal($amounts->markupMicros));
        self::assertSame('110.000000', BillingAmounts::decimal($amounts->totalMicros));
    }

    #[Test]
    public function noMarkupLeavesTheTotalAtTheCost(): void
    {
        $amounts = BillingAmounts::of(12.345678, BillingMarkup::fromConfiguredValue(''));

        self::assertSame(0, $amounts->markupMicros);
        self::assertSame($amounts->costMicros, $amounts->totalMicros);
        self::assertSame('12.345678', BillingAmounts::decimal($amounts->totalMicros));
    }

    /**
     * @return iterable<string, array{float, string, string, string}>
     */
    public static function roundings(): iterable
    {
        // 15 % of 0.000010 = 0.0000015 → half up to 0.000002
        yield 'half a micro-dollar rounds up' => [0.00001, '15', '0.000002', '0.000012'];
        // 14 % of 0.000010 = 0.0000014 → 0.000001
        yield 'less than half rounds down' => [0.00001, '14', '0.000001', '0.000011'];
        // 7 % of 1234.567891 = 86.41975237 → 86.419752
        yield 'a realistic month' => [1234.567891, '7', '86.419752', '1320.987643'];
        // The maximum: 1000 % of 2.5 = 25
        yield 'the maximum markup' => [2.5, '1000', '25.000000', '27.500000'];
    }

    #[Test]
    #[DataProvider('roundings')]
    public function theMarkupIsRoundedHalfUpToOneMicroDollar(float $cost, string $percent, string $markup, string $total): void
    {
        $amounts = BillingAmounts::of($cost, BillingMarkup::fromConfiguredValue($percent));

        self::assertSame($markup, BillingAmounts::decimal($amounts->markupMicros));
        self::assertSame($total, BillingAmounts::decimal($amounts->totalMicros));
        self::assertSame($amounts->costMicros + $amounts->markupMicros, $amounts->totalMicros);
    }

    /**
     * @return iterable<string, array{mixed, int, bool}>
     */
    public static function configuredValues(): iterable
    {
        yield 'not set' => [null, 0, false];
        yield 'empty' => ['', 0, false];
        yield 'a whole number' => ['15', 15, false];
        yield 'an int' => [25, 25, false];
        yield 'zero' => ['0', 0, false];
        yield 'the maximum' => ['1000', 1000, false];
        yield 'above maximum' => ['2000', 1000, true];
        yield 'negative' => ['-5', 0, true];
        yield 'a fraction' => ['12.5', 0, true];
        yield 'text' => ['ten', 0, true];
    }

    #[Test]
    #[DataProvider('configuredValues')]
    public function theSettingIsReadDefensively(mixed $raw, int $percent, bool $adjusted): void
    {
        $markup = BillingMarkup::fromConfiguredValue($raw);

        self::assertSame($percent, $markup->percent);
        self::assertSame($adjusted, $markup->adjusted);
    }
}
