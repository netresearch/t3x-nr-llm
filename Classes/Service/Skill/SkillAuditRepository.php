<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Append-only store for the skill audit trail (`tx_nrllm_skill_audit`, ADR-061).
 *
 * By construction the application can only INSERT: this class exposes
 * {@see record()} and read helpers and *deliberately* offers no update or
 * delete method. The audit rows are the immutable provenance record of every
 * skill that can reach a prompt; a purge, if ever needed, is a separate,
 * explicitly documented retention operation — never the regular write path.
 */
final readonly class SkillAuditRepository
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_skill_audit';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Append one immutable audit row.
     */
    public function record(
        string $event,
        int $sourceUid,
        string $skillIdentifier,
        string $sourceSha,
        string $bodyChecksum,
        string $trustLevel,
        string $scanResult,
        int $actorUid,
        string $detail,
    ): void {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'              => 0,
            'crdate'           => time(),
            'event'            => $event,
            'source_uid'       => $sourceUid,
            'skill_identifier' => $skillIdentifier,
            'source_sha'       => $sourceSha,
            'body_checksum'    => $bodyChecksum,
            'trust_level'      => $trustLevel,
            'scan_result'      => $scanResult,
            'actor_uid'        => $actorUid,
            'detail'           => $detail,
        ]);
    }

    /**
     * Read the audit rows for a source, oldest first. Read path for the
     * administration UI and the functional tests.
     *
     * @return list<array<string, mixed>>
     */
    public function findBySourceUid(int $sourceUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('source_uid', $queryBuilder->createNamedParameter($sourceUid)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * Total number of audit rows (read path for tests).
     */
    public function countAll(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return self::toInt($queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchOne());
    }
}
