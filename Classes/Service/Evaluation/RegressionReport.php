<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * The verdict of comparing a run against its predecessor for one
 * (set, model) (ADR-060).
 *
 * The deltas are current minus previous, so a negative value is a decline.
 * `hasBaseline` is false for the first ever run of a (set, model), in which
 * case there is nothing to compare and `isRegression` is false.
 */
final readonly class RegressionReport
{
    public function __construct(
        public string $setIdentifier,
        public string $model,
        public bool $hasBaseline,
        public float $passRateDelta,
        public float $meanScoreDelta,
        public bool $isRegression,
        public string $summary,
    ) {}
}
