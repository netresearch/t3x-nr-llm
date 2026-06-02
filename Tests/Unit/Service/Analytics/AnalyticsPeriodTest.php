<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Analytics;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Analytics\AnalyticsPeriod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalyticsPeriod::class)]
final class AnalyticsPeriodTest extends TestCase
{
    private const NOW = '2026-06-15 13:45:00';

    #[Test]
    public function sevenDayPresetSpansSevenInclusiveDaysEndingToday(): void
    {
        $period = AnalyticsPeriod::fromPreset('7d', new DateTimeImmutable(self::NOW));
        self::assertSame('7d', $period->preset);
        self::assertSame('2026-06-09 00:00:00', $period->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-15 00:00:00', $period->to->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function thirtyDayIsTheDefaultForUnknownPresets(): void
    {
        $period = AnalyticsPeriod::fromPreset('nonsense', new DateTimeImmutable(self::NOW));
        self::assertSame('30d', $period->preset);
        self::assertSame('2026-05-17 00:00:00', $period->from->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function ninetyDayPresetSpansNinetyInclusiveDays(): void
    {
        $period = AnalyticsPeriod::fromPreset('90d', new DateTimeImmutable(self::NOW));
        self::assertSame('90d', $period->preset);
        self::assertSame('2026-03-18 00:00:00', $period->from->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function monthPresetStartsOnFirstOfMonth(): void
    {
        $period = AnalyticsPeriod::fromPreset('month', new DateTimeImmutable(self::NOW));
        self::assertSame('month', $period->preset);
        self::assertSame('2026-06-01 00:00:00', $period->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-15 00:00:00', $period->to->format('Y-m-d H:i:s'));
    }
}
