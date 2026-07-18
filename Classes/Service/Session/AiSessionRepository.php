<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Session;

use Netresearch\NrLlm\Domain\ValueObject\AiSession;
use Netresearch\NrLlm\Domain\ValueObject\AiSessionMessage;
use Netresearch\NrLlm\Utility\SafeCastTrait;
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

    public function touch(int $sessionUid, int $messageCount): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::TABLE_SESSION)->update(
            self::TABLE_SESSION,
            [
                'message_count' => $messageCount,
                'last_activity' => $now,
                'tstamp'        => $now,
            ],
            ['uid' => $sessionUid],
        );
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
        $messageBuilder    = $messageConnection->createQueryBuilder();
        $messageBuilder
            ->delete(self::TABLE_MESSAGE)
            ->where($messageBuilder->expr()->in('session', $messageBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeStatement();

        $deleteBuilder = $sessionConnection->createQueryBuilder();

        return (int)$deleteBuilder
            ->delete(self::TABLE_SESSION)
            ->where($deleteBuilder->expr()->lt('last_activity', $deleteBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->executeStatement();
    }

}
