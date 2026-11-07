<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The default ModelQualityScoreProvider: reads a model's quality score from
 * stored evaluation results (ADR-060).
 *
 * A thin adapter over EvaluationResultRepository so routing depends only on
 * the quality-score interface, not on the persistence layer.
 */
final readonly class EvaluationQualityScoreProvider implements ProviderModelQualityScoreProviderInterface
{
    public function __construct(
        private EvaluationResultRepositoryInterface $repository,
        private ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    public function getQualityScore(string $modelId): ?float
    {
        return $this->score($modelId, null);
    }

    public function getQualityScoreForProviderModel(
        string $providerId,
        string $modelId,
    ): ?float {
        return $providerId !== '' ? $this->score($modelId, $providerId) : null;
    }

    private function score(string $modelId, ?string $providerId): ?float
    {
        if ($modelId === '' || !$this->repository instanceof BenchmarkEvaluationResultRepositoryInterface) {
            return null;
        }

        try {
            $config = $this->extensionConfiguration?->get('nr_llm');
        } catch (Throwable) {
            return null;
        }

        $routing     = is_array($config) && is_array($config['routing'] ?? null) ? $config['routing'] : [];
        $set         = $routing['qualitySet'] ?? null;
        $fingerprint = $routing['qualityFingerprint'] ?? null;
        if (!is_string($set) || $set === '' || !is_string($fingerprint) || !CompletionBenchmarkIdentity::isKnown($fingerprint)) {
            return null;
        }

        return $this->repository->qualityScoreForBenchmark(
            $set,
            $fingerprint,
            $modelId,
            DeterministicGrader::IDENTIFIER,
            $providerId,
        );
    }
}
