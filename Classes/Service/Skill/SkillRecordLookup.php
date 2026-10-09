<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * DBAL read of whether a skill record is active: it exists, is not deleted,
 * hidden, disabled or orphaned (ADR-214 item 6). A skill the sync auto-disabled
 * or the injection scan force-disabled stops steering a suspended run.
 *
 * A plain query, for the reason {@see SkillSourceLookup} gives: the answer
 * must be the stored value now, not an object a worker read earlier.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillRecordLookup implements SkillRecordLookupInterface
{
    private const TABLE = 'tx_nrllm_skill';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function isActive(int $skillUid): bool
    {
        if ($skillUid <= 0) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('orphaned', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('enabled', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int)$count > 0;
    }
}
