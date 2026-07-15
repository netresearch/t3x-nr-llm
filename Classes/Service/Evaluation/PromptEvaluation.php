<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * The evaluation of one golden prompt within a run (ADR-060): the grading
 * verdict plus the wall-clock latency of the model call that produced the
 * graded response.
 */
final readonly class PromptEvaluation
{
    public function __construct(
        public string $promptId,
        public GradingResult $result,
        public int $latencyMs,
    ) {}

    /**
     * @return array{promptId: string, passed: bool, score: float, grader: string, reason: string, latencyMs: int}
     */
    public function toArray(): array
    {
        return [
            'promptId' => $this->promptId,
            'passed' => $this->result->passed,
            'score' => $this->result->score,
            'grader' => $this->result->grader,
            'reason' => $this->result->reason,
            'latencyMs' => $this->latencyMs,
        ];
    }
}
