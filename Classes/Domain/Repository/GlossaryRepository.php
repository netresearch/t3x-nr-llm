<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Repository;

use Netresearch\NrLlm\Domain\Model\Glossary;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * Repository for the Glossary domain model — the backend module's read side.
 *
 * Ignores the storage page (glossaries live wherever an editor created them,
 * `rootLevel => -1`) and the enable fields (the module lists hidden records so
 * they can be switched back on). The translation path does NOT read through
 * here: {@see \Netresearch\NrLlm\Service\Glossary\GlossaryResolver} honours
 * `hidden`, because a hidden glossary must not reach a translation.
 *
 * @extends Repository<Glossary>
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
class GlossaryRepository extends Repository
{
    protected $defaultOrderings = [
        'siteIdentifier' => QueryInterface::ORDER_ASCENDING,
        'sourceLanguage' => QueryInterface::ORDER_ASCENDING,
        'targetLanguage' => QueryInterface::ORDER_ASCENDING,
        // Within one site and pair, the translation path uses the lowest uid
        // (GlossaryResolver); listing by uid puts that record first.
        'uid' => QueryInterface::ORDER_ASCENDING,
    ];

    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * Count the glossaries that reach a translation: not deleted, not hidden.
     * The overview card reports this number, so a hidden glossary does not
     * count as set up.
     */
    public function countActive(): int
    {
        $query = $this->createQuery();
        $query->getQuerySettings()->setIgnoreEnableFields(false);

        return $query->count();
    }
}
