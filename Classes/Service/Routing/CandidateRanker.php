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
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Evaluation\ModelQualityScoreProviderInterface;
use Netresearch\NrLlm\Service\Evaluation\ProviderModelQualityScoreProviderInterface;
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
 * The call history (ADR-256) adds two signals where the operator switched it on
 * and the operation is known: `latency`, and `callCost` — the measured cost per
 * call, which then replaces the list-price `cost` signal for the whole decision.
 * Both are relative to the other measured candidates, centred on the neutral
 * 0.5 ({@see CallHistoryRanking}), because no fixed ceiling fits both a 300 ms
 * embedding and a 30 s tool turn.
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
     * The signals scored RELATIVE to the other candidates of the decision
     * (ADR-256). For these an absence counts as {@see self::NEUTRAL} rather
     * than being skipped: their 0.5 is the average of the measured candidates,
     * and skipping it would let an unmeasured model keep its health-only score
     * while every measured one is pulled toward 0.5 — an unmeasured model would
     * outrank a measured average one. An absolute signal (quality, health,
     * list price) has no such midpoint and is skipped as before.
     */
    private const RELATIVE_SIGNALS = ['latency' => true, 'callCost' => true];

    /**
     * Cost at/above which the cost signal is fully penalised, in the same unit
     * `Model::getCostInput()` uses. Beyond it the exact figure stops mattering
     * for ordering: the model is simply expensive.
     */
    private const COST_CEILING = 100;

    public function __construct(
        private ?ModelQualityScoreProviderInterface $qualityScoreProvider = null,
        private ?ProviderHealthServiceInterface $healthService = null,
        private ?ModelCallHistoryInterface $callHistory = null,
    ) {}

    /**
     * @param list<Model>                                                                                                                                                      $models    eligible models only — a rejected candidate has no score to compute
     * @param array{capabilities?: string[], operationCapability?: string, adapterTypes?: string[], minContextLength?: int, maxCostInput?: int|float, preferLowestCost?: bool} $criteria
     * @param ?ProviderOperation                                                                                                                                               $operation the operation the call runs; the call history is per operation, so without one it is not read
     *
     * @return list<RoutingCandidate> best first
     */
    public function rank(array $models, RoutingPolicyMode $mode, array $criteria, ?ProviderOperation $operation = null): array
    {
        $preferLowestCost = $criteria['preferLowestCost'] ?? false;
        $models           = array_values($models);
        $history          = $this->historyFor($models, $mode, $operation);

        $scored = [];
        foreach ($models as $order => $model) {
            $signals  = $this->signalsFor($model, $mode, $preferLowestCost, $history?->forModel($order));
            $scored[] = [
                'candidate' => RoutingCandidate::eligible($model, $this->score($signals, $mode, $preferLowestCost, $history instanceof CallHistoryRanking), $signals),
                'order'     => $order,
            ];
        }

        usort(
            $scored,
            fn (array $a, array $b): int => $this->compare($a, $b, $preferLowestCost),
        );

        return array_map(static fn (array $entry): RoutingCandidate => $entry['candidate'], $scored);
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
    private function signalsFor(Model $model, RoutingPolicyMode $mode, bool $preferLowestCost, ?CallHistorySignals $history): array
    {
        if (!$mode->usesMeasuredSignals()) {
            return [];
        }

        $signals = [
            'quality' => $this->qualitySignal($model),
            'health'  => $this->healthSignal($model),
        ];

        if ($history instanceof CallHistorySignals && $history->latencyIsMeasured) {
            $signals['latency'] = $history->latency;
        }

        if ($preferLowestCost || $mode->alwaysWeighsCost()) {
            if ($history instanceof CallHistorySignals && $history->costIsMeasured) {
                $signals['callCost'] = $history->callCost;
            } else {
                $signals['cost'] = $this->costSignal($model);
            }
        }

        return $signals;
    }

    /**
     * The call history of every candidate, or null where it is not read: the
     * operator has not switched it on, the mode weighs no measured signal, or
     * the operation is unknown — latency and cost per call differ by an order
     * of magnitude between a chat answer and a tool turn of the same model, so
     * a figure across operations would compare the wrong things.
     *
     * @param list<Model> $models
     */
    private function historyFor(array $models, RoutingPolicyMode $mode, ?ProviderOperation $operation): ?CallHistoryRanking
    {
        if (!$operation instanceof ProviderOperation
            || !$mode->usesMeasuredSignals()
            || !$this->callHistory instanceof ModelCallHistoryInterface
            || !$this->callHistory->enabled()
        ) {
            return null;
        }

        $history = $this->callHistory->all();
        $stats   = [];
        foreach ($models as $order => $model) {
            $stats[$order] = $history[ModelCallStats::key(
                $model->getProvider()?->getAdapterType() ?? '',
                $model->getModelId(),
                $operation->value,
            )] ?? null;
        }

        return CallHistoryRanking::fromStats($models, $stats);
    }

    /**
     * A provider's health as a 0–1 signal, or null when it has no samples in
     * the window. Null rather than the service's neutral score, so "never
     * called" is visible as an absence in the decision trace instead of being
     * indistinguishable from a measured mid-range provider.
     */
    private function healthSignal(Model $model): ?float
    {
        // By adapter type: that is the name telemetry records a provider under
        // (ProviderCallContext::telemetryProvider()), and so the only name the
        // health window knows. The record's identifier found nothing wherever
        // the two differ.
        $adapterType = $model->getProvider()?->getAdapterType();
        if (!$this->healthService instanceof ProviderHealthServiceInterface || $adapterType === null || $adapterType === '') {
            return null;
        }

        $score = $this->healthService->scoreFor($adapterType);

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
    private function score(array $signals, RoutingPolicyMode $mode, bool $preferLowestCost, bool $historyIsRead = false): float
    {
        $costWeight = $preferLowestCost || $mode->alwaysWeighsCost() ? $mode->costWeight() : 0.0;
        $weights    = [
            'quality' => $mode->qualityWeight(),
            'health'  => $mode->healthWeight(),
            'latency' => $mode->latencyWeight(),
            'cost'    => $costWeight,
            // The measured basis of the same signal: at most one of the two
            // is collected per decision, so they never add up.
            'callCost' => $costWeight,
        ];

        $weighted  = 0.0;
        $weightSum = 0.0;
        foreach ($weights as $signal => $weight) {
            $value = $signals[$signal] ?? null;
            if ($weight <= 0.0) {
                continue;
            }

            if ($value === null && array_key_exists($signal, $signals)
                && (isset(self::RELATIVE_SIGNALS[$signal]) || ($signal === 'cost' && $historyIsRead))
            ) {
                // Collected for this decision, absent for this candidate. With
                // the history read, an unpriced model counts as average on the
                // list-price basis too: it must score the same on cost whichever
                // basis the decision uses, and over the same set of signals as
                // its rivals, or the latency it adds would weigh for it and
                // against it by a different share than for theirs (ADR-256).
                $value = self::NEUTRAL;
            }

            if ($value === null) {
                continue;
            }

            $weighted += $weight * $value;
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
            // On the measured basis the tiebreak reads the same cost the score
            // did; on the list-price basis, the list price as always.
            $byCost = array_key_exists('callCost', $a['candidate']->signals)
                ? ($b['candidate']->signals['callCost'] ?? self::NEUTRAL) <=> ($a['candidate']->signals['callCost'] ?? self::NEUTRAL)
                : $this->compareByCost($modelA, $modelB);
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
        // Prices are decimal (ADR-211): compare against 0.0, or an unpriced
        // model would sort as the cheapest instead of last.
        if ($costA <= 0.0) {
            $costA = PHP_FLOAT_MAX;
        }

        if ($costB <= 0.0) {
            $costB = PHP_FLOAT_MAX;
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

    private function qualitySignal(Model $model): ?float
    {
        if ($this->qualityScoreProvider instanceof ProviderModelQualityScoreProviderInterface) {
            return $this->qualityScoreProvider->getQualityScoreForProviderModel(
                $model->getProvider()?->getIdentifier() ?? '',
                $model->getModelId(),
            );
        }

        return $this->qualityScoreProvider?->getQualityScore($model->getModelId());
    }
}
