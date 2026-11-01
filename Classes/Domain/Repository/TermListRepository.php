<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\TermList;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * The backend module's read side of the term lists (ADR-238).
 *
 * Ignores the storage page and the enable fields, like the glossary repository:
 * the module lists hidden records so they can be switched back on. The
 * guardrail reads through
 * {@see \Netresearch\NrLlm\Service\Guardrail\Term\TermListResolver}.
 *
 * @extends Repository<TermList>
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
class TermListRepository extends Repository
{
    protected $defaultOrderings = [
        'name' => QueryInterface::ORDER_ASCENDING,
        'uid'  => QueryInterface::ORDER_ASCENDING,
    ];

    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * The lists that stop anything: not deleted, not hidden.
     */
    public function countActive(): int
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setIgnoreEnableFields(false);

        return $query->count();
    }
}
