<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A `processDatamap_afterAllOperations` hook that ends the open transaction
 * with a plain `ROLLBACK` statement, which DBAL does not count, whenever a run
 * wrote a page.
 *
 * The opposite of {@see FlushesADatabaseCacheHook}: the transaction of a
 * scoped call ends before the call does, and what the call wrote up to the
 * hook is gone. The call must not be reported as written.
 *
 * Registered per test under `processDatamapClass` and removed in tearDown.
 */
final class RollsTheTransactionBackHook
{
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if (($dataHandler->datamap['pages'] ?? []) === []) {
            return;
        }

        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')->executeStatement('ROLLBACK');
    }
}
