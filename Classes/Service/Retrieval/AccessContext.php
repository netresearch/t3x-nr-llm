<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Who is asking — carried through the retrieval core so a later frontend
 * consumer can widen filtering per fe_group without touching backends
 * (ADR-049).
 *
 * In the current iteration every backend filters the INDEX side
 * public-only (fe_group ''/0, gr_list '0,-1', Solr access groups [0,-1])
 * regardless of context: RAG evidence is what the anonymous visitor could
 * read, so no additional per-user narrowing exists today — the variants
 * only preserve WHO asked for future widening.
 */
final readonly class AccessContext
{
    /**
     * @param list<int> $frontendGroupIds
     */
    private function __construct(
        public ?BackendUserAuthentication $backendUser,
        public array $frontendGroupIds,
    ) {}

    public static function publicOnly(): self
    {
        return new self(null, []);
    }

    public static function forBackendUser(BackendUserAuthentication $backendUser): self
    {
        return new self($backendUser, []);
    }

    /**
     * Reserved for a future frontend consumer: the given fe_group ids MAY
     * widen index-level filtering once backends implement it. Until then
     * backends treat this exactly like {@see publicOnly()}.
     *
     * @param list<int> $frontendGroupIds
     */
    public static function forFrontendGroups(array $frontendGroupIds): self
    {
        return new self(null, array_values($frontendGroupIds));
    }
}
