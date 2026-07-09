<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

/**
 * One curated piece of evidence a search backend returned for a
 * {@see RetrievalQuery} (ADR-049).
 *
 * `sourceId` follows the grammar of {@see SourceReference} and can be
 * handed back to `site_fetch_source` to load the underlying indexed text.
 * `pageUid` (when resolvable) lets the calling tool apply per-user page
 * post-filtering; backends without a page notion return null.
 */
final readonly class EvidenceSource
{
    public function __construct(
        public string $sourceId,
        public string $title,
        public string $url,
        public string $excerpt,
        public string $backend,
        public int $languageId,
        public ?int $pageUid = null,
        public ?float $score = null,
    ) {}
}
