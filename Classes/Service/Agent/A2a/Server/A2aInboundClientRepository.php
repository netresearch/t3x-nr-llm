<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Reads the client records of the server role and issues their tokens
 * (ADR-280).
 *
 * A token is `nra2a_<prefix>_<secret>`: a public prefix, unique per record,
 * and 32 random bytes. The record keeps the prefix and the sha256 of the
 * whole token. Issuing a token replaces both on the same record, so the
 * record's uid, which owns the client's tasks, survives a rotation.
 *
 * Every read that serves a request returns a self-service record (ADR-294)
 * after {@see A2aSelfServicePolicy::constrain()}: withdrawn when its owner's
 * groups no longer cover it, so the authenticator, the handlers and the resume
 * guard all see the same record and none of them has to remember the rule. The
 * `*ForManagement` and `*Raw` reads are the unconstrained ones for the module,
 * which has to show a withdrawn record as what it is.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aInboundClientRepository
{
    public const TABLE = 'tx_nrllm_a2a_client';

    public const TOKEN_PREFIX = 'nra2a_';

    /** The task limit of a self-service record: the column default, not a choice. */
    public const SELF_SERVICE_TASK_LIMIT = 4;

    public function __construct(
        private ConnectionPool $connectionPool,
        private A2aSelfServicePolicy $policy,
    ) {}

    /**
     * The prefix of a token, null when the token does not have the form.
     */
    public static function prefixOf(string $token): ?string
    {
        return preg_match('/^nra2a_([a-f0-9]{16})_[A-Za-z0-9_-]{43}$/', $token, $match) === 1 ? $match[1] : null;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function findByTokenPrefix(string $prefix): ?A2aInboundClient
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $row = $builder->select('*')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('token_prefix', $builder->createNamedParameter($prefix)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) && $prefix !== '' ? $this->policy->constrain(A2aInboundClient::fromRow($row), time()) : null;
    }

    public function findByUid(int $uid): ?A2aInboundClient
    {
        $client = $this->findRawByUid($uid);

        return $client instanceof A2aInboundClient ? $this->policy->constrain($client, time()) : null;
    }

    /**
     * The record as stored, without the owner's current limits applied. For
     * the management module only; a request never reads a client this way.
     */
    public function findRawByUid(int $uid): ?A2aInboundClient
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $row = $builder->select('*')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('uid', $builder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? A2aInboundClient::fromRow($row) : null;
    }

    /**
     * Every record as stored (the administrator's list), or only the
     * self-service records of one owner. Never a token or a hash: the value
     * object carries the hash, the module does not show it.
     *
     * @return list<A2aInboundClient>
     */
    public function findForManagement(?int $selfServiceOwnerUid = null): array
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $builder->select('*')->from(self::TABLE)->orderBy('title')->addOrderBy('uid');
        if ($selfServiceOwnerUid !== null) {
            $builder->where(
                $builder->expr()->eq('self_service', 1),
                $builder->expr()->eq('be_user', $builder->createNamedParameter($selfServiceOwnerUid, Connection::PARAM_INT)),
            );
        }

        return array_map(A2aInboundClient::fromRow(...), $builder->executeQuery()->fetchAllAssociative());
    }

    /**
     * The time of the newest task each client created, by client uid. The only
     * "last used" there is: a request that creates no task leaves no trace.
     *
     * @param list<int> $clientUids
     *
     * @return array<int, int>
     */
    public function lastTaskTimes(array $clientUids): array
    {
        if ($clientUids === []) {
            return [];
        }

        $builder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_a2a_task');
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('owner_client_uid')->addSelectLiteral('MAX(' . $builder->quoteIdentifier('crdate') . ') AS last_task')
            ->from('tx_nrllm_a2a_task')
            ->where($builder->expr()->in('owner_client_uid', $builder->createNamedParameter($clientUids, Connection::PARAM_INT_ARRAY)))
            ->groupBy('owner_client_uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $times = [];
        foreach ($rows as $row) {
            if (is_numeric($row['owner_client_uid'] ?? null) && is_numeric($row['last_task'] ?? null)) {
                $times[(int) $row['owner_client_uid']] = (int) $row['last_task'];
            }
        }

        return $times;
    }

    /**
     * How many self-service tokens of the owner can act now: enabled, with a
     * token, not expired. A revoked or expired one does not count.
     */
    public function countActiveSelfService(int $ownerUid, int $now): int
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $count = $builder->count('uid')->from(self::TABLE)
            ->where(
                $builder->expr()->eq('self_service', 1),
                $builder->expr()->eq('be_user', $builder->createNamedParameter($ownerUid, Connection::PARAM_INT)),
                $builder->expr()->eq('enabled', 1),
                $builder->expr()->neq('token_hash', $builder->createNamedParameter('')),
                $builder->expr()->or(
                    $builder->expr()->eq('expires', 0),
                    $builder->expr()->gt('expires', $builder->createNamedParameter($now, Connection::PARAM_INT)),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Store a self-service record without a token (ADR-294). The caller has
     * run {@see A2aSelfServicePolicy::refusal()}; the owner is the editor, and
     * the task limit is the default, not the editor's choice.
     *
     * @param list<int> $profileUids
     */
    public function insertSelfService(int $ownerUid, string $title, int $workspaceId, array $profileUids, int $expires, int $now): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid'          => 0,
            'title'        => mb_substr($title, 0, 255),
            'be_user'      => $ownerUid,
            'workspace'    => $workspaceId,
            'profiles'     => implode(',', $profileUids),
            'task_limit'   => self::SELF_SERVICE_TASK_LIMIT,
            'enabled'      => 1,
            'expires'      => $expires,
            'self_service' => 1,
            'tstamp'       => $now,
            'crdate'       => $now,
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * Disable a record and delete its token hash, so the token cannot work
     * again. With an owner the record must be that owner's self-service record;
     * the answer for a record that is not theirs is the answer for a missing one.
     *
     * @return bool whether a record was revoked
     */
    public function revoke(int $uid, ?int $selfServiceOwnerUid = null): bool
    {
        $criteria = ['uid' => $uid, 'deleted' => 0];
        if ($selfServiceOwnerUid !== null) {
            $criteria += ['self_service' => 1, 'be_user' => $selfServiceOwnerUid];
        }

        return $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['enabled' => 0, 'token_hash' => '', 'tstamp' => time()],
            $criteria,
        ) > 0;
    }

    /**
     * Delete a record that never got a token (the issuing failed after the insert).
     */
    public function discardUnissued(int $uid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, ['deleted' => 1], ['uid' => $uid, 'token_hash' => '']);
    }

    /**
     * Issue a new token for the record and return it in clear; this is the
     * only time it exists outside the client.
     */
    public function issueToken(int $uid): string
    {
        if (!$this->findByUid($uid) instanceof A2aInboundClient) {
            throw new A2aException(sprintf('There is no A2A client record %d.', $uid), 1799300101);
        }

        $prefix = bin2hex(random_bytes(8));
        $token  = self::TOKEN_PREFIX . $prefix . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['token_prefix' => $prefix, 'token_hash' => self::hash($token), 'token_issued' => time(), 'tstamp' => time()],
            ['uid' => $uid],
        );

        return $token;
    }
}
