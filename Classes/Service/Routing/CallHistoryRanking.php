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
 * Both signals are RELATIVE to the best measured candidate: the fastest model
 * scores 1.0 on latency and a model twice as slow 0.5; the cheapest per call
 * scores 1.0 on cost. A fixed ceiling, the way the list-price signal and the
 * provider-health latency work, cannot fit this population — the same model
 * averages a few hundred milliseconds for an embedding and tens of seconds for
 * a tool turn, and a ceiling high enough for the second flattens the first.
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
        $fastest        = $latencies === [] ? null : min($latencies);
        $cheapest       = $costs === [] ? null : min($costs);

        $signals = [];
        foreach ($models as $order => $model) {
            $signals[$order] = new CallHistorySignals(
                self::relative($fastest, $latencies[$order] ?? null),
                $costIsMeasured,
                $costIsMeasured ? self::relative($cheapest, $costs[$order] ?? null) : null,
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
     * Best over this, higher is better. A measured zero is the best there is: a
     * model that answered for nothing scores 1.0 and, when it is the best, every
     * model that cost anything scores 0.0.
     */
    private static function relative(?float $best, ?float $value): ?float
    {
        if ($best === null || $value === null) {
            return null;
        }

        if ($value <= 0.0) {
            return 1.0;
        }

        return max(0.0, $best) / $value;
    }
}
