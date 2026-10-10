<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Service;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Evaluation\RegressionThresholds;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RegressionThresholds::class)]
final class RegressionThresholdsFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function independentFiniteUnitIntervalTolerancesArePreserved(): void
    {
        $this
            ->forAll($this->floatBetween(0.0, 1.0), $this->floatBetween(0.0, 1.0))
            ->then(
                function (float $passRate, float $meanScore): void {
                    $thresholds = new RegressionThresholds($passRate, $meanScore);
                    self::assertSame($passRate, $thresholds->maxPassRateDrop);
                    self::assertSame($meanScore, $thresholds->maxMeanScoreDrop);
                },
            );
    }
    #[Test]
    public function anyMagnitudeOutsideUnitIntervalIsRejectedForEitherTolerance(): void
    {
        $this
            ->forAll($this->floatBetween(0.001, 1000.0))
            ->then(
                function (float $magnitude): void {
                    foreach ([
                        [-$magnitude, 0.1],
                        [0.1, -$magnitude],
                        [1.0 + $magnitude, 0.1],
                        [0.1, 1.0 + $magnitude],
                    ] as [$passRate, $meanScore]) {
                        $rejected = false;
                        try {
                            new RegressionThresholds($passRate, $meanScore);
                        } catch (InvalidArgumentException) {
                            $rejected = true;
                        }
                        self::assertTrue($rejected);
                    }
                },
            );
    }
}
