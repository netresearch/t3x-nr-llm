<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use JsonException;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The private file store of the A2A server role (spec 051 A2A-FILE4,
 * ADR-284): `tx_nrllm_a2a_file`.
 *
 * A file's bytes are sealed in its row ({@see AgentStateCodec}), so they are
 * neither in FAL nor under any public path, and a row and its bytes commit and
 * roll back together with the task that accepts them. Every read names the
 * owning client (A2A-SEC2).
 *
 * The task's manifest (`tx_nrllm_a2a_task.input_manifest`) lists the files
 * the accepted message carried, written in the same transaction. The worker
 * compares the rows with it before the run's first model call, so a row that
 * is gone, or bytes that changed, stop the run instead of passing unnoticed.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aFileStore
{
    public const TABLE = 'tx_nrllm_a2a_file';

    public const CHECK_PENDING = 'pending';

    public const CHECK_PASSED = 'passed';

    public const CHECK_FAILED = 'failed';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REFUSED = 'refused';

    public function __construct(
        private ConnectionPool $connectionPool,
        private AgentStateCodec $codec,
        private A2aFileSettings $settings,
    ) {}

    /**
     * Store the files of an accepted message and the task's manifest. Called
     * inside the acceptance transaction (A2A-SV5).
     *
     * A raw part was checked at the request and is approved here: it passed
     * its type's check and the profile takes its type. A url part waits for
     * the worker.
     *
     * @param list<A2aInboundFile> $files
     */
    public function storeForTask(int $ownerClientUid, int $taskUid, array $files, int $now): void
    {
        if ($files === []) {
            return;
        }

        $connection = $this->connection();
        $manifest   = [];
        $expires    = $now + 3600 * $this->settings->retentionHours();
        foreach ($files as $file) {
            $connection->insert(self::TABLE, [
                'file_id'             => $file->fileId,
                'owner_client_uid'    => $ownerClientUid,
                'task_uid'            => $taskUid,
                'part_index'          => $file->partIndex,
                'origin'              => $file->origin,
                'source_url'          => $this->codec->encode($file->url, AgentStateCodec::PURPOSE_A2A_FILE),
                'filename'            => $file->filename,
                'declared_media_type' => $file->mediaType,
                'media_type'          => $file->isRaw() ? $file->mediaType : '',
                'size'                => strlen($file->bytes),
                'sha256'              => $file->sha256(),
                'check_state'         => $file->isRaw() ? self::CHECK_PASSED : self::CHECK_PENDING,
                'approval_state'      => $file->isRaw() ? self::APPROVAL_APPROVED : self::APPROVAL_PENDING,
                'content'             => $this->codec->encode($file->bytes, AgentStateCodec::PURPOSE_A2A_FILE),
                'expires'             => $expires,
                'crdate'              => $now,
                'tstamp'              => $now,
            ]);

            $manifest[] = ['fileId' => $file->fileId, 'origin' => $file->origin, 'sha256' => $file->sha256(), 'size' => strlen($file->bytes)];
        }

        $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)->update(A2aTaskStore::TABLE, ['input_manifest' => json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)], ['uid' => $taskUid]);
    }

    /**
     * The manifest of a task: the files its accepted message carried.
     *
     * @return list<array{fileId: string, origin: string, sha256: string, size: int}>|null null when it cannot be read
     */
    public function manifest(string $stored): ?array
    {
        if ($stored === '') {
            return [];
        }

        try {
            $decoded = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !is_string($entry['fileId'] ?? null) || !is_string($entry['origin'] ?? null) || !is_string($entry['sha256'] ?? null) || !is_int($entry['size'] ?? null)) {
                return null;
            }

            $entries[] = ['fileId' => $entry['fileId'], 'origin' => $entry['origin'], 'sha256' => $entry['sha256'], 'size' => $entry['size']];
        }

        return $entries;
    }

    /**
     * The stored files of one task of one client, by file id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function rowsOfTask(int $taskUid, int $ownerClientUid): array
    {
        $rows = $this->connection()->select(
            ['uid', 'file_id', 'part_index', 'origin', 'filename', 'declared_media_type', 'media_type', 'size', 'sha256', 'check_state', 'approval_state', 'expires'],
            self::TABLE,
            ['task_uid' => $taskUid, 'owner_client_uid' => $ownerClientUid],
            [],
            ['part_index' => 'ASC'],
        )->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            $byId[is_string($row['file_id'] ?? null) ? $row['file_id'] : ''] = $row;
        }

        return $byId;
    }

    /**
     * The bytes of one stored file of one client.
     */
    public function content(int $uid, int $ownerClientUid): string
    {
        $sealed = $this->connection()->select(['content'], self::TABLE, ['uid' => $uid, 'owner_client_uid' => $ownerClientUid])->fetchOne();

        return is_string($sealed) ? $this->codec->decode($sealed, AgentStateCodec::PURPOSE_A2A_FILE) : '';
    }

    /**
     * The URL of one stored url part of one client.
     */
    public function sourceUrl(int $uid, int $ownerClientUid): string
    {
        $sealed = $this->connection()->select(['source_url'], self::TABLE, ['uid' => $uid, 'owner_client_uid' => $ownerClientUid])->fetchOne();

        return is_string($sealed) ? $this->codec->decode($sealed, AgentStateCodec::PURPOSE_A2A_FILE) : '';
    }

    /**
     * Record the checked bytes of a fetched url part, which are its input
     * from now on: the URL is never fetched again (A2A-FILE4). Only a file
     * still pending moves; false when none did.
     */
    public function recordFetched(int $uid, int $ownerClientUid, string $bytes, string $mediaType, int $now): bool
    {
        return $this->connection()->update(self::TABLE, [
            'media_type'     => $mediaType,
            'size'           => strlen($bytes),
            'sha256'         => hash('sha256', $bytes),
            'check_state'    => self::CHECK_PASSED,
            'approval_state' => self::APPROVAL_APPROVED,
            'content'        => $this->codec->encode($bytes, AgentStateCodec::PURPOSE_A2A_FILE),
            'tstamp'         => $now,
        ], ['uid' => $uid, 'owner_client_uid' => $ownerClientUid, 'check_state' => self::CHECK_PENDING]) === 1;
    }

    /**
     * Record why a url part was refused; it is not fetched again.
     */
    public function recordRefused(int $uid, int $ownerClientUid, string $reason, int $now): void
    {
        $this->connection()->update(self::TABLE, [
            'check_state'    => self::CHECK_FAILED,
            'approval_state' => self::APPROVAL_REFUSED,
            'refusal'        => mb_substr($reason, 0, 255),
            'tstamp'         => $now,
        ], ['uid' => $uid, 'owner_client_uid' => $ownerClientUid, 'check_state' => self::CHECK_PENDING]);
    }

    /**
     * Delete the files past their expiry. Only rows of this table are
     * deleted: a file adopted into FAL is a FAL file and stays.
     */
    public function purgeExpired(int $now): int
    {
        $builder = $this->connection()->createQueryBuilder();

        return $builder
            ->delete(self::TABLE)
            ->where($builder->expr()->lte('expires', $builder->createNamedParameter($now, Connection::PARAM_INT)))
            ->executeStatement();
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
