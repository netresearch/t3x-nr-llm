<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Widgets\DataProvider\MonthlyCostDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(MonthlyCostDataProvider::class)]
class MonthlyCostDataProviderTest extends AbstractUnitTestCase
{
    #[Test]
    public function returnsFlooredIntegerCost(): void
    {
        $tracker = self::createStub(UsageTrackerServiceInterface::class);
        $tracker->method('getCurrentMonthCost')->willReturn(12.95);

        $provider = new MonthlyCostDataProvider($tracker);

        self::assertSame(12, $provider->getNumber());
    }

    #[Test]
    public function returnsZeroForZeroCost(): void
    {
        $tracker = self::createStub(UsageTrackerServiceInterface::class);
        $tracker->method('getCurrentMonthCost')->willReturn(0.0);

        $provider = new MonthlyCostDataProvider($tracker);

        self::assertSame(0, $provider->getNumber());
    }

    /**
     * @return array<string, array{float, int}>
     */
    public static function costCases(): array
    {
        return [
            'exact dollar' => [1.0, 1],
            'sub-dollar floors to zero' => [0.99, 0],
            'truncates cents' => [42.99, 42],
            'large value' => [12345.67, 12345],
        ];
    }

    #[Test]
    #[DataProvider('costCases')]
    public function flooringTable(float $cost, int $expected): void
    {
        $tracker = self::createStub(UsageTrackerServiceInterface::class);
        $tracker->method('getCurrentMonthCost')->willReturn($cost);

        $provider = new MonthlyCostDataProvider($tracker);

        self::assertSame($expected, $provider->getNumber());
    }
}
