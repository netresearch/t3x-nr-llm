<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Evaluation\RegressionThresholds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegressionThresholds::class)]
final class RegressionThresholdsTest extends TestCase
{
    #[Test]
    #[DataProvider('invalidThresholds')]
    public function rejectsNonfiniteAndOutOfRangeValues(
        float $passRate,
        float $meanScore,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new RegressionThresholds($passRate, $meanScore);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function invalidThresholds(): iterable
    {
        foreach ([
            'negative' => -0.0001,
            'above-one' => 1.0001,
            'nan' => NAN,
            'infinity' => INF,
            'negative-infinity' => -INF,
        ] as $case => $value) {
            yield 'pass-' . $case => [$value, 0.1];
            yield 'mean-' . $case => [0.1, $value];
        }
    }

    #[Test]
    #[DataProvider('validThresholds')]
    public function preservesFiniteUnitIntervalValues(
        float $passRate,
        float $meanScore,
    ): void {
        $thresholds = new RegressionThresholds($passRate, $meanScore);
        self::assertSame($passRate, $thresholds->maxPassRateDrop);
        self::assertSame($meanScore, $thresholds->maxMeanScoreDrop);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function validThresholds(): iterable
    {
        yield 'zero' => [0.0, 0.0];
        yield 'negative-zero' => [-0.0, -0.0];
        yield 'one' => [1.0, 1.0];
        yield 'independent' => [0.125, 0.875];
    }
}
