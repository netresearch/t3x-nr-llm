<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Rerank;

use Netresearch\NrLlm\Service\Rerank\Exception\RerankerException;

/**
 * Scores retrieval candidates against a query with a cross-encoder (ADR-075).
 *
 * Neutral protocol: candidates go in as plain ``id``/``text`` shapes and
 * scores come back as plain ``id``/``score`` shapes — no consumer DTOs
 * cross this boundary. Consumers own DTO mapping, the ordering merge,
 * the degradation policy on failure, and any score-threshold gate.
 */
interface RerankerInterface
{
    /**
     * Scores each (query, candidate text) pair.
     *
     * Returns one entry per scored candidate in input order. An entry the
     * backend failed to score may be omitted — consumers merge by ``id``
     * and decide how an unscored candidate ranks.
     *
     * @param list<array{id: string, text: string}> $candidates
     *
     * @throws RerankerException when the reranker backend is unreachable or
     *                           answers outside the protocol
     *
     * @return list<array{id: string, score: float}>
     */
    public function rerank(string $query, array $candidates): array;
}
