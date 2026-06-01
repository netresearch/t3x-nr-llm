<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\UsageAnalyticsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UsageAnalyticsService::class)]
final class UsageAnalyticsServiceShaperTest extends TestCase
{
    #[Test]
    public function fillDailyGapsInsertsZeroRowsForMissingDays(): void
    {
        $jun10 = (new DateTimeImmutable('2026-06-10 00:00:00'))->getTimestamp();
        $jun12 = (new DateTimeImmutable('2026-06-12 00:00:00'))->getTimestamp();
        $rows = [
            ['request_date' => $jun10, 'cost' => 1.5, 'requests' => 10, 'tokens' => 1000],
            ['request_date' => $jun12, 'cost' => 2.0, 'requests' => 20, 'tokens' => 2000],
        ];

        $series = UsageAnalyticsService::fillDailyGaps(
            $rows,
            new DateTimeImmutable('2026-06-10 00:00:00'),
            new DateTimeImmutable('2026-06-12 00:00:00'),
        );

        self::assertCount(3, $series);
        self::assertSame('2026-06-10', $series[0]['date']);
        self::assertSame(1.5, $series[0]['cost']);
        self::assertSame('2026-06-11', $series[1]['date']);
        self::assertSame(0.0, $series[1]['cost']);
        self::assertSame(0, $series[1]['requests']);
        self::assertSame('2026-06-12', $series[2]['date']);
        self::assertSame(20, $series[2]['requests']);
    }

    #[Test]
    public function mergeUsernamesLabelsRowsAndFallsBackForSystemAndUnknown(): void
    {
        $usageRows = [
            ['be_user' => 5, 'cost' => 3.0, 'requests' => 30, 'tokens' => 3000],
            ['be_user' => 0, 'cost' => 1.0, 'requests' => 10, 'tokens' => 1000],
            ['be_user' => 7, 'cost' => 0.5, 'requests' => 5, 'tokens' => 500],
        ];
        $userMap = [5 => 'editor_anna'];

        $merged = UsageAnalyticsService::mergeUsernames($usageRows, $userMap);

        self::assertSame('editor_anna', $merged[0]['label']);
        self::assertSame(5, $merged[0]['beUserUid']);
        self::assertSame('system', $merged[1]['label']);
        self::assertSame('user #7', $merged[2]['label']);
    }

    #[Test]
    public function fillDailyGapsAlignsBucketsUnderNonUtcTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            // Berlin local midnight; '@'.$ts would bucket this as the previous UTC day.
            $from = new DateTimeImmutable('2026-06-10 00:00:00');
            $to = new DateTimeImmutable('2026-06-10 00:00:00');
            $ts = (new DateTimeImmutable('2026-06-10 00:00:00'))->getTimestamp();
            $rows = [
                ['request_date' => $ts, 'cost' => 5.0, 'requests' => 1, 'tokens' => 10],
            ];

            $series = UsageAnalyticsService::fillDailyGaps($rows, $from, $to);

            self::assertCount(1, $series);
            self::assertSame('2026-06-10', $series[0]['date']);
            self::assertSame(5.0, $series[0]['cost']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}
