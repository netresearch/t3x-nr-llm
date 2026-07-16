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
use Netresearch\NrLlm\Tests\Unit\Service\Retrieval\Fixtures\FakeSiteFinder;
use Netresearch\NrLlm\Tests\Unit\Service\Retrieval\Fixtures\FakeSolrHttpClient;
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
    public function buildsSelectUrlWithAccessFilterAndMapsDocuments(): void
    {
        $requestFactory = FakeSolrHttpClient::withJson(self::DOCS_JSON);
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), $requestFactory);

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('https://solr.example:8983/solr/core_en/select?', $uris[0]);
        self::assertStringContainsString('fq=' . rawurlencode('{!typo3access}0,-1'), $uris[0]);
        self::assertStringNotContainsString('fq=' . rawurlencode('language:0'), $uris[0], 'per-language read core carries no language fq');
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
        self::assertSame('https://example.org/news/aikido', $second->url, 'relative URL not absolutized against the site base');
    }

    #[Test]
    public function crossSiteDocumentsFromASharedCoreAreDropped(): void
    {
        $json = '{"response":{"docs":[
            {"type":"pages","uid":9,"title":"Foreign site page",
             "content":"Aikido elsewhere.","url":"https://other.example/aikido","language":0}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertTrue($result->isEmpty(), 'foreign-host document from a shared core leaked');

        $reference = SourceReference::parse('solr:main:pages:9:0');
        self::assertNotNull($reference);
        $fetchBackend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson(
            '{"response":{"docs":[{"title":"Foreign","content":"x","url":"https://other.example/aikido"}]}}',
        ));
        self::assertNull(
            $fetchBackend->fetchSource($reference, AccessContext::publicOnly()),
            'foreign-host document leaked through fetchSource',
        );
    }

    #[Test]
    public function escapesLuceneQuerySyntaxInTheTerm(): void
    {
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
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
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$site]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('https://solr.example:8983/solr/core_en/select?', $uris[0]);
    }

    #[Test]
    public function disabledOrCorelessSiteYieldsNoRequestAndEmptyResult(): void
    {
        $disabled = $this->site(['solr_enabled_read' => false]);
        $requestFactory = FakeSolrHttpClient::withJson(self::DOCS_JSON);
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
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$site]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido', 8, null, 1), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('https://solr.example:8983/solr/core_de/select?', $uris[0]);
        self::assertStringNotContainsString('fq=' . rawurlencode('language:1'), $uris[0], 'language is selected by the core, not an fq');
    }

    #[Test]
    public function httpErrorOrInvalidJsonYieldsEmptyResult(): void
    {
        $error = new SolrSearchBackend(
            new FakeSiteFinder([$this->site()]),
            FakeSolrHttpClient::withJson('irrelevant', 500),
        );
        self::assertTrue($error->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly())->isEmpty());

        $garbage = new SolrSearchBackend(
            new FakeSiteFinder([$this->site()]),
            FakeSolrHttpClient::withJson('not json at all'),
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
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        self::assertTrue($result->isEmpty());
    }

    #[Test]
    public function fetchSourceQueriesByTypeUidLanguageAndFormatsText(): void
    {
        $json = '{"response":{"docs":[{"title":"Aikido Migration Services","content":"Full page text."}]}}';
        $requestFactory = FakeSolrHttpClient::withJson($json);
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), $requestFactory);

        $reference = SourceReference::parse('solr:main:pages:2:0');
        self::assertNotNull($reference);
        $text = $backend->fetchSource($reference, AccessContext::publicOnly());

        self::assertSame("# Aikido Migration Services\n\nFull page text.", $text);
        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringContainsString('fq=' . rawurlencode('type:pages'), $uris[0]);
        self::assertStringContainsString('fq=' . rawurlencode('uid:2'), $uris[0]);
        // The match-all `q` must be present: fetchSource selects by fq only.
        self::assertStringContainsString('q=' . rawurlencode('*:*'), $uris[0]);
        // The public-only access filter must guard the FETCH path exactly
        // like the search path — a guessable uid must never bypass it.
        self::assertStringContainsString('fq=' . rawurlencode('{!typo3access}0,-1'), $uris[0]);
        self::assertStringNotContainsString('fq=' . rawurlencode('language:0'), $uris[0], 'language is encoded by the core, not an fq');
    }

    #[Test]
    public function fetchSourceWithMalformedReferenceOrUnknownSiteIsNull(): void
    {
        $backend = new SolrSearchBackend(
            new FakeSiteFinder([$this->site()]),
            FakeSolrHttpClient::withJson('{"response":{"docs":[]}}'),
        );

        $tooFewParts = SourceReference::parse('solr:main:pages:2');
        self::assertNotNull($tooFewParts);
        self::assertNull($backend->fetchSource($tooFewParts, AccessContext::publicOnly()));

        $unknownSite = SourceReference::parse('solr:ghost:pages:2:0');
        self::assertNotNull($unknownSite);
        self::assertNull($backend->fetchSource($unknownSite, AccessContext::publicOnly()));
    }

    #[Test]
    public function continuesToTheNextSiteWhenOneHasNoEndpoint(): void
    {
        // The first site is disabled (no endpoint); the loop must CONTINUE to
        // the enabled site — a break would abort the cascade after the first.
        $disabled = new Site('secondary', 2, [
            'base' => 'https://secondary.example/',
            'solr_enabled_read' => false,
            'solr_scheme_read' => 'https',
            'solr_host_read' => 'solr.example',
            'solr_port_read' => 8983,
            'solr_path_read' => '',
            'solr_core_read' => 'core_en',
            'languages' => [['languageId' => 0, 'title' => 'EN', 'locale' => 'en_US.UTF-8', 'base' => '/']],
        ]);
        $requestFactory = FakeSolrHttpClient::withJson(self::DOCS_JSON);
        $backend = new SolrSearchBackend(new FakeSiteFinder([$disabled, $this->site()]), $requestFactory);

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('https://solr.example:8983/solr/core_en/select?', $uris[0]);
        self::assertFalse($result->isEmpty());
    }

    #[Test]
    public function stopsCollectingWhenMaxSourcesCapIsReached(): void
    {
        // Two mappable documents, but the query caps at one source: the `>=`
        // cap must return AT the limit, never one past it.
        $requestFactory = FakeSolrHttpClient::withJson(self::DOCS_JSON);
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), $requestFactory);

        $result = $backend->search(RetrievalQuery::create('aikido', 1), AccessContext::publicOnly());

        self::assertCount(1, $result->sources);
        self::assertSame('solr:main:pages:2:0', $result->sources[0]->sourceId);
    }

    #[Test]
    public function nonOkStatusWithParseableBodyStillYieldsEmptyResult(): void
    {
        // A 5xx carrying a well-formed body must be discarded on the status
        // check alone — the parser must never run on a failed response.
        $backend = new SolrSearchBackend(
            new FakeSiteFinder([$this->site()]),
            FakeSolrHttpClient::withJson(self::DOCS_JSON, 500),
        );

        self::assertTrue($backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly())->isEmpty());
    }

    #[Test]
    public function scalarJsonBodyYieldsEmptyResult(): void
    {
        // A non-array decoded body (a bare JSON string) is rejected by the
        // is_array guard before any ['response'] access.
        $backend = new SolrSearchBackend(
            new FakeSiteFinder([$this->site()]),
            FakeSolrHttpClient::withJson('"a bare string, not an object"'),
        );

        self::assertTrue($backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly())->isEmpty());
    }

    #[Test]
    public function fetchSourceRejectsTypeWithLeadingOrTrailingJunk(): void
    {
        $doc = '{"response":{"docs":[{"title":"T","content":"C","url":"https://example.org/x"}]}}';

        $trailing = SourceReference::parse('solr:main:pages.:2:0');
        self::assertNotNull($trailing);
        $backendTrailing = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($doc));
        self::assertNull(
            $backendTrailing->fetchSource($trailing, AccessContext::publicOnly()),
            'a type with a trailing non-word char must fail the anchored ($) regex',
        );

        $leading = SourceReference::parse('solr:main:.pages:2:0');
        self::assertNotNull($leading);
        $backendLeading = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($doc));
        self::assertNull(
            $backendLeading->fetchSource($leading, AccessContext::publicOnly()),
            'a type with a leading non-word char must fail the anchored (^) regex',
        );
    }

    #[Test]
    public function dropsDocumentsWithoutAPositiveUid(): void
    {
        // Missing uid → default 0 → below the `>= 1` floor → dropped. A default
        // of 1, or a weakened OR chain, would wrongly keep it.
        $json = '{"response":{"docs":[
            {"type":"pages","title":"No uid","content":"c","url":"https://example.org/x","language":0}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($json));

        self::assertTrue(
            $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly())->isEmpty(),
        );
    }

    #[Test]
    public function keepsDocumentWithUidEqualToOne(): void
    {
        // uid === 1 is valid: the floor is `< 1`, not `<= 1`.
        $json = '{"response":{"docs":[
            {"type":"pages","uid":1,"title":"One","content":"c","url":"https://example.org/one","language":0}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertCount(1, $result->sources);
        self::assertSame('solr:main:pages:1:0', $result->sources[0]->sourceId);
    }

    #[Test]
    public function usesTheDocumentLanguageIdForTheSource(): void
    {
        // The source language comes from the document's own `language` field,
        // not the query language (coalesce order matters).
        $json = '{"response":{"docs":[
            {"type":"pages","uid":3,"title":"L","content":"c","url":"https://example.org/l","language":5}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertCount(1, $result->sources);
        self::assertSame(5, $result->sources[0]->languageId);
        self::assertSame('solr:main:pages:3:5', $result->sources[0]->sourceId);
    }

    #[Test]
    public function mapsNumericStringScoreAndRejectsNonNumericScore(): void
    {
        $json = '{"response":{"docs":[
            {"type":"pages","uid":10,"title":"A","content":"a","url":"https://example.org/a","language":0,"score":"2.5"},
            {"type":"pages","uid":11,"title":"B","content":"b","url":"https://example.org/b","language":0,"score":"nope"}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertCount(2, $result->sources);
        // Numeric string → float via the explicit (float) cast.
        self::assertSame(2.5, $result->sources[0]->score);
        // Non-numeric score → null (the is_numeric guard is an AND, not an OR).
        self::assertNull($result->sources[1]->score);
    }

    #[Test]
    public function keepsAbsoluteUrlWhenItHasNoParseableHost(): void
    {
        // baseHost is set but the document URL has an empty host: the host
        // comparison is guarded by `$host !== ''` (an AND), so the URL is kept.
        $json = '{"response":{"docs":[
            {"type":"pages","uid":4,"title":"H","content":"c","url":"https:///aikido","language":0}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertCount(1, $result->sources);
        self::assertSame('https:///aikido', $result->sources[0]->url);
    }

    #[Test]
    public function absolutizesRelativeUrlIncludingTheSitePort(): void
    {
        // A relative URL is absolutized against scheme://host:port — the port
        // segment and its ':' separator must both survive.
        $site = $this->site(['base' => 'https://example.org:8443/']);
        $json = '{"response":{"docs":[
            {"type":"pages","uid":6,"title":"N","content":"c","url":"/news","language":0}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$site]), FakeSolrHttpClient::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertCount(1, $result->sources);
        self::assertSame('https://example.org:8443/news', $result->sources[0]->url);
    }

    #[Test]
    public function absolutizesRelativeUrlAgainstSchemeRelativeSiteBase(): void
    {
        // A scheme-relative site base ('base: //host/') has no scheme; the
        // https default must apply — not the degenerate '://example.org/news'.
        $site = $this->site(['base' => '//example.org/']);
        $json = '{"response":{"docs":[
            {"type":"pages","uid":6,"title":"N","content":"c","url":"/news","language":0}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$site]), FakeSolrHttpClient::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertCount(1, $result->sources);
        self::assertSame('https://example.org/news', $result->sources[0]->url);
    }

    #[Test]
    public function schemeRelativeDocumentUrlGetsSchemeOnlyNotADoubleOrigin(): void
    {
        // '//example.org/news' starts with '/', so the plain relative branch
        // would prepend the full origin a second time
        // ('https://example.org//example.org/news'); only the scheme may be added.
        $json = '{"response":{"docs":[
            {"type":"pages","uid":6,"title":"N","content":"c","url":"//example.org/news","language":0}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertCount(1, $result->sources);
        self::assertSame('https://example.org/news', $result->sources[0]->url);
    }

    #[Test]
    public function crossHostSchemeRelativeDocumentUrlIsDropped(): void
    {
        // The shared-core host guard applies to scheme-relative document
        // URLs just as it does to absolute ones.
        $json = '{"response":{"docs":[
            {"type":"pages","uid":9,"title":"F","content":"c","url":"//other.example/aikido","language":0}
        ]}}';
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site()]), FakeSolrHttpClient::withJson($json));

        $result = $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertTrue($result->isEmpty(), 'foreign-host scheme-relative document leaked');
    }

    #[Test]
    public function firstMatchingLanguageConfigWins(): void
    {
        // Two entries for language 0: the loop BREAKS on the first match, so
        // the first core is used (a continue would let the last one win).
        $site = $this->site(languages: [
            ['languageId' => 0, 'title' => 'EN', 'locale' => 'en_US.UTF-8', 'base' => '/', 'solr_core_read' => 'core_first'],
            ['languageId' => 0, 'title' => 'EN2', 'locale' => 'en_US.UTF-8', 'base' => '/', 'solr_core_read' => 'core_second'],
        ]);
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$site]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('https://solr.example:8983/solr/core_first/select?', $uris[0]);
    }

    #[Test]
    public function solrReadEnabledDefaultsToTrueWhenUnset(): void
    {
        // No solr_enabled_read key → the default (true) applies → a request is
        // made. A default of false would silently disable read.
        $site = new Site('main', 1, [
            'base' => 'https://example.org/',
            'solr_scheme_read' => 'https',
            'solr_host_read' => 'solr.example',
            'solr_port_read' => 8983,
            'solr_path_read' => '',
            'solr_core_read' => 'core_en',
            'languages' => [['languageId' => 0, 'title' => 'EN', 'locale' => 'en_US.UTF-8', 'base' => '/']],
        ]);
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$site]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        self::assertCount(1, $requestFactory->requestedUris());
    }

    #[Test]
    public function rejectsCoreWithLeadingOrTrailingUnsafeCharacters(): void
    {
        // The core name is validated by an anchored ^...$ regex; junk on either
        // end must produce no request at all.
        $trailingFactory = FakeSolrHttpClient::withJson(self::DOCS_JSON);
        (new SolrSearchBackend(new FakeSiteFinder([$this->site(['solr_core_read' => 'core_en.'])]), $trailingFactory))
            ->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertSame([], $trailingFactory->requestedUris(), 'trailing junk in the core must be rejected ($ anchor)');

        $leadingFactory = FakeSolrHttpClient::withJson(self::DOCS_JSON);
        (new SolrSearchBackend(new FakeSiteFinder([$this->site(['solr_core_read' => '.core_en'])]), $leadingFactory))
            ->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());
        self::assertSame([], $leadingFactory->requestedUris(), 'leading junk in the core must be rejected (^ anchor)');
    }

    #[Test]
    public function acceptsPortAtLowerBoundaryOne(): void
    {
        // port 1 is inside the accepted range: the floor is `< 1`, not `<= 1`.
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site(['solr_port_read' => 1])]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('https://solr.example:1/solr/core_en/select?', $uris[0]);
    }

    #[Test]
    public function acceptsPortAtUpperBoundary65535(): void
    {
        // port 65535 is inside the accepted range: the ceiling is `> 65535`.
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site(['solr_port_read' => 65535])]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('https://solr.example:65535/solr/core_en/select?', $uris[0]);
    }

    #[Test]
    public function rejectsPortBelowOne(): void
    {
        // port 0 is out of range → no request. Guards the `$port < 1` term.
        $requestFactory = FakeSolrHttpClient::withJson(self::DOCS_JSON);
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site(['solr_port_read' => 0])]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        self::assertSame([], $requestFactory->requestedUris());
    }

    #[Test]
    public function rejectsNonHttpScheme(): void
    {
        // A scheme outside {http,https} → no request. Guards the in_array term.
        $requestFactory = FakeSolrHttpClient::withJson(self::DOCS_JSON);
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site(['solr_scheme_read' => 'ftp'])]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        self::assertSame([], $requestFactory->requestedUris());
    }

    #[Test]
    public function stripsSurroundingSlashesFromConfiguredPath(): void
    {
        // '/custom/' → 'custom' → '/custom/solr/{core}/select'. Pins the outer
        // slash-trim, the '/solr' guard (must NOT fire for a non-solr path),
        // and the leading-slash join.
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site(['solr_path_read' => '/custom/'])]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('https://solr.example:8983/custom/solr/core_en/select?', $uris[0]);
    }

    #[Test]
    public function stripsTrailingSolrSegmentFromALongerPath(): void
    {
        // 'foo/solr' → the trailing '/solr' is peeled to 'foo'. Pins the
        // substr offsets and the rtrim in the '/solr' handling.
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site(['solr_path_read' => '/foo/solr'])]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('https://solr.example:8983/foo/solr/core_en/select?', $uris[0]);
    }

    #[Test]
    public function whitespaceOnlyConfigValueFallsBackToDefault(): void
    {
        // A whitespace-only scheme is treated as unset → default 'http'. The
        // trim inside the emptiness check makes this a fallback, not an empty
        // (invalid) scheme that would suppress the request.
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$this->site(['solr_scheme_read' => '   '])]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('http://solr.example:8983/solr/core_en/select?', $uris[0]);
    }

    #[Test]
    public function missingConfigValueFallsBackToDefault(): void
    {
        // No solr_scheme_read key → default 'http'. If the null fallback were
        // skipped the scheme would be '' and no request made.
        $site = new Site('main', 1, [
            'base' => 'https://example.org/',
            'solr_enabled_read' => true,
            'solr_host_read' => 'solr.example',
            'solr_port_read' => 8983,
            'solr_path_read' => '',
            'solr_core_read' => 'core_en',
            'languages' => [['languageId' => 0, 'title' => 'EN', 'locale' => 'en_US.UTF-8', 'base' => '/']],
        ]);
        $requestFactory = FakeSolrHttpClient::withJson('{"response":{"docs":[]}}');
        $backend = new SolrSearchBackend(new FakeSiteFinder([$site]), $requestFactory);

        $backend->search(RetrievalQuery::create('aikido'), AccessContext::publicOnly());

        $uris = $requestFactory->requestedUris();
        self::assertCount(1, $uris);
        self::assertStringStartsWith('http://solr.example:8983/solr/core_en/select?', $uris[0]);
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
            'solr_scheme_read' => 'https',
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
