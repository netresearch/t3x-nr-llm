<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

/**
 * One keyword-search hit returned by {@see KeywordSearchInterface}
 * (ADR-071).
 *
 * The public counterpart of the internal {@see EvidenceSource}: the same
 * fields minus the backend label, so the facade can evolve the cascade
 * internals without changing this contract. `score` is backend-native
 * relevance (not comparable across backends); `pageUid` is set when the
 * answering backend can resolve the hit to a page, null otherwise.
 */
final readonly class KeywordHit
{
    public function __construct(
        public string $sourceId,
        public string $title,
        public string $url,
        public string $excerpt,
        public int $languageId,
        public ?float $score = null,
        public ?int $pageUid = null,
    ) {}
}
