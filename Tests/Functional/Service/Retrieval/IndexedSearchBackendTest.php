<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Retrieval;

use Netresearch\NrLlm\Service\Retrieval\AccessContext;
use Netresearch\NrLlm\Service\Retrieval\IndexedSearchBackend;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Service\Retrieval\SourceReference;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for the indexed_search retrieval backend (ADR-049)
 * against the real core `index_*` schema: word-hash matching with
 * all-words-required semantics, the anonymous gr_list guard, the
 * fulltext LIKE fallback for useMysqlFulltext installations, and the
 * source round-trip.
 */
#[CoversClass(IndexedSearchBackend::class)]
final class IndexedSearchBackendTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
        'indexed_search',
    ];

    private IndexedSearchBackend $backend;

    private ConnectionPool $connectionPool;

    private string $publicPhash;

    private string $restrictedPhash;

    private string $regainedPhash;

    protected function setUp(): void
    {
        parent::setUp();

        $siteDir = $this->instancePath . '/typo3conf/sites/main';
        GeneralUtility::mkdir_deep($siteDir);
        file_put_contents($siteDir . '/config.yaml', <<<YAML
            rootPageId: 1
            base: 'http://localhost:59999/'
            languages:
              - languageId: 0
                title: English
                locale: en_US.UTF-8
                base: /
            YAML);

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $pages = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1,
            'sorting' => 1, 'is_siteroot' => 1, 'slug' => '/',
        ]);
        $pages->insert('pages', [
            'uid' => 2, 'pid' => 1, 'title' => 'Migration', 'doktype' => 1,
            'sorting' => 2, 'slug' => '/migration',
        ]);

        $this->publicPhash = self::indexHash('public-doc');
        $this->restrictedPhash = self::indexHash('restricted-doc');
        $this->regainedPhash = self::indexHash('regained-doc');

        $connection = $connectionPool->getConnectionForTable('index_phash');
        self::assertInstanceOf(Connection::class, $connection);

        $this->insertDocument($connection, $this->publicPhash, 2, 'Aikido Migration Services', '0,-1');
        $this->insertDocument($connection, $this->restrictedPhash, 2, 'Aikido insider plan', '0,-1,5');
        $this->insertDocument($connection, $this->regainedPhash, 2, 'Aikido public schedule', '0,-1,7');
        // The "regained" document was ALSO rendered by an anonymous session.
        $connection->insert('index_grlist', [
            'phash' => $this->regainedPhash, 'phash_x' => $this->regainedPhash,
            'hash_gr_list' => self::indexHash('0,-1'), 'gr_list' => '0,-1',
        ]);

        $connection->insert('index_fulltext', [
            'phash' => $this->publicPhash,
            'fulltextdata' => 'Aikido migrations done right with belts and mats.',
        ]);
        $connection->insert('index_fulltext', [
            'phash' => $this->restrictedPhash,
            'fulltextdata' => 'Aikido insider pricing nobody public should read.',
        ]);
        $connection->insert('index_fulltext', [
            'phash' => $this->regainedPhash,
            'fulltextdata' => 'Aikido training schedule open to everyone.',
        ]);

        foreach (['aikido', 'migration', 'insider', 'schedule'] as $word) {
            $connection->insert('index_words', ['wid' => self::indexHash($word), 'baseword' => $word]);
        }
        $this->relate($connection, $this->publicPhash, 'aikido', 4, 12);
        $this->relate($connection, $this->publicPhash, 'migration', 4, 8);
        $this->relate($connection, $this->restrictedPhash, 'aikido', 0, 5);
        $this->relate($connection, $this->restrictedPhash, 'insider', 0, 5);
        $this->relate($connection, $this->regainedPhash, 'aikido', 0, 3);
        $this->relate($connection, $this->regainedPhash, 'schedule', 0, 3);

        $this->backend = $this->createBackend();
    }

    #[Test]
    public function isAvailableWithLoadedExtensionAndPopulatedIndex(): void
    {
        self::assertTrue($this->backend->isAvailable());
        self::assertSame('indexed_search', $this->backend->getIdentifier());
    }

    #[Test]
    public function wordHashSearchFindsPublicDocumentsOnly(): void
    {
        $result = $this->backend->search(
            RetrievalQuery::create('Aikido'),
            AccessContext::publicOnly(),
        );

        $ids = array_map(static fn($source): string => $source->sourceId, $result->sources);
        self::assertContains('indexed_search:' . $this->publicPhash, $ids);
        self::assertContains('indexed_search:' . $this->regainedPhash, $ids, 'anonymous index_grlist row ignored');
        self::assertNotContains('indexed_search:' . $this->restrictedPhash, $ids, 'group-restricted document leaked');

        $first = $result->sources[0];
        self::assertSame('Aikido Migration Services', $first->title, 'rank_flag ordering violated');
        self::assertSame('http://localhost:59999/migration', $first->url);
        self::assertSame(2, $first->pageUid);
        self::assertStringContainsString('belts and mats', $first->excerpt);
    }

    #[Test]
    public function allQueryWordsAreRequired(): void
    {
        $result = $this->backend->search(
            RetrievalQuery::create('aikido migration'),
            AccessContext::publicOnly(),
        );
        $ids = array_map(static fn($source): string => $source->sourceId, $result->sources);
        self::assertSame(['indexed_search:' . $this->publicPhash], $ids);

        $none = $this->backend->search(
            RetrievalQuery::create('aikido zzznotindexed'),
            AccessContext::publicOnly(),
        );
        self::assertTrue($none->isEmpty());
    }

    #[Test]
    public function repeatedWordsAndStopwordsDoNotInflateTheRequiredCount(): void
    {
        // 'the' is indexed as a stopword (no index_rel rows, like the core
        // Lexer produces) — it must be ignored, not required.
        $connection = $this->connectionPool->getConnectionForTable('index_words');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->insert('index_words', ['wid' => self::indexHash('the'), 'baseword' => 'the', 'is_stopword' => 1]);

        $repeated = $this->backend->search(
            RetrievalQuery::create('aikido aikido migration'),
            AccessContext::publicOnly(),
        );
        $ids = array_map(static fn($source): string => $source->sourceId, $repeated->sources);
        self::assertSame(['indexed_search:' . $this->publicPhash], $ids, 'duplicate query word broke the required count');

        $withStopword = $this->backend->search(
            RetrievalQuery::create('the aikido migration'),
            AccessContext::publicOnly(),
        );
        $ids = array_map(static fn($source): string => $source->sourceId, $withStopword->sources);
        self::assertSame(['indexed_search:' . $this->publicPhash], $ids, 'stopword was treated as a required word');
    }

    #[Test]
    public function emptyWordTablesFallBackToFulltextLike(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('index_words');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->truncate('index_words');
        $connection->truncate('index_rel');

        $result = $this->createBackend()->search(
            RetrievalQuery::create('migrations done right'),
            AccessContext::publicOnly(),
        );

        $ids = array_map(static fn($source): string => $source->sourceId, $result->sources);
        self::assertSame(['indexed_search:' . $this->publicPhash], $ids);

        // The gr_list guard must hold on the LIKE fallback path too: the
        // restricted document's fulltext matches, but must not surface.
        $restricted = $this->createBackend()->search(
            RetrievalQuery::create('insider pricing'),
            AccessContext::publicOnly(),
        );
        self::assertTrue($restricted->isEmpty(), 'group-restricted document leaked on the LIKE path');
    }

    #[Test]
    public function nonPageItemTypesNeverSurface(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('index_phash');
        self::assertInstanceOf(Connection::class, $connection);

        $filePhash = self::indexHash('file-doc');
        $connection->insert('index_phash', [
            'phash' => $filePhash,
            'phash_grouping' => $filePhash,
            'contentHash' => self::indexHash('content-' . $filePhash),
            'data_page_id' => 2,
            'gr_list' => '0,-1',
            'item_type' => 'pdf',
            'item_title' => 'Aikido brochure.pdf',
            'sys_language_uid' => 0,
        ]);
        $connection->insert('index_fulltext', [
            'phash' => $filePhash, 'fulltextdata' => 'Aikido brochure download.',
        ]);
        $this->relate($connection, $filePhash, 'aikido', 0, 2);

        $result = $this->backend->search(
            RetrievalQuery::create('aikido'),
            AccessContext::publicOnly(),
        );

        $ids = array_map(static fn($source): string => $source->sourceId, $result->sources);
        self::assertNotContains('indexed_search:' . $filePhash, $ids, 'non-page item_type leaked');
    }

    #[Test]
    public function fetchSourceRoundTripsPublicDocumentsAndBlocksRestricted(): void
    {
        $public = SourceReference::parse('indexed_search:' . $this->publicPhash);
        self::assertNotNull($public);
        $text = $this->backend->fetchSource($public, AccessContext::publicOnly());

        self::assertNotNull($text);
        self::assertStringContainsString('# Aikido Migration Services', $text);
        self::assertStringContainsString('belts and mats', $text);

        $restricted = SourceReference::parse('indexed_search:' . $this->restrictedPhash);
        self::assertNotNull($restricted);
        self::assertNull($this->backend->fetchSource($restricted, AccessContext::publicOnly()));

        $malformed = SourceReference::parse('indexed_search:abc:def');
        self::assertNotNull($malformed);
        self::assertNull($this->backend->fetchSource($malformed, AccessContext::publicOnly()));
    }

    #[Test]
    public function siteFilterExcludesForeignSites(): void
    {
        $result = $this->backend->search(
            RetrievalQuery::create('aikido', 8, 'other-site'),
            AccessContext::publicOnly(),
        );

        self::assertTrue($result->isEmpty());
    }

    private function createBackend(): IndexedSearchBackend
    {
        $siteFinder = $this->get(SiteFinder::class);
        self::assertInstanceOf(SiteFinder::class, $siteFinder);

        return new IndexedSearchBackend($this->connectionPool, $siteFinder);
    }

    private function insertDocument(Connection $connection, string $phash, int $pageUid, string $title, string $grList): void
    {
        $connection->insert('index_phash', [
            'phash' => $phash,
            'phash_grouping' => $phash,
            'contentHash' => self::indexHash('content-' . $phash),
            'data_page_id' => $pageUid,
            'gr_list' => $grList,
            'item_type' => '0',
            'item_title' => $title,
            'item_description' => 'Description of ' . $title,
            'sys_language_uid' => 0,
        ]);
    }

    private function relate(Connection $connection, string $phash, string $word, int $flags, int $freq): void
    {
        $connection->insert('index_rel', [
            'phash' => $phash, 'wid' => self::indexHash($word),
            'count' => 1, 'first' => 1, 'freq' => $freq, 'flags' => $flags,
        ]);
    }

    /**
     * The 32-char hash the core index tables store (`index_words.wid` IS
     * the md5 of the lowercased word since TYPO3 13, #102975) — a storage
     * FORMAT, not a security control.
     */
    private static function indexHash(string $value): string
    {
        return md5($value); // NOSONAR php:S4790 — index table format, not cryptography
    }
}
