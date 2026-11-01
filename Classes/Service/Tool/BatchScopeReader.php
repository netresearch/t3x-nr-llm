<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The records a background batch covers (ADR-236): the files of a folder, the
 * pages of a tree, and of those the ones a named field leaves empty.
 *
 * This reads, and it reads as much as the user may: a page the user may not show
 * is not listed, and a file is authorised per file afterwards, by the catalogue.
 * Everything here is a filter. The answer to "may this run" stays where ADR-162
 * put it, at one catalogue call per record.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class BatchScopeReader
{
    /** The widest tree a batch walks: a typo in a depth must not read a site. */
    public const MAX_DEPTH = 10;

    /** Spacers, storage folders and recyclers come from here on. */
    private const FIRST_DOKTYPE_WITHOUT_CONTENT = 199;

    public function __construct(
        private ResourceFactory $resourceFactory,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * The uids of the files in a folder, subfolders included, or null when the
     * folder cannot be read. Null, and not an empty list: "that folder does not
     * exist" and "that folder is empty" are different answers to an operator.
     *
     * @return list<int>|null
     */
    public function filesIn(string $combinedIdentifier): ?array
    {
        try {
            $files = $this->resourceFactory
                ->getFolderObjectFromCombinedIdentifier($combinedIdentifier)
                ->getFiles(0, 0, Folder::FILTER_MODE_USE_OWN_AND_STORAGE_FILTERS, true);
        } catch (Throwable) {
            return null;
        }

        $uids = [];
        foreach ($files as $file) {
            if ($file instanceof File) {
                $uids[] = $file->getUid();
            }
        }

        sort($uids);

        return $uids;
    }

    /**
     * The uids of a page and the pages below it down to `$depth` levels, or null
     * when the user cannot see that page. A depth of 0 is the page alone.
     *
     * A tree is walked as far as the user may show it, from a page inside their
     * mounts, and the answer holds what a writer could write: pages the user may
     * edit, and no folders or recyclers, which have no text. Each of the others
     * would be a model call that can only end in a refusal.
     *
     * @return list<int>|null
     */
    public function pagesIn(int $rootUid, int $depth, BackendUserAuthentication $user): ?array
    {
        // A tree is its default-language pages, and the writers refuse a user
        // without that language.
        if (!$user->checkLanguageAccess(0)) {
            return null;
        }

        $found = $this->pagesWhere('uid', [$rootUid], $user, Permission::PAGE_SHOW);
        if ($found === [] || !$user->isInWebMount($rootUid)) {
            return null;
        }

        $level = $found;
        for ($i = 0; $i < min($depth, self::MAX_DEPTH) && $level !== []; ++$i) {
            $level = array_values(array_diff($this->pagesWhere('pid', $level, $user, Permission::PAGE_SHOW), $found));
            array_push($found, ...$level);
        }

        return $this->pagesWhere('uid', $found, $user, Permission::PAGE_EDIT, self::FIRST_DOKTYPE_WITHOUT_CONTENT);
    }

    /**
     * Those of `$uids` with all of `$fields` empty: the records there is something
     * to do for. Nothing holds a value it would lose. Null for a field the table
     * does not have, which would otherwise read as "all filled".
     *
     * A file's fields are on its default-language metadata record; a file
     * without one is left out, because the writers refuse it.
     *
     * @param list<int>    $uids
     * @param list<string> $fields
     *
     * @return list<int>|null
     */
    public function withEmptyFields(string $table, array $uids, array $fields): ?array
    {
        if ($uids === [] || $fields === []) {
            return $uids;
        }

        $metadata = $table === 'sys_file';
        $source   = $metadata ? 'sys_file_metadata' : $table;
        $key      = $metadata ? 'file' : 'uid';

        $columns = $this->connectionPool->getConnectionForTable($source)->createSchemaManager()->listTableColumns($source);
        foreach ($fields as $field) {
            if (!isset($columns[strtolower($field)])) {
                return null;
            }
        }

        $empty = [];
        foreach (array_chunk($uids, 500) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($source);
            $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

            $allEmpty = [];
            foreach ($fields as $field) {
                $allEmpty[] = $queryBuilder->expr()->or(
                    $queryBuilder->expr()->isNull($field),
                    $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter('')),
                );
            }

            $queryBuilder
                ->select($key)
                ->from($source)
                ->where(
                    $queryBuilder->expr()->in($key, $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    ...$allEmpty,
                );

            if ($metadata) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
            }

            foreach ($queryBuilder->executeQuery()->fetchFirstColumn() as $uid) {
                $empty[(int) (is_numeric($uid) ? $uid : 0)] = true;
            }
        }

        return array_values(array_filter($uids, static fn (int $uid): bool => isset($empty[$uid])));
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    private function pagesWhere(string $column, array $values, BackendUserAuthentication $user, int $permission, int $belowDoktype = PHP_INT_MAX): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in($column, $queryBuilder->createNamedParameter($values, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('doktype', $queryBuilder->createNamedParameter($belowDoktype, Connection::PARAM_INT)),
                $user->getPagePermsClause($permission),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn (mixed $uid): int => is_numeric($uid) ? (int) $uid : 0, $rows);
    }
}
