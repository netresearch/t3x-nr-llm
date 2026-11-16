<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\File;

use JsonException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Symfony\Component\Uid\Uuid;
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
 * A file a task sends (A2A-FILE5) is a row of the same table with the
 * direction `out`, registered against the task's run while it runs
 * ({@see self::storeOutboundForRun()}); the run's completion turns it into a
 * part of the task's artifacts ({@see A2aOutboundFileArtifacts}). Only files
 * with the direction `in` are the task's input.
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

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public function __construct(
        private ConnectionPool $connectionPool,
        private AgentStateCodec $codec,
        private A2aFileSettings $settings,
        private A2aFileValidator $validator = new A2aFileValidator(),
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
                'direction'           => self::DIRECTION_IN,
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
            ['task_uid' => $taskUid, 'owner_client_uid' => $ownerClientUid, 'direction' => self::DIRECTION_IN],
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
     * Register a file the task of a run sends (A2A-FILE5). The file is one of
     * the five types and passed its type's check (A2A-FILE1), and it is at
     * most `a2a.maxRawPartBytes` large, so its sealed row fits one database
     * packet. It expires with the files of the store.
     *
     * A file the task already sends, with the same bytes under the same name,
     * is not registered again: a retried segment repeats the call that
     * registered it, and the client would receive it twice.
     *
     * @param string $origin what produced the file, at most 8 characters
     *
     * @return string the file's id, the existing one for a repeated file
     *
     * @throws A2aException when the run has no A2A task, or the file is refused
     */
    public function storeOutboundForRun(int $runUid, string $origin, string $bytes, string $mediaType, string $filename, int $now): string
    {
        $task = $this->connectionPool->getConnectionForTable(A2aTaskStore::TABLE)
            ->select(['uid', 'owner_client_uid'], A2aTaskStore::TABLE, ['run_uid' => $runUid])
            ->fetchAssociative();
        if (!is_array($task) || !is_numeric($task['uid'] ?? null) || !is_numeric($task['owner_client_uid'] ?? null) || (int) $task['owner_client_uid'] < 1) {
            throw new A2aException(sprintf('Run %d has no A2A task to send a file with.', $runUid), 1799700101);
        }

        $refusal = $this->validator->refusal($mediaType, $bytes);
        if ($refusal !== null) {
            throw new A2aException('The file is not sent: ' . $refusal, 1799700102);
        }

        if (strlen($bytes) > $this->settings->maxRawPartBytes()) {
            throw new A2aException(sprintf('The file is not sent: it holds more than %d bytes.', $this->settings->maxRawPartBytes()), 1799700103);
        }

        $taskUid    = (int) $task['uid'];
        $owner      = (int) $task['owner_client_uid'];
        $connection = $this->connection();
        $filename   = self::safeFilename($filename);
        $sha256     = hash('sha256', $bytes);
        $builder    = $connection->createQueryBuilder();
        $existing   = $builder
            ->select('file_id')
            ->from(self::TABLE)
            ->where(
                $builder->expr()->eq('task_uid', $builder->createNamedParameter($taskUid, Connection::PARAM_INT)),
                $builder->expr()->eq('owner_client_uid', $builder->createNamedParameter($owner, Connection::PARAM_INT)),
                $builder->expr()->eq('direction', $builder->createNamedParameter(self::DIRECTION_OUT)),
                $builder->expr()->eq('sha256', $builder->createNamedParameter($sha256)),
                $builder->expr()->eq('filename', $builder->createNamedParameter($filename)),
                $builder->expr()->gt('expires', $builder->createNamedParameter($now, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $fileId = Uuid::v4()->toRfc4122();
        $connection->insert(self::TABLE, [
            'file_id'             => $fileId,
            'owner_client_uid'    => $owner,
            'task_uid'            => $taskUid,
            'part_index'          => $connection->count('uid', self::TABLE, ['task_uid' => $taskUid, 'direction' => self::DIRECTION_OUT]),
            'direction'           => self::DIRECTION_OUT,
            'origin'              => mb_substr($origin, 0, 8),
            'source_url'          => '',
            'filename'            => $filename,
            'declared_media_type' => $mediaType,
            'media_type'          => $mediaType,
            'size'                => strlen($bytes),
            'sha256'              => $sha256,
            'check_state'         => self::CHECK_PASSED,
            'approval_state'      => self::APPROVAL_APPROVED,
            'content'             => $this->codec->encode($bytes, AgentStateCodec::PURPOSE_A2A_FILE),
            'expires'             => $now + 3600 * $this->settings->retentionHours(),
            'crdate'              => $now,
            'tstamp'              => $now,
        ]);

        return $fileId;
    }

    /**
     * The files one task of one client sends, in the order they were
     * registered.
     *
     * @return list<array<string, mixed>>
     */
    public function outboundRowsOfTask(int $taskUid, int $ownerClientUid): array
    {
        return array_values($this->connection()->select(
            ['uid', 'file_id', 'filename', 'media_type', 'size', 'sha256'],
            self::TABLE,
            ['task_uid' => $taskUid, 'owner_client_uid' => $ownerClientUid, 'direction' => self::DIRECTION_OUT],
            [],
            ['part_index' => 'ASC', 'uid' => 'ASC'],
        )->fetchAllAssociative());
    }

    /**
     * One file a task of one client sends and that has not expired; null for
     * an unknown file, another client's and an expired one alike (A2A-SEC2).
     *
     * @return array<string, mixed>|null
     */
    public function outboundFile(string $fileId, int $ownerClientUid, int $now): ?array
    {
        $builder = $this->connection()->createQueryBuilder();
        $row     = $builder
            ->select('uid', 'file_id', 'task_uid', 'filename', 'media_type', 'size', 'sha256')
            ->from(self::TABLE)
            ->where(
                $builder->expr()->eq('file_id', $builder->createNamedParameter($fileId)),
                $builder->expr()->eq('owner_client_uid', $builder->createNamedParameter($ownerClientUid, Connection::PARAM_INT)),
                $builder->expr()->eq('direction', $builder->createNamedParameter(self::DIRECTION_OUT)),
                $builder->expr()->gt('expires', $builder->createNamedParameter($now, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * A file name without control characters, quotes, backslashes or
     * slashes, at most 255 characters: it goes into a header.
     */
    public static function safeFilename(string $filename): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '_', $filename), 0, 255);
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
