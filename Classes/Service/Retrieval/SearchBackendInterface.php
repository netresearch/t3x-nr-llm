<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One retrieval source behind the site-search cascade (ADR-049).
 *
 * Implementations are discovered via the `nr_llm.retrieval_backend` tag
 * (auto-applied, mirroring ToolInterface) and asked by RetrievalService in
 * {@see getPriority()} order; the first available backend answers. A
 * backend over an optional extension keeps every reference to that
 * extension's classes behind availability checks so the class can always
 * be instantiated.
 *
 * Security contract: query values originate from the model and are
 * untrusted (RetrievalQuery caps them); the INDEX side is filtered
 * public-only in this iteration (see AccessContext). Returned excerpts
 * egress to the external provider and the backend DOM — never include
 * content the anonymous visitor could not read.
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface SearchBackendInterface
{
    public const TAG_NAME = 'nr_llm.retrieval_backend';

    /**
     * Stable identifier, also the first segment of every source id this
     * backend emits (e.g. `database`, `ke_search`).
     */
    public function getIdentifier(): string;

    /**
     * Cascade position; higher runs first. The always-available database
     * fallback uses 0, index-backed engines use higher values.
     */
    public function getPriority(): int;

    /**
     * Whether this backend can answer right now (extension installed,
     * index table present and non-empty, connection configured). Must not
     * throw and must be cheap enough to call once per retrieval.
     */
    public function isAvailable(): bool;

    /**
     * Run the query and return curated evidence. May throw — the cascade
     * treats any throwable as "backend unavailable" and continues.
     */
    public function search(RetrievalQuery $query, AccessContext $context): EvidenceList;

    /**
     * Resolve a source id this backend emitted back to the indexed text
     * (capped by the caller). Null when unknown, gone, or not permitted.
     */
    public function fetchSource(SourceReference $reference, AccessContext $context): ?string;
}
