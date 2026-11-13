<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

/**
 * Reads the publication profiles (spec 051 A2A-SV4).
 *
 * A hidden or deleted profile does not exist for the server: it is neither
 * on the card nor callable.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSkillProfileRepository
{
    public const TABLE = 'tx_nrllm_a2a_profile';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * The public profiles, for the card.
     *
     * @return list<A2aSkillProfile>
     */
    public function findPublic(): array
    {
        $builder = $this->builder();

        return $this->hydrate($builder->select('*')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('public', 1))
            ->orderBy('skill_id')
            ->executeQuery()
            ->fetchAllAssociative());
    }

    /**
     * The public profiles a client may call (A2A-SV4a, A2A-SEC3).
     *
     * @return list<A2aSkillProfile>
     */
    public function findAllowedFor(A2aInboundClient $client): array
    {
        return $this->findPublicByUids($client->profileUids);
    }

    /**
     * The public profiles among the given uids.
     *
     * @param list<int> $uids
     *
     * @return list<A2aSkillProfile>
     */
    public function findPublicByUids(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $builder = $this->builder();

        return $this->hydrate($builder->select('*')
            ->from(self::TABLE)
            ->where(
                $builder->expr()->eq('public', 1),
                $builder->expr()->in('uid', $builder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('skill_id')
            ->executeQuery()
            ->fetchAllAssociative());
    }

    public function findByUid(int $uid): ?A2aSkillProfile
    {
        $builder = $this->builder();
        $row     = $builder->select('*')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('uid', $builder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? A2aSkillProfile::fromRow($row) : null;
    }

    private function builder(): QueryBuilder
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $builder->getRestrictions()->removeAll()->add(new DeletedRestriction())->add(new HiddenRestriction());

        return $builder;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<A2aSkillProfile>
     */
    private function hydrate(array $rows): array
    {
        return array_values(array_filter(
            array_map(A2aSkillProfile::fromRow(...), $rows),
            static fn (A2aSkillProfile $profile): bool => $profile->skillId !== '',
        ));
    }
}
