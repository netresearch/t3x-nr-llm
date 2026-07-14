<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * The default ModelQualityScoreProvider: reads a model's quality score from
 * stored evaluation results (ADR-060).
 *
 * A thin adapter over EvaluationResultRepository so routing depends only on
 * the quality-score interface, not on the persistence layer.
 */
final readonly class EvaluationQualityScoreProvider implements ModelQualityScoreProviderInterface
{
    public function __construct(
        private EvaluationResultRepositoryInterface $repository,
    ) {}

    public function getQualityScore(string $modelId): ?float
    {
        if ($modelId === '') {
            return null;
        }

        return $this->repository->meanQualityScoreForModel($modelId);
    }
}
