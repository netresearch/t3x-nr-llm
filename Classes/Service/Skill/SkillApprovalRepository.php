<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * DBAL store for ``tx_nrllm_skill_approval`` (ADR-214 item 2).
 *
 * The table has no TCA, so FormEngine and the DataHandler cannot reach it; the
 * approval action is its only writer. Rows are never deleted: a revocation
 * flags them, so the history of who approved which version, and who revoked it,
 * stays readable next to the append-only audit trail.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SkillApprovalRepository implements SkillApprovalRepositoryInterface
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_skill_approval';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function hasUnrevokedApproval(int $skillUid, int $sourceUid, string $versionDigest): bool
    {
        return $this->findUnrevoked($skillUid, $sourceUid, $versionDigest) instanceof SkillApproval;
    }

    public function findUnrevoked(int $skillUid, int $sourceUid, string $versionDigest): ?SkillApproval
    {
        if ($skillUid <= 0 || $versionDigest === '') {
            return null;
        }

        $queryBuilder = $this->queryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('skill_uid', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('source_uid', $queryBuilder->createNamedParameter($sourceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('version_digest', $queryBuilder->createNamedParameter($versionDigest)),
                $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findLatestUnrevoked(int $skillUid): ?SkillApproval
    {
        if ($skillUid <= 0) {
            return null;
        }

        $queryBuilder = $this->queryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('skill_uid', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findBySkill(int $skillUid): array
    {
        if ($skillUid <= 0) {
            return [];
        }

        $queryBuilder = $this->queryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('skill_uid', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)))
            ->orderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_map($this->hydrate(...), $rows));
    }

    public function add(int $skillUid, int $sourceUid, string $versionDigest, array $fields, string $trustLevel, int $approvedBy): int
    {
        $tools = SkillVersionDigest::normaliseTools($fields['allowed_tools']);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid'            => 0,
            'crdate'         => time(),
            'skill_uid'      => $skillUid,
            'source_uid'     => $sourceUid,
            'version_digest' => $versionDigest,
            'name'           => $fields['name'],
            'description'    => $fields['description'],
            'body'           => $fields['body'],
            'support_status' => $fields['support_status'],
            'allowed_tools'  => $tools === null ? '' : (string)json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'process'        => $fields['process'] ? 1 : 0,
            'trust_level'    => $trustLevel,
            'approved_by'    => $approvedBy,
            'revoked'        => 0,
            'revoked_by'     => 0,
            'revoked_at'     => 0,
        ]);

        return self::toInt($connection->lastInsertId());
    }

    public function revoke(int $skillUid, string $versionDigest, int $revokedBy): int
    {
        if ($skillUid <= 0 || $versionDigest === '') {
            return 0;
        }

        $queryBuilder = $this->queryBuilder();

        return $queryBuilder
            ->update(self::TABLE)
            ->set('revoked', 1, true, Connection::PARAM_INT)
            ->set('revoked_by', $revokedBy, true, Connection::PARAM_INT)
            ->set('revoked_at', time(), true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->eq('skill_uid', $queryBuilder->createNamedParameter($skillUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('version_digest', $queryBuilder->createNamedParameter($versionDigest)),
                $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    private function queryBuilder(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SkillApproval
    {
        return new SkillApproval(
            uid: self::toInt($row['uid'] ?? 0),
            skillUid: self::toInt($row['skill_uid'] ?? 0),
            sourceUid: self::toInt($row['source_uid'] ?? 0),
            versionDigest: self::toStr($row['version_digest'] ?? ''),
            name: self::toStr($row['name'] ?? ''),
            description: self::toStr($row['description'] ?? ''),
            body: self::toStr($row['body'] ?? ''),
            supportStatus: self::toStr($row['support_status'] ?? ''),
            allowedTools: $this->decodeTools(self::toStr($row['allowed_tools'] ?? '')),
            process: self::toInt($row['process'] ?? 0) === 1,
            trustLevel: self::toStr($row['trust_level'] ?? ''),
            approvedBy: self::toInt($row['approved_by'] ?? 0),
            approvedAt: self::toInt($row['crdate'] ?? 0),
            revoked: self::toInt($row['revoked'] ?? 0) === 1,
            revokedBy: self::toInt($row['revoked_by'] ?? 0),
            revokedAt: self::toInt($row['revoked_at'] ?? 0),
        );
    }

    /**
     * @return list<string>|null
     */
    private function decodeTools(string $stored): ?array
    {
        if ($stored === '') {
            return null;
        }

        $decoded = json_decode($stored, true);

        // A stored value that is not a list is corrupt. Reading it as "no
        // declaration" would widen the run to every tool, so it reads as the
        // declared-empty list, which grants nothing.
        return SkillVersionDigest::normaliseTools(is_array($decoded) ? array_values($decoded) : []);
    }
}
