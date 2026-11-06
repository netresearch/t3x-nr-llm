<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * A `processCmdmapClass` hook that records the global backend user each
 * command of a DataHandler run sees: core reads page TSconfig and, in TYPO3
 * 13.4, a copied element's page translations through that global, so a test
 * of what a writer sets it to needs to look from inside the run.
 *
 * Registered per test and removed in its `finally`.
 */
final class RecordsTheGlobalUserHook
{
    /** @var list<array{uid: int, workspace: int}> */
    public static array $seen = [];

    /**
     * @param mixed $value
     * @param mixed $pasteUpdate
     */
    public function processCmdmap_preProcess(string &$command, string $table, int|string $id, &$value, DataHandler $dataHandler, &$pasteUpdate): void
    {
        $user         = $GLOBALS['BE_USER'] ?? null;
        self::$seen[] = $user instanceof BackendUserAuthentication
            ? ['uid' => is_numeric($user->user['uid'] ?? null) ? (int) $user->user['uid'] : 0, 'workspace' => $user->workspace]
            : ['uid' => 0, 'workspace' => -1];
    }
}
