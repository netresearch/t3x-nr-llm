<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Reads arbitrary backend records for the Task record-picker pathway.
 *
 * Owns the schema-introspection + DB-query work that previously sat
 * inline in `TaskController` for the three record-picker AJAX actions.
 * Centralises:
 *
 * - which tables the picker may show (`listAllowedTables`),
 * - how a raw table name renders as a label (`formatTableLabel`),
 * - which column the picker should display per row
 *   (`detectLabelField`, with TCA-aware lookup + common-name fallback),
 * - the sample fetch + the by-uid load themselves.
 *
 * The class is `final readonly`; it has no state. Tests mock it via
 * `RecordTableReaderInterface`.
 */
final readonly class RecordTableReader implements RecordTableReaderInterface
{
    /**
     * @param list<string> $excludedTablePrefixes Tables whose name starts with any of these are hidden from the picker
     * @param list<string> $excludedTableNames    Tables whose exact name appears here are hidden from the picker
     * @param list<string> $fallbackLabelFields   Column names tried in order when TCA has no label capability
     */
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private array $excludedTablePrefixes = ['cache_', 'cf_', 'index_'],
        private array $excludedTableNames = ['sys_refindex', 'sys_registry', 'sys_history', 'sys_lockedrecords'],
        private array $fallbackLabelFields = ['name', 'title', 'header', 'subject', 'username', 'email', 'identifier'],
    ) {}

    /**
     * @return list<array{name: string, label: string}>
     */
    public function listAllowedTables(): array
    {
        $connection = $this->connectionPool->getConnectionByName('Default');
        $tables = $connection->createSchemaManager()->listTableNames();

        $allowed = [];
        foreach ($tables as $table) {
            if ($this->isExcluded($table)) {
                continue;
            }
            $allowed[] = [
                'name'  => $table,
                'label' => $this->formatTableLabel($table),
            ];
        }

        usort($allowed, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $allowed;
    }

    public function formatTableLabel(string $table): string
    {
        $label = $table;
        if (str_starts_with($label, 'tx_')) {
            $label = substr($label, 3);
        } elseif (str_starts_with($label, 'sys_')) {
            $label = 'System: ' . substr($label, 4);
        } elseif (str_starts_with($label, 'be_')) {
            $label = 'Backend: ' . substr($label, 3);
        } elseif (str_starts_with($label, 'fe_')) {
            $label = 'Frontend: ' . substr($label, 3);
        }

        return ucwords(str_replace('_', ' ', $label));
    }

    public function detectLabelField(string $table): string
    {
        if ($this->tcaSchemaFactory->has($table)) {
            $schema = $this->tcaSchemaFactory->get($table);
            if ($schema->hasCapability(TcaSchemaCapability::Label)) {
                $labelFieldName = $schema->getCapability(TcaSchemaCapability::Label)->getPrimaryFieldName();
                if ($labelFieldName !== null) {
                    return $labelFieldName;
                }
            }
        }

        $connection = $this->connectionPool->getConnectionForTable($table);
        $columns = $connection->createSchemaManager()->listTableColumns($table);

        foreach ($this->fallbackLabelFields as $field) {
            if (isset($columns[$field])) {
                return $field;
            }
        }

        return '';
    }

    public function tableHasUidColumn(string $table): bool
    {
        $connection = $this->connectionPool->getConnectionForTable($table);
        $columns = $connection->createSchemaManager()->listTableColumns($table);
        return isset($columns['uid']);
    }

    /**
     * @return list<array{uid: int, label: string}>
     */
    public function fetchSampleRecords(string $table, string $labelField, int $limit): array
    {
        $resolvedLabelField = $labelField !== '' ? $labelField : $this->detectLabelField($table);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);

        $selectFields = ['uid'];
        if ($resolvedLabelField !== '' && $resolvedLabelField !== 'uid') {
            $selectFields[] = $resolvedLabelField;
        }

        $queryBuilder
            ->select(...$selectFields)
            ->from($table)
            ->setMaxResults($limit);

        if ($resolvedLabelField !== '' && $resolvedLabelField !== 'uid') {
            $queryBuilder->orderBy($resolvedLabelField, 'ASC');
        } else {
            $queryBuilder->orderBy('uid', 'DESC');
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        $records = [];
        foreach ($rows as $row) {
            $uid = isset($row['uid']) && is_numeric($row['uid']) ? (int)$row['uid'] : 0;
            $label = $resolvedLabelField !== ''
                && isset($row[$resolvedLabelField])
                && is_scalar($row[$resolvedLabelField])
                    ? (string)$row[$resolvedLabelField]
                    : '';
            $records[] = [
                'uid'   => $uid,
                'label' => $label !== '' ? $label : '[UID ' . $uid . ']',
            ];
        }

        return $records;
    }

    /**
     * @param list<int> $uids
     *
     * @return list<array<string, mixed>>
     */
    public function loadRecordsByUids(string $table, array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->in('uid', $uids),
            );

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $table, int $limit): array
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            return array_values(
                $queryBuilder
                    ->select('*')
                    ->from($table)
                    ->setMaxResults($limit)
                    ->executeQuery()
                    ->fetchAllAssociative(),
            );
        } catch (Throwable) {
            // Caller decides how to surface this — table may be missing,
            // permission may be denied, etc. Returning [] keeps the
            // pre-existing behaviour where the caller treated any read
            // failure as "no data available".
            return [];
        }
    }

    private function isExcluded(string $table): bool
    {
        foreach ($this->excludedTablePrefixes as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }
        return in_array($table, $this->excludedTableNames, true);
    }
}
