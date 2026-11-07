<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * Optional revision-specific completion evidence reads (ADR-271). @internal.
 */
interface BenchmarkEvaluationResultRepositoryInterface extends GeneratorEvaluationResultRepositoryInterface
{
    public function findLatestForBenchmark(
        string $setIdentifier,
        string $benchmarkFingerprint,
        string $providerId,
        string $modelId,
        string $reportedModelId,
        string $grader,
    ): ?EvaluationResultSummary;

    public function qualityScoreForBenchmark(
        string $setIdentifier,
        string $benchmarkFingerprint,
        string $modelId,
        string $grader,
        ?string $providerId = null,
    ): ?float;
}
