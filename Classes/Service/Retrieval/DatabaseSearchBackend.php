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
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Schema\SearchableSchemaFieldsCollector;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Always-available retrieval fallback (ADR-049): LIKE search across the
 * TCA search fields of `pages` and `tt_content`, grouped per page.
 *
 * Runs when no search extension index exists. Public-only by
 * construction: default restrictions plus a live-workspace restriction
 * drop deleted/hidden/timed/draft rows, `fe_group` must be empty/0,
 * pages must be searchable (`no_search` = 0) content page types
 * (doktype < 199), and the ANCESTOR rootline is walked so pages inside
 * an access-restricted or hidden section (`extendToSubpages`) or under
 * a recycler never surface — the anonymous frontend would not render
 * them either.
 */
final readonly class DatabaseSearchBackend implements SearchBackendInterface
{
    use SafeCastTrait;

    public const IDENTIFIER = 'database';

    private const MAX_ROWS_PER_TABLE = 50;

    public function __construct(
        private ConnectionPool $connectionPool,
        private SearchableSchemaFieldsCollector $searchableFields,
        private SiteFinder $siteFinder,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function search(RetrievalQuery $query, AccessContext $context): EvidenceList
    {
        $languageId = $query->languageId;

        // routeUid (default-language page uid) => partial evidence data.
        /** @var array<int, array{title: string, excerpt: string}> $byPage */
        $byPage = [];

        foreach ($this->searchPages($query->query, $languageId) as $row) {
            $routeUid = $languageId === 0
                ? self::toInt($row['uid'] ?? 0)
                : self::toInt($row['l10n_parent'] ?? 0);
            if ($routeUid < 1 || isset($byPage[$routeUid])) {
                continue;
            }

            $byPage[$routeUid] = [
                'title' => self::toStr($row['title'] ?? ''),
                'excerpt' => $this->excerptFromRow($row, $this->pageFields(), $query->query),
            ];
        }

        foreach ($this->searchContent($query->query, $languageId) as $row) {
            $routeUid = self::toInt($row['pid'] ?? 0);
            if ($routeUid < 1 || isset($byPage[$routeUid])) {
                continue;
            }

            $byPage[$routeUid] = [
                'title' => '',
                'excerpt' => $this->excerptFromRow($row, $this->contentFields(), $query->query),
            ];
        }

        if ($byPage === []) {
            return new EvidenceList(self::IDENTIFIER, []);
        }

        $pageRows = $this->visiblePages(array_keys($byPage), $languageId);

        $sources = [];
        foreach ($byPage as $routeUid => $partial) {
            if (!isset($pageRows[$routeUid])) {
                // Hidden, timed, access-protected or non-searchable page.
                continue;
            }

            $title = $partial['title'] !== '' ? $partial['title'] : $pageRows[$routeUid];
            $url = $this->buildPageUrl($routeUid, $languageId, $query->siteIdentifier);
            if ($url === null) {
                // Outside the requested site (or no site at all).
                continue;
            }

            $sources[] = new EvidenceSource(
                sourceId: sprintf('%s:%d:%d', self::IDENTIFIER, $routeUid, $languageId),
                title: $title,
                url: $url,
                excerpt: $partial['excerpt'],
                backend: self::IDENTIFIER,
                languageId: $languageId,
                pageUid: $routeUid,
            );
            if (count($sources) >= $query->maxSources) {
                break;
            }
        }

        return new EvidenceList(self::IDENTIFIER, $sources);
    }

    public function fetchSource(SourceReference $reference, AccessContext $context): ?string
    {
        if (count($reference->parts) !== 2) {
            return null;
        }

        $routeUid = self::toInt($reference->parts[0]);
        $languageId = self::toInt($reference->parts[1]);
        if ($routeUid < 1 || $languageId < 0) {
            return null;
        }

        $pageRows = $this->visiblePages([$routeUid], $languageId);
        if (!isset($pageRows[$routeUid])) {
            return null;
        }

        $parts = ['# ' . $pageRows[$routeUid]];

        $queryBuilder = $this->liveQueryBuilder('tt_content');
        $rows = $queryBuilder
            ->select('header', 'bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($routeUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([$languageId, -1], Connection::PARAM_INT_ARRAY)),
                $this->publicGroupCondition($queryBuilder),
            )
            ->orderBy('sorting', 'ASC')
            ->setMaxResults(200)
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $header = ExcerptBuilder::plain(self::toStr($row['header'] ?? ''));
            $body = ExcerptBuilder::plain(self::toStr($row['bodytext'] ?? ''));
            if ($header !== '') {
                $parts[] = '## ' . $header;
            }
            if ($body !== '') {
                $parts[] = $body;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchPages(string $query, int $languageId): array
    {
        $fields = $this->pageFields();
        $queryBuilder = $this->liveQueryBuilder('pages');
        $select = array_values(array_unique(array_merge(['uid', 'l10n_parent', 'title'], $fields)));

        $rows = $queryBuilder
            ->select(...$select)
            ->from('pages')
            ->where(
                $this->likeAnyCondition($queryBuilder, $fields, $query),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('no_search', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('doktype', $queryBuilder->createNamedParameter(199, Connection::PARAM_INT)),
                $this->publicGroupCondition($queryBuilder),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(self::MAX_ROWS_PER_TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchContent(string $query, int $languageId): array
    {
        $fields = $this->contentFields();
        $queryBuilder = $this->liveQueryBuilder('tt_content');
        $select = array_values(array_unique(array_merge(['uid', 'pid'], $fields)));

        $rows = $queryBuilder
            ->select(...$select)
            ->from('tt_content')
            ->where(
                $this->likeAnyCondition($queryBuilder, $fields, $query),
                // -1 = "all languages": rendered in every language.
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([$languageId, -1], Connection::PARAM_INT_ARRAY)),
                $this->publicGroupCondition($queryBuilder),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(self::MAX_ROWS_PER_TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values($rows);
    }

    /**
     * The visible, searchable, public default-language rows for the given
     * page uids (or their translations' titles when a language is
     * requested): routeUid => display title.
     *
     * @param list<int> $routeUids
     *
     * @return array<int, string>
     */
    private function visiblePages(array $routeUids, int $languageId): array
    {
        $queryBuilder = $this->liveQueryBuilder('pages');
        $rows = $queryBuilder
            ->select('uid', 'pid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($routeUids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('no_search', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('doktype', $queryBuilder->createNamedParameter(199, Connection::PARAM_INT)),
                $this->publicGroupCondition($queryBuilder),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $titles = [];
        $ancestorCache = [];
        foreach ($rows as $row) {
            // Pages inside a restricted/hidden section or under a recycler
            // are unreachable for the anonymous visitor even when the row
            // itself is public.
            if (!$this->ancestorsArePublic(self::toInt($row['pid'] ?? 0), $ancestorCache)) {
                continue;
            }
            $titles[self::toInt($row['uid'] ?? 0)] = self::toStr($row['title'] ?? '');
        }

        if ($languageId > 0 && $titles !== []) {
            $queryBuilder = $this->liveQueryBuilder('pages');
            $translations = $queryBuilder
                ->select('l10n_parent', 'title')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in('l10n_parent', $queryBuilder->createNamedParameter(array_keys($titles), Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
                    $this->publicGroupCondition($queryBuilder),
                )
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($translations as $row) {
                $parent = self::toInt($row['l10n_parent'] ?? 0);
                if (isset($titles[$parent])) {
                    $titles[$parent] = self::toStr($row['title'] ?? '');
                }
            }
        }

        return $titles;
    }

    /**
     * Routed public URL for the page, or null when the page belongs to no
     * site or not to the requested one.
     */
    private function buildPageUrl(int $routeUid, int $languageId, ?string $siteIdentifier): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($routeUid);
        } catch (Throwable) {
            return null;
        }

        if ($siteIdentifier !== null && $site->getIdentifier() !== $siteIdentifier) {
            return null;
        }

        try {
            return (string)$site->getRouter()->generateUri($routeUid, ['_language' => $languageId]);
        } catch (Throwable) {
            // No routable public URL (missing language, broken site config)
            // — skip the row like the index backends do.
            return null;
        }
    }

    /**
     * @param list<string> $fields
     */
    private function likeAnyCondition(QueryBuilder $queryBuilder, array $fields, string $query): string
    {
        $like = '%' . $queryBuilder->escapeLikeWildcards($query) . '%';
        $conditions = [];
        foreach ($fields as $field) {
            $conditions[] = $queryBuilder->expr()->like($field, $queryBuilder->createNamedParameter($like));
        }

        return (string)$queryBuilder->expr()->or(...$conditions);
    }

    /**
     * Only rows the anonymous visitor could read: fe_group unset or 0.
     */
    private function publicGroupCondition(QueryBuilder $queryBuilder): string
    {
        return (string)$queryBuilder->expr()->or(
            $queryBuilder->expr()->eq('fe_group', $queryBuilder->createNamedParameter('')),
            $queryBuilder->expr()->eq('fe_group', $queryBuilder->createNamedParameter('0')),
        );
    }

    /**
     * Query builder with the default restrictions (deleted/hidden/timed)
     * plus the live-workspace restriction, so unpublished draft rows never
     * match.
     */
    private function liveQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));

        return $queryBuilder;
    }

    /**
     * Whether every ancestor up to the root leaves its subtree reachable
     * for the anonymous visitor: not deleted, no recycler, and no
     * `extendToSubpages` combined with hidden or an fe_group restriction.
     * Memoised per ancestor uid across one retrieval.
     *
     * @param array<int, bool> $cache
     */
    private function ancestorsArePublic(int $parentUid, array &$cache): bool
    {
        $chain = [];
        $current = $parentUid;
        $public = true;

        for ($depth = 0; $current > 0 && $depth < 99; ++$depth) {
            if (isset($cache[$current])) {
                $public = $cache[$current];
                break;
            }
            $chain[] = $current;

            $row = $this->ancestorRow($current);
            if ($row === null || !$this->ancestorAllowsSubtree($row)) {
                $public = false;
                break;
            }
            $current = self::toInt($row['pid'] ?? 0);
        }

        foreach ($chain as $uid) {
            $cache[$uid] = $public;
        }

        return $public;
    }

    /**
     * The ancestor row incl. hidden — a hidden ancestor without
     * `extendToSubpages` still serves its children, so it must be
     * inspected rather than filtered away.
     *
     * @return array<string, mixed>|null
     */
    private function ancestorRow(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));

        $row = $queryBuilder
            ->select('uid', 'pid', 'hidden', 'extendToSubpages', 'fe_group', 'doktype', 'starttime', 'endtime')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function ancestorAllowsSubtree(array $row): bool
    {
        if (self::toInt($row['doktype'] ?? 0) === 255) {
            return false;
        }

        if (self::toInt($row['extendToSubpages'] ?? 0) !== 1) {
            return true;
        }

        $feGroup = self::toStr($row['fe_group'] ?? '');
        $now = time();
        $endtime = self::toInt($row['endtime'] ?? 0);

        return self::toInt($row['hidden'] ?? 0) === 0
            && ($feGroup === '' || $feGroup === '0')
            && self::toInt($row['starttime'] ?? 0) <= $now
            && ($endtime === 0 || $endtime > $now);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $fields
     */
    private function excerptFromRow(array $row, array $fields, string $query): string
    {
        $fallback = '';
        foreach ($fields as $field) {
            $value = self::toStr($row[$field] ?? '');
            if ($value === '') {
                continue;
            }
            $plain = ExcerptBuilder::plain($value);
            if ($fallback === '') {
                $fallback = mb_substr($plain, 0, ExcerptBuilder::DEFAULT_LENGTH);
            }
            if (mb_stripos($plain, $query) !== false) {
                return ExcerptBuilder::around($plain, $query);
            }
        }

        return $fallback;
    }

    /**
     * @return list<string>
     */
    private function pageFields(): array
    {
        $fields = array_values($this->searchableFields->getFieldNames('pages'));

        return $fields !== [] ? $fields : ['title'];
    }

    /**
     * @return list<string>
     */
    private function contentFields(): array
    {
        $fields = array_values($this->searchableFields->getFieldNames('tt_content'));

        return $fields !== [] ? $fields : ['header', 'bodytext'];
    }
}
