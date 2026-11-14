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
     * The languages of the content on a page and of the translations of the page
     * itself.
     *
     * @return list<int>
     */
    public function languagesOnPage(int $pageUid, int $workspace): array;

    /**
     * The stored value of one column of a row, or null when the row or the column
     * does not exist.
     */
    public function storedValue(string $table, int $uid, string $field): ?string;

    /**
     * The languages the row has translations in.
     *
     * @return list<int>
     */
    public function translationLanguages(string $table, int $uid, int $workspace): array;

    /**
     * Every page below the page, at any depth, once each.
     *
     * @return list<int>|null null when there are more than $limit
     */
    public function descendantPages(int $pageUid, int $workspace, int $limit): ?array;
}
