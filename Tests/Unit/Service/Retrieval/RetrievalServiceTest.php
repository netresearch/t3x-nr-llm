<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval;

use Netresearch\NrLlm\Service\Retrieval\AccessContext;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Service\Retrieval\RetrievalService;
use Netresearch\NrLlm\Service\Retrieval\SourceReference;
use Netresearch\NrLlm\Tests\Unit\Service\Retrieval\Fixtures\FakeSearchBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetrievalService::class)]
final class RetrievalServiceTest extends TestCase
{
    #[Test]
    public function highestPriorityAvailableBackendAnswers(): void
    {
        $low = new FakeSearchBackend('low', 0, sources: [FakeSearchBackend::source('low:1')]);
        $high = new FakeSearchBackend('high', 10, sources: [FakeSearchBackend::source('high:1')]);

        $service = new RetrievalService([$low, $high]);
        $result = $service->search($this->query(), AccessContext::publicOnly());

        self::assertSame('high', $result->backend);
        self::assertSame(0, $low->searchCalls);
    }

    #[Test]
    public function unavailableBackendIsSkipped(): void
    {
        $unavailable = new FakeSearchBackend('solr', 10, available: false);
        $fallback = new FakeSearchBackend('database', 0, sources: [FakeSearchBackend::source('database:1:0')]);

        $service = new RetrievalService([$unavailable, $fallback]);
        $result = $service->search($this->query(), AccessContext::publicOnly());

        self::assertSame('database', $result->backend);
        self::assertSame(0, $unavailable->searchCalls);
    }

    #[Test]
    public function throwingBackendDegradesToNextWithNote(): void
    {
        $broken = new FakeSearchBackend('solr', 10, throwsOnSearch: true);
        $fallback = new FakeSearchBackend('database', 0, sources: [FakeSearchBackend::source('database:1:0')]);

        $service = new RetrievalService([$broken, $fallback]);
        $result = $service->search($this->query(), AccessContext::publicOnly());

        self::assertSame('database', $result->backend);
        self::assertSame(['Backend "solr" failed and was skipped.'], $result->notes);
    }

    #[Test]
    public function noBackendAvailableYieldsEmptyLabelledResult(): void
    {
        $service = new RetrievalService([new FakeSearchBackend('solr', 10, available: false)]);
        $result = $service->search($this->query(), AccessContext::publicOnly());

        self::assertSame('none', $result->backend);
        self::assertTrue($result->isEmpty());
        self::assertContains('No search backend available.', $result->notes);
    }

    #[Test]
    public function duplicateUrlsAreDroppedAndResultIsCapped(): void
    {
        $sources = [
            FakeSearchBackend::source('a:1', 'https://example.org/one'),
            FakeSearchBackend::source('a:2', 'https://example.org/one'),
            FakeSearchBackend::source('a:3', 'https://example.org/three'),
            FakeSearchBackend::source('a:4', 'https://example.org/four'),
        ];
        $service = new RetrievalService([new FakeSearchBackend('a', 5, sources: $sources)]);

        $result = $service->search(
            RetrievalQuery::create('netresearch', maxSources: 2),
            AccessContext::publicOnly(),
        );

        self::assertCount(2, $result->sources);
        self::assertSame('a:1', $result->sources[0]->sourceId);
        self::assertSame('a:3', $result->sources[1]->sourceId);
    }

    #[Test]
    public function emptyResultFromAvailableBackendIsFinalNotFallThrough(): void
    {
        $emptyIndex = new FakeSearchBackend('ke_search', 20);
        $fallback = new FakeSearchBackend('database', 0, sources: [FakeSearchBackend::source('database:1:0')]);

        $service = new RetrievalService([$emptyIndex, $fallback]);
        $result = $service->search($this->query(), AccessContext::publicOnly());

        self::assertSame('ke_search', $result->backend);
        self::assertTrue($result->isEmpty());
        self::assertSame(0, $fallback->searchCalls, 'empty result must not fall through to a lower-quality engine');
    }

    #[Test]
    public function fetchSourceRoutesToTheEmittingBackend(): void
    {
        $database = new FakeSearchBackend('database', 0, fetchResult: 'full text');
        $service = new RetrievalService([$database]);

        $reference = SourceReference::parse('database:1:0');
        self::assertNotNull($reference);
        self::assertSame('full text', $service->fetchSource($reference, AccessContext::publicOnly()));
    }

    #[Test]
    public function fetchSourceForUnknownOrUnavailableBackendIsNull(): void
    {
        $service = new RetrievalService([new FakeSearchBackend('database', 0, available: false, fetchResult: 'x')]);

        $reference = SourceReference::parse('database:1:0');
        self::assertNotNull($reference);
        self::assertNull($service->fetchSource($reference, AccessContext::publicOnly()));

        $unknown = SourceReference::parse('kesearch:1');
        self::assertNotNull($unknown);
        self::assertNull($service->fetchSource($unknown, AccessContext::publicOnly()));
    }

    private function query(): RetrievalQuery
    {
        return RetrievalQuery::create('netresearch migration');
    }
}
