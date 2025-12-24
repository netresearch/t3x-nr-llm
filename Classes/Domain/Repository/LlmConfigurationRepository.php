<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for LlmConfiguration domain model
 *
 * @extends Repository<LlmConfiguration>
 */
class LlmConfigurationRepository extends Repository
{
    protected $defaultOrderings = [
        'sorting' => QueryInterface::ORDER_ASCENDING,
        'name' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * Find configuration by identifier string
     */
    public function findOneByIdentifier(string $identifier): ?LlmConfiguration
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('identifier', $identifier)
        );
        /** @var LlmConfiguration|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Find all active configurations
     *
     * @return QueryResultInterface<LlmConfiguration>
     */
    public function findActive(): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('isActive', true)
        );
        return $query->execute();
    }

    /**
     * Find default configuration
     */
    public function findDefault(): ?LlmConfiguration
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('isDefault', true)
            )
        );
        /** @var LlmConfiguration|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Find configurations by provider
     *
     * @return QueryResultInterface<LlmConfiguration>
     */
    public function findByProvider(string $provider): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('provider', $provider)
            )
        );
        return $query->execute();
    }

    /**
     * Find configurations accessible to specific backend user groups
     *
     * @param array<int> $groupUids
     * @return QueryResultInterface<LlmConfiguration>
     */
    public function findAccessibleForGroups(array $groupUids): QueryResultInterface
    {
        $query = $this->createQuery();

        if (empty($groupUids)) {
            // No groups: only return configurations without access restrictions
            $query->matching(
                $query->logicalAnd(
                    $query->equals('isActive', true),
                    $query->equals('allowedGroups', 0)
                )
            );
        } else {
            // Return configurations without restrictions OR with matching groups
            $query->matching(
                $query->logicalAnd(
                    $query->equals('isActive', true),
                    $query->logicalOr(
                        $query->equals('allowedGroups', 0),
                        $query->in('beGroups.uid', $groupUids)
                    )
                )
            );
        }

        return $query->execute();
    }

    /**
     * Check if identifier is unique (for validation)
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
                $query->equals('uid', $excludeUid)
            );
        }

        $query->matching($query->logicalAnd(...$constraints));

        return $query->count() === 0;
    }

    /**
     * Unset all defaults (used before setting a new default)
     */
    public function unsetAllDefaults(): void
    {
        $configurations = $this->findAll();
        foreach ($configurations as $configuration) {
            if ($configuration->isDefault()) {
                $configuration->setIsDefault(false);
                $this->update($configuration);
            }
        }
    }
}
