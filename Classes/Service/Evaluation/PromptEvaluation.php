<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;

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
        public ?GeneratorProvenance $generatorProvenance = null,
        public string $reportedAdapterKey = '',
    ) {}

    /**
     * @return array{promptId: string, passed: bool, score: float, grader: string, reason: string, latencyMs: int, generatorProvenance: array{version: 1, providerIdentifier: string, modelId: string, reportedModelId: string}|null, reportedAdapterKey: string}
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
            'generatorProvenance' => $this->generatorProvenance?->toArray(),
            'reportedAdapterKey' => $this->reportedAdapterKey,
        ];
    }
}
