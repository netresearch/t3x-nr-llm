<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval\Fixtures;

use Netresearch\NrLlm\Service\Retrieval\AccessContext;
use Netresearch\NrLlm\Service\Retrieval\EvidenceList;
use Netresearch\NrLlm\Service\Retrieval\EvidenceSource;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Service\Retrieval\SearchBackendInterface;
use Netresearch\NrLlm\Service\Retrieval\SourceReference;
use RuntimeException;

/**
 * Scriptable backend for cascade tests: availability, priority, canned
 * sources, optional throwing.
 */
final class FakeSearchBackend implements SearchBackendInterface
{
    public int $searchCalls = 0;

    public ?RetrievalQuery $lastQuery = null;

    /**
     * @param list<EvidenceSource> $sources
     */
    public function __construct(
        private readonly string $identifier,
        private readonly int $priority,
        private readonly bool $available = true,
        private readonly array $sources = [],
        private readonly bool $throwsOnSearch = false,
        private readonly ?string $fetchResult = null,
        private readonly bool $throwsOnAvailability = false,
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function isAvailable(): bool
    {
        if ($this->throwsOnAvailability) {
            throw new RuntimeException('availability boom', 1751000001);
        }

        return $this->available;
    }

    public function search(RetrievalQuery $query, AccessContext $context): EvidenceList
    {
        ++$this->searchCalls;
        $this->lastQuery = $query;
        if ($this->throwsOnSearch) {
            throw new RuntimeException('boom', 1751000000);
        }

        return new EvidenceList($this->identifier, $this->sources);
    }

    public function fetchSource(SourceReference $reference, AccessContext $context): ?string
    {
        return $this->fetchResult;
    }

    public static function source(string $id, string $url = ''): EvidenceSource
    {
        return new EvidenceSource(
            sourceId: $id,
            title: 'Title ' . $id,
            url: $url,
            excerpt: 'Excerpt ' . $id,
            backend: 'fake',
            languageId: 0,
        );
    }
}
