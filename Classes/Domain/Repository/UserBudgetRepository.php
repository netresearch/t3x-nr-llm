<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\UserBudget;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<UserBudget>
 */
class UserBudgetRepository extends Repository
{
    /**
     * Budgets are global records (rootLevel=-1), so drop storage-page filtering.
     * Respect enable fields so hidden / deleted rows stay excluded.
     */
    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Look up the (at most one) budget record for a backend user.
     */
    public function findOneByBeUser(int $beUser): ?UserBudget
    {
        if ($beUser <= 0) {
            return null;
        }
        $query = $this->createQuery();
        $query->matching($query->equals('beUser', $beUser));
        /** @var UserBudget|null $result */
        $result = $query->execute()->getFirst();
        return $result;
    }
}
