<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * Persistence contract for evaluation run results (ADR-060).
 *
 * Stores aggregate summaries in `tx_nrllm_eval_result` and exposes the
 * reads regression detection and quality-aware routing need. Extracted as
 * an interface so the eval command and quality-score provider can be tested
 * against an in-memory double without a database.
 */
interface EvaluationResultRepositoryInterface
{
    /**
     * Persist one set run as a new result row.
     */
    public function save(SetEvaluationResult $result): void;

    /**
     * The most recent stored summary for a (set, model, grader), or null if
     * none. The grader is part of the key: a pass rate / mean score only
     * means the same thing when compared against a run graded the same way.
     */
    public function findLatest(string $setIdentifier, string $model, string $grader): ?EvaluationResultSummary;

    /**
     * The most recent stored summaries for a (set, model, grader), newest
     * first.
     *
     * @return list<EvaluationResultSummary>
     */
    public function findRecent(string $setIdentifier, string $model, string $grader, int $limit): array;

    /**
     * Mean quality score for a model across its golden sets (0.0-1.0) for a
     * single grader, or null when the model has no stored results for it.
     *
     * Averages the latest run per set for the model, so one heavily-evaluated
     * set does not dominate and stale runs are ignored. Scoped to one grader
     * because deterministic assertion fractions and LLM-judge scores are not
     * comparable and must not be averaged together.
     */
    public function meanQualityScoreForModel(string $model, string $grader): ?float;

    /**
     * Delete result rows whose run date is strictly before the given UNIX
     * timestamp. The central retention path (ADR-064), driven by
     * `nrllm:privacy:purge`.
     *
     * @return int number of rows deleted
     */
    public function purgeOlderThan(int $timestamp): int;
}
