<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Rerank;

/**
 * No-op reranker selected when no sidecar endpoint is configured (ADR-075).
 *
 * Returns one entry per candidate in input order with a uniform score of
 * ``0.0``: the value carries no ranking signal, so a stable sort by score
 * preserves the caller's ordering. A uniform score (rather than omitting
 * entries) keeps the result shape-identical to {@see HttpReranker}, so a
 * consumer's merge code needs no null branch.
 */
final readonly class NullReranker implements RerankerInterface
{
    public function rerank(string $query, array $candidates): array
    {
        return array_map(
            static fn(array $candidate): array => ['id' => $candidate['id'], 'score' => 0.0],
            $candidates,
        );
    }
}
