<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Closure;
use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * @internal Durable source ownership and publication fence (ADR-221).
 */
final readonly class SkillSyncLeaseRepository
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_skill_source';

    private const STALE_SECONDS = 180;

    public function __construct(private ConnectionPool $connectionPool) {}

    public function hasOnePublicationConnection(): bool
    {
        $connection = $this->connection();
        return $connection === $this->connectionPool->getConnectionForTable('tx_nrllm_skill') && $connection === $this->connectionPool->getConnectionForTable('tx_nrllm_skill_audit');
    }

    /**
     * @phpstan-impure The persisted row can change between calls.
     */
    public function exists(int $uid): bool
    {
        return $uid > 0 && $this
            ->connection()
            ->count('*', self::TABLE, ['uid' => $uid, 'deleted' => 0]) === 1;
    }

    public function claim(int $uid, string $owner, int $now): bool
    {
        return $this
            ->connection()
            ->executeStatement(
                'UPDATE ' . self::TABLE . ' SET sync_status = :syncing, last_synced = :now, sync_lock_token = :owner, sync_lock_version = 0' . ' WHERE uid = :uid AND deleted = 0 AND (sync_status <> :syncing OR last_synced = 0 OR last_synced < :earliest OR last_synced > :latest)',
                [
                    'syncing' => SyncStatus::SYNCING->value,
                    'now' => $now,
                    'owner' => $owner,
                    'uid' => $uid,
                    'earliest' => $now - self::STALE_SECONDS,
                    'latest' => $now + self::STALE_SECONDS,
                ],
            ) === 1;
    }

    public function renew(int $uid, string $owner, int $now): bool
    {
        // The increment makes affected-row counts reliable even in the same
        // second on drivers that count only changed rows. It is not ownership.
        return $this
            ->connection()
            ->executeStatement(
                'UPDATE ' . self::TABLE . ' SET last_synced = :now, sync_lock_version = sync_lock_version + 1' . ' WHERE uid = :uid AND deleted = 0 AND sync_status = :syncing AND sync_lock_token = :owner' . ' AND last_synced <> 0 AND last_synced >= :earliest AND last_synced <= :latest',
                [
                    'now' => $now,
                    'uid' => $uid,
                    'syncing' => SyncStatus::SYNCING->value,
                    'owner' => $owner,
                    'earliest' => $now - self::STALE_SECONDS,
                    'latest' => $now + self::STALE_SECONDS,
                ],
            ) === 1;
    }

    public function reclaim(int $uid, int $now): bool
    {
        return $this
            ->connection()
            ->executeStatement(
                'UPDATE ' . self::TABLE . ' SET sync_status = :error, sync_error = :message, sync_lock_token = :empty' . ' WHERE uid = :uid AND deleted = 0 AND sync_status = :syncing' . ' AND (last_synced = 0 OR last_synced < :earliest OR last_synced > :latest)',
                [
                    'error' => SyncStatus::ERROR->value,
                    'message' => 'The previous sync was interrupted before it finished. Trigger a new sync to retry.',
                    'empty' => '',
                    'uid' => $uid,
                    'syncing' => SyncStatus::SYNCING->value,
                    'earliest' => $now - self::STALE_SECONDS,
                    'latest' => $now + self::STALE_SECONDS,
                ],
            ) === 1;
    }

    public function release(
        int $uid,
        string $owner,
        SyncStatus $status,
        int $now,
        string $error,
        ?string $pinnedSha,
    ): bool {
        $parameters = [
            'status' => $status->value,
            'now' => $now,
            'error' => $error,
            'empty' => '',
            'uid' => $uid,
            'owner' => $owner,
            'syncing' => SyncStatus::SYNCING->value,
        ];
        if ($pinnedSha !== null) {
            $parameters['sha'] = $pinnedSha;
        }

        return $this
            ->connection()
            ->executeStatement(
                'UPDATE ' . self::TABLE . ' SET sync_status = :status, last_synced = :now, sync_error = :error, sync_lock_token = :empty' . ($pinnedSha === null ? '' : ', pinned_sha = :sha') . ' WHERE uid = :uid AND deleted = 0 AND sync_status = :syncing AND sync_lock_token = :owner',
                $parameters,
            ) === 1;
    }

    /**
     * @template T
     *
     * @param Closure(): T $publish
     *
     * @return T
     */
    public function publication(
        int $uid,
        string $owner,
        int $now,
        Closure $publish,
    ): mixed {
        return $this
            ->connection()
            ->transactional(
                function () use ($uid, $owner, $now, $publish): mixed {
                    if (!$this->renew($uid, $owner, $now)) {
                        throw new RuntimeException(
                            'Skill synchronization lease was lost; collected data was not published.',
                            1781650221,
                        );
                    }

                    return $publish();
                },
            );
    }

    /**
     * @return array{status: string, error: string, lastSynced: int, pinnedSha: string}|null
     */
    public function bookkeeping(int $uid): ?array
    {
        $row = $this
            ->connection()
            ->executeQuery(
                'SELECT sync_status, sync_error, last_synced, pinned_sha FROM ' . self::TABLE . ' WHERE uid = :uid AND deleted = 0',
                ['uid' => $uid],
            )
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }

        return [
            'status' => self::toStr($row['sync_status']),
            'error' => self::toStr($row['sync_error']),
            'lastSynced' => self::toInt($row['last_synced']),
            'pinnedSha' => self::toStr($row['pinned_sha']),
        ];
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
