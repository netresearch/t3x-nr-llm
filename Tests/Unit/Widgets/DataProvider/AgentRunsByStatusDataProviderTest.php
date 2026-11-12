<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
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

        self::assertSame(['#45A048', '#D43B36'], $shaped['datasets'][0]['backgroundColor']);
    }

    /**
     * Every status the enum knows has a colour of its own, the waiting state
     * of spec 051 A2A-R1 included, so a new status never falls back to grey.
     */
    #[Test]
    public function everyStatusHasItsOwnColour(): void
    {
        $counts = [];
        foreach (AgentRunStatus::cases() as $case) {
            $counts[$case->value] = 1;
        }

        $colours = AgentRunsByStatusDataProvider::shapeChartData($counts, self::LABELS)['datasets'][0]['backgroundColor'];

        self::assertCount(count(AgentRunStatus::cases()), $colours);
        self::assertCount(count($colours), array_unique($colours));
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
