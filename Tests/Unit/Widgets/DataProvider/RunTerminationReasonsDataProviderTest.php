<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Widgets\DataProvider\RunTerminationReasonsDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RunTerminationReasonsDataProvider::class)]
final class RunTerminationReasonsDataProviderTest extends AbstractUnitTestCase
{
    /** @var array<string, string> */
    private const LABELS = [
        'completed'         => 'Completed',
        'budget_exhausted'  => 'Budget exhausted',
        'policy_denied'     => 'Policy denied',
        'provider_failed'   => 'Provider failed',
    ];

    #[Test]
    public function emitsBarsInEnumOrderWithTheDatasetLabel(): void
    {
        $shaped = RunTerminationReasonsDataProvider::shapeChartData([
            'provider_failed'  => 4,
            'completed'        => 20,
            'budget_exhausted' => 7,
        ], self::LABELS, 'Runs');

        // Enum order places completed first, budget_exhausted before provider_failed.
        self::assertSame(['Completed', 'Budget exhausted', 'Provider failed'], $shaped['labels']);
        self::assertCount(1, $shaped['datasets']);
        self::assertSame('Runs', $shaped['datasets'][0]['label']);
        self::assertSame([20, 7, 4], $shaped['datasets'][0]['data']);
        self::assertSame(['#4CAF50', '#E8731A', '#8E2A27'], $shaped['datasets'][0]['backgroundColor']);
    }

    #[Test]
    public function skipsZeroCounts(): void
    {
        $shaped = RunTerminationReasonsDataProvider::shapeChartData([
            'completed'       => 0,
            'policy_denied'   => 2,
        ], self::LABELS, 'Runs');

        self::assertSame(['Policy denied'], $shaped['labels']);
        self::assertSame([2], $shaped['datasets'][0]['data']);
    }

    #[Test]
    public function fallsBackToRawReasonValueWhenLabelMissing(): void
    {
        $shaped = RunTerminationReasonsDataProvider::shapeChartData([
            'policy_denied' => 1,
        ], [], 'Runs');

        self::assertSame(['policy_denied'], $shaped['labels']);
    }

    #[Test]
    public function returnsEmptyStructureForNoCounts(): void
    {
        $shaped = RunTerminationReasonsDataProvider::shapeChartData([], self::LABELS, 'Runs');

        self::assertSame([], $shaped['labels']);
        self::assertSame([], $shaped['datasets'][0]['data']);
    }
}
