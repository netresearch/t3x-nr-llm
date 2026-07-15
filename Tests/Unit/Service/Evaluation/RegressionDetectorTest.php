<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\EvaluationResultSummary;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Service\Evaluation\RegressionThresholds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegressionDetector::class)]
final class RegressionDetectorTest extends TestCase
{
    private RegressionDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new RegressionDetector();
    }

    private function summary(float $passRate, float $meanScore): EvaluationResultSummary
    {
        return new EvaluationResultSummary('nr_llm.smoke', 'gpt-test', 'deterministic', 10, (int)round($passRate * 10), $passRate, $meanScore, 1_700_000_000);
    }

    #[Test]
    public function noBaselineIsNotARegression(): void
    {
        $report = $this->detector->compare($this->summary(0.5, 0.5), null, new RegressionThresholds());

        self::assertFalse($report->hasBaseline);
        self::assertFalse($report->isRegression);
        self::assertSame(0.0, $report->passRateDelta);
    }

    #[Test]
    public function largePassRateDropIsARegression(): void
    {
        $report = $this->detector->compare($this->summary(0.5, 0.5), $this->summary(1.0, 0.9), new RegressionThresholds());

        self::assertTrue($report->hasBaseline);
        self::assertTrue($report->isRegression);
        self::assertEqualsWithDelta(-0.5, $report->passRateDelta, 0.0001);
        self::assertStringContainsString('Regression', $report->summary);
    }

    #[Test]
    public function improvementIsNotARegression(): void
    {
        $report = $this->detector->compare($this->summary(1.0, 0.95), $this->summary(0.5, 0.5), new RegressionThresholds());

        self::assertFalse($report->isRegression);
        self::assertEqualsWithDelta(0.5, $report->passRateDelta, 0.0001);
    }

    #[Test]
    public function smallDropWithinToleranceIsStable(): void
    {
        $report = $this->detector->compare($this->summary(0.85, 0.85), $this->summary(0.9, 0.9), new RegressionThresholds());

        self::assertFalse($report->isRegression);
        self::assertStringContainsString('No regression', $report->summary);
    }

    #[Test]
    public function meanScoreDropAloneTriggersRegression(): void
    {
        // Pass rate stable, mean score falls beyond tolerance.
        $report = $this->detector->compare($this->summary(0.9, 0.6), $this->summary(0.9, 0.9), new RegressionThresholds());

        self::assertTrue($report->isRegression);
        self::assertStringContainsString('mean score', $report->summary);
    }

    #[Test]
    public function customThresholdWidensTolerance(): void
    {
        // A 0.3 pass-rate drop is a regression by default but tolerated at 0.5.
        $lenient = new RegressionThresholds(maxPassRateDrop: 0.5, maxMeanScoreDrop: 0.5);
        $report = $this->detector->compare($this->summary(0.6, 0.6), $this->summary(0.9, 0.9), $lenient);

        self::assertFalse($report->isRegression);
    }
}
