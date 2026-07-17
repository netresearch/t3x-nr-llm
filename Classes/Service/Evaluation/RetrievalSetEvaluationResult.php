<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * The rich result of running a golden question set against one retriever
 * (ADR-072): every per-question evaluation plus the aggregate top-1/top-3
 * hit rates and their by-form and by-hard-class breakdowns — the retrieval
 * counterpart of {@see SetEvaluationResult}.
 *
 * `toSetEvaluationResult()` maps the run onto the existing persistence and
 * regression machinery (ADR-060): the retriever identifier takes the model
 * column, the top-1 hit verdict becomes the per-question pass, and the
 * top-3 hit becomes the per-question score — so the stored `passRate` is
 * the top-1 hit rate and the stored `meanScore` is the top-3 hit rate.
 */
final readonly class RetrievalSetEvaluationResult
{
    /**
     * The grader identifier retrieval runs are stored under. It scopes the
     * (set, model, grader) persistence key so retrieval hit rates are never
     * compared against prompt-grading scores.
     */
    public const GRADER_IDENTIFIER = 'retrieval_hit_rate';

    /**
     * @param list<QuestionEvaluation> $evaluations
     */
    public function __construct(
        public string $setIdentifier,
        public string $retriever,
        public array $evaluations,
        public int $runTimestamp,
    ) {}

    public function questionCount(): int
    {
        return count($this->evaluations);
    }

    public function top1HitCount(): int
    {
        return $this->countHits($this->evaluations, static fn(QuestionEvaluation $evaluation): bool => $evaluation->top1Hit);
    }

    public function top3HitCount(): int
    {
        return $this->countHits($this->evaluations, static fn(QuestionEvaluation $evaluation): bool => $evaluation->top3Hit);
    }

    /**
     * Fraction of questions whose top-ranked document is a target
     * (0.0-1.0); 0.0 for an empty set.
     */
    public function top1HitRate(): float
    {
        return $this->rate($this->top1HitCount());
    }

    /**
     * Fraction of questions with any target in the top three documents
     * (0.0-1.0); 0.0 for an empty set.
     */
    public function top3HitRate(): float
    {
        return $this->rate($this->top3HitCount());
    }

    /**
     * Hit rates split by question form (`match` / `gap`), the primary
     * reporting split of the methodology. Only forms that occur in the set
     * appear.
     *
     * @return array<string, array{questions: int, top1HitRate: float, top3HitRate: float}>
     */
    public function hitRatesByForm(): array
    {
        return $this->breakdown(static fn(QuestionEvaluation $evaluation): string => $evaluation->form->value);
    }

    /**
     * Hit rates split by hard class, the secondary reporting split.
     * Questions without a hard class are not part of the breakdown.
     *
     * @return array<string, array{questions: int, top1HitRate: float, top3HitRate: float}>
     */
    public function hitRatesByHardClass(): array
    {
        return $this->breakdown(static fn(QuestionEvaluation $evaluation): ?string => $evaluation->hardClass);
    }

    /**
     * Map the run onto the ADR-060 result model so the existing repository
     * and RegressionDetector can persist and compare it: passed = top-1
     * hit, score = 1.0 for a top-3 hit else 0.0, model = retriever
     * identifier, grader = {@see self::GRADER_IDENTIFIER}.
     */
    public function toSetEvaluationResult(): SetEvaluationResult
    {
        $evaluations = [];
        foreach ($this->evaluations as $evaluation) {
            $evaluations[] = new PromptEvaluation(
                $evaluation->questionId,
                new GradingResult(
                    $evaluation->top1Hit,
                    $evaluation->top3Hit ? 1.0 : 0.0,
                    self::GRADER_IDENTIFIER,
                    sprintf(
                        'top-1 %s, top-3 %s',
                        $evaluation->top1Hit ? 'hit' : 'miss',
                        $evaluation->top3Hit ? 'hit' : 'miss',
                    ),
                ),
                $evaluation->latencyMs,
            );
        }

        return new SetEvaluationResult(
            $this->setIdentifier,
            $this->retriever,
            self::GRADER_IDENTIFIER,
            $evaluations,
            $this->runTimestamp,
        );
    }

    /**
     * @param list<QuestionEvaluation>           $evaluations
     * @param callable(QuestionEvaluation): bool $isHit
     */
    private function countHits(array $evaluations, callable $isHit): int
    {
        $hits = 0;
        foreach ($evaluations as $evaluation) {
            if ($isHit($evaluation)) {
                ++$hits;
            }
        }

        return $hits;
    }

    private function rate(int $hits): float
    {
        if ($this->evaluations === []) {
            return 0.0;
        }

        return (float)$hits / $this->questionCount();
    }

    /**
     * @param callable(QuestionEvaluation): (string|null) $group Returns the group key, or null to exclude the question
     *
     * @return array<string, array{questions: int, top1HitRate: float, top3HitRate: float}>
     */
    private function breakdown(callable $group): array
    {
        /** @var array<string, list<QuestionEvaluation>> $grouped */
        $grouped = [];
        foreach ($this->evaluations as $evaluation) {
            $key = $group($evaluation);
            if ($key === null) {
                continue;
            }
            $grouped[$key][] = $evaluation;
        }

        $result = [];
        foreach ($grouped as $key => $evaluations) {
            $count = count($evaluations);
            $result[$key] = [
                'questions' => $count,
                'top1HitRate' => (float)$this->countHits($evaluations, static fn(QuestionEvaluation $evaluation): bool => $evaluation->top1Hit) / $count,
                'top3HitRate' => (float)$this->countHits($evaluations, static fn(QuestionEvaluation $evaluation): bool => $evaluation->top3Hit) / $count,
            ];
        }

        return $result;
    }
}
