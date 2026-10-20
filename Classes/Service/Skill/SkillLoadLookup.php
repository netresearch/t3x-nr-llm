<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Model\Skill;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * DBAL reads behind {@see SkillLoadLookupInterface}: plain queries, for the
 * reason {@see SkillRecordLookup} gives — the answer must be the stored value
 * now, not an object the run read when it started.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class SkillLoadLookup implements SkillLoadLookupInterface
{
    private const SKILL_TABLE = 'tx_nrllm_skill';

    private const ATTACHMENT_TABLE = 'tx_nrllm_configuration_skill_mm';

    public function __construct(
        private ConnectionPool $connectionPool,
        private SkillSourceLookupInterface $sources,
    ) {}

    public function isAttachedOnDemand(int $configurationUid, int $skillUid): bool
    {
        if ($configurationUid <= 0 || $skillUid <= 0) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::ATTACHMENT_TABLE);
        $count        = $queryBuilder
            ->count('uid_local')
            ->from(self::ATTACHMENT_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid_local', $queryBuilder->createNamedParameter($configurationUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('load_mode', $queryBuilder->createNamedParameter('on_demand')),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int) $count > 0;
    }

    public function loadableDigest(int $skillUid): ?string
    {
        if ($skillUid <= 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SKILL_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('source', 'version_digest', 'body_checksum', 'name', 'description', 'body', 'support_status', 'allowed_tools')
            ->from(self::SKILL_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('enabled', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('orphaned', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('process', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        // The digest the catalogue computed, by the same rule: a skill
        // written in the backend is identified by its current fields, every
        // other one by what its sync stored (SkillComposer::isEditedInPlace()).
        $skill = new Skill();
        $skill->setSource(is_numeric($row['source'] ?? null) ? (int) $row['source'] : 0);
        $skill->setVersionDigest(is_string($row['version_digest'] ?? null) ? $row['version_digest'] : '');
        $skill->setBodyChecksum(is_string($row['body_checksum'] ?? null) ? $row['body_checksum'] : '');
        if (!SkillComposer::isEditedInPlace($skill, $this->sources->find($skill->getSource()))) {
            return $skill->getVersionDigest();
        }

        $skill->setName(is_string($row['name'] ?? null) ? $row['name'] : '');
        $skill->setDescription(is_string($row['description'] ?? null) ? $row['description'] : '');
        $skill->setBody(is_string($row['body'] ?? null) ? $row['body'] : '');
        $skill->setSupportStatus(is_string($row['support_status'] ?? null) ? $row['support_status'] : '');
        $skill->setAllowedTools(is_string($row['allowed_tools'] ?? null) ? $row['allowed_tools'] : '');

        return SkillVersionDigest::of($skill);
    }

    public function storedSource(int $skillUid): ?int
    {
        if ($skillUid <= 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SKILL_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $value = $queryBuilder
            ->select('source')
            ->from(self::SKILL_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    public function trustLevel(int $skillUid): ?string
    {
        if ($skillUid <= 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SKILL_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $value = $queryBuilder
            ->select('trust_level')
            ->from(self::SKILL_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }
}
