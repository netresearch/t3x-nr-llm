<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * The retrieval cascade (ADR-049): ask the registered search backends in
 * priority order, let the first available one answer, and label the
 * evidence with the backend that produced it.
 *
 * No cross-backend score merging happens — Solr relevance, MySQL fulltext
 * scores and LIKE hits are not comparable. A backend that throws is
 * treated as unavailable and the cascade continues with a note, so a
 * misconfigured Solr connection degrades to the next index instead of
 * failing the run. An EMPTY result from an available backend is final by
 * design: falling through would silently mix engines of different
 * quality, and "the site's search finds nothing" is itself the honest
 * evidence (the answering backend is always named).
 */
final class RetrievalService
{
    /** @var list<SearchBackendInterface>|null */
    private ?array $sortedBackends = null;

    /**
     * @param iterable<SearchBackendInterface> $backends
     */
    public function __construct(
        #[AutowireIterator(SearchBackendInterface::TAG_NAME)]
        private readonly iterable $backends,
    ) {}

    public function search(RetrievalQuery $query, AccessContext $context): EvidenceList
    {
        $notes = [];

        foreach ($this->prioritized() as $backend) {
            if (!$this->available($backend)) {
                continue;
            }

            try {
                $result = $backend->search($query, $context);
            } catch (Throwable) {
                $notes[] = sprintf('Backend "%s" failed and was skipped.', $backend->getIdentifier());
                continue;
            }

            return $this->deduplicate($result, $query->maxSources)->withNotes($notes);
        }

        return new EvidenceList('none', [], [...$notes, 'No search backend available.']);
    }

    public function fetchSource(SourceReference $reference, AccessContext $context): ?string
    {
        foreach ($this->prioritized() as $backend) {
            if ($backend->getIdentifier() !== $reference->backend) {
                continue;
            }
            if (!$this->available($backend)) {
                return null;
            }

            try {
                return $backend->fetchSource($reference, $context);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return list<SearchBackendInterface>
     */
    private function prioritized(): array
    {
        if ($this->sortedBackends === null) {
            $backends = [];
            foreach ($this->backends as $backend) {
                $backends[] = $backend;
            }
            usort(
                $backends,
                static fn(SearchBackendInterface $a, SearchBackendInterface $b): int => $b->getPriority() <=> $a->getPriority(),
            );
            $this->sortedBackends = $backends;
        }

        return $this->sortedBackends;
    }

    private function available(SearchBackendInterface $backend): bool
    {
        try {
            return $backend->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Drop same-URL duplicates (multiple index rows for one page) and cap
     * the source count.
     */
    private function deduplicate(EvidenceList $list, int $maxSources): EvidenceList
    {
        $seen = [];
        $sources = [];
        foreach ($list->sources as $source) {
            $key = $source->url !== '' ? $source->url : $source->sourceId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sources[] = $source;
            if (count($sources) >= $maxSources) {
                break;
            }
        }

        return new EvidenceList($list->backend, $sources, $list->notes);
    }
}
