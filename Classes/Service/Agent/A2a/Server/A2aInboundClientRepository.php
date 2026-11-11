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
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aInboundClientRepository
{
    public const TABLE = 'tx_nrllm_a2a_client';

    public const TOKEN_PREFIX = 'nra2a_';

    public function __construct(
        private ConnectionPool $connectionPool,
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

        return is_array($row) && $prefix !== '' ? A2aInboundClient::fromRow($row) : null;
    }

    public function findByUid(int $uid): ?A2aInboundClient
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
