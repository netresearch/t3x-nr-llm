<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * The persisted, aggregate-only view of a set evaluation run (ADR-060).
 *
 * This is what the eval_result table stores and what regression detection
 * compares: pass rate and mean score for one (set, model) at a point in
 * time, without the per-prompt detail. `uid` is 0 for a summary that has not
 * been read back from the database.
 */
final readonly class EvaluationResultSummary
{
    public function __construct(
        public string $setIdentifier,
        public string $model,
        public string $grader,
        public int $promptCount,
        public int $passedCount,
        public float $passRate,
        public float $meanScore,
        public int $runTimestamp,
        public int $uid = 0,
    ) {}
}
