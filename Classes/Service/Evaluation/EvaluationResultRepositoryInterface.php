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
     * The most recent stored summary for a (set, model), or null if none.
     */
    public function findLatest(string $setIdentifier, string $model): ?EvaluationResultSummary;

    /**
     * The most recent stored summaries for a (set, model), newest first.
     *
     * @return list<EvaluationResultSummary>
     */
    public function findRecent(string $setIdentifier, string $model, int $limit): array;

    /**
     * Mean quality score for a model across its golden sets (0.0-1.0), or
     * null when the model has no stored results.
     *
     * Averages the latest run per set for the model, so one heavily-evaluated
     * set does not dominate and stale runs are ignored.
     */
    public function meanQualityScoreForModel(string $model): ?float;
}
