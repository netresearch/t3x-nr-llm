<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * The tolerances that separate normal run-to-run variance from a regression
 * (ADR-060).
 *
 * A drop is a regression only when it exceeds the threshold: a pass-rate
 * fall greater than `maxPassRateDrop` or a mean-score fall greater than
 * `maxMeanScoreDrop`. Both are absolute deltas on the 0.0-1.0 scale and
 * default to 0.1.
 */
final readonly class RegressionThresholds
{
    public function __construct(
        public float $maxPassRateDrop = 0.1,
        public float $maxMeanScoreDrop = 0.1,
    ) {
        if ($maxPassRateDrop < 0.0 || $maxMeanScoreDrop < 0.0) {
            throw new InvalidArgumentException('Regression thresholds must be non-negative.', 1794000040);
        }
    }
}
