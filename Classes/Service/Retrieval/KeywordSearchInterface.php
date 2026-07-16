<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

/**
 * Public keyword-search contract over the site-search cascade (ADR-071).
 *
 * The narrow facade downstream extensions wire against instead of the
 * private retrieval internals. Semantics:
 *
 * - Input is never rejected: the query is trimmed and truncated to
 *   {@see RetrievalQuery::MAX_QUERY_LENGTH}, the limit clamped to
 *   1..{@see RetrievalQuery::MAX_SOURCES}, a negative language id
 *   clamped to 0. A query shorter than
 *   {@see RetrievalQuery::MIN_QUERY_LENGTH} yields an empty list.
 * - Results are always filtered public-only
 *   ({@see AccessContext::publicOnly()}): hits are what the anonymous
 *   visitor could read.
 * - Any backend failure degrades to an empty list — the facade never
 *   throws.
 *
 * Two container registrations exist (ADR-071): this interface alias
 * resolves the full cascade including the database LIKE fallback, and
 * the named service `nr_llm.keyword_search.index_backed` resolves an
 * index-backed-only variant that excludes the fallback — for consumers
 * (e.g. hybrid dense+sparse fusion) that must see "index unavailable"
 * as empty rather than receive LIKE hits.
 */
interface KeywordSearchInterface
{
    /**
     * Run a public-only keyword search and return the hits of the first
     * available backend, deduplicated by URL and capped at `$limit`.
     *
     * @param string   $query      free-text query; trimmed and truncated, never rejected
     * @param int      $limit      maximum hits, clamped to 1..RetrievalQuery::MAX_SOURCES
     * @param int|null $languageId sys_language uid to search in; null means default (0)
     *
     * @return list<KeywordHit> empty when nothing matched, the query is
     *                          too short, or no backend is available
     */
    public function search(string $query, int $limit, ?int $languageId = null): array;

    /**
     * Whether at least one search backend of this variant can answer
     * right now. Never throws.
     */
    public function isAvailable(): bool;
}
