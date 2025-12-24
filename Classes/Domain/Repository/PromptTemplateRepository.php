<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for PromptTemplate domain model
 *
 * @extends Repository<PromptTemplate>
 */
class PromptTemplateRepository extends Repository
{
    protected $defaultOrderings = [
        'name' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * Find prompt template by identifier string
     */
    public function findOneByIdentifier(string $identifier): ?PromptTemplate
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('identifier', $identifier)
        );
        /** @var PromptTemplate|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Find all active templates
     *
     * @return QueryResultInterface<PromptTemplate>
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
     * Find templates by category
     *
     * @return QueryResultInterface<PromptTemplate>
     */
    public function findByCategory(string $category): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('category', $category)
            )
        );
        return $query->execute();
    }
}
