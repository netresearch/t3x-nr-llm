<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Widgets\DataProvider\AgentRunsByStatusDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(AgentRunsByStatusDataProvider::class)]
final class AgentRunsByStatusDataProviderTest extends AbstractUnitTestCase
{
    /** @var array<string, string> */
    private const LABELS = [
        'queued'               => 'Queued',
        'running'              => 'Running',
        'waiting_for_approval' => 'Awaiting approval',
        'waiting_for_input'    => 'Awaiting input',
        'completed'            => 'Completed',
        'failed'               => 'Failed',
        'cancelled'            => 'Cancelled',
    ];

    #[Test]
    public function emitsSlicesInEnumOrderRegardlessOfCountOrder(): void
    {
        $shaped = AgentRunsByStatusDataProvider::shapeChartData([
            'failed'    => 3,
            'completed' => 10,
            'running'   => 2,
        ], self::LABELS);

        // Enum order is queued, running, waiting_*, completed, failed, cancelled.
        self::assertSame(['Running', 'Completed', 'Failed'], $shaped['labels']);
        self::assertSame([2, 10, 3], $shaped['datasets'][0]['data']);
        self::assertCount(3, $shaped['datasets'][0]['backgroundColor']);
    }

    #[Test]
    public function skipsZeroAndNegativeCounts(): void
    {
        $shaped = AgentRunsByStatusDataProvider::shapeChartData([
            'completed' => 0,
            'running'   => 5,
            'failed'    => -1,
        ], self::LABELS);

        self::assertSame(['Running'], $shaped['labels']);
        self::assertSame([5], $shaped['datasets'][0]['data']);
    }

    #[Test]
    public function fallsBackToRawStatusValueWhenLabelMissing(): void
    {
        $shaped = AgentRunsByStatusDataProvider::shapeChartData([
            'running' => 4,
        ], []);

        self::assertSame(['running'], $shaped['labels']);
    }

    #[Test]
    public function assignsTheFixedSemanticColourPerStatus(): void
    {
        $shaped = AgentRunsByStatusDataProvider::shapeChartData([
            'completed' => 1,
            'failed'    => 1,
        ], self::LABELS);

        self::assertSame(['#4CAF50', '#D9534F'], $shaped['datasets'][0]['backgroundColor']);
    }

    #[Test]
    public function returnsEmptyStructureForNoCounts(): void
    {
        $shaped = AgentRunsByStatusDataProvider::shapeChartData([], self::LABELS);

        self::assertSame([], $shaped['labels']);
        self::assertSame([], $shaped['datasets'][0]['data']);
        self::assertSame([], $shaped['datasets'][0]['backgroundColor']);
    }
}
