<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Retrieval over the core indexed_search index (ADR-049): reads the
 * `index_*` tables directly — they carry no TCA and no stable PHP API
 * (classes are @internal), so raw table access is the decoupled
 * integration surface; schema verified identical on 13.4 and 14.x.
 *
 * Matching uses the word-hash path (`index_words.wid` = md5 of the
 * LOWERCASED word, computed in PHP — DBMS-agnostic), all words required,
 * ranked like the core's default `rank_flag` order. When the word tables
 * are empty (extension config `useMysqlFulltext` leaves them unpopulated)
 * a LIKE scan over `index_fulltext.fulltextdata` takes over.
 *
 * Public-only: `gr_list` must be the anonymous '0,-1' on the row itself
 * or on a matching `index_grlist` row; regular page rows only
 * (`item_type` '0', `freeIndexUid` 0).
 */
final class IndexedSearchBackend implements SearchBackendInterface
{
    use SafeCastTrait;

    public const IDENTIFIER = 'indexed_search';

    private const PUBLIC_GR_LIST = '0,-1';

    private const MAX_ROWS = 50;

    private ?bool $tableUsable = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function isAvailable(): bool
    {
        if (!ExtensionManagementUtility::isLoaded('indexed_search')) {
            return false;
        }

        if ($this->tableUsable === null) {
            $this->tableUsable = $this->indexIsUsable();
        }

        return $this->tableUsable;
    }

    public function search(RetrievalQuery $query, AccessContext $context): EvidenceList
    {
        $words = $this->queryWords($query->query);
        if ($words === []) {
            return new EvidenceList(self::IDENTIFIER, []);
        }

        $rows = $this->wordTablesArePopulated()
            ? $this->searchByWordHash($words, $query->languageId)
            : $this->searchByFulltext($query->query, $query->languageId);

        $fulltext = $this->fulltextByPhash(array_map(
            static fn(array $row): string => self::toStr($row['phash'] ?? ''),
            $rows,
        ));

        $sources = [];
        foreach ($rows as $row) {
            $pageUid = self::toInt($row['data_page_id'] ?? 0);
            if ($pageUid < 1) {
                continue;
            }

            $url = $this->buildUrl($row, $query->siteIdentifier);
            if ($url === null) {
                continue;
            }

            $phash = self::toStr($row['phash'] ?? '');
            $text = $fulltext[$phash] ?? '';
            $excerpt = $text !== ''
                ? ExcerptBuilder::around($text, $query->query)
                : mb_substr(ExcerptBuilder::plain(self::toStr($row['item_description'] ?? '')), 0, ExcerptBuilder::DEFAULT_LENGTH);

            $sources[] = new EvidenceSource(
                sourceId: sprintf('%s:%s', self::IDENTIFIER, $phash),
                title: ExcerptBuilder::plain(self::toStr($row['item_title'] ?? '')),
                url: $url,
                excerpt: $excerpt,
                backend: self::IDENTIFIER,
                languageId: self::toInt($row['sys_language_uid'] ?? 0),
                pageUid: $pageUid,
            );
            if (count($sources) >= $query->maxSources) {
                break;
            }
        }

        return new EvidenceList(self::IDENTIFIER, $sources);
    }

