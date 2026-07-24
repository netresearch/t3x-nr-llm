<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Widgets\DataProvider\GovernanceBlocksOverTimeDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GovernanceBlocksOverTimeDataProvider::class)]
final class GovernanceBlocksOverTimeDataProviderTest extends AbstractUnitTestCase
{
    /** @var array<string, string> */
    private const LABELS = [
        'tool_denied'       => 'Tool denied',
        'response_blocked'  => 'Response blocked',
        'approval_required' => 'Approval required',
        'content_filter'    => 'Content filter',
    ];

    #[Test]
    public function emitsBarsInEnumOrderWithColoursAndDatasetLabel(): void
    {
        $shaped = GovernanceBlocksOverTimeDataProvider::shapeChartData([
            'content_filter'   => 2,
            'tool_denied'      => 9,
            'response_blocked' => 3,
        ], self::LABELS, 'Events');

        self::assertSame(['Tool denied', 'Response blocked', 'Content filter'], $shaped['labels']);
        self::assertSame('Events', $shaped['datasets'][0]['label']);
        self::assertSame([9, 3, 2], $shaped['datasets'][0]['data']);
        self::assertSame(['#607D8B', '#D9534F', '#8E2A27'], $shaped['datasets'][0]['backgroundColor']);
    }

    #[Test]
    public function skipsZeroCounts(): void
    {
        $shaped = GovernanceBlocksOverTimeDataProvider::shapeChartData([
            'tool_denied'       => 0,
            'approval_required' => 4,
        ], self::LABELS, 'Events');

        self::assertSame(['Approval required'], $shaped['labels']);
        self::assertSame([4], $shaped['datasets'][0]['data']);
    }

    #[Test]
    public function fallsBackToRawDecisionValueWhenLabelMissing(): void
    {
        $shaped = GovernanceBlocksOverTimeDataProvider::shapeChartData([
            'tool_denied' => 1,
        ], [], 'Events');

        self::assertSame(['tool_denied'], $shaped['labels']);
    }

    #[Test]
    public function returnsEmptyStructureForNoCounts(): void
    {
        $shaped = GovernanceBlocksOverTimeDataProvider::shapeChartData([], self::LABELS, 'Events');

        self::assertSame([], $shaped['labels']);
        self::assertSame([], $shaped['datasets'][0]['data']);
    }
}
