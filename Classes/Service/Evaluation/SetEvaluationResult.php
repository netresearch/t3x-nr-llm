<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;

/**
 * The rich result of running a golden set against one model (ADR-060):
 * every per-prompt evaluation plus the aggregate pass rate and mean score
 * derived from them.
 *
 * `toSummary()` collapses it to the aggregate-only EvaluationResultSummary
 * that gets persisted and compared for regression detection.
 */
final readonly class SetEvaluationResult
{
    /**
     * @param list<PromptEvaluation> $evaluations
     */
    public function __construct(
        public string $setIdentifier,
        public string $model,
        public string $grader,
        public array $evaluations,
        public int $runTimestamp,
        public ?RetrievalSetEvaluationResult $retrieval = null,
        public ?GeneratorProvenance $generatorProvenance = null,
    ) {}

    /**
     * Whether every grading reports the grader the run is stored under.
     * A failed decision or changed grading model has no single yardstick (ADR-211).
     * Generator identity is checked separately: different generating models can
     * legitimately share this grading protocol (ADR-220).
     */
    public function sharesOneYardstick(): bool
    {
        foreach ($this->evaluations as $evaluation) {
            if ($evaluation->result->grader !== $this->grader) {
                return false;
            }
        }

        return true;
    }

    public function promptCount(): int
    {
        return count($this->evaluations);
    }

    public function passedCount(): int
    {
        $passed = 0;
        foreach ($this->evaluations as $evaluation) {
            if ($evaluation->result->passed) {
                ++$passed;
            }
        }

        return $passed;
    }

    /**
     * Fraction of prompts that passed (0.0-1.0); 0.0 for an empty set.
     */
    public function passRate(): float
    {
        if ($this->evaluations === []) {
            return 0.0;
        }

        return $this->passedCount() / $this->promptCount();
    }

    /**
     * Mean grading score across all prompts (0.0-1.0); 0.0 for an empty set.
     */
    public function meanScore(): float
    {
        if ($this->evaluations === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($this->evaluations as $evaluation) {
            $sum += $evaluation->result->score;
        }

        return $sum / $this->promptCount();
    }

    public function toSummary(int $uid = 0): EvaluationResultSummary
    {
        return new EvaluationResultSummary(
            $this->setIdentifier,
            $this->model,
            $this->grader,
            $this->promptCount(),
            $this->passedCount(),
            $this->passRate(),
            $this->meanScore(),
            $this->runTimestamp,
            $uid,
            $this->retrieval->identity->benchmarkFingerprint ?? '',
            $this->retrieval->identity->variantFingerprint ?? '',
            $this->retrieval?->identity?->provenance,
            $this->verifiedGeneratorProvenance(),
        );
    }

    /**
     * Evidence for every prompt, checked again before persistence (ADR-220).
     */
    public function verifiedGeneratorProvenance(): ?GeneratorProvenance
    {
        $record = $this->generatorProvenance;
        if (!$record instanceof GeneratorProvenance || $this->evaluations === [] || $this->model !== $record->modelId) {
            return null;
        }

        foreach ($this->evaluations as $evaluation) {
            if (!$evaluation->generatorProvenance instanceof GeneratorProvenance || !$record->sameGenerator($evaluation->generatorProvenance)) {
                return null;
            }
        }

        return $record;
    }
}
