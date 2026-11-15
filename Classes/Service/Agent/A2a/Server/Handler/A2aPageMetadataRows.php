<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The page rows of the page metadata skill as a workspace shows them: the
 * live rows and the rows new in that workspace, not deleted, with the
 * workspace's version laid over a live row. A row deleted in the workspace
 * is not shown: `workspaceOL()` lays the delete placeholder (t3ver_state 2)
 * over the live row instead of dropping it, so it is skipped here. The
 * handler reads the page and its translation through it before a task
 * exists, and the contract rule reads the same rows before a write. Neither
 * checks a permission here.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPageMetadataRows
{
    /** The t3ver_state of a row deleted in a workspace. */
    private const DELETE_PLACEHOLDER = 2;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<array-key, mixed>|null the row with its live uid
     */
    public function page(int $uid, int $workspace): ?array
    {
        return $this->visible($this->rows(['uid' => $uid], $workspace), $workspace);
    }

    /**
     * @return array<array-key, mixed>|null the translation's row with its live uid
     */
    public function translation(int $page, int $language, int $workspace): ?array
    {
        return $this->visible($this->rows(['l10n_parent' => $page, 'sys_language_uid' => $language], $workspace), $workspace);
    }

    /**
     * The uid of the row a request names: the page for language 0, its
     * translation otherwise; null when there is none, or when the page is
     * itself a translation.
     */
    public function target(A2aPageMetadataRequest $request, int $workspace): ?int
    {
        $page = $this->page($request->page, $workspace);
        if ($page === null || !self::isDefaultLanguage($page)) {
            return null;
        }

        if ($request->language === 0) {
            return $request->page;
        }

        $translation = $this->translation($request->page, $request->language, $workspace);

        return $translation === null ? null : self::int($translation['uid'] ?? 0);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    public static function isDefaultLanguage(array $row): bool
    {
        return self::int($row['sys_language_uid'] ?? 0) === 0 && self::int($row['l10n_parent'] ?? 0) === 0;
    }

    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, int> $where
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $where, int $workspace): array
    {
        $query = $this->connectionPool->getQueryBuilderForTable('pages');
        $query->getRestrictions()->removeAll();
        $query->select('*')->from('pages')
            ->where(
                $query->expr()->eq('deleted', $query->createNamedParameter(0, Connection::PARAM_INT)),
                $query->expr()->eq('t3ver_oid', $query->createNamedParameter(0, Connection::PARAM_INT)),
                $query->expr()->in('t3ver_wsid', $query->createNamedParameter([0, $workspace], Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('t3ver_wsid')
            ->addOrderBy('uid');
        foreach ($where as $field => $value) {
            $query->andWhere($query->expr()->eq($field, $query->createNamedParameter($value, Connection::PARAM_INT)));
        }

        $rows = [];
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<array-key, mixed>|null
     */
    private function visible(array $rows, int $workspace): ?array
    {
        foreach ($rows as $row) {
            $uid = self::int($row['uid'] ?? 0);
            BackendUtility::workspaceOL('pages', $row, $workspace);
            if (is_array($row) && self::int($row['t3ver_state'] ?? 0) !== self::DELETE_PLACEHOLDER) {
                $row['uid'] = $uid;

                return $row;
            }
        }

        return null;
    }
}
