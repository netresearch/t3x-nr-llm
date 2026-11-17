<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A `processDatamap_afterAllOperations` hook that flushes a whole cache on a
 * database backend whenever a run wrote a page, as an extension of the
 * installation does to drop counts it keeps per page (nr_mcp_agent's
 * dashboard counts, for one).
 *
 * `Typo3DatabaseBackend::flush()` truncates the cache tables. On MySQL and
 * MariaDB a `TRUNCATE` commits the open transaction implicitly, so this hook
 * ends the transaction of a scoped tool call in the middle of the call.
 *
 * Registered per test under `processDatamapClass` and removed in tearDown.
 */
final class FlushesADatabaseCacheHook
{
    public const CACHE = 'nrllm_test_page_counts';

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if (($dataHandler->datamap['pages'] ?? []) === []) {
            return;
        }

        // The DataHandler builds a hook without arguments, so the cache
        // manager is taken from the container here.
        $cacheManager = GeneralUtility::getContainer()->get(CacheManager::class);
        assert($cacheManager instanceof CacheManager);
        $cacheManager->getCache(self::CACHE)->flush();
    }
}
