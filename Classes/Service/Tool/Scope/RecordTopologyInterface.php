<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

/**
 * Where a row stands in the page tree, as a run in one workspace sees it
 * (ADR-295). The seam between the scope check and the database.
 *
 * @internal
 */
interface RecordTopologyInterface
{
    /**
     * @return RecordFacts|null null when no row of that uid exists in the table
     */
    public function row(string $table, int $uid, int $workspace): ?RecordFacts;

    /**
     * Every page below the page, at any depth, once each.
     *
     * @return list<int>|null null when there are more than $limit
     */
    public function descendantPages(int $pageUid, int $workspace, int $limit): ?array;
}
