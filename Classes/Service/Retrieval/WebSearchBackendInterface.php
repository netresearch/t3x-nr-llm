<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

/**
 * A site-limited external web search as the last retrieval stage
 * (ADR-049).
 *
 * Deliberately interface-only in this iteration: no implementation ships,
 * so no network egress exists. A future provider (Brave, Google CSE,
 * SearXNG, ...) implements this against its API — restricted to the
 * current site's public host — and RetrievalService gains an opt-in
 * config gate before consulting it.
 */
interface WebSearchBackendInterface
{
    /**
     * Whether a provider is configured (API key present, endpoint set).
     */
    public function isConfigured(): bool;

    /**
     * Search the public web restricted to the given site host and return
     * evidence in the same shape as the index backends.
     */
    public function searchSite(RetrievalQuery $query, string $siteHost): EvidenceList;
}
