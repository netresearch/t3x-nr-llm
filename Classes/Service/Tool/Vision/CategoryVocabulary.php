<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Vision;

use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The categories a file may be tagged with (ADR-268): the `sys_category`
 * records below the root an operator configured in `vision.categories.rootUid`,
 * as far as the user may assign them.
 *
 * The list is what the model chooses from and what the writer accepts, so the
 * prompt, the refusal and the write read the same answer. It holds existing
 * records only: nothing here creates a category.
 *
 * - **Below the root, not the root.** The root names the tree; it is a
 *   container an operator picked, not a tag.
 * - **Live, default language, not hidden, not deleted.** A category is a record
 *   of its own with its own workspace and translations; a file is tagged with
 *   the live default-language record, as the backend form does.
 * - **The user's category mounts.** Core's category tree shows a backend user
 *   who has mounts (`category_perms`) only the categories below them, and the
 *   DataHandler does not check them on a write. They are applied here, because
 *   the tool writes for that user. A user without mounts may use every
 *   category, as in core.
 * - **Bounded.** At most {@see self::MAX_DEPTH} levels and
 *   {@see self::MAX_CATEGORIES} categories, in tree order, because the list
 *   goes into every prompt.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class CategoryVocabulary
{
    use SafeCastTrait;

    public const MAX_DEPTH = 10;

    public const MAX_CATEGORIES = 200;

    private const TABLE = 'sys_category';

    public function __construct(
        private ConnectionPool $connectionPool,
        private VisionSettings $settings,
    ) {}

    public function rootUid(): int
    {
        return $this->settings->categoryRoot();
    }

    /**
     * The categories `$user` may assign, uid => title, in tree order; empty
     * without a configured root or without a user.
     *
     * @return array<int, string>
     */
    public function forUser(?BackendUserAuthentication $user): array
    {
        $root = $this->rootUid();
        if ($root < 1 || !$user instanceof BackendUserAuthentication) {
            return [];
        }

        $mounts = $user->isAdmin() ? [] : $this->mountsOf($user);
        // Every category is usable when there are no mounts, or when a mount is
        // the root or lies above it.
        $openFromTheRoot = $mounts === [] || array_intersect($mounts, $this->rootAndAncestors($root)) !== [];

        $categories = [];
        // The level being read, each parent with whether its subtree is usable.
        $level = [$root => $openFromTheRoot];
        for ($depth = 0; $depth < self::MAX_DEPTH && $level !== []; ++$depth) {
            $next = [];
            foreach ($this->childrenOf(array_keys($level)) as $row) {
                $uid    = self::toInt($row['uid'] ?? 0);
                $parent = self::toInt($row['parent'] ?? 0);
                if ($uid < 1 || isset($categories[$uid])) {
                    continue;
                }

                $usable = ($level[$parent] ?? false) || in_array($uid, $mounts, true);
                if ($usable) {
                    $categories[$uid] = trim(self::toStr($row['title'] ?? ''));
                    if (count($categories) >= self::MAX_CATEGORIES) {
                        return $categories;
                    }
                }

                $next[$uid] = $usable;
            }

            $level = $next;
        }

        return $categories;
    }

    /**
     * The uids of `$user`'s category mounts.
     *
     * @return list<int>
     */
    private function mountsOf(BackendUserAuthentication $user): array
    {
        $mounts = $user->getCategoryMountPoints();

        return array_values(array_filter(
            array_map(self::toInt(...), is_array($mounts) ? $mounts : []),
            static fn (int $uid): bool => $uid > 0,
        ));
    }

    /**
     * @return list<int> the root and its parents, up to {@see self::MAX_DEPTH} of them
     */
    private function rootAndAncestors(int $root): array
    {
        $chain = [$root];
        $uid   = $root;
        for ($depth = 0; $depth < self::MAX_DEPTH; ++$depth) {
            $queryBuilder = $this->queryBuilder();
            $parent       = $queryBuilder
                ->select('parent')
                ->from(self::TABLE)
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchOne();

            $uid = self::toInt($parent);
            if ($uid < 1 || in_array($uid, $chain, true)) {
                break;
            }

            $chain[] = $uid;
        }

        return $chain;
    }

    /**
     * @param list<int> $parents
     *
     * @return list<array<string, mixed>>
     */
    private function childrenOf(array $parents): array
    {
        $queryBuilder = $this->queryBuilder();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid', 'parent', 'title')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('parent', $queryBuilder->createNamedParameter($parents, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('sorting')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    private function queryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder;
    }
}
