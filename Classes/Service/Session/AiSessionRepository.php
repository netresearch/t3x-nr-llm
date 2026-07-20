<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Session;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use Netresearch\NrLlm\Domain\ValueObject\AiSessionMessage;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Writes and reads conversation sessions and their message turns (ADR-083).
 *
 * Uses the Doctrine ConnectionPool directly — both tables are UI-less logs with
 * no Extbase persistence needs, mirroring {@see \Netresearch\NrLlm\Service\Telemetry\TelemetryRepository}.
 * Unlike telemetry, a session has a read path (load the session and its ordered
 * turns) and a mutable activity summary (last-activity + message count).
 */
final readonly class AiSessionRepository implements AiSessionRepositoryInterface, SingletonInterface
{
    use SafeCastTrait;

    private const TABLE_SESSION = 'tx_nrllm_ai_session';

    private const TABLE_MESSAGE = 'tx_nrllm_ai_session_message';

    /** Sessions deleted per statement, so a neglected install purges in batches. */
    private const PURGE_CHUNK_SIZE = 500;

    /** Retries when concurrent turns race for the same message sequence. */
    private const SEQUENCE_ATTEMPTS = 5;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function startSession(string $uuid, int $beUser, string $configurationIdentifier, string $title): int
    {
        $now        = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_SESSION);
        $connection->insert(self::TABLE_SESSION, [
            'pid'                      => 0,
            'uuid'                     => $uuid,
            'be_user'                  => $beUser,
            'configuration_identifier' => $configurationIdentifier,
            'title'                    => $title,
            'message_count'            => 0,
            'last_activity'            => $now,
            'tstamp'                   => $now,
            'crdate'                   => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    public function appendMessage(
        int $sessionUid,
        int $sequence,
        string $role,
        string $content,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
    ): void {
        $this->connectionPool->getConnectionForTable(self::TABLE_MESSAGE)->insert(self::TABLE_MESSAGE, [
            'pid'               => 0,
            'session'           => $sessionUid,
            'sequence'          => $sequence,
            'role'              => $role,
            'content'           => $content,
            'model'             => $model,
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens'      => $totalTokens,
            'crdate'            => time(),
        ]);
    }

    public function appendMessageAtNextSequence(
        int $sessionUid,
        string $role,
        string $content,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
    ): int {
        // Two concurrent turns in one session must not share a sequence. The
        // unique key on (session, sequence) is the authority: read the next free
        // slot, try to take it, and on a collision read again. The loser of a
        // race simply lands on the next slot instead of silently overwriting the
        // ordering of the conversation.
        for ($attempt = 0; $attempt < self::SEQUENCE_ATTEMPTS; ++$attempt) {
            $sequence = $this->nextSequence($sessionUid);

            try {
                $this->appendMessage($sessionUid, $sequence, $role, $content, $model, $promptTokens, $completionTokens, $totalTokens);

                return $sequence;
            } catch (UniqueConstraintViolationException) {
                // Another turn took this slot between the read and the insert.
            }
        }

        throw new RuntimeException(
            sprintf('Could not allocate a message sequence for session %d after %d attempts.', $sessionUid, self::SEQUENCE_ATTEMPTS),
            1784600003,
        );
    }

    public function touch(int $sessionUid, int $messageCount): void
    {
        $now        = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_SESSION);

        // last_activity always advances; the count only ever grows. A slower
        // concurrent turn settling after a faster one must not report the
        // session back down to its own view of the message count.
        $builder = $connection->createQueryBuilder();
        $builder
            ->update(self::TABLE_SESSION)
            ->set('last_activity', (string)$now, false, Connection::PARAM_INT)
            ->set('tstamp', (string)$now, false, Connection::PARAM_INT)
            ->where($builder->expr()->eq('uid', $builder->createNamedParameter($sessionUid, Connection::PARAM_INT)))
            ->executeStatement();

        $countBuilder = $connection->createQueryBuilder();
        $countBuilder
            ->update(self::TABLE_SESSION)
            ->set('message_count', (string)$messageCount, false, Connection::PARAM_INT)
            ->where(
                $countBuilder->expr()->eq('uid', $countBuilder->createNamedParameter($sessionUid, Connection::PARAM_INT)),
                $countBuilder->expr()->lt('message_count', $countBuilder->createNamedParameter($messageCount, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    /**
     * The next free sequence in a session: one past the highest stored, or 0 for
     * an empty session. Derived from the message rows rather than from the
     * session's own counter, which is a summary and can lag.
     */
    private function nextSequence(int $sessionUid): int
    {
        $builder = $this->connectionPool->getConnectionForTable(self::TABLE_MESSAGE)->createQueryBuilder();
        $highest = $builder
            ->select('sequence')
            ->from(self::TABLE_MESSAGE)
            ->where($builder->expr()->eq('session', $builder->createNamedParameter($sessionUid, Connection::PARAM_INT)))
            ->orderBy('sequence', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $highest === false || $highest === null ? 0 : self::toInt($highest) + 1;
    }

    public function findByUuid(string $uuid): ?AiSession
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_SESSION);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE_SESSION)
            ->where($queryBuilder->expr()->eq('uuid', $queryBuilder->createNamedParameter($uuid)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return new AiSession(
            uid: self::toInt($row['uid'] ?? 0),
            uuid: self::toStr($row['uuid'] ?? ''),
            beUser: self::toInt($row['be_user'] ?? 0),
            configurationIdentifier: self::toStr($row['configuration_identifier'] ?? ''),
            title: self::toStr($row['title'] ?? ''),
            messageCount: self::toInt($row['message_count'] ?? 0),
            lastActivity: self::toInt($row['last_activity'] ?? 0),
            crdate: self::toInt($row['crdate'] ?? 0),
        );
    }

    public function findMessages(int $sessionUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_MESSAGE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_MESSAGE)
            ->where($queryBuilder->expr()->eq('session', $queryBuilder->createNamedParameter($sessionUid, Connection::PARAM_INT)))
            ->orderBy('sequence', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn(array $row): AiSessionMessage => new AiSessionMessage(
            uid: self::toInt($row['uid'] ?? 0),
            session: self::toInt($row['session'] ?? 0),
            sequence: self::toInt($row['sequence'] ?? 0),
            role: self::toStr($row['role'] ?? ''),
            content: self::toStr($row['content'] ?? ''),
            model: self::toStr($row['model'] ?? ''),
            promptTokens: self::toInt($row['prompt_tokens'] ?? 0),
            completionTokens: self::toInt($row['completion_tokens'] ?? 0),
            totalTokens: self::toInt($row['total_tokens'] ?? 0),
            crdate: self::toInt($row['crdate'] ?? 0),
        ), $rows);
    }

    public function purgeInactiveSince(int $timestamp): int
    {
        $sessionConnection = $this->connectionPool->getConnectionForTable(self::TABLE_SESSION);
        $selectBuilder     = $sessionConnection->createQueryBuilder();
        $rows              = $selectBuilder
            ->select('uid')
            ->from(self::TABLE_SESSION)
            ->where($selectBuilder->expr()->lt('last_activity', $selectBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAllAssociative();

        $uids = array_map(fn(array $row): int => self::toInt($row['uid'] ?? 0), $rows);
        if ($uids === []) {
            return 0;
        }

        $messageConnection = $this->connectionPool->getConnectionForTable(self::TABLE_MESSAGE);
        $deleted           = 0;

        // Chunked: a long-neglected installation must not build one unbounded
        // IN() list, and each batch commits on its own.
        foreach (array_chunk($uids, self::PURGE_CHUNK_SIZE) as $chunk) {
            $messageBuilder = $messageConnection->createQueryBuilder();
            $messageBuilder
                ->delete(self::TABLE_MESSAGE)
                ->where($messageBuilder->expr()->in('session', $messageBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeStatement();

            $deleteBuilder = $sessionConnection->createQueryBuilder();
            $deleted += (int)$deleteBuilder
                ->delete(self::TABLE_SESSION)
                ->where($deleteBuilder->expr()->in('uid', $deleteBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeStatement();
        }

        return $deleted;
    }

}
