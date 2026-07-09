<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Retrieval;

use Netresearch\NrLlm\Service\Retrieval\AccessContext;
use Netresearch\NrLlm\Service\Retrieval\DatabaseSearchBackend;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Service\Retrieval\SourceReference;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\SearchableSchemaFieldsCollector;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for the always-available database retrieval fallback
 * (ADR-049): public rows are found and grouped per page with a routed URL,
 * hidden / access-protected / no_search pages never surface, and
 * fetchSource round-trips a source id back to the page text.
 */
#[CoversClass(DatabaseSearchBackend::class)]
final class DatabaseSearchBackendTest extends AbstractFunctionalTestCase
{
    private DatabaseSearchBackend $backend;

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

        $pages = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1,
            'sorting' => 1, 'is_siteroot' => 1, 'slug' => '/',
        ]);
        $pages->insert('pages', [
            'uid' => 2, 'pid' => 1, 'title' => 'Aikido Migration Services', 'doktype' => 1,
            'sorting' => 2, 'slug' => '/migration', 'abstract' => 'We migrate aikido dojos.',
        ]);
        $pages->insert('pages', [
            'uid' => 3, 'pid' => 1, 'title' => 'Aikido secret draft', 'doktype' => 1,
            'sorting' => 3, 'slug' => '/draft', 'hidden' => 1,
        ]);
        $pages->insert('pages', [
            'uid' => 4, 'pid' => 1, 'title' => 'Aikido members area', 'doktype' => 1,
            'sorting' => 4, 'slug' => '/members', 'fe_group' => '1',
        ]);
        $pages->insert('pages', [
            'uid' => 5, 'pid' => 1, 'title' => 'Aikido unlisted page', 'doktype' => 1,
            'sorting' => 5, 'slug' => '/unlisted', 'no_search' => 1,
        ]);

        $content = $connectionPool->getConnectionForTable('tt_content');
        self::assertInstanceOf(Connection::class, $content);
        $content->insert('tt_content', [
            'uid' => 10, 'pid' => 2, 'colPos' => 0, 'sorting' => 1, 'CType' => 'text',
            'header' => 'Our aikido offering', 'bodytext' => '<p>Aikido migrations done right.</p>',
        ]);
        $content->insert('tt_content', [
            'uid' => 11, 'pid' => 3, 'colPos' => 0, 'sorting' => 1, 'CType' => 'text',
            'header' => 'Draft content', 'bodytext' => 'Aikido secrets nobody should read.',
        ]);
        $content->insert('tt_content', [
            'uid' => 12, 'pid' => 2, 'colPos' => 0, 'sorting' => 2, 'CType' => 'text',
            'header' => 'Protected element', 'bodytext' => 'Aikido insider pricing.', 'fe_group' => '1',
        ]);

        $collector = $this->get(SearchableSchemaFieldsCollector::class);
        self::assertInstanceOf(SearchableSchemaFieldsCollector::class, $collector);
        $siteFinder = $this->get(SiteFinder::class);
        self::assertInstanceOf(SiteFinder::class, $siteFinder);
        $this->backend = new DatabaseSearchBackend($connectionPool, $collector, $siteFinder);
    }

    #[Test]
    public function isAlwaysAvailableWithLowestPriority(): void
    {
        self::assertTrue($this->backend->isAvailable());
        self::assertSame(0, $this->backend->getPriority());
        self::assertSame('database', $this->backend->getIdentifier());
    }

    #[Test]
    public function findsPublicContentGroupedByPageWithRoutedUrl(): void
    {
        $result = $this->backend->search(
            RetrievalQuery::create('aikido'),
            AccessContext::publicOnly(),
        );

        $ids = array_map(static fn($source): string => $source->sourceId, $result->sources);
        self::assertContains('database:2:0', $ids);

        $migration = null;
        foreach ($result->sources as $source) {
            if ($source->sourceId === 'database:2:0') {
                $migration = $source;
            }
        }
        self::assertNotNull($migration);
        self::assertSame('Aikido Migration Services', $migration->title);
        self::assertSame('http://localhost:59999/migration', $migration->url);
        self::assertSame(2, $migration->pageUid);
        self::assertStringContainsString('aikido', mb_strtolower($migration->excerpt));
    }

    #[Test]
    public function hiddenProtectedAndUnlistedPagesNeverSurface(): void
    {
        $result = $this->backend->search(
            RetrievalQuery::create('aikido'),
            AccessContext::publicOnly(),
        );

        $ids = array_map(static fn($source): string => $source->sourceId, $result->sources);
        self::assertNotContains('database:3:0', $ids, 'hidden page leaked');
        self::assertNotContains('database:4:0', $ids, 'fe_group-protected page leaked');
        self::assertNotContains('database:5:0', $ids, 'no_search page leaked');
    }

    #[Test]
    public function noMatchYieldsEmptyList(): void
    {
        $result = $this->backend->search(
            RetrievalQuery::create('zzz-not-present'),
            AccessContext::publicOnly(),
        );

        self::assertTrue($result->isEmpty());
        self::assertSame('database', $result->backend);
    }

    #[Test]
    public function fetchSourceReturnsPublicPageTextWithoutProtectedElements(): void
    {
        $reference = SourceReference::parse('database:2:0');
        self::assertNotNull($reference);

        $text = $this->backend->fetchSource($reference, AccessContext::publicOnly());

        self::assertNotNull($text);
        self::assertStringContainsString('# Aikido Migration Services', $text);
        self::assertStringContainsString('Our aikido offering', $text);
        self::assertStringContainsString('Aikido migrations done right.', $text);
        self::assertStringNotContainsString('insider pricing', $text, 'fe_group-protected element leaked');
    }

    #[Test]
    public function fetchSourceForHiddenPageOrMalformedReferenceIsNull(): void
    {
        $hidden = SourceReference::parse('database:3:0');
        self::assertNotNull($hidden);
        self::assertNull($this->backend->fetchSource($hidden, AccessContext::publicOnly()));

        $tooFewParts = SourceReference::parse('database:2');
        self::assertNotNull($tooFewParts);
        self::assertNull($this->backend->fetchSource($tooFewParts, AccessContext::publicOnly()));
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
}
