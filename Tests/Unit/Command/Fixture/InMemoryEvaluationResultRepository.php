<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command\Fixture;

use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultSummary;
use Netresearch\NrLlm\Service\Evaluation\SetEvaluationResult;

/**
 * In-memory EvaluationResultRepository for command tests: records what was
 * saved and returns pre-seeded baselines, so the command's persistence and
 * regression flow can be exercised without a database.
 */
final class InMemoryEvaluationResultRepository implements EvaluationResultRepositoryInterface
{
    /** @var list<SetEvaluationResult> */
    public array $saved = [];

    /** @var array<string, EvaluationResultSummary> */
    public array $seeded = [];

    public function seed(EvaluationResultSummary $summary): void
    {
        $this->seeded[$summary->setIdentifier . '|' . $summary->model] = $summary;
    }

    public function save(SetEvaluationResult $result): void
    {
        $this->saved[] = $result;
    }

    public function findLatest(string $setIdentifier, string $model): ?EvaluationResultSummary
    {
        return $this->seeded[$setIdentifier . '|' . $model] ?? null;
    }

    public function findRecent(string $setIdentifier, string $model, int $limit): array
    {
        $latest = $this->findLatest($setIdentifier, $model);

        return $latest === null ? [] : [$latest];
    }

    public function meanQualityScoreForModel(string $model): ?float
    {
        return null;
    }
}
