<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

/**
 * Reads recent rows from TYPO3's `sys_log` table for the Task
 * "syslog" input source.
 *
 * Concrete implementation: `SystemLogReader`. The interface exists
 * so the slice 13b `TaskInputResolver` can be unit-tested without
 * standing up a real database.
 */
interface SystemLogReaderInterface
{
    /**
     * @param int  $limit     Maximum number of rows to return (newest first)
     * @param bool $errorOnly Return only rows whose `error` column is greater than 0
     *
     * @return list<array<string, mixed>>
     */
    public function readRecent(int $limit, bool $errorOnly): array;
}
