<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Default implementation of {@see KeywordSearchInterface} (ADR-071): a
 * clamping, degrade-to-empty facade over the retrieval cascade.
 *
 * Composes a {@see RetrievalService} over the tagged backends rather
 * than duplicating the cascade. With `$indexBackedOnly` the priority-0
 * fallback tier (the database LIKE backend, see
 * {@see SearchBackendInterface::getPriority()}) is excluded, so an
 * unavailable index yields an empty result instead of LIKE hits.
 */
final class KeywordSearchService implements KeywordSearchInterface
{
    private ?RetrievalService $cascade = null;

    /** @var list<SearchBackendInterface>|null */
    private ?array $selectedBackends = null;

    /**
     * @param iterable<SearchBackendInterface> $backends
     */
    public function __construct(
        #[AutowireIterator(SearchBackendInterface::TAG_NAME)]
        private readonly iterable $backends,
        private readonly bool $indexBackedOnly = false,
    ) {}

    public function search(string $query, int $limit, ?int $languageId = null): array
    {
        $query = trim($query);
        if (mb_strlen($query) > RetrievalQuery::MAX_QUERY_LENGTH) {
            $query = trim(mb_substr($query, 0, RetrievalQuery::MAX_QUERY_LENGTH));
        }
        if (mb_strlen($query) < RetrievalQuery::MIN_QUERY_LENGTH) {
            return [];
        }

        $limit = max(1, min($limit, RetrievalQuery::MAX_SOURCES));

        try {
            $result = $this->cascade()->search(
                RetrievalQuery::create($query, $limit, null, max(0, $languageId ?? 0)),
                AccessContext::publicOnly(),
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(
            static fn(EvidenceSource $source): KeywordHit => new KeywordHit(
                sourceId: $source->sourceId,
                title: $source->title,
                url: $source->url,
                excerpt: $source->excerpt,
                languageId: $source->languageId,
                score: $source->score,
                pageUid: $source->pageUid,
            ),
            $result->sources,
        );
    }

    public function isAvailable(): bool
    {
        foreach ($this->selectedBackends() as $backend) {
            try {
                if ($backend->isAvailable()) {
                    return true;
                }
            } catch (Throwable) {
                // An erroring backend counts as unavailable.
            }
        }

        return false;
    }

    private function cascade(): RetrievalService
    {
        return $this->cascade ??= new RetrievalService($this->selectedBackends());
    }

    /**
     * @return list<SearchBackendInterface>
     */
    private function selectedBackends(): array
    {
        if ($this->selectedBackends === null) {
            $selected = [];
            foreach ($this->backends as $backend) {
                if ($this->indexBackedOnly && $backend->getPriority() <= 0) {
                    continue;
                }
                $selected[] = $backend;
            }
            $this->selectedBackends = $selected;
        }

        return $this->selectedBackends;
    }
}
