<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Widgets\DataProvider\ToolDenialsByReasonDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ToolDenialsByReasonDataProvider::class)]
final class ToolDenialsByReasonDataProviderTest extends AbstractUnitTestCase
{
    /** @var array<string, string> */
    private const LABELS = [
        'notRegistered'      => 'Not registered',
        'toolDisabled'       => 'Tool disabled',
        'requiresAdmin'      => 'Requires admin',
        'configurationGroup' => 'Outside configuration groups',
        'trustZone'          => 'Trust zone ceiling',
    ];

    #[Test]
    public function emitsBarsInEnumOrderWithColoursAndDatasetLabel(): void
    {
        $shaped = ToolDenialsByReasonDataProvider::shapeChartData([
            'trustZone'     => 5,
            'requiresAdmin' => 2,
            'toolDisabled'  => 8,
        ], self::LABELS, 'Denials');

        // Enum order: notRegistered, toolDisabled, requiresAdmin, configurationGroup, trustZone.
        self::assertSame(['Tool disabled', 'Requires admin', 'Trust zone ceiling'], $shaped['labels']);
        self::assertSame('Denials', $shaped['datasets'][0]['label']);
        self::assertSame([8, 2, 5], $shaped['datasets'][0]['data']);
        self::assertSame(['#9E9E9E', '#D9534F', '#8E2A27'], $shaped['datasets'][0]['backgroundColor']);
    }

    #[Test]
    public function skipsZeroAndTheNoneCase(): void
    {
        $shaped = ToolDenialsByReasonDataProvider::shapeChartData([
            'none'          => 3,
            'toolDisabled'  => 0,
            'requiresAdmin' => 1,
        ], self::LABELS, 'Denials');

        // The NONE case is skipped outright (a "denials by reason" chart never
        // shows "not denied"), and zero-count reasons drop out — leaving
        // requiresAdmin as the sole bar.
        self::assertSame(['Requires admin'], $shaped['labels']);
        self::assertSame([1], $shaped['datasets'][0]['data']);
    }

    #[Test]
    public function returnsEmptyStructureForNoCounts(): void
    {
        $shaped = ToolDenialsByReasonDataProvider::shapeChartData([], self::LABELS, 'Denials');

        self::assertSame([], $shaped['labels']);
        self::assertSame([], $shaped['datasets'][0]['data']);
    }
}
