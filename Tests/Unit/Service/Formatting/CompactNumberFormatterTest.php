<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Formatting;

use Netresearch\NrLlm\Service\Formatting\CompactNumberFormatter;
use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(CompactNumberFormatter::class)]
final class CompactNumberFormatterTest extends AbstractUnitTestCase
{
    /**
     * @return iterable<string, array{string, float|int, string}>
     */
    public static function compactCases(): iterable
    {
        // The tokens card of the Analytics module overflowed at "17.546.410".
        yield 'German millions' => ['de', 17546410, "17,5\u{a0}Mio."];
        yield 'English millions' => ['en', 17546410, '17.5M'];
        yield 'German with region' => ['de-DE', 17546410, "17,5\u{a0}Mio."];
        yield 'German billions' => ['de', 1234567890, "1,2\u{a0}Mrd."];
        yield 'English billions' => ['en', 1234567890, '1.2B'];
        yield 'a whole number of millions drops the decimal' => ['de', 9000000, "9\u{a0}Mio."];
        yield 'from the threshold on' => ['en', 1000000, '1M'];
        yield 'a half rounds up' => ['en', 2250000, '2.3M'];
        yield 'a negative value' => ['de', -2500000, "-2,5\u{a0}Mio."];
        // Below a million the full grouped number: German CLDR has no short
        // form for thousands and would print "3714" without grouping.
        yield 'German below the threshold stays full' => ['de', 999999, '999.999'];
        yield 'English below the threshold stays full' => ['en', 3714, '3,714'];
        yield 'German thousands keep their grouping' => ['de', 3714, '3.714'];
    }

    #[Test]
    #[DataProvider('compactCases')]
    public function formatShortensLargeCountsTheWayTheLocaleDoes(string $locale, float|int $value, string $expected): void
    {
        self::assertSame($expected, (new CompactNumberFormatter(new LocalizedNumberFormatter($locale)))->format($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableLocales(): iterable
    {
        yield 'empty' => [''];
        yield 'POSIX C' => ['C'];
        yield 'unknown to ICU' => ['xx'];
    }

    #[Test]
    #[DataProvider('unusableLocales')]
    public function aLocaleIcuCannotUseFallsBackToUsEnglishLikeTheFullNumber(string $locale): void
    {
        $formatter = new CompactNumberFormatter(new LocalizedNumberFormatter($locale));

        self::assertSame('17.5M', $formatter->format(17546410));
        self::assertSame('3,714', $formatter->format(3714));
    }
}
