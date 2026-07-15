<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * The outcome of grading one response against one golden prompt (ADR-060).
 *
 * `score` is normalised to 0.0-1.0. `passed` is the pass/fail verdict a
 * grader derives from that score (all assertions satisfied for the
 * deterministic grader; score above the judge's threshold for the LLM
 * judge). `grader` records which strategy produced the verdict and `reason`
 * carries a short human-readable justification for run output and audit.
 */
final readonly class GradingResult
{
    public function __construct(
        public bool $passed,
        public float $score,
        public string $grader,
        public string $reason = '',
    ) {}
}
