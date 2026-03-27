<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for PromptTemplate domain model.
 *
 * @extends Repository<PromptTemplate>
 */
class PromptTemplateRepository extends Repository
{
    protected $defaultOrderings = [
        'title' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * Find prompt template by identifier string.
     */
    public function findOneByIdentifier(string $identifier): ?PromptTemplate
    {
        $query = $this->createQuery();
        $query->matching(
            $query->equals('identifier', $identifier),
        );
        /** @var PromptTemplate|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Find all active templates.
     *
     * @return QueryResultInterface<int, PromptTemplate>
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
     * @deprecated Use findByFeature() instead. Will be removed in v1.0.
     *
     * @return QueryResultInterface<int, PromptTemplate>
     */
    public function findByCategory(string $category): QueryResultInterface
    {
        return $this->findByFeature($category);
    }

    /**
     * Find templates by feature.
     *
     * @return QueryResultInterface<int, PromptTemplate>
     */
    public function findByFeature(string $feature): QueryResultInterface
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('isActive', true),
                $query->equals('feature', $feature),
            ),
        );
        return $query->execute();
    }

    /**
     * Find a variant template by parent identifier and variant name.
     */
    public function findVariant(string $parentIdentifier, string $variantName): ?PromptTemplate
    {
        $parent = $this->findOneByIdentifier($parentIdentifier);
        if ($parent === null || $parent->getUid() === null) {
            return null;
        }

        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('parentUid', $parent->getUid()),
                $query->equals('identifier', $variantName),
            ),
        );
        /** @var PromptTemplate|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }

    /**
     * Save a prompt template.
     */
    public function save(PromptTemplate $template): void
    {
        if ($template->getUid() === null) {
            $this->add($template);
        } else {
            $this->update($template);
        }
    }
}