    public function fetchSource(SourceReference $reference, AccessContext $context): ?string
    {
        if (count($reference->parts) !== 1) {
            return null;
        }
        $phash = $reference->parts[0];

        $queryBuilder = $this->phashQueryBuilder();
        $row = $queryBuilder
            ->select('IP.phash', 'IP.item_title')
            ->andWhere(
                $queryBuilder->expr()->eq('IP.phash', $queryBuilder->createNamedParameter($phash)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $fulltext = $this->fulltextByPhash([$phash]);
        $parts = ['# ' . ExcerptBuilder::plain(self::toStr($row['item_title'] ?? ''))];
        if (($fulltext[$phash] ?? '') !== '') {
            $parts[] = $fulltext[$phash];
        }

        return implode("\n\n", $parts);
    }

    /**
     * Lowercased words of the query — matching the Lexer's index-time
     * lowercasing, so `md5(word)` equals the stored `wid`.
     *
     * @return list<string>
     */
    private function queryWords(string $query): array
    {
        $split = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];

        return array_values(array_filter($split, static fn(string $word): bool => mb_strlen($word) >= 2));
    }

    /**
     * All-words-required word-hash join, ordered like the core's default
     * `rank_flag` mode (title/keyword weight, then frequency).
     *
     * @param list<string> $words
     *
     * @return list<array<string, mixed>>
     */
    private function searchByWordHash(array $words, int $languageId): array
    {
        $hashes = array_map(static fn(string $word): string => md5($word), $words);

        $queryBuilder = $this->phashQueryBuilder();
        $queryBuilder
            ->join('IP', 'index_rel', 'IR', 'IR.phash = IP.phash')
            ->join('IR', 'index_words', 'IW', 'IW.wid = IR.wid')
            ->andWhere(
                $queryBuilder->expr()->in('IW.wid', $queryBuilder->createNamedParameter($hashes, Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->eq('IW.is_stopword', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('IP.sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
            )
            ->groupBy('IP.phash', 'IP.data_page_id', 'IP.data_page_type', 'IP.static_page_arguments', 'IP.item_title', 'IP.item_description', 'IP.sys_language_uid')
            ->having('COUNT(DISTINCT IW.wid) = ' . count($hashes))
            ->addSelectLiteral('MAX(IR.flags) AS rank_flags', 'SUM(IR.freq) AS rank_freq')
            ->orderBy('rank_flags', 'DESC')
            ->addOrderBy('rank_freq', 'DESC')
            ->setMaxResults(self::MAX_ROWS);

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * LIKE scan over index_fulltext for installations where
     * `useMysqlFulltext` leaves the word tables empty.
     *
     * @return list<array<string, mixed>>
     */
    private function searchByFulltext(string $query, int $languageId): array
    {
        $queryBuilder = $this->phashQueryBuilder();
        $like = '%' . $queryBuilder->escapeLikeWildcards($query) . '%';
        $queryBuilder
            ->join('IP', 'index_fulltext', 'IFT', 'IFT.phash = IP.phash')
            ->andWhere(
                $queryBuilder->expr()->like('IFT.fulltextdata', $queryBuilder->createNamedParameter($like)),
                $queryBuilder->expr()->eq('IP.sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
            )
            ->groupBy('IP.phash', 'IP.data_page_id', 'IP.data_page_type', 'IP.static_page_arguments', 'IP.item_title', 'IP.item_description', 'IP.sys_language_uid')
            ->orderBy('IP.item_mtime', 'DESC')
            ->setMaxResults(self::MAX_ROWS);

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * Base query: public page rows from index_phash (aliased IP), the
     * anonymous-visitor gr_list on the row or on an index_grlist row.
     */
    private function phashQueryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('index_phash');
        $queryBuilder->getRestrictions()->removeAll();

        $publicList = $queryBuilder->createNamedParameter(self::PUBLIC_GR_LIST);
        $queryBuilder
            ->select('IP.phash', 'IP.data_page_id', 'IP.data_page_type', 'IP.static_page_arguments', 'IP.item_title', 'IP.item_description', 'IP.sys_language_uid')
            ->from('index_phash', 'IP')
            ->leftJoin('IP', 'index_grlist', 'IG', 'IG.phash = IP.phash AND IG.gr_list = ' . $publicList)
            ->andWhere(
                $queryBuilder->expr()->eq('IP.item_type', $queryBuilder->createNamedParameter('0')),
                $queryBuilder->expr()->eq('IP.freeIndexUid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('IP.gr_list', $publicList),
                    $queryBuilder->expr()->isNotNull('IG.phash'),
                ),
            );

        return $queryBuilder;
    }

    /**
     * @param list<string> $phashes
     *
     * @return array<string, string> phash => plain fulltext
     */
    private function fulltextByPhash(array $phashes): array
    {
        $phashes = array_values(array_filter($phashes, static fn(string $phash): bool => $phash !== ''));
        if ($phashes === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('index_fulltext');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('phash', 'fulltextdata')
            ->from('index_fulltext')
            ->where(
                $queryBuilder->expr()->in('phash', $queryBuilder->createNamedParameter($phashes, Connection::PARAM_STR_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[self::toStr($row['phash'] ?? '')] = ExcerptBuilder::plain(self::toStr($row['fulltextdata'] ?? ''));
        }

        return $result;
    }

    /**
     * Routed URL from the indexed route data: page id + page type +
     * recorded static route arguments; the router recomputes cHash.
     *
     * @param array<string, mixed> $row
     */
    private function buildUrl(array $row, ?string $siteIdentifier): ?string
    {
        $pageUid = self::toInt($row['data_page_id'] ?? 0);

        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (Throwable) {
            return null;
        }
        if ($siteIdentifier !== null && $site->getIdentifier() !== $siteIdentifier) {
            return null;
        }

        $arguments = [];
        $encoded = self::toStr($row['static_page_arguments'] ?? '');
        if ($encoded !== '') {
            try {
                $decoded = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $arguments = $decoded;
                }
            } catch (Throwable) {
                // Unusable recorded arguments — link the plain page.
            }
        }

        $pageType = self::toInt($row['data_page_type'] ?? 0);
        if ($pageType > 0) {
            $arguments['type'] = $pageType;
        }
        $arguments['_language'] = self::toInt($row['sys_language_uid'] ?? 0);

        try {
            return (string)$site->getRouter()->generateUri($pageUid, $arguments);
        } catch (Throwable) {
            return null;
        }
    }

    private function wordTablesArePopulated(): bool
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('index_words');
            $queryBuilder->getRestrictions()->removeAll();

            return $queryBuilder
                ->select('wid')
                ->from('index_words')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function indexIsUsable(): bool
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable('index_phash');
            if (!$connection->createSchemaManager()->tablesExist(['index_phash', 'index_fulltext', 'index_words', 'index_rel', 'index_grlist'])) {
                return false;
            }

            $queryBuilder = $connection->createQueryBuilder();
            $row = $queryBuilder
                ->select('phash')
                ->from('index_phash')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            return $row !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
