<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * Optional verified-generator persistence capability (ADR-220). @internal.
 */
interface GeneratorEvaluationResultRepositoryInterface extends EvaluationResultRepositoryInterface
{
    public function meanQualityScoreForProviderModel(
        string $providerId,
        string $modelId,
        string $grader,
    ): ?float;

    public function findLatestForGenerator(
        string $setIdentifier,
        string $providerId,
        string $modelId,
        string $reportedModelId,
        string $grader,
    ): ?EvaluationResultSummary;
}
