<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

/**
 * Reciprocal Rank Fusion (Cormack et al., 2009) for hybrid retrieval (ADR-074).
 *
 * Fuses several ranked key lists using only per-list RANK, never score
 * magnitude — so it combines dense cosine and sparse BM25 rankings, which live
 * on incomparable scales, without any score normalization. For each key the
 * fused score is Σ_i weight_i / (k + rank_i), where rank_i is the key's 1-based
 * position in list i (absent = no contribution).
 *
 * Newable utility, deliberately not a DI service (excluded from container
 * autoconfiguration): consumers construct it with `new`. nr_llm's own
 * retrieval cascade stays first-available-wins (ADR-049) and does not call it.
 */
final readonly class ReciprocalRankFusion
{
    /**
     * @param list<list<string>> $rankedKeyLists each inner list is keys best-first;
     *                                           duplicates within a list are ignored past their first rank
     * @param list<float>        $weights        per-list weight (same index); missing/extra default to 1.0
     *
     * @return list<int|string> fused keys, highest RRF score first; ties keep first-seen
     *                          order. PHP array-key coercion applies: numeric-string keys
     *                          (e.g. '42') come back as ints.
     */
    public function fuse(array $rankedKeyLists, int $k = 60, array $weights = []): array
    {
        $k = max(1, $k);

        /** @var array<int|string, float> $scores insertion order = list order, then per-list new keys */
        $scores = [];

        foreach ($rankedKeyLists as $listIndex => $keys) {
            $weight = $weights[$listIndex] ?? 1.0;
            $rank = 0;
            $seenInList = [];

            foreach ($keys as $key) {
                if (isset($seenInList[$key])) {
                    continue;
                }

                $seenInList[$key] = true;
                ++$rank;
                $scores[$key] = ($scores[$key] ?? 0.0) + $weight / ($k + $rank);
            }
        }

        // arsort is stable in PHP 8: equal scores keep insertion order (list 0
        // before list 1's new keys), giving deterministic tie-breaking.
        arsort($scores);

        return array_keys($scores);
    }
}
