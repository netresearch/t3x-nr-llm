<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
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

    /**
     * Find model by identifier string.
     */
    public function findOneByIdentifier(string $identifier): ?Model
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('identifier', $identifier),
        );
        /** @var Model|null $result */
        $result = $query->execute()->getFirst();
        return $result;
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
        /** @var Model|null $result */
        $result = $query->execute()->getFirst();
        return $result;
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
                $query->equals('providerUid', $provider->getUid()),
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
                $query->equals('providerUid', $providerUid),
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
        return $this->findByCapability(Model::CAPABILITY_CHAT);
    }

    /**
     * Find models that support embeddings.
     *
     * @return QueryResultInterface<int, Model>
     */
    public function findEmbeddingModels(): QueryResultInterface
    {
        return $this->findByCapability(Model::CAPABILITY_EMBEDDINGS);
    }

    /**
     * Find models that support vision.
     *
     * @return QueryResultInterface<int, Model>
     */
    public function findVisionModels(): QueryResultInterface
    {
        return $this->findByCapability(Model::CAPABILITY_VISION);
    }

    /**
     * Check if identifier is unique (for validation).
     */
    public function isIdentifierUnique(string $identifier, ?int $excludeUid = null): bool
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);

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
        $counts = [];
        $models = $this->findActive();

        foreach ($models as $model) {
            if (!$model instanceof Model) {
                continue;
            }
            $providerUid = $model->getProviderUid();
            if (!isset($counts[$providerUid])) {
                $counts[$providerUid] = 0;
            }
            $counts[$providerUid]++;
        }

        return $counts;
    }
}
