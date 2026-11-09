<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A DataHandler hook that rewrites the alternative text of one file
 * reference row after a delete command, the way an installation's hook can
 * touch a row the call did not ask to change. A test sees what a take-back
 * reports about a row that was there before the call and changed.
 *
 * Registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']`
 * and removed again afterwards; `$row` names the row and `$connectionPool`
 * the test's pool, both reset by the test.
 */
final class ChangesAKeptReferenceDraftHook
{
    public const VALUE = 'Rewritten by an installation hook';

    public static int $row = 0;

    public static ?ConnectionPool $connectionPool = null;

    public function processCmdmap_postProcess(string $command, string $table): void
    {
        if ($command !== 'delete' || $table !== 'sys_file_reference' || self::$row < 1 || !self::$connectionPool instanceof ConnectionPool) {
            return;
        }

        self::$connectionPool
            ->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', ['alternative' => self::VALUE], ['uid' => self::$row]);
    }
}
