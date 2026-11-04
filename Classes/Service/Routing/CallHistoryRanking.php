<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Routing;

use Netresearch\NrLlm\Domain\Model\Model;

/**
 * The call-history signals of one decision's candidates (ADR-256).
 *
 * Both signals are RELATIVE to the measured candidates of this decision:
 * g / (g + x), where x is the candidate's figure and g the geometric mean of
 * the measured figures. A candidate at the mean scores 0.5, and an unmeasured
 * candidate of the same decision is scored as 0.5 too
 * ({@see CandidateRanker::score()}) — so a model is promoted only for being
 * faster or cheaper than the others, and neither promoted nor demoted for
 * merely having been measured: a sole measured candidate scores exactly 0.5. Twice as fast as the mean is
 * 0.67, twice as slow 0.33; the spread stays narrower than the health
 * signal's, so a model that is slow does not outrank one whose provider fails.
 *
 * A fixed ceiling, the way the list-price signal and the provider-health
 * latency work, cannot fit this population — the same model averages a few
 * hundred milliseconds for an embedding and tens of seconds for a tool turn,
 * and a ceiling high enough for the second flattens the first.
 *
 * The cost basis is decided once for the whole decision. Measured cost per call
 * and list price are different units, so a decision uses the measured basis
 * only when every candidate that has ANY cost figure has a measured one;
 * otherwise every candidate is ranked by list price, as before. A candidate
 * with neither (an unpriced local model nobody called) blocks neither basis —
 * it has no cost under either, and is skipped as an absence.
 *
 * @internal
 */
final readonly class CallHistoryRanking
{
    /**
     * @param array<int, CallHistorySignals> $signals by candidate position
     */
    private function __construct(
        private array $signals,
    ) {}

    /**
     * @param list<Model>                     $models
     * @param array<int, ModelCallStats|null> $stats  by candidate position
     */
    public static function fromStats(array $models, array $stats): self
    {
        $latencies = [];
        $costs     = [];
        foreach ($models as $order => $model) {
            $latency = $stats[$order]?->latencyMs();
            if ($latency !== null) {
                $latencies[$order] = $latency;
            }

            $cost = $stats[$order]?->costPerCall();
            if ($cost !== null) {
                $costs[$order] = $cost;
            }
        }

        $costIsMeasured = $costs !== [] && self::everyCostedCandidateIsMeasured($models, $costs);
        $latencyMean    = self::geometricMean($latencies);
        $costMean       = self::geometricMean($costs);

        $signals = [];
        foreach ($models as $order => $model) {
            $signals[$order] = new CallHistorySignals(
                self::relative($latencyMean, $latencies[$order] ?? null),
                $latencies !== [],
                $costIsMeasured,
                $costIsMeasured ? self::relative($costMean, $costs[$order] ?? null) : null,
            );
        }

        return new self($signals);
    }

    public function forModel(int $order): ?CallHistorySignals
    {
        return $this->signals[$order] ?? null;
    }

    /**
     * @param list<Model>       $models
     * @param array<int, float> $costs  measured cost per call by candidate position
     */
    private static function everyCostedCandidateIsMeasured(array $models, array $costs): bool
    {
        foreach ($models as $order => $model) {
            if (!isset($costs[$order]) && $model->getCostInput() + $model->getCostOutput() > 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The geometric mean of the positive figures, or null when there is none.
     * Geometric, because the figures are ratios of each other: a model twice as
     * fast and one twice as slow sit symmetrically around it. A zero (a call
     * that cost nothing) has no place in a geometric mean and is left out; it
     * scores 1.0 on its own in {@see self::relative()}.
     *
     * @param array<int, float> $values
     */
    private static function geometricMean(array $values): ?float
    {
        $logs = [];
        foreach ($values as $value) {
            if ($value > 0.0) {
                $logs[] = log($value);
            }
        }

        return $logs === [] ? null : exp(array_sum($logs) / count($logs));
    }

    /**
     * g / (g + x), higher is better. A measured zero is the best there is: a
     * model that answered for nothing scores 1.0.
     */
    private static function relative(?float $mean, ?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if ($value <= 0.0) {
            return 1.0;
        }

        if ($mean === null) {
            return null;
        }

        // Rounded: exp(log(x)) is not exactly x, and a candidate at the mean
        // must tie with the neutral 0.5 of an unmeasured one, not beat or lose
        // to it by a rounding error.
        return round($mean / ($mean + $value), 9);
    }
}
