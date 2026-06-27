<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\Skill;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<Skill>
 */
final class SkillRepository extends Repository
{
    protected $defaultOrderings = [
        'name' => QueryInterface::ORDER_ASCENDING,
    ];

    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    public function findBySourceAndIdentifier(int $source, string $identifier): ?Skill
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd(
                $query->equals('source', $source),
                $query->equals('identifier', $identifier),
            ),
        );
        $result = $query->execute();
        $first = $result->getFirst();
        return $first instanceof Skill ? $first : null;
    }

    /**
     * @return list<Skill>
     */
    public function findBySource(int $source): array
    {
        $query = $this->createQuery();
        $query->matching($query->equals('source', $source));
        /** @var list<Skill> $skills */
        $skills = array_values($query->execute()->toArray());
        return $skills;
    }
}
