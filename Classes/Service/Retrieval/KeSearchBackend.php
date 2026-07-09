<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Retrieval over the ke_search index (ADR-049): reads `tx_kesearch_index`
 * directly — the table IS ke_search's public storage contract (verified
 * against ke_search v6.6/v7, identical schema) — so no PHP dependency on
 * the extension exists.
 *
 * Matching: `MATCH … AGAINST (… IN BOOLEAN MODE)` on (title, content) on
 * MySQL/MariaDB (ke_search's own engine requirement), a LIKE scan
 * elsewhere. `hidden_content` is never matched and never returned:
 * ke_search itself never renders it and it may hold access-restricted
 * text from custom indexers. Public-only: `fe_group` ''/0 plus the
 * start/endtime window; the table has no deleted/hidden columns, so all
 * default restrictions are removed and the enable-fields applied by hand,
 * exactly like ke_search does.
 */
final class KeSearchBackend implements SearchBackendInterface
{
    use SafeCastTrait;

    public const IDENTIFIER = 'ke_search';

    private const TABLE = 'tx_kesearch_index';

    private const MAX_ROWS = 50;

    private ?bool $tableUsable = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function isAvailable(): bool
    {
        if (!ExtensionManagementUtility::isLoaded('ke_search')) {
            return false;
        }

        if ($this->tableUsable === null) {
            $this->tableUsable = $this->indexIsUsable();
        }

        return $this->tableUsable;
    }

    public function search(RetrievalQuery $query, AccessContext $context): EvidenceList
    {
        $sources = [];
        foreach ($this->searchRows($query) as $row) {
            $languageId = max(0, self::toInt($row['language'] ?? 0));
            $url = $this->buildUrl($row, $languageId === 0 ? $query->languageId : $languageId, $query->siteIdentifier);
            if ($url === null) {
                continue;
            }

            $targetPid = self::toInt($row['targetpid'] ?? 0);
            $sources[] = new EvidenceSource(
                sourceId: sprintf('%s:%d', self::IDENTIFIER, self::toInt($row['uid'] ?? 0)),
                title: ExcerptBuilder::plain(self::toStr($row['title'] ?? '')),
                url: $url,
                excerpt: $this->excerpt($row, $query->query),
                backend: self::IDENTIFIER,
                languageId: self::toInt($row['language'] ?? 0),
                pageUid: $this->isPageType(self::toStr($row['type'] ?? '')) && $targetPid > 0 ? $targetPid : null,
                score: isset($row['score']) && is_numeric($row['score']) ? (float)$row['score'] : null,
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

        $uid = self::toInt($reference->parts[0]);
        if ($uid < 1) {
            return null;
        }

        $queryBuilder = $this->publicQueryBuilder();
        $row = $queryBuilder
            ->select('title', 'abstract', 'content')
            ->from(self::TABLE)
            ->andWhere(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $parts = ['# ' . ExcerptBuilder::plain(self::toStr($row['title'] ?? ''))];
        $abstract = ExcerptBuilder::plain(self::toStr($row['abstract'] ?? ''));
        $content = ExcerptBuilder::plain(self::toStr($row['content'] ?? ''));
        if ($abstract !== '') {
            $parts[] = $abstract;
        }
        if ($content !== '') {
            $parts[] = $content;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchRows(RetrievalQuery $query): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $isMysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;

        $queryBuilder = $this->publicQueryBuilder();
        $queryBuilder
            ->select('uid', 'title', 'abstract', 'content', 'type', 'targetpid', 'params', 'orig_uid', 'language')
            ->from(self::TABLE)
            ->andWhere(
                $queryBuilder->expr()->in(
                    'language',
                    $queryBuilder->createNamedParameter([$query->languageId, -1], Connection::PARAM_INT_ARRAY),
                ),
            )
            ->setMaxResults(self::MAX_ROWS);

        if ($isMysql) {
            $term = $this->booleanModeTerm($query->query);
            if ($term === '') {
                return [];
            }
            $placeholder = $queryBuilder->createNamedParameter($term);
            $queryBuilder
                ->addSelectLiteral('MATCH (title, content) AGAINST (' . $placeholder . ') AS score')
                ->andWhere('MATCH (title, content) AGAINST (' . $placeholder . ' IN BOOLEAN MODE)')
                ->orderBy('score', 'DESC');
        } else {
            $like = '%' . $queryBuilder->escapeLikeWildcards($query->query) . '%';
            $queryBuilder
                ->andWhere($queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('title', $queryBuilder->createNamedParameter($like)),
                    $queryBuilder->expr()->like('content', $queryBuilder->createNamedParameter($like)),
                ))
                ->orderBy('sortdate', 'DESC');
        }

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * All words required, MySQL boolean-mode operator characters stripped
     * (they are interpreted by the server and attacker-influenceable).
     */
    private function booleanModeTerm(string $query): string
    {
        $words = [];
        foreach (preg_split('/\s+/', $query) ?: [] as $word) {
            $clean = trim((string)preg_replace('/[+\-<>~*"()@]/', '', $word));
            if (mb_strlen($clean) >= 2) {
                $words[] = '+' . $clean;
            }
        }

        return implode(' ', $words);
    }

    /**
     * Result URL per row type (page / custom record / file / external);
     * null skips the row. When a site filter is set, only rows routable
     * within that site qualify — files and external URLs cannot be
     * attributed to a site and are skipped.
     *
     * @param array<string, mixed> $row
     */
    private function buildUrl(array $row, int $languageId, ?string $siteIdentifier): ?string
    {
        $type = self::toStr($row['type'] ?? '');

        if (str_starts_with($type, 'file')) {
            if ($siteIdentifier !== null) {
                return null;
            }

            return $this->fileUrl($row);
        }

        if ($type === 'external') {
            if ($siteIdentifier !== null) {
                return null;
            }
            $url = self::toStr($row['params'] ?? '');

            return str_starts_with($url, 'https://') || str_starts_with($url, 'http://') ? $url : null;
        }

        $targetPid = self::toInt($row['targetpid'] ?? 0);
        if ($targetPid < 1) {
            return null;
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($targetPid);
        } catch (Throwable) {
            return null;
        }
        if ($siteIdentifier !== null && $site->getIdentifier() !== $siteIdentifier) {
            return null;
        }

        $arguments = [];
        $params = ltrim(self::toStr($row['params'] ?? ''), '&');
        if ($params !== '') {
            parse_str($params, $arguments);
        }
        $arguments['_language'] = $languageId;

        try {
            return (string)$site->getRouter()->generateUri($targetPid, $arguments);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fileUrl(array $row): ?string
    {
        $fileUid = self::toInt($row['orig_uid'] ?? 0);
        if ($fileUid < 1) {
            return null;
        }

        try {
            $url = $this->resourceFactory->getFileObject($fileUid)->getPublicUrl();
        } catch (Throwable) {
            return null;
        }

        return $url !== null && $url !== '' ? $url : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function excerpt(array $row, string $query): string
    {
        $content = self::toStr($row['content'] ?? '');
        $plainContent = ExcerptBuilder::plain($content);
        if ($plainContent !== '' && mb_stripos($plainContent, $query) !== false) {
            return ExcerptBuilder::around($plainContent, $query);
        }

        $abstract = ExcerptBuilder::plain(self::toStr($row['abstract'] ?? ''));
        if ($abstract !== '') {
            return mb_substr($abstract, 0, ExcerptBuilder::DEFAULT_LENGTH);
        }

        return mb_substr($plainContent, 0, ExcerptBuilder::DEFAULT_LENGTH);
    }

    private function isPageType(string $type): bool
    {
        return $type !== 'external' && !str_starts_with($type, 'file');
    }

    /**
     * Query builder with the public-only enable-fields ke_search applies
     * itself: the table has no deleted/hidden columns, so default
     * restrictions are removed and fe_group / start / endtime are added
     * manually.
     */
    private function publicQueryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $now = time();
        $queryBuilder->andWhere(
            $queryBuilder->expr()->or(
                $queryBuilder->expr()->eq('fe_group', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->eq('fe_group', $queryBuilder->createNamedParameter('0')),
            ),
            $queryBuilder->expr()->lte('starttime', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
            $queryBuilder->expr()->or(
                $queryBuilder->expr()->eq('endtime', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('endtime', $queryBuilder->createNamedParameter($now, Connection::PARAM_INT)),
            ),
        );

        return $queryBuilder;
    }

    private function indexIsUsable(): bool
    {
        try {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
            if (!$connection->createSchemaManager()->tablesExist([self::TABLE])) {
                return false;
            }

            $queryBuilder = $connection->createQueryBuilder();
            $row = $queryBuilder
                ->select('uid')
                ->from(self::TABLE)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            return $row !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
