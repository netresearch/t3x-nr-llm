<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\Provider;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for Provider domain model.
 *
 * @extends Repository<Provider>
 */
class ProviderRepository extends Repository
{
    protected $defaultOrderings = [
        'sorting' => QueryInterface::ORDER_ASCENDING,
        'name' => QueryInterface::ORDER_ASCENDING,
    ];

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
     * Find provider by identifier string.
     */
    public function findOneByIdentifier(string $identifier): ?Provider
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('identifier', $identifier),
        );
        /** @var Provider|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Find all active providers.
     *
     * @return QueryResultInterface<int, Provider>
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
     * Find providers by adapter type.
     *
     * @return QueryResultInterface<int, Provider>
     */
    public function findByAdapterType(string $adapterType): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('adapterType', $adapterType),
            ),
        );
        return $query->execute();
    }

    /**
     * Find providers with API key configured.
     *
     * @return QueryResultInterface<int, Provider>
     */
    public function findWithApiKey(): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->logicalNot(
                    $query->equals('apiKey', ''),
                ),
            ),
        );
        return $query->execute();
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
     * Count providers by adapter type.
     *
     * @return array<string, int>
     */
    public function countByAdapterType(): array
    {
        $counts = [];
        $providers = $this->findAll();

        foreach ($providers as $provider) {
            if (!$provider instanceof Provider) {
                continue;
            }
            $type = $provider->getAdapterType();
            if (!isset($counts[$type])) {
                $counts[$type] = 0;
            }
            $counts[$type]++;
        }

        return $counts;
    }
}
