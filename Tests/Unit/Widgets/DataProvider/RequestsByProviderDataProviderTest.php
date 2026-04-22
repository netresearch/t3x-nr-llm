<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Widgets\DataProvider;

use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Widgets\DataProvider\RequestsByProviderDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RequestsByProviderDataProvider::class)]
class RequestsByProviderDataProviderTest extends AbstractUnitTestCase
{
    #[Test]
    public function shapesPopulatedRowsIntoChartJsStructure(): void
    {
        $shaped = RequestsByProviderDataProvider::shapeChartData([
            ['service_provider' => 'openai', 'total_requests' => 150],
            ['service_provider' => 'claude', 'total_requests' => 80],
            ['service_provider' => 'ollama', 'total_requests' => 5],
        ]);

        self::assertSame(['openai', 'claude', 'ollama'], $shaped['labels']);
        self::assertCount(1, $shaped['datasets']);
        self::assertSame('Requests', $shaped['datasets'][0]['label']);
        self::assertSame([150, 80, 5], $shaped['datasets'][0]['data']);
        self::assertCount(3, $shaped['datasets'][0]['backgroundColor'] ?? []);
    }

    #[Test]
    public function skipsRowsWithEmptyProviderName(): void
    {
        $shaped = RequestsByProviderDataProvider::shapeChartData([
            ['service_provider' => 'openai', 'total_requests' => 10],
            ['service_provider' => '', 'total_requests' => 100], // silently dropped
            ['service_provider' => 'gemini', 'total_requests' => 20],
        ]);

        self::assertSame(['openai', 'gemini'], $shaped['labels']);
        self::assertSame([10, 20], $shaped['datasets'][0]['data']);
    }

    #[Test]
    public function skipsRowsWithNonStringProviderName(): void
    {
        $shaped = RequestsByProviderDataProvider::shapeChartData([
            ['service_provider' => null, 'total_requests' => 50],
            ['service_provider' => 123, 'total_requests' => 70],
            ['service_provider' => 'mistral', 'total_requests' => 9],
        ]);

        self::assertSame(['mistral'], $shaped['labels']);
        self::assertSame([9], $shaped['datasets'][0]['data']);
    }

    #[Test]
    public function treatsMissingRequestCountAsZero(): void
    {
        $shaped = RequestsByProviderDataProvider::shapeChartData([
            ['service_provider' => 'openai'],
        ]);

        self::assertSame(['openai'], $shaped['labels']);
        self::assertSame([0], $shaped['datasets'][0]['data']);
    }

    #[Test]
    public function returnsEmptyLabelsAndDataForEmptyRows(): void
    {
        $shaped = RequestsByProviderDataProvider::shapeChartData([]);

        self::assertSame([], $shaped['labels']);
        self::assertSame([], $shaped['datasets'][0]['data']);
        self::assertSame([], $shaped['datasets'][0]['backgroundColor'] ?? []);
    }

    #[Test]
    public function castsStringRequestCountToInt(): void
    {
        // MySQL SUM() can return strings; we normalize to int.
        $shaped = RequestsByProviderDataProvider::shapeChartData([
            ['service_provider' => 'openai', 'total_requests' => '42'],
        ]);

        self::assertSame([42], $shaped['datasets'][0]['data']);
    }
}
