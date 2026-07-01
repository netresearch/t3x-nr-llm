<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for Model domain model.
 *
 * @extends Repository<Model>
 */
class ModelRepository extends Repository
{
    protected $defaultOrderings = [
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
     * Find model by identifier string.
     */
    public function findOneByIdentifier(string $identifier): ?Model
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('identifier', $identifier),
        );
        $result = $query->execute()->getFirst();

        return $result instanceof Model ? $result : null;
    }

    /**
     * Find a model by its provider model id (the API model string,
     * e.g. "gpt-image-2"). Multiple records may share a model id when
     * the same model is offered through several providers; the default
     * ordering (sorting, name) decides which one wins.
     */
    public function findOneByModelId(string $modelId): ?Model
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('modelId', $modelId),
        );
        $result = $query->execute()->getFirst();

        return $result instanceof Model ? $result : null;
    }

    /**
     * Find all active models.
     *
     * @return QueryResultInterface<int, Model>
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
     * Find default model.
     */
    public function findDefault(): ?Model
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('isDefault', true),
            ),
        );
        $result = $query->execute()->getFirst();

        return $result instanceof Model ? $result : null;
    }

    /**
     * Find models by provider.
     *
     * @return QueryResultInterface<int, Model>
     */
    public function findByProvider(Provider $provider): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('provider', $provider->getUid()),
            ),
        );
        return $query->execute();
    }

    /**
     * Find models by provider UID.
     *
     * @return QueryResultInterface<int, Model>
     */
    public function findByProviderUid(int $providerUid): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('provider', $providerUid),
            ),
        );
        return $query->execute();
    }

    /**
     * Find models with specific capability.
     *
     * @return QueryResultInterface<int, Model>
     */
    public function findByCapability(string $capability): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->like('capabilities', '%' . $capability . '%'),
            ),
        );
        return $query->execute();
    }

    /**
     * Find models that support chat.
     *
     * @return QueryResultInterface<int, Model>
     */
    public function findChatModels(): QueryResultInterface
    {
        return $this->findByCapability(ModelCapability::CHAT->value);
    }

    /**
     * Find models that support embeddings.
     *
     * @return QueryResultInterface<int, Model>
     */
    public function findEmbeddingModels(): QueryResultInterface
    {
        return $this->findByCapability(ModelCapability::EMBEDDINGS->value);
    }

    /**
     * Find models that support vision.
     *
     * @return QueryResultInterface<int, Model>
     */
    public function findVisionModels(): QueryResultInterface
    {
        return $this->findByCapability(ModelCapability::VISION->value);
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
     * Count all non-deleted models.
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
     * Unset all defaults (used before setting a new default).
     */
    public function unsetAllDefaults(): void
    {
        $models = $this->findAll();
        foreach ($models as $model) {
            if (!$model instanceof Model) {
                continue;
            }
            if ($model->isDefault()) {
                $model->setIsDefault(false);
                $this->update($model);
            }
        }
    }

    /**
     * Set model as default (unsets other defaults first).
     */
    public function setAsDefault(Model $model): void
    {
        $this->unsetAllDefaults();
        $model->setIsDefault(true);
        $this->update($model);
    }

    /**
     * Count active models grouped by provider.
     *
     * @return array<int, int> Provider UID => count
     */
    public function countByProvider(): array
    {
        // Aggregate in a single grouped query on the provider FK rather than
        // hydrating every active model and lazy-loading its Provider (N+1).
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_model');
        // Match findActive(): the repository ignores enable-fields
        // (initializeObject → setIgnoreEnableFields), so hidden active models
        // must still be counted. The DeletedRestriction stays (deleted rows are
        // excluded there too).
        $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
        $rows = $queryBuilder
            ->select('provider_uid')
            ->addSelectLiteral(
                $queryBuilder->expr()->count('*', 'model_count'),
            )
            ->from('tx_nrllm_model')
            ->where(
                $queryBuilder->expr()->eq(
                    'is_active',
                    $queryBuilder->createNamedParameter(1, Connection::PARAM_INT),
                ),
            )
            ->groupBy('provider_uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $providerUid = $row['provider_uid'];
            $modelCount = $row['model_count'];
            if (is_numeric($providerUid) && is_numeric($modelCount)) {
                $counts[(int)$providerUid] = (int)$modelCount;
            }
        }

        return $counts;
    }
}
