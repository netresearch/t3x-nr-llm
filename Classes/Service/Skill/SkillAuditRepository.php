<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Append-only skill audit trail during normal writes (ADR-061).
 * record() inserts provenance rows; no normal operation updates existing rows.
 * purgeOlderThan() is the explicit retention exception (ADR-064), driven by
 * nrllm:privacy:purge and the skill-audit retention setting.
 */
final readonly class SkillAuditRepository implements SkillAuditRepositoryInterface
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_skill_audit';

    public function __construct(
        private ConnectionPool $connectionPool,
        private PrivacyPolicyInterface $privacyPolicy,
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
        string $versionDigest = '',
    ): void {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'              => 0,
            'crdate'           => time(),
            'event'            => $event,
            'source_uid'       => $sourceUid,
            'skill_identifier' => $skillIdentifier,
            'source_sha'       => $sourceSha,
            'body_checksum'    => $bodyChecksum,
            'version_digest'   => $versionDigest,
            'trust_level'      => $trustLevel,
            // scan_result and detail are free-form content; gate them through
            // the central privacy policy before persisting (ADR-064). All other
            // columns are metadata and are always kept.
            'scan_result'      => $this->privacyPolicy->filterContent($scanResult) ?? '',
            'actor_uid'        => $actorUid,
            'detail'           => $this->privacyPolicy->filterContent($detail) ?? '',
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

    /**
     * The one retention exception to the append-only rule (ADR-064): delete
     * rows created strictly before the given UNIX timestamp. Driven by
     * `nrllm:privacy:purge`, never the regular write path.
     */
    public function purgeOlderThan(int $timestamp): int
    {
        $connection   = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();

        return $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp)))
            ->executeStatement();
    }
}
