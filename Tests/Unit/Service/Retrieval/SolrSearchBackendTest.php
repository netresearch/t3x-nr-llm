<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval;

use Netresearch\NrLlm\Service\Retrieval\AccessContext;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Service\Retrieval\SolrSearchBackend;
use Netresearch\NrLlm\Service\Retrieval\SourceReference;
use Netresearch\NrLlm\Tests\Unit\Service\Retrieval\Fixtures\FakeRequestFactory;
use Netresearch\NrLlm\Tests\Unit\Service\Retrieval\Fixtures\FakeSiteFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

#[CoversClass(SolrSearchBackend::class)]
final class SolrSearchBackendTest extends TestCase
{
    private const DOCS_JSON = '{"response":{"numFound":2,"docs":[
        {"type":"pages","uid":2,"title":"Aikido Migration Services",
         "content":"Aikido migrations done right with belts and mats.",
         "url":"https://example.org/migration","language":0,"score":3.5},
        {"type":"tx_news_domain_model_news","uid":7,"title":"Aikido news",
         "content":"Aikido news content.","url":"/news/aikido","language":0,"score":1.25}
    ]}}';

    #[Test]
    public function buildsSelectUrlWithAccessAndLanguageFiltersAndMapsDocuments(): void
    {
        $requestFactory = FakeRequestFactory::withJson(self::DOCS_JSON);
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), $requestFactory);

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('http://solr.example:8983/solr/core_en/select?', $uris[0]);
        self::assertStringContainsString('fq=' . rawurlencode('{!typo3access}0,-1'), $uris[0]);
        self::assertStringContainsString('fq=' . rawurlencode('language:0'), $uris[0]);
        self::assertStringContainsString('defType=edismax', $uris[0]);

        self::assertCount(2, $result->sources);
        $first = $result->sources[0];
        self::assertSame('solr:main:pages:2:0', $first->sourceId);
        self::assertSame('Aikido Migration Services', $first->title);
        self::assertSame('https://example.org/migration', $first->url);
        self::assertSame(2, $first->pageUid);
        self::assertSame(3.5, $first->score);
        self::assertStringContainsString('belts and mats', $first->excerpt);

        $second = $result->sources[1];
        self::assertSame('solr:main:tx_news_domain_model_news:7:0', $second->sourceId);
        self::assertNull($second->pageUid, 'non-page document must not carry a page uid');
    }

    #[Test]
    public function escapesLuceneQuerySyntaxInTheTerm(): void
    {
        $requestFactory = FakeRequestFactory::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido (hack:* OR "x")'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringContainsString('q=' . rawurlencode('aikido \(hack\:\* OR \"x\"\)'), $uris[0]);
    }

    #[Test]
    public function stripsTrailingSolrSegmentFromConfiguredPath(): void
    {
        $site = $this->site(['solr_path_read' => '/solr']);
        $requestFactory = FakeRequestFactory::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$site]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('http://solr.example:8983/solr/core_en/select?', $uris[0]);
    }

    #[Test]
    public function disabledOrCorelessSiteYieldsNoRequestAndEmptyResult(): void
    {
        $disabled = $this->site(['solr_enabled_read' => false]);
        $requestFactory = FakeRequestFactory::withJson(self::DOCS_JSON);
        $backend = new SolrSearchBackend(new FakeSiteFinder([$disabled]), $requestFactory);

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        self::assertSame([], $requestFactory->requestedUris());
        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function languageOverrideSelectsTheLanguageCore(): void
    {
        $site = $this->site(languages: [
            ['languageId' => 0, 'title' => 'EN', 'locale' => 'en_US.UTF-8', 'base' => '/', 'solr_core_read' => 'core_en'],
            ['languageId' => 1, 'title' => 'DE', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'solr_core_read' => 'core_de'],
        ]);
        $requestFactory = FakeRequestFactory::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$site]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido', 8, null, 1), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('http://solr.example:8983/solr/core_de/select?', $uris[0]);
        self::assertStringContainsString('fq=' . rawurlencode('language:1'), $uris[0]);
    }

    #[Test]
    public function httpErrorOrInvalidJsonYieldsEmptyResult(): void
    {
        $error = new SolrSearchBackend(
            new FakeSiteFinder([$this->site()]),
            FakeRequestFactory::withJson('irrelevant', 500),
        );
        self::assertTrue($error->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly())->isEmpty());

        $garbage = new SolrSearchBackend(
            new FakeSiteFinder([$this->site()]),
            FakeRequestFactory::withJson('not json at all'),
        );
        self::assertTrue($garbage->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly())->isEmpty());
    }

    #[Test]
    public function documentsWithUnsafeTypeOrUrlAreDropped(): void
    {
        $json = '{"response":{"docs":[
            {"type":"pages;drop","uid":2,"title":"Bad type","content":"x","url":"https://example.org/a","language":0},
            {"type":"pages","uid":3,"title":"Bad url","content":"x","url":"javascript:alert(1)","language":0}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeRequestFactory::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function fetchSourceQueriesByTypeUidLanguageAndFormatsText(): void
    {
        $json = '{"response":{"docs":[{"title":"Aikido Migration Services","content":"Full page text."}]}}';
        $requestFactory = FakeRequestFactory::withJson($json);
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), $requestFactory);

        $reference = SourceReference::parse('solr:main:pages:2:0');
        self::assertNotNull($reference);
        $text = $backend->fetchSource($reference, AccessContext::publicOnly());

        self::assertSame("# Aikido Migration Services\n\nFull page text.", $text);
        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringContainsString('fq=' . rawurlencode('type:pages'), $uris[0]);
        self::assertStringContainsString('fq=' . rawurlencode('uid:2'), $uris[0]);
        // The public-only access filter must guard the FETCH path exactly
        // like the search path — a guessable uid must never bypass it.
        self::assertStringContainsString('fq=' . rawurlencode('{!typo3access}0,-1'), $uris[0]);
        self::assertStringContainsString('fq=' . rawurlencode('language:0'), $uris[0]);
    }

    #[Test]
    public function fetchSourceWithMalformedReferenceOrUnknownSiteIsNull(): void
    {
        $backend = new SolrSearchBackend(
            new FakeSiteFinder([$this->site()]),
            FakeRequestFactory::withJson('{"response":{"docs":[]}}'),
        );

        $tooFewParts = SourceReference::parse('solr:main:pages:2');
        self::assertNotNull($tooFewParts);
        self::assertNull($backend->fetchSource($tooFewParts, AccessContext::publicOnly()));

        $unknownSite = SourceReference::parse('solr:ghost:pages:2:0');
        self::assertNotNull($unknownSite);
        self::assertNull($backend->fetchSource($unknownSite, AccessContext::publicOnly()));
    }

    /**
     * @param array<string, mixed>            $overrides
     * @param list<array<string, mixed>>|null $languages
     */
    private function site(array $overrides = [], ?array $languages = null): Site
    {
        $configuration = array_merge([
            'base' => 'https://example.org/',
            'solr_enabled_read' => true,
            'solr_scheme_read' => 'http',
            'solr_host_read' => 'solr.example',
            'solr_port_read' => 8983,
            'solr_path_read' => '',
            'solr_core_read' => 'core_en',
            'languages' => $languages ?? [
                ['languageId' => 0, 'title' => 'EN', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ],
        ], $overrides);

        return new Site('main', 1, $configuration);
    }
}
