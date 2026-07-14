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
 * The opt-in quality dimension for model routing (ADR-060).
 *
 * This is a documented HOOK, not a change to ModelSelectionService: it takes
 * that service's existing candidate list for a criteria set and re-ranks it
 * by measured quality score. Nothing calls it unless a consumer explicitly
 * routes through it, so the established cost/latency selection modes are
 * untouched. Wiring quality into ModelSelectionService as a first-class sort
 * key is a deliberate follow-up (see ADR-060).
 *
 * Ranking: candidates with a quality score sort highest-first; candidates
 * without a score keep their original (base-selection) order behind the
 * scored ones. With no scores at all and no minimum, this degrades exactly
 * to the base selection's first candidate.
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
     * @param array{capabilities?: string[], adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     * @param float                                                                                                                        $minQuality Minimum acceptable quality (0.0 disables the filter); candidates below it,
     *                                                                                                                                                 or without any quality data, are excluded when this is greater than 0.0
     */
    public function selectByQuality(array $criteria, float $minQuality = 0.0): ?Model
    {
        $candidates = $this->modelSelectionService->findCandidates($criteria);
        if ($candidates === []) {
            return null;
        }

        $ranked = [];
        foreach (array_values($candidates) as $order => $model) {
            $score = $this->qualityScoreProvider->getQualityScore($model->getModelId());
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
