<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use RuntimeException;

/**
 * A `clearCachePostProc` hook that records the page of every cache flush the
 * DataHandler prepares for a written record.
 *
 * The functional test setup runs the page cache on a null backend, so an
 * entry cannot be put there and found gone afterwards. The DataHandler calls
 * this hook from `processClearCacheQueue()`, the step at the end of a run
 * that flushes the cache of every page it wrote to — which is what a test of
 * that step has to see.
 *
 * With `$fail` set it throws instead, the way a cache hook of an installation
 * can: the test of a finishing step that fails needs a producer.
 *
 * Registered per test under `clearCachePostProc` and reset in tearDown.
 */
final class CountsCacheClearsHook
{
    /** @var list<int> */
    public static array $pages = [];

    public static bool $fail = false;

    public static function reset(): void
    {
        self::$pages = [];
        self::$fail  = false;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function record(array $params): void
    {
        if (self::$fail) {
            throw new RuntimeException('A test cache hook fails', 1790000002);
        }

        if (isset($params['uid_page']) && is_numeric($params['uid_page'])) {
            self::$pages[] = (int)$params['uid_page'];
        }
    }
}
