<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval;

use Netresearch\NrLlm\Service\Retrieval\EvidenceSource;
use Netresearch\NrLlm\Service\Retrieval\KeywordHit;
use Netresearch\NrLlm\Service\Retrieval\KeywordSearchService;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Tests\Unit\Service\Retrieval\Fixtures\FakeSearchBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeywordSearchService::class)]
#[CoversClass(KeywordHit::class)]
final class KeywordSearchServiceTest extends TestCase
{
    #[Test]
    public function queryIsTrimmedAndTruncatedToMaxLength(): void
    {
        $backend = new FakeSearchBackend('solr', 30, sources: [FakeSearchBackend::source('solr:1')]);
        $service = new KeywordSearchService([$backend]);

        $service->search('  ' . str_repeat('a', RetrievalQuery::MAX_QUERY_LENGTH + 50) . '  ', 5);

        self::assertNotNull($backend->lastQuery);
        self::assertSame(str_repeat('a', RetrievalQuery::MAX_QUERY_LENGTH), $backend->lastQuery->query);
    }

    #[Test]
    public function tooShortQueryReturnsEmptyWithoutQueryingBackends(): void
    {
        $backend = new FakeSearchBackend('solr', 30, sources: [FakeSearchBackend::source('solr:1')]);
        $service = new KeywordSearchService([$backend]);

        self::assertSame([], $service->search('  a  ', 5));
        self::assertSame(0, $backend->searchCalls);
    }

    #[Test]
    public function limitIsClampedToRetrievalQueryBounds(): void
    {
        $backend = new FakeSearchBackend('solr', 30, sources: [FakeSearchBackend::source('solr:1')]);
        $service = new KeywordSearchService([$backend]);

        $service->search('term', 0);
        self::assertNotNull($backend->lastQuery);
        self::assertSame(1, $backend->lastQuery->maxSources);

        $service->search('term', 999);
        self::assertNotNull($backend->lastQuery);
        self::assertSame(RetrievalQuery::MAX_SOURCES, $backend->lastQuery->maxSources);
    }

    #[Test]
    public function negativeAndNullLanguageIdsResolveToDefaultLanguage(): void
    {
        $backend = new FakeSearchBackend('solr', 30, sources: [FakeSearchBackend::source('solr:1')]);
        $service = new KeywordSearchService([$backend]);

        $service->search('term', 5, -3);
        self::assertNotNull($backend->lastQuery);
        self::assertSame(0, $backend->lastQuery->languageId);

        $service->search('term', 5);
        self::assertNotNull($backend->lastQuery);
        self::assertSame(0, $backend->lastQuery->languageId);

        $service->search('term', 5, 2);
        self::assertNotNull($backend->lastQuery);
        self::assertSame(2, $backend->lastQuery->languageId);
    }

    #[Test]
    public function throwingBackendDegradesToEmptyList(): void
    {
        $service = new KeywordSearchService([new FakeSearchBackend('solr', 30, throwsOnSearch: true)]);

        self::assertSame([], $service->search('term', 5));
    }

    #[Test]
    public function noAvailableBackendYieldsEmptyListAndUnavailability(): void
    {
        $service = new KeywordSearchService([new FakeSearchBackend('solr', 30, available: false)]);

        self::assertSame([], $service->search('term', 5));
        self::assertFalse($service->isAvailable());
    }

    #[Test]
    public function availabilityProbeSurvivesThrowingBackend(): void
    {
        $service = new KeywordSearchService([
            new FakeSearchBackend('broken', 30, throwsOnAvailability: true),
            new FakeSearchBackend('database', 0),
        ]);

        self::assertTrue($service->isAvailable());
    }

    #[Test]
    public function fullCascadeIncludesDatabaseFallback(): void
    {
        $solr = new FakeSearchBackend('solr', 30, available: false);
        $database = new FakeSearchBackend('database', 0, sources: [FakeSearchBackend::source('database:1:0')]);
        $service = new KeywordSearchService([$solr, $database]);

        $hits = $service->search('term', 5);

        self::assertCount(1, $hits);
        self::assertSame('database:1:0', $hits[0]->sourceId);
        self::assertTrue($service->isAvailable());
    }

    #[Test]
    public function indexBackedOnlyModeExcludesPriorityZeroFallback(): void
    {
        $solr = new FakeSearchBackend('solr', 30, available: false);
        $database = new FakeSearchBackend('database', 0, sources: [FakeSearchBackend::source('database:1:0')]);
        $service = new KeywordSearchService([$solr, $database], indexBackedOnly: true);

        self::assertSame([], $service->search('term', 5));
        self::assertSame(0, $database->searchCalls);
        self::assertFalse($service->isAvailable());
    }

    #[Test]
    public function indexBackedOnlyModeStillAnswersFromIndexBackedBackend(): void
    {
        $solr = new FakeSearchBackend('solr', 30, sources: [FakeSearchBackend::source('solr:1')]);
        $database = new FakeSearchBackend('database', 0, sources: [FakeSearchBackend::source('database:1:0')]);
        $service = new KeywordSearchService([$solr, $database], indexBackedOnly: true);

        $hits = $service->search('term', 5);

        self::assertCount(1, $hits);
        self::assertSame('solr:1', $hits[0]->sourceId);
        self::assertTrue($service->isAvailable());
    }

    #[Test]
    public function hitsCarryAllEvidenceSourceFields(): void
    {
        $source = new EvidenceSource(
            sourceId: 'solr:site:42:1',
            title: 'A page',
            url: 'https://example.org/a-page',
            excerpt: 'Excerpt text',
            backend: 'solr',
            languageId: 1,
            pageUid: 42,
            score: 0.87,
        );
        $service = new KeywordSearchService([new FakeSearchBackend('solr', 30, sources: [$source])]);

        $hits = $service->search('term', 5, 1);

        self::assertCount(1, $hits);
        $hit = $hits[0];
        self::assertSame('solr:site:42:1', $hit->sourceId);
        self::assertSame('A page', $hit->title);
        self::assertSame('https://example.org/a-page', $hit->url);
        self::assertSame('Excerpt text', $hit->excerpt);
        self::assertSame(1, $hit->languageId);
        self::assertSame(0.87, $hit->score);
        self::assertSame(42, $hit->pageUid);
    }

    #[Test]
    public function hitsAreDeduplicatedByUrlAndCappedAtLimit(): void
    {
        $sources = [
            FakeSearchBackend::source('a:1', 'https://example.org/one'),
            FakeSearchBackend::source('a:2', 'https://example.org/one'),
            FakeSearchBackend::source('a:3', 'https://example.org/three'),
            FakeSearchBackend::source('a:4', 'https://example.org/four'),
        ];
        $service = new KeywordSearchService([new FakeSearchBackend('solr', 30, sources: $sources)]);

        $hits = $service->search('term', 2);

        self::assertCount(2, $hits);
        self::assertSame(['a:1', 'a:3'], array_map(static fn(KeywordHit $hit): string => $hit->sourceId, $hits));
    }
}
