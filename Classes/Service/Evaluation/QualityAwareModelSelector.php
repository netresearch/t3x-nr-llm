<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;

/**
 * Consumer-facing quality ordering and minimum-quality filter (ADR-060, ADR-220).
 * Core ModelSelectionService already uses measured quality under the opt-in
 * policy modes from ADR-142. This explicit selector additionally filters by a
 * minimum score and orders by quality alone, preserving base order for ties.
 * Verified core scores use the configured provider instance and model alias;
 * custom providers without that capability keep their model-ID contract.
 */
final readonly class QualityAwareModelSelector
{
    public function __construct(
        private ModelSelectionServiceInterface $modelSelectionService,
        private ModelQualityScoreProviderInterface $qualityScoreProvider,
    ) {}

    /**
     * Select the highest-quality model among those matching the criteria.
     *
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int|float, preferLowestCost?: bool} $criteria
     * @param float                                                                                                                              $minQuality Minimum acceptable quality (0.0 disables the filter); candidates below it,
     *                                                                                                                                                       or without any quality data, are excluded when this is greater than 0.0
     */
    public function selectByQuality(array $criteria, float $minQuality = 0.0): ?Model
    {
        $candidates = $this->modelSelectionService->findCandidates($criteria);
        if ($candidates === []) {
            return null;
        }

        $ranked = [];
        foreach (array_values($candidates) as $order => $model) {
            $score = $this->qualityScoreProvider instanceof ProviderModelQualityScoreProviderInterface ? $this->qualityScoreProvider->getQualityScoreForProviderModel(
                $model->getProvider()?->getIdentifier() ?? '',
                $model->getModelId(),
            ) : $this->qualityScoreProvider->getQualityScore($model->getModelId());
            if ($minQuality > 0.0 && ($score === null || $score < $minQuality)) {
                continue;
            }

            $ranked[] = ['model' => $model, 'score' => $score, 'order' => $order];
        }

        if ($ranked === []) {
            return null;
        }

        usort($ranked, static function (array $a, array $b): int {
            // Scored candidates outrank unscored ones; among scored, higher first.
            if ($a['score'] === null && $b['score'] === null) {
                return $a['order'] <=> $b['order'];
            }

            if ($a['score'] === null) {
                return 1;
            }

            if ($b['score'] === null) {
                return -1;
            }

            if ($a['score'] === $b['score']) {
                return $a['order'] <=> $b['order'];
            }

            return $b['score'] <=> $a['score'];
        });

        return $ranked[0]['model'];
    }
}
