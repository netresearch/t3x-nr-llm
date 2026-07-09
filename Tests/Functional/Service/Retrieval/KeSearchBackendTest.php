<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Retrieval;

use Netresearch\NrLlm\Service\Retrieval\AccessContext;
use Netresearch\NrLlm\Service\Retrieval\KeSearchBackend;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Service\Retrieval\SourceReference;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for the ke_search retrieval backend (ADR-049) against
 * the REAL tx_kesearch_index schema (ke_search installed as test
 * extension). SQLite exercises the LIKE path; the MATCH…AGAINST path is
 * platform-gated to MySQL/MariaDB and shares all filters with it.
 */
#[CoversClass(KeSearchBackend::class)]
final class KeSearchBackendTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'tpwd/ke_search',
    ];

    private KeSearchBackend $backend;

    private ConnectionPool $connectionPool;

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

        $index = $connectionPool->getConnectionForTable('tx_kesearch_index');
        self::assertInstanceOf(Connection::class, $index);
        $index->insert('tx_kesearch_index', [
            'uid' => 1, 'pid' => 9, 'type' => 'page', 'targetpid' => '2',
            'title' => 'Aikido Migration Services',
            'abstract' => 'We migrate aikido dojos.',
            'content' => 'Aikido migrations done right, with belts and mats.',
            'hidden_content' => 'SECRET-HIDDEN-TAGS',
            'params' => '', 'language' => 0, 'fe_group' => '0', 'sortdate' => 100,
        ]);
        $index->insert('tx_kesearch_index', [
            'uid' => 2, 'pid' => 9, 'type' => 'page', 'targetpid' => '2',
            'title' => 'Aikido members-only training plan',
            'content' => 'Aikido insider schedule.', 'params' => '',
            'language' => 0, 'fe_group' => '1', 'sortdate' => 90,
        ]);
        $index->insert('tx_kesearch_index', [
            'uid' => 3, 'pid' => 9, 'type' => 'page', 'targetpid' => '2',
            'title' => 'Aikido expired announcement',
            'content' => 'Aikido event long gone.', 'params' => '',
            'language' => 0, 'fe_group' => '0', 'endtime' => 1000, 'sortdate' => 80,
        ]);
        $index->insert('tx_kesearch_index', [
            'uid' => 4, 'pid' => 9, 'type' => 'page', 'targetpid' => '2',
            'title' => 'Aikido auf Deutsch',
            'content' => 'Aikido Migrationen richtig gemacht.', 'params' => '',
            'language' => 1, 'fe_group' => '0', 'sortdate' => 70,
        ]);
        $index->insert('tx_kesearch_index', [
            'uid' => 5, 'pid' => 9, 'type' => 'external',
            'targetpid' => '0', 'title' => 'Aikido federation',
            'content' => 'Aikido rules of the federation.',
            'params' => 'https://aikido.example.org/rules',
            'language' => -1, 'fe_group' => '0', 'sortdate' => 60,
        ]);

        $siteFinder = $this->get(SiteFinder::class);
        self::assertInstanceOf(SiteFinder::class, $siteFinder);
        $resourceFactory = $this->get(ResourceFactory::class);
        self::assertInstanceOf(ResourceFactory::class, $resourceFactory);
        $this->backend = new KeSearchBackend($connectionPool, $siteFinder, $resourceFactory);
    }

    #[Test]
    public function isAvailableWithLoadedExtensionAndPopulatedIndex(): void
    {
        self::assertTrue($this->backend->isAvailable());
        self::assertSame('ke_search', $this->backend->getIdentifier());
        self::assertGreaterThan(0, $this->backend->getPriority());
    }

    #[Test]
    public function emptyIndexMakesTheBackendUnavailable(): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_kesearch_index');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->truncate('tx_kesearch_index');

        $siteFinder = $this->get(SiteFinder::class);
        self::assertInstanceOf(SiteFinder::class, $siteFinder);
        $resourceFactory = $this->get(ResourceFactory::class);
        self::assertInstanceOf(ResourceFactory::class, $resourceFactory);
        $freshBackend = new KeSearchBackend($this->connectionPool, $siteFinder, $resourceFactory);

        self::assertFalse($freshBackend->isAvailable());
    }

    #[Test]
    public function findsPublicRowsWithRoutedUrlAndAllLanguagesRows(): void
    {
        $result = $this->backend->search(
            RetrievalQuery::create('aikido'),
            AccessContext::publicOnly(),
        );

        $ids = array_map(static fn($source): string => $source->sourceId, $result->sources);
        self::assertContains('ke_search:1', $ids, 'public page row missing');
        self::assertContains('ke_search:5', $ids, 'language=-1 external row missing');

        foreach ($result->sources as $source) {
            if ($source->sourceId === 'ke_search:1') {
                self::assertSame('Aikido Migration Services', $source->title);
                self::assertSame('http://localhost:59999/migration', $source->url);
                self::assertSame(2, $source->pageUid);
                self::assertStringContainsString('aikido', mb_strtolower($source->excerpt));
            }
            if ($source->sourceId === 'ke_search:5') {
                self::assertSame('https://aikido.example.org/rules', $source->url);
                self::assertNull($source->pageUid);
            }
        }
    }

    #[Test]
    public function restrictedExpiredAndForeignLanguageRowsNeverSurface(): void
    {
        $result = $this->backend->search(
            RetrievalQuery::create('aikido'),
            AccessContext::publicOnly(),
        );

        $ids = array_map(static fn($source): string => $source->sourceId, $result->sources);
        self::assertNotContains('ke_search:2', $ids, 'fe_group-restricted row leaked');
        self::assertNotContains('ke_search:3', $ids, 'expired row leaked');
        self::assertNotContains('ke_search:4', $ids, 'foreign-language row leaked');
    }

    #[Test]
    public function hiddenContentNeverReachesExcerptOrFetchedText(): void
    {
        $result = $this->backend->search(
            RetrievalQuery::create('aikido'),
            AccessContext::publicOnly(),
        );
        foreach ($result->sources as $source) {
            self::assertStringNotContainsString('SECRET-HIDDEN-TAGS', $source->excerpt);
        }

        $reference = SourceReference::parse('ke_search:1');
        self::assertNotNull($reference);
        $text = $this->backend->fetchSource($reference, AccessContext::publicOnly());

        self::assertNotNull($text);
        self::assertStringContainsString('# Aikido Migration Services', $text);
        self::assertStringContainsString('We migrate aikido dojos.', $text);
        self::assertStringContainsString('belts and mats', $text);
        self::assertStringNotContainsString('SECRET-HIDDEN-TAGS', $text, 'hidden_content leaked');
    }

    #[Test]
    public function fetchSourceForRestrictedRowOrBadReferenceIsNull(): void
    {
        $restricted = SourceReference::parse('ke_search:2');
        self::assertNotNull($restricted);
        self::assertNull($this->backend->fetchSource($restricted, AccessContext::publicOnly()));

        $missing = SourceReference::parse('ke_search:999');
        self::assertNotNull($missing);
        self::assertNull($this->backend->fetchSource($missing, AccessContext::publicOnly()));

        $malformed = SourceReference::parse('ke_search:1:extra');
        self::assertNotNull($malformed);
        self::assertNull($this->backend->fetchSource($malformed, AccessContext::publicOnly()));
    }

    #[Test]
    public function siteFilterDropsExternalAndForeignRows(): void
    {
        $result = $this->backend->search(
            RetrievalQuery::create('aikido', 8, 'other-site'),
            AccessContext::publicOnly(),
        );
        self::assertTrue($result->isEmpty());

        $result = $this->backend->search(
            RetrievalQuery::create('aikido', 8, 'main'),
            AccessContext::publicOnly(),
        );
        $ids = array_map(static fn($source): string => $source->sourceId, $result->sources);
        self::assertContains('ke_search:1', $ids);
        self::assertNotContains('ke_search:5', $ids, 'external row not attributable to a site');
    }
}
