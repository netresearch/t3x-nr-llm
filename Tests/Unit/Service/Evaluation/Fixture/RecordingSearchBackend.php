<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture;

use Netresearch\NrLlm\Service\Retrieval\AccessContext;
use Netresearch\NrLlm\Service\Retrieval\EvidenceList;
use Netresearch\NrLlm\Service\Retrieval\EvidenceSource;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Service\Retrieval\SearchBackendInterface;
use Netresearch\NrLlm\Service\Retrieval\SourceReference;

/**
 * A recording lexical backend for LexicalSearchRetriever tests:
 * RetrievalService is final and cannot be mocked, so the adapter is tested
 * through a real cascade over this single backend, which returns canned
 * source ids and records the query it received.
 */
final class RecordingSearchBackend implements SearchBackendInterface
{
    public ?RetrievalQuery $receivedQuery = null;

    /**
     * @param list<string> $sourceIds
     */
    public function __construct(
        private readonly array $sourceIds = [],
    ) {}

    public function getIdentifier(): string
    {
        return 'test';
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function search(RetrievalQuery $query, AccessContext $context): EvidenceList
    {
        $this->receivedQuery = $query;
        $sources = [];
        foreach ($this->sourceIds as $sourceId) {
            // Unique URL per source — the cascade deduplicates same-URL hits.
            $sources[] = new EvidenceSource($sourceId, 'Title', 'https://example.org/' . rawurlencode($sourceId), 'Excerpt', 'test', 0);
        }

        return new EvidenceList('test', $sources);
    }

    public function fetchSource(SourceReference $reference, AccessContext $context): ?string
    {
        return null;
    }
}
