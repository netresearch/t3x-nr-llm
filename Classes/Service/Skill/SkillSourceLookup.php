<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\ValueObject\SkillSourceFacts;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * DBAL read of a skill source's type and trust level (ADR-214 item 2).
 *
 * A plain query rather than the Extbase repository: the answer must be the
 * stored value at the moment of the check, not an object an earlier read in
 * the same process put into the persistence session. One query per source per
 * composition; no cache, because a re-classification must take effect on the
 * next run, not on the next worker restart.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillSourceLookup implements SkillSourceLookupInterface
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_skill_source';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function find(int $sourceUid): ?SkillSourceFacts
    {
        if ($sourceUid <= 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid', 'type', 'trust_level')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($sourceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                // A hidden or disabled source vouches for nothing: its skills
                // fall back to the fenced channel (fail closed).
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('enabled', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        return new SkillSourceFacts(
            self::toInt($row['uid'] ?? 0),
            SkillSourceType::tryFrom(self::toStr($row['type'] ?? '')),
            // Fail closed: an unknown stored level reads as the lowest.
            SkillTrustLevel::fromStringOrUntrusted(self::toStr($row['trust_level'] ?? '')),
        );
    }
}
