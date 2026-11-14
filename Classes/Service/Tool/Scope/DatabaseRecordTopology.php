<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The topology read from the database (ADR-295).
 *
 * A row is read without the delete and hidden restrictions, because a scope
 * decides about a deleted or hidden row the same as about a live one, and then
 * overlaid with its version in the run's workspace, so a page a draft moved is
 * where the draft put it.
 *
 * @internal
 */
final class DatabaseRecordTopology implements RecordTopologyInterface
{
    public function row(string $table, int $uid, int $workspace): ?RecordFacts
    {
        $allTca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $tca    = $allTca[$table] ?? null;
        if (!is_array($tca) || $uid < 1) {
            return null;
        }

        $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll();
        $row = $query->select('*')->from($table)->where($query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)))->executeQuery()->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }

        BackendUtility::workspaceOL($table, $row, $workspace);
        if (!is_array($row)) {
            return null;
        }

        $ctrl          = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
        $languageField = is_string($ctrl['languageField'] ?? null) ? $ctrl['languageField'] : '';
        $originField   = is_string($ctrl['transOrigPointerField'] ?? null) ? $ctrl['transOrigPointerField'] : '';
        $origin        = $originField !== '' ? $this->int($row[$originField] ?? 0) : 0;

        return new RecordFacts(
            $this->int($row['uid'] ?? 0),
            $this->int($row['pid'] ?? 0),
            $languageField !== '' ? $this->int($row[$languageField] ?? 0) : 0,
            $origin > 0 ? $origin : null,
            $table === 'sys_file_metadata' && $this->int($row['file'] ?? 0) > 0 ? new RecordReference('sys_file', $this->int($row['file'])) : ChildRelations::host($table, $row),
        );
    }

    public function languagesOnPage(int $pageUid, int $workspace): array
    {
        $languages = [];
        $tca       = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        foreach ($tca as $table => $schema) {
            $ctrl  = is_array($schema) && is_array($schema['ctrl'] ?? null) ? $schema['ctrl'] : [];
            $field = $ctrl['languageField'] ?? null;
            if (!is_string($table) || !is_string($field) || preg_match('/\A\w+\z/', $table . $field) !== 1) {
                continue;
            }

            // Every table that has a language, translations of free mode (no
            // translation parent) included; for the pages themselves the overlays.
            $where = $table === 'pages' ? 'l10n_parent' : 'pid';
            $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
            $query->getRestrictions()->removeAll();
            $query->select($field)->from($table)->where($query->expr()->eq($where, $query->createNamedParameter($pageUid, Connection::PARAM_INT)));
            if (($ctrl['versioningWS'] ?? false) === true) {
                $query->andWhere($query->expr()->in('t3ver_wsid', $query->createNamedParameter([0, $workspace], Connection::PARAM_INT_ARRAY)));
            }

            foreach ($query->executeQuery()->fetchFirstColumn() as $language) {
                $languages[] = $this->int($language);
            }
        }

        return array_values(array_unique($languages));
    }

    public function storedValue(string $table, int $uid, string $field): ?string
    {
        if (preg_match('/\A\w+\z/', $table . $field) !== 1 || $uid < 1) {
            return null;
        }

        $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll();
        $value = $query->select($field)->from($table)->where($query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)))->executeQuery()->fetchOne();

        return is_scalar($value) ? (string) $value : null;
    }

    public function translationLanguages(string $table, int $uid, int $workspace): array
    {
        $allTca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $tca    = is_array($allTca[$table] ?? null) ? $allTca[$table] : [];
        $ctrl   = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
        $origin = $ctrl['transOrigPointerField'] ?? null;
        $field  = $ctrl['languageField'] ?? null;
        if (!is_string($origin) || !is_string($field) || preg_match('/\A\w+\z/', $origin . $field) !== 1) {
            return [];
        }

        $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll();
        $rows = $query
            ->select($field)
            ->from($table)
            ->where($query->expr()->eq($origin, $query->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_unique(array_map(fn (mixed $language): int => $this->int($language), $rows)));
    }

    public function descendantPages(int $pageUid, int $workspace, int $limit): ?array
    {
        $found    = [];
        $frontier = [$pageUid];
        while ($frontier !== []) {
            $query = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $query->getRestrictions()->removeAll();
            $rows = $query
                ->select('uid', 'l10n_parent')
                ->from('pages')
                ->where(
                    $query->expr()->in('pid', $query->createNamedParameter($frontier, Connection::PARAM_INT_ARRAY)),
                    $query->expr()->in('t3ver_wsid', $query->createNamedParameter([0, $workspace], Connection::PARAM_INT_ARRAY)),
                    $query->expr()->eq('deleted', 0),
                )
                ->executeQuery()
                ->fetchAllAssociative();

            $next = [];
            foreach ($rows as $row) {
                $default = $this->int($row['l10n_parent'] ?? 0) > 0 ? $this->int($row['l10n_parent']) : $this->int($row['uid'] ?? 0);
                if ($default < 1 || $default === $pageUid || isset($found[$default])) {
                    continue;
                }

                $found[$default] = true;
                $next[]          = $default;
                if (count($found) > $limit) {
                    return null;
                }
            }

            $frontier = $next;
        }

        return array_keys($found);
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
