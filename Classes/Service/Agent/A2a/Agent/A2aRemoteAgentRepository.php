<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Agent;

use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * The remote agent records (spec 051 A2A-C1).
 *
 * Like every repository of the extension it ignores enable fields and storage
 * pages (`Classes/AGENTS.md`); the runtime switch is the record's `enabled`
 * column, checked by {@see A2aRemoteAgent::useRefusal()}.
 *
 * @extends Repository<A2aRemoteAgent>
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
class A2aRemoteAgentRepository extends Repository
{
    public function initializeObject(): void
    {
        $querySettings = $this->createQuery()->getQuerySettings();
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * The record named by its identifier, or by its uid when the reference is
     * a decimal number (the command line accepts both).
     */
    public function findByReference(string $reference): ?A2aRemoteAgent
    {
        if (preg_match('/^[1-9]\d*$/', $reference) === 1) {
            $byUid = $this->findByUid((int) $reference);
            if ($byUid instanceof A2aRemoteAgent) {
                return $byUid;
            }
        }

        $query = $this->createQuery();
        $query->matching($query->equals('identifier', $reference));

        $found = $query->setLimit(1)->execute()->getFirst();

        return $found instanceof A2aRemoteAgent ? $found : null;
    }
}
