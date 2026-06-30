<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\Task;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for Task domain model.
 *
 * @extends Repository<Task>
 */
class TaskRepository extends Repository
{
    private const TABLE = 'tx_nrllm_task';

    protected $defaultOrderings = [
        'category' => QueryInterface::ORDER_ASCENDING,
        'sorting' => QueryInterface::ORDER_ASCENDING,
        'name' => QueryInterface::ORDER_ASCENDING,
    ];

    private ConnectionPool $connectionPool;

    public function injectConnectionPool(ConnectionPool $connectionPool): void
    {
        $this->connectionPool = $connectionPool;
    }

    /**
     * Initialize repository for backend module use.
     * Ignores storage page and enable fields restrictions.
     */
    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Find task by identifier string.
     */
    public function findOneByIdentifier(string $identifier): ?Task
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('identifier', $identifier),
        );
        /** @var Task|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Find all active tasks.
     *
     * @return QueryResultInterface<int, Task>
     */
    public function findActive(): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('isActive', true),
        );
        return $query->execute();
    }

    /**
     * Find tasks by category.
     *
     * @return QueryResultInterface<int, Task>
     */
    public function findByCategory(string $category): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('category', $category),
            ),
        );
        return $query->execute();
    }

    /**
     * Find system tasks (shipped with extension).
     *
     * @return QueryResultInterface<int, Task>
     */
    public function findSystemTasks(): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('isSystem', true),
        );
        return $query->execute();
    }

    /**
     * Find user-created tasks (non-system).
     *
     * @return QueryResultInterface<int, Task>
     */
    public function findUserTasks(): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('isSystem', false),
        );
        return $query->execute();
    }

    /**
     * Find tasks by input type.
     *
     * @return QueryResultInterface<int, Task>
     */
    public function findByInputType(string $inputType): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('inputType', $inputType),
            ),
        );
        return $query->execute();
    }

    /**
     * Find tasks using a specific configuration.
     *
     * @return QueryResultInterface<int, Task>
     */
    public function findByConfigurationUid(int $configurationUid): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('configurationUid', $configurationUid),
        );
        return $query->execute();
    }

    /**
     * Check if identifier is unique among non-deleted records (for validation).
     */
    public function isIdentifierUnique(string $identifier, ?int $excludeUid = null): bool
    {
        $query = $this->createQuery();
        $querySettings = $query->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        // Enable soft-delete check to only find non-deleted records
        $querySettings->setIgnoreEnableFields(false);
        $querySettings->setEnableFieldsToBeIgnored(['hidden']);

        $constraints = [
            $query->equals('identifier', $identifier),
        ];

        if ($excludeUid !== null) {
            $constraints[] = $query->logicalNot(
                $query->equals('uid', $excludeUid),
            );
        }

        $query->matching($query->logicalAnd(...$constraints));

        return $query->count() === 0;
    }

    /**
     * Count all non-deleted tasks.
     */
    public function countActive(): int
    {
        $query = $this->createQuery();
        $querySettings = $query->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(false);
        $querySettings->setEnableFieldsToBeIgnored(['hidden']);

        return $query->count();
    }

    /**
     * Count tasks grouped by category.
     *
     * DB-side COUNT(*) grouped by category instead of loading every active
     * task into PHP and tallying. Matches findActive()'s effective filter:
     * active (is_active = 1) and non-deleted, ignoring the hidden enable
     * field and storage page (the backend module query settings).
     *
     * @return array<string, int>
     */
    public function countByCategory(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('category')
            ->addSelectLiteral('COUNT(*) AS task_count')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('is_active', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->groupBy('category')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $category = is_string($row['category'] ?? null) ? $row['category'] : '';
            $counts[$category] = is_numeric($row['task_count'] ?? null) ? (int)$row['task_count'] : 0;
        }

        return $counts;
    }
}
