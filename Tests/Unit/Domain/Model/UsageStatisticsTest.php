<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

#[CoversNothing] // Domain/Model excluded from coverage in phpunit.xml
class UsageStatisticsTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $usage = new UsageStatistics(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
            estimatedCost: 0.0025,
        );

        self::assertEquals(100, $usage->promptTokens);
        self::assertEquals(50, $usage->completionTokens);
        self::assertEquals(150, $usage->totalTokens);
        self::assertEquals(0.0025, $usage->estimatedCost);
    }

    #[Test]
    public function constructorDefaultsEstimatedCostToNull(): void
    {
        $usage = new UsageStatistics(100, 50, 150);

        self::assertNull($usage->estimatedCost);
    }

    #[Test]
    public function getTotalReturnsTotalTokens(): void
    {
        $usage = new UsageStatistics(100, 50, 150);

        self::assertEquals(150, $usage->getTotal());
        self::assertEquals($usage->totalTokens, $usage->getTotal());
    }

    #[Test]
    public function getCostReturnsEstimatedCost(): void
    {
        $usage = new UsageStatistics(100, 50, 150, 0.003);

        self::assertEquals(0.003, $usage->getCost());
    }

    #[Test]
    public function getCostReturnsNullWhenNotSet(): void
    {
        $usage = new UsageStatistics(100, 50, 150);

        self::assertNull($usage->getCost());
    }

    #[Test]
    public function fromTokensCreatesInstanceWithCalculatedTotal(): void
    {
        $usage = UsageStatistics::fromTokens(100, 50);

        self::assertEquals(100, $usage->promptTokens);
        self::assertEquals(50, $usage->completionTokens);
        self::assertEquals(150, $usage->totalTokens);
        self::assertNull($usage->estimatedCost);
    }

    #[Test]
    public function fromTokensAcceptsEstimatedCost(): void
    {
        $usage = UsageStatistics::fromTokens(100, 50, 0.002);

        self::assertEquals(0.002, $usage->estimatedCost);
        self::assertEquals(0.002, $usage->getCost());
    }

    #[Test]
    public function fromTokensCalculatesTotalCorrectly(): void
    {
        $testCases = [
            [0, 0, 0],
            [100, 0, 100],
            [0, 100, 100],
            [500, 1500, 2000],
            [10000, 5000, 15000],
        ];

        foreach ($testCases as [$prompt, $completion, $expectedTotal]) {
            $usage = UsageStatistics::fromTokens($prompt, $completion);
            self::assertEquals($expectedTotal, $usage->totalTokens);
        }
    }

    #[Test]
    public function propertiesAreReadonly(): void
    {
        $usage = new UsageStatistics(100, 50, 150, 0.001);

        // Test that readonly properties exist and are accessible with expected values
        self::assertSame(100, $usage->promptTokens);
        self::assertSame(50, $usage->completionTokens);
        self::assertSame(150, $usage->totalTokens);
    }

    #[Test]
    public function zeroTokensAreValid(): void
    {
        $usage = new UsageStatistics(0, 0, 0);

        self::assertEquals(0, $usage->promptTokens);
        self::assertEquals(0, $usage->completionTokens);
        self::assertEquals(0, $usage->totalTokens);
        self::assertEquals(0, $usage->getTotal());
    }

    #[Test]
    public function largeTokenCountsAreSupported(): void
    {
        $largeCount = 1_000_000;
        $usage = new UsageStatistics($largeCount, $largeCount, $largeCount * 2);

        self::assertEquals($largeCount, $usage->promptTokens);
        self::assertEquals($largeCount, $usage->completionTokens);
        self::assertEquals($largeCount * 2, $usage->totalTokens);
    }

    #[Test]
    public function costCanBeZero(): void
    {
        $usage = new UsageStatistics(100, 50, 150, 0.0);

        self::assertEquals(0.0, $usage->estimatedCost);
        self::assertEquals(0.0, $usage->getCost());
    }

    #[Test]
    public function verySmallCostValuesArePreserved(): void
    {
        $smallCost = 0.000001;
        $usage = new UsageStatistics(10, 5, 15, $smallCost);

        self::assertEquals($smallCost, $usage->getCost());
    }

    // ====================================================================
    // Cache-codec serialization (toArray / fromArray) — ADR-026 cleanup.
    // ====================================================================

    #[Test]
    public function toArrayEmitsCanonicalKeys(): void
    {
        $usage = new UsageStatistics(10, 5, 15, 0.025);

        self::assertSame(
            [
                'promptTokens'     => 10,
                'completionTokens' => 5,
                'totalTokens'      => 15,
                'estimatedCost'    => 0.025,
            ],
            $usage->toArray(),
        );
    }

    #[Test]
    public function toArrayPreservesNullEstimatedCost(): void
    {
        $usage = new UsageStatistics(1, 2, 3);

        $array = $usage->toArray();

        self::assertArrayHasKey('estimatedCost', $array);
        self::assertNull($array['estimatedCost']);
    }

    #[Test]
    public function fromArrayRoundTripsAllFields(): void
    {
        $original = new UsageStatistics(100, 50, 150, 0.003);

        $restored = UsageStatistics::fromArray($original->toArray());

        self::assertEquals($original, $restored);
    }

    #[Test]
    public function fromArrayDefaultsMissingTokenFieldsToZero(): void
    {
        $restored = UsageStatistics::fromArray([]);

        self::assertSame(0, $restored->promptTokens);
        self::assertSame(0, $restored->completionTokens);
        self::assertSame(0, $restored->totalTokens);
        self::assertNull($restored->estimatedCost);
    }

    #[Test]
    public function fromArrayCoercesIntegerCostToFloat(): void
    {
        $restored = UsageStatistics::fromArray([
            'promptTokens'  => 1,
            'estimatedCost' => 1, // legacy cached payload may have stored an int
        ]);

        self::assertSame(1.0, $restored->estimatedCost);
    }

    #[Test]
    public function fromArrayIgnoresNonNumericCost(): void
    {
        $restored = UsageStatistics::fromArray(['estimatedCost' => 'not-a-number']);

        self::assertNull($restored->estimatedCost);
    }

    #[Test]
    public function fromArrayIgnoresNonIntegerTokenFields(): void
    {
        $restored = UsageStatistics::fromArray([
            'promptTokens'     => '10',   // string — should not be silently cast
            'completionTokens' => 5.5,    // float
            'totalTokens'      => null,
        ]);

        self::assertSame(0, $restored->promptTokens);
        self::assertSame(0, $restored->completionTokens);
        self::assertSame(0, $restored->totalTokens);
    }
}
