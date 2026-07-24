<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Widgets\DataProvider\ToolUsageByNameDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ToolUsageByNameDataProvider::class)]
final class ToolUsageByNameDataProviderTest extends AbstractUnitTestCase
{
    #[Test]
    public function preservesTheRepositoryOrderAndUsesToolNamesAsLabels(): void
    {
        // The repository already returns most-decided first; the shaper keeps that order.
        $shaped = ToolUsageByNameDataProvider::shapeChartData([
            'fetch_logs'  => 12,
            'get_env'     => 5,
            'read_source' => 1,
        ], 'Decisions');

        self::assertSame(['fetch_logs', 'get_env', 'read_source'], $shaped['labels']);
        self::assertSame('Decisions', $shaped['datasets'][0]['label']);
        self::assertSame([12, 5, 1], $shaped['datasets'][0]['data']);
        self::assertCount(3, $shaped['datasets'][0]['backgroundColor']);
    }

    #[Test]
    public function skipsEmptyNamesAndNonPositiveCounts(): void
    {
        $shaped = ToolUsageByNameDataProvider::shapeChartData([
            'fetch_logs' => 4,
            ''           => 99,
            'get_env'    => 0,
        ], 'Decisions');

        self::assertSame(['fetch_logs'], $shaped['labels']);
        self::assertSame([4], $shaped['datasets'][0]['data']);
    }

    #[Test]
    public function returnsEmptyStructureForNoRows(): void
    {
        $shaped = ToolUsageByNameDataProvider::shapeChartData([], 'Decisions');

        self::assertSame([], $shaped['labels']);
        self::assertSame([], $shaped['datasets'][0]['data']);
        self::assertSame([], $shaped['datasets'][0]['backgroundColor']);
    }
}
