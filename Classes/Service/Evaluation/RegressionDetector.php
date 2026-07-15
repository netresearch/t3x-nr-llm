<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * Compares a current set result against the previous run for the same
 * (set, model) and flags a regression when a metric falls beyond the
 * configured tolerance (ADR-060).
 *
 * Pure logic: no persistence, no side effects — the caller supplies the
 * previous summary (or null for the first run).
 */
final readonly class RegressionDetector
{
    public function compare(
        EvaluationResultSummary $current,
        ?EvaluationResultSummary $previous,
        RegressionThresholds $thresholds,
    ): RegressionReport {
        if ($previous === null) {
            return new RegressionReport(
                $current->setIdentifier,
                $current->model,
                false,
                0.0,
                0.0,
                false,
                'No previous run for this set and model — recorded as the baseline.',
            );
        }

        $passRateDelta = $current->passRate - $previous->passRate;
        $meanScoreDelta = $current->meanScore - $previous->meanScore;

        $passRateRegressed = -$passRateDelta > $thresholds->maxPassRateDrop;
        $meanScoreRegressed = -$meanScoreDelta > $thresholds->maxMeanScoreDrop;
        $isRegression = $passRateRegressed || $meanScoreRegressed;

        return new RegressionReport(
            $current->setIdentifier,
            $current->model,
            true,
            $passRateDelta,
            $meanScoreDelta,
            $isRegression,
            $this->summarise($passRateDelta, $meanScoreDelta, $passRateRegressed, $meanScoreRegressed),
        );
    }

    private function summarise(
        float $passRateDelta,
        float $meanScoreDelta,
        bool $passRateRegressed,
        bool $meanScoreRegressed,
    ): string {
        if (!$passRateRegressed && !$meanScoreRegressed) {
            return sprintf(
                'No regression: pass rate %+.1f pp, mean score %+.3f versus the previous run.',
                $passRateDelta * 100,
                $meanScoreDelta,
            );
        }

        $causes = [];
        if ($passRateRegressed) {
            $causes[] = sprintf('pass rate dropped %.1f pp', -$passRateDelta * 100);
        }
        if ($meanScoreRegressed) {
            $causes[] = sprintf('mean score dropped %.3f', -$meanScoreDelta);
        }

        return 'Regression: ' . implode(' and ', $causes) . ' beyond the configured tolerance.';
    }
}
