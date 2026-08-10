<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Routing;

use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Service\Evaluation\ModelQualityScoreProviderInterface;
use Netresearch\NrLlm\Service\Health\ProviderHealthScore;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;

/**
 * Orders eligible candidates, and says what each number came from (ADR-142).
 *
 * Two levels, in this order, and the order is the whole design:
 *
 * 1. **Provider priority.** An operator's explicit statement about which
 *    provider they want used. No measured signal overrides it — a score is
 *    evidence, a priority is an instruction.
 * 2. **Score**, then the established tiebreaks (cost where asked for, the
 *    default-model flag, the sorting field).
 *
 * Absent data is not bad data. A signal with no measurement for a model is
 * skipped — it contributes neither a value nor its weight — so the score is the
 * weighted mean over what IS known. A model nobody measured therefore lands on
 * the neutral midpoint rather than at zero, the same rule
 * {@see ProviderHealthScore::NEUTRAL_SCORE} states for an unsampled provider.
 *
 * The consequence that matters: in {@see RoutingPolicyMode::PROVIDER_PRIORITY},
 * and in any mode where no signal has data, every candidate scores identically
 * and the decision falls through to the tiebreaks — which is the ordering this
 * extension always applied.
 *
 * @internal
 */
final readonly class CandidateRanker
{
    /**
     * The value a signal contributes when it has no data — the midpoint, so an
     * absence neither promotes nor demotes.
     */
    private const NEUTRAL = 0.5;

    /**
     * Cost at/above which the cost signal is fully penalised, in the same unit
     * `Model::getCostInput()` uses. Beyond it the exact figure stops mattering
     * for ordering: the model is simply expensive.
     */
    private const COST_CEILING = 100;

    public function __construct(
        private ?ModelQualityScoreProviderInterface $qualityScoreProvider = null,
        private ?ProviderHealthServiceInterface $healthService = null,
    ) {}

    /**
     * @param list<Model>                                                                                                                                                $models   eligible models only — a rejected candidate has no score to compute
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int, preferLowestCost?: bool} $criteria
     *
     * @return list<RoutingCandidate> best first
     */
    public function rank(array $models, RoutingPolicyMode $mode, array $criteria): array
    {
        $preferLowestCost = $criteria['preferLowestCost'] ?? false;

        $scored = [];
        foreach (array_values($models) as $order => $model) {
            $signals  = $this->signalsFor($model, $mode, $preferLowestCost);
            $scored[] = [
                'candidate' => RoutingCandidate::eligible($model, $this->score($signals, $mode, $preferLowestCost), $signals),
                'order'     => $order,
            ];
        }

        usort(
            $scored,
            fn(array $a, array $b): int => $this->compare($a, $b, $preferLowestCost),
        );

        return array_map(static fn(array $entry): RoutingCandidate => $entry['candidate'], $scored);
    }

    /**
     * The raw ranking inputs for one model, null where a signal has no data.
     *
     * A mode that does not use measured signals collects none: asking a quality
     * store and a telemetry window per candidate costs queries, and their
     * answers would not be read.
     *
     * @return array<string, float|null>
     */
    private function signalsFor(Model $model, RoutingPolicyMode $mode, bool $preferLowestCost): array
    {
        if (!$mode->usesMeasuredSignals()) {
            return [];
        }

        $signals = [
            'quality' => $this->qualityScoreProvider?->getQualityScore($model->getModelId()),
            'health'  => $this->healthSignal($model),
        ];

        if ($preferLowestCost || $mode->alwaysWeighsCost()) {
            $signals['cost'] = $this->costSignal($model);
        }

        return $signals;
    }

    /**
     * A provider's health as a 0–1 signal, or null when it has no samples in
     * the window. Null rather than the service's neutral score, so "never
     * called" is visible as an absence in the decision trace instead of being
     * indistinguishable from a measured mid-range provider.
     */
    private function healthSignal(Model $model): ?float
    {
        $identifier = $model->getProvider()?->getIdentifier();
        if (!$this->healthService instanceof ProviderHealthServiceInterface || $identifier === null || $identifier === '') {
            return null;
        }

        $score = $this->healthService->scoreFor($identifier);

        return $score->sampleCount > 0 ? $score->score : null;
    }

    /**
     * Combined input+output cost as a 0–1 signal where HIGHER IS BETTER, so it
     * composes with the other signals without a sign flip at the call site.
     *
     * Unknown cost (0) yields null, not a perfect score: an unpriced model is
     * usually a local one, and letting "we do not know" win a cost comparison
     * outright would make the cheapest model the one nobody measured.
     */
    private function costSignal(Model $model): ?float
    {
        $cost = $model->getCostInput() + $model->getCostOutput();
        if ($cost <= 0) {
            return null;
        }

        return 1.0 - min(1.0, $cost / self::COST_CEILING);
    }

    /**
     * Weighted mean over the signals that have data, so an absent signal
     * neither contributes nor dilutes. With no data at all every candidate
     * scores {@see self::NEUTRAL} and the tiebreaks decide.
     *
     * @param array<string, float|null> $signals
     */
    private function score(array $signals, RoutingPolicyMode $mode, bool $preferLowestCost): float
    {
        $weights = [
            'quality' => $mode->qualityWeight(),
            'health'  => $mode->healthWeight(),
            'cost'    => $preferLowestCost || $mode->alwaysWeighsCost() ? $mode->costWeight() : 0.0,
        ];

        $weighted   = 0.0;
        $weightSum  = 0.0;
        foreach ($weights as $signal => $weight) {
            $value = $signals[$signal] ?? null;
            if ($weight <= 0.0 || $value === null) {
                continue;
            }

            $weighted  += $weight * $value;
            $weightSum += $weight;
        }

        return $weightSum > 0.0 ? $weighted / $weightSum : self::NEUTRAL;
    }

    /**
     * Provider priority first, then score, then the established tiebreaks.
     *
     * @param array{candidate: RoutingCandidate, order: int} $a
     * @param array{candidate: RoutingCandidate, order: int} $b
     */
    private function compare(array $a, array $b, bool $preferLowestCost): int
    {
        $modelA = $a['candidate']->model;
        $modelB = $b['candidate']->model;

        // An operator's explicit provider ordering outranks every measurement.
        $byPriority = ($modelB->getProvider()?->getPriority() ?? 0) <=> ($modelA->getProvider()?->getPriority() ?? 0);
        if ($byPriority !== 0) {
            return $byPriority;
        }

        $byScore = ($b['candidate']->score ?? 0.0) <=> ($a['candidate']->score ?? 0.0);
        if ($byScore !== 0) {
            return $byScore;
        }

        if ($preferLowestCost) {
            $byCost = $this->compareByCost($modelA, $modelB);
            if ($byCost !== 0) {
                return $byCost;
            }
        }

        return $this->compareByDefaultThenSorting($modelA, $modelB);
    }

    /**
     * Compare by combined input/output cost, lower first. Unknown cost (0)
     * sorts last: it must not win a cost comparison by not being measured.
     */
    private function compareByCost(Model $a, Model $b): int
    {
        $costA = $a->getCostInput() + $a->getCostOutput();
        $costB = $b->getCostInput() + $b->getCostOutput();
        if ($costA === 0) {
            $costA = PHP_INT_MAX;
        }

        if ($costB === 0) {
            $costB = PHP_INT_MAX;
        }

        return $costA <=> $costB;
    }

    /**
     * Compare by the default-model flag, then the explicit sorting order.
     *
     * `ModelRepository` pre-orders by `sorting, name`, so this yields a
     * deterministic result without relying on usort() preserving input order.
     */
    private function compareByDefaultThenSorting(Model $a, Model $b): int
    {
        if ($a->isDefault() !== $b->isDefault()) {
            return $a->isDefault() ? -1 : 1;
        }

        return $a->getSorting() <=> $b->getSorting();
    }
}
