<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * A `processCmdmapClass` hook that records the global backend user each
 * command of a DataHandler run sees: core reads page TSconfig and, in TYPO3
 * 13.4, a copied element's page translations through that global's
 * workspace, so a test of what a writer sets needs to look from inside the
 * run: which user object it is, and in which workspace.
 *
 * Registered per test and removed in its `finally`.
 */
final class RecordsTheGlobalUserHook
{
    /** @var list<array{object: int, workspace: int}> */
    public static array $seen = [];

    /**
     * Core passes the command, table, uid and value; the record is only of
     * the global user, so it takes none of them.
     */
    public function processCmdmap_preProcess(): void
    {
        $user         = $GLOBALS['BE_USER'] ?? null;
        self::$seen[] = $user instanceof BackendUserAuthentication
            ? ['object' => spl_object_id($user), 'workspace' => $user->workspace]
            : ['object' => 0, 'workspace' => -1];
    }
}
