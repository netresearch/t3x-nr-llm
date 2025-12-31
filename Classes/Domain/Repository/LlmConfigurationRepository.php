<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for LlmConfiguration domain model.
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
     * Find configuration by identifier string.
     */
    public function findOneByIdentifier(string $identifier): ?LlmConfiguration
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('identifier', $identifier),
        );
        /** @var LlmConfiguration|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Find all active configurations.
     *
     * @return QueryResultInterface<int, LlmConfiguration>
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
     * Find default configuration.
     */
    public function findDefault(): ?LlmConfiguration
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('isDefault', true),
            ),
        );
        /** @var LlmConfiguration|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Find configurations by provider.
     *
     * @return QueryResultInterface<int, LlmConfiguration>
     */
    public function findByProvider(string $provider): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('provider', $provider),
            ),
        );
        return $query->execute();
    }

    /**
     * Find configurations accessible to specific backend user groups.
     *
     * @param array<int> $groupUids
     *
     * @return QueryResultInterface<int, LlmConfiguration>
     */
    public function findAccessibleForGroups(array $groupUids): QueryResultInterface
    {
        $query = $this->createQuery();

        if (empty($groupUids)) {
            // No groups: only return configurations without access restrictions
            $query->matching(
                $query->logicalAnd(
                    $query->equals('isActive', true),
                    $query->equals('allowedGroups', 0),
                ),
            );
        } else {
            // Return configurations without restrictions OR with matching groups
            $query->matching(
                $query->logicalAnd(
                    $query->equals('isActive', true),
                    $query->logicalOr(
                        $query->equals('allowedGroups', 0),
                        $query->in('beGroups.uid', $groupUids),
                    ),
                ),
            );
        }

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
     * Count all non-deleted configurations.
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
        $configurations = $this->findAll();
        foreach ($configurations as $configuration) {
            if (!$configuration instanceof LlmConfiguration) {
                continue;
            }
            if ($configuration->isDefault()) {
                $configuration->setIsDefault(false);
                $this->update($configuration);
            }
        }
    }
}
