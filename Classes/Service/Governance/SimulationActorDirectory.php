<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Governance;

use Netresearch\NrLlm\Domain\ValueObject\SimulationActor;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The backend users the governance simulator can answer for (ADR-157).
 *
 * A read of `be_users` and nothing else: no session, no authentication, no
 * write. The filter is the query builder's default restriction set over the
 * enable columns `be_users` declares — `deleted`, `disable`, `starttime`,
 * `endtime` — so the picker cannot offer an account the rest of the backend
 * hides. `SimulationActorDirectoryTest` holds a deleted and a disabled row
 * against that claim.
 *
 * **Read on every render of the tab, and uncapped.** Both are consequences of
 * where the picker sits, not preferences:
 *
 * - the picker is part of the form that submits the simulation, so it renders
 *   whenever the tab does. Deferring the read behind "a pair was submitted"
 *   would remove the control that submits the pair;
 * - the cost is three columns of one row per backend user, ordered by
 *   `username`, which the core schema indexes (`KEY username (username)`).
 *   `be_users` holds editors, not site visitors;
 * - a `setMaxResults()` would silently omit the account an operator came here
 *   to ask about, on the one page whose purpose is to stop them guessing. If an
 *   installation ever outgrows a single select, the answer is a searchable
 *   control, not a cut nobody is told about.
 *
 * The `admin` flag is carried for the LABEL only. The gate is asked through the
 * live user the resolver loads from the database, never through this row: a
 * privilege read off a stale list is a privilege claim nobody verified.
 *
 * @internal
 */
final readonly class SimulationActorDirectory
{
    use SafeCastTrait;

    private const TABLE = 'be_users';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Every selectable backend user, ordered by username.
     *
     * @return list<SimulationActor>
     */
    public function actors(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $rows = $queryBuilder
            ->select('uid', 'username', 'admin')
            ->from(self::TABLE)
            ->orderBy('username')
            ->executeQuery()
            ->fetchAllAssociative();

        $actors = [];
        foreach ($rows as $row) {
            $actors[] = new SimulationActor(
                self::toInt($row['uid'] ?? null),
                self::toStr($row['username'] ?? null),
                self::toInt($row['admin'] ?? null) === 1,
            );
        }

        return $actors;
    }
}
